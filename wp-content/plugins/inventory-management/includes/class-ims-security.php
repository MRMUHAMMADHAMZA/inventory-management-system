<?php
/**
 * Security services:
 *  - Database-backed rate limiting (atomic counters + lockouts).
 *  - Authentication event log.
 *  - Roles & capabilities (authorization).
 *  - "Remember me" cookie lifetimes and session helpers.
 *  - Daily cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_Security {

	/* ===================================================================
	 * Authorization: capabilities and roles
	 * ================================================================= */

	const CAPS = array(
		'ims_view_inventory'   => 'View inventory, stock levels and history',
		'ims_manage_stock'     => 'Record stock in / out / adjustments',
		'ims_manage_products'  => 'Create, edit and delete products',
		'ims_manage_suppliers' => 'Create, edit and delete suppliers',
		'ims_export'           => 'Export inventory to CSV',
		'ims_view_security'    => 'View the security log and unlock accounts',
	);

	const ROLES = array(
		'ims_manager' => array( 'Inventory Manager', array( 'ims_view_inventory', 'ims_manage_stock', 'ims_manage_products', 'ims_manage_suppliers', 'ims_export', 'ims_view_security' ) ),
		'ims_staff'   => array( 'Inventory Staff', array( 'ims_view_inventory', 'ims_manage_stock' ) ),
		'ims_viewer'  => array( 'Inventory Viewer', array( 'ims_view_inventory' ) ),
	);

	const ROLES_VERSION = '1';

	/** Create/refresh roles and give administrators every inventory capability. */
	public static function install_roles() {
		foreach ( self::ROLES as $slug => $role ) {
			remove_role( $slug );
			add_role( $slug, $role[0], array_fill_keys( array_merge( array( 'read' ), $role[1] ), true ) );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::CAPS ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		update_option( 'ims_roles_version', self::ROLES_VERSION );
	}

	/* ===================================================================
	 * Rate limiting (database-backed)
	 * ================================================================= */

	/**
	 * Policies: max attempts within window (seconds), then lockout (seconds).
	 * A lockout of 0 means "blocked until the window resets".
	 */
	const POLICIES = array(
		'login_ip'       => array( 20, 900, 900 ),   // Failed logins per IP.
		'login_account'  => array( 5, 900, 900 ),    // Failed logins per account (email).
		'register_ip'    => array( 5, 3600, 3600 ),  // Registrations per IP.
		'forgot_ip'      => array( 5, 900, 900 ),    // Reset requests per IP.
		'forgot_account' => array( 3, 900, 0 ),      // Reset emails per account (silently skipped beyond this).
		'otp_ip'         => array( 20, 900, 900 ),   // OTP verification attempts per IP.
		'reauth'         => array( 5, 900, 900 ),    // Wrong current-password attempts per signed-in user.
		'api'            => array( 120, 60, 0 ),     // REST requests per user (or IP) per minute.
	);

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Behind a trusted reverse proxy, return the real client IP here.
		 * Proxy headers are NOT trusted by default because clients can forge them.
		 */
		$ip = (string) apply_filters( 'ims_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	private static function ident( $value ) {
		return hash( 'sha256', strtolower( trim( (string) $value ) ) . '|' . wp_salt( 'auth' ) );
	}

	/** Seconds remaining on an active lockout/block, or 0 when allowed. */
	public static function blocked_for( $bucket, $value ) {
		global $wpdb;
		$t = IMS_DB::table( 'rate_limits' );
		list( $max, $window ) = self::POLICIES[ $bucket ];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT hits, window_start, locked_until FROM $t WHERE bucket = %s AND ident = %s", $bucket, self::ident( $value ) ) );
		if ( ! $row ) {
			return 0;
		}
		$now = time();
		if ( (int) $row->locked_until > $now ) {
			return (int) $row->locked_until - $now;
		}
		// Windows without a lockout block until the window resets.
		if ( (int) $row->hits >= $max && (int) $row->window_start > $now - $window ) {
			return (int) $row->window_start + $window - $now;
		}
		return 0;
	}

	/**
	 * Record one hit atomically. Returns array( hits, remaining, blocked_seconds ).
	 */
	public static function hit( $bucket, $value ) {
		global $wpdb;
		$t = IMS_DB::table( 'rate_limits' );
		list( $max, $window, $lockout ) = self::POLICIES[ $bucket ];
		$ident  = self::ident( $value );
		$now    = time();
		$cutoff = $now - $window;

		// One statement: start a new window if the old one expired, otherwise increment.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $t (bucket, ident, hits, window_start, locked_until) VALUES (%s, %s, 1, %d, 0)
				ON DUPLICATE KEY UPDATE
					hits = IF(window_start < %d, 1, hits + 1),
					window_start = IF(window_start < %d, %d, window_start)",
				$bucket,
				$ident,
				$now,
				$cutoff,
				$cutoff,
				$now
			)
		);
		$hits    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM $t WHERE bucket = %s AND ident = %s", $bucket, $ident ) );
		$blocked = 0;
		if ( $hits >= $max ) {
			if ( $lockout > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET locked_until = %d WHERE bucket = %s AND ident = %s AND locked_until < %d", $now + $lockout, $bucket, $ident, $now ) );
				$blocked = $lockout;
			} else {
				$blocked = $window;
			}
		}
		return array( $hits, max( 0, $max - $hits ), $blocked );
	}

	public static function clear( $bucket, $value ) {
		global $wpdb;
		$wpdb->delete( IMS_DB::table( 'rate_limits' ), array( 'bucket' => $bucket, 'ident' => self::ident( $value ) ) );
	}

	/** Active lockouts for the admin screen. */
	public static function active_lockouts() {
		global $wpdb;
		$t = IMS_DB::table( 'rate_limits' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, bucket, hits, locked_until FROM $t WHERE locked_until > %d ORDER BY locked_until DESC", time() ) );
	}

	public static function unlock_by_id( $id ) {
		global $wpdb;
		$wpdb->delete( IMS_DB::table( 'rate_limits' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function format_wait( $seconds ) {
		$minutes = (int) ceil( $seconds / 60 );
		return $minutes <= 1 ? 'a minute' : $minutes . ' minutes';
	}

	/* ===================================================================
	 * Authentication log
	 * ================================================================= */

	public static function log( $event, $user_id = 0, $email = '' ) {
		global $wpdb;
		if ( $user_id && ! $email ) {
			$u     = get_userdata( $user_id );
			$email = $u ? $u->user_email : '';
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$wpdb->insert(
			IMS_DB::table( 'auth_log' ),
			array(
				'user_id'    => (int) $user_id,
				'email'      => substr( sanitize_email( $email ), 0, 191 ),
				'event'      => substr( sanitize_key( $event ), 0, 40 ),
				'ip'         => self::client_ip(),
				'user_agent' => $ua,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	public static function get_log( $limit = 200, $event = '' ) {
		global $wpdb;
		$t     = IMS_DB::table( 'auth_log' );
		$where = $event ? $wpdb->prepare( 'WHERE event = %s', $event ) : '';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t $where ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/* ===================================================================
	 * Remember me + sessions
	 * ================================================================= */

	const REMEMBER_DAYS = 30;

	public static function init() {
		// "Remember me" => 30 days. Otherwise the cookie is a browser-session cookie (WordPress keeps it valid server-side for 2 days max).
		add_filter(
			'auth_cookie_expiration',
			function ( $length, $user_id, $remember ) {
				return $remember ? self::REMEMBER_DAYS * DAY_IN_SECONDS : 2 * DAY_IN_SECONDS;
			},
			10,
			3
		);

		add_action(
			'wp_logout',
			function ( $user_id = 0 ) {
				if ( $user_id ) {
					self::log( 'logout', $user_id );
				}
			}
		);

		// Daily cleanup of expired rate-limit rows and old log entries.
		add_action( 'ims_daily_cleanup', array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( 'ims_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ims_daily_cleanup' );
		}

		if ( get_option( 'ims_roles_version' ) !== self::ROLES_VERSION ) {
			self::install_roles();
		}
	}

	public static function cleanup() {
		global $wpdb;
		$rl = IMS_DB::table( 'rate_limits' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $rl WHERE locked_until < %d AND window_start < %d", time(), time() - DAY_IN_SECONDS ) );
		$log  = IMS_DB::table( 'auth_log' );
		$days = (int) apply_filters( 'ims_auth_log_retention_days', 90 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $log WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'ims_daily_cleanup' );
	}

	/** Whether the current login session was created with "Remember me". */
	public static function current_session_remembered() {
		$token = wp_get_session_token();
		if ( ! $token ) {
			return false;
		}
		$session = WP_Session_Tokens::get_instance( get_current_user_id() )->get( $token );
		return $session && ( (int) $session['expiration'] - (int) $session['login'] ) > 2 * DAY_IN_SECONDS + HOUR_IN_SECONDS;
	}

	/** All sessions for a user, current one first. */
	public static function sessions( $user_id ) {
		$manager  = WP_Session_Tokens::get_instance( $user_id );
		$current  = ims_hash_session_token( wp_get_session_token() );
		$sessions = array();
		foreach ( (array) get_user_meta( $user_id, 'session_tokens', true ) as $verifier => $s ) {
			if ( ! is_array( $s ) || (int) $s['expiration'] < time() ) {
				continue;
			}
			$s['current'] = hash_equals( (string) $verifier, (string) $current );
			$sessions[]   = $s;
		}
		usort(
			$sessions,
			function ( $a, $b ) {
				return $b['current'] <=> $a['current'] ?: $b['login'] <=> $a['login'];
			}
		);
		unset( $manager );
		return $sessions;
	}

	/** Short device description from a user-agent string. */
	public static function describe_ua( $ua ) {
		$ua      = (string) $ua;
		$browser = 'Browser';
		foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox', 'Safari/' => 'Safari', 'curl/' => 'curl' ) as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}
		$os = '';
		foreach ( array( 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Mac OS X' => 'macOS', 'Windows' => 'Windows', 'Linux' => 'Linux' ) as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}
		return $os ? "$browser on $os" : $browser;
	}
}

/** Same hashing WordPress uses for session tokens (WP_User_Meta_Session_Tokens::hash_token). */
function ims_hash_session_token( $token ) {
	if ( ! $token ) {
		return '';
	}
	return function_exists( 'hash' ) ? hash( 'sha256', $token ) : sha1( $token );
}
