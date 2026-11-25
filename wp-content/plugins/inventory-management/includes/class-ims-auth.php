<?php
/**
 * Front-end account pages: login, register, forgot password, OTP verification,
 * change password and profile.
 *
 * Flow:
 *  - Register  -> account created as "unverified" -> 6-digit OTP emailed -> Verify OTP -> logged in.
 *  - Login     -> if account is unverified, a new OTP is emailed and the user is sent to Verify OTP.
 *  - Forgot    -> OTP emailed -> Verify OTP -> set new password -> Login.
 *
 * OTPs are hashed, expire after 10 minutes, allow 5 attempts, and can be resent after 60 seconds.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_Auth {

	const OTP_TTL        = 600;  // Code lifetime (seconds).
	const FLOW_TTL       = 1800; // How long a verification session lasts.
	const OTP_MAX_TRIES  = 5;
	const RESEND_WAIT    = 60;
	const MAX_RESENDS    = 5;
	const FLOW_COOKIE    = 'ims_flow';
	const META_VERIFIED  = 'ims_email_verified';

	/** Page slug => [title, shortcode]. */
	const PAGES = array(
		'login'           => array( 'Login', 'ims_login' ),
		'register'        => array( 'Register', 'ims_register' ),
		'forgot-password' => array( 'Forgot Password', 'ims_forgot_password' ),
		'verify-otp'      => array( 'Verify OTP', 'ims_verify_otp' ),
		'change-password' => array( 'Change Password', 'ims_change_password' ),
		'profile'         => array( 'My Profile', 'ims_profile' ),
	);

	const NOTICES = array(
		'registered'     => 'Account created! We emailed you a 6-digit code to verify your email.',
		'verify_needed'  => 'Please verify your email. We sent a new 6-digit code.',
		'otp_sent'       => 'If an account exists for that email, we sent it a 6-digit code.',
		'otp_resent'     => 'A new code has been sent.',
		'verified'       => 'Email verified. Welcome!',
		'otp_ok'         => 'Code verified. Now choose a new password.',
		'password_reset' => 'Your password has been reset. Please log in.',
		'password_saved' => 'Your password has been changed.',
		'profile_saved'  => 'Your profile has been updated.',
		'logged_out'     => 'You have been logged out.',
		'login_required' => 'Please log in to continue.',
		'sessions_ended' => 'You have been logged out of all other devices.',
	);

	/** True while our own login form is calling wp_signon (so the global hooks don't double-count). */
	private $own_login = false;

	private $errors = array();

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_create_pages' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		foreach ( self::PAGES as $page ) {
			add_shortcode( $page[1], array( $this, 'render_' . substr( $page[1], 4 ) ) );
		}

		add_filter( 'authenticate', array( $this, 'enforce_lockout' ), 98, 2 );
		add_filter( 'authenticate', array( $this, 'block_unverified' ), 99 );
		add_action( 'wp_login_failed', array( $this, 'on_core_login_failed' ) );
		add_action( 'wp_login', array( $this, 'on_core_login' ), 10, 2 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 2 );
		add_filter( 'register_url', function () {
			return $this->url( 'register' );
		} );
		add_filter( 'lostpassword_url', function () {
			return $this->url( 'forgot-password' );
		} );
		add_filter( 'logout_redirect', function ( $redirect_to, $requested ) {
			// Honour an explicit destination (e.g. "Reset it by email"), otherwise go to the login page.
			return $requested ? $redirect_to : add_query_arg( 'ims_notice', 'logged_out', $this->url( 'login' ) );
		}, 10, 2 );
		add_filter( 'wp_nav_menu_items', array( $this, 'menu_items' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/* ===================================================================
	 * Setup
	 * ================================================================= */

	public function maybe_create_pages() {
		$ids     = (array) get_option( 'ims_pages', array() );
		$changed = false;

		foreach ( self::PAGES as $slug => $page ) {
			if ( ! empty( $ids[ $slug ] ) && 'page' === get_post_type( $ids[ $slug ] ) && 'trash' !== get_post_status( $ids[ $slug ] ) ) {
				continue;
			}
			$existing = get_page_by_path( $slug );
			$id       = $existing ? $existing->ID : wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page[0],
					'post_name'    => $slug,
					'post_content' => '<!-- wp:shortcode -->[' . $page[1] . ']<!-- /wp:shortcode -->',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$ids[ $slug ] = $id;
				$changed      = true;
			}
		}
		if ( $changed ) {
			update_option( 'ims_pages', $ids );
		}

		// Apply the full-width Astra layout (once per layout version).
		if ( '2' !== get_option( 'ims_pages_layout' ) ) {
			foreach ( $ids as $id ) {
				self::apply_fullwidth_layout( $id );
			}
			update_option( 'ims_pages_layout', '2' );
		}
	}

	/** Astra page settings: full width, unboxed, no sidebar, no title (our templates have their own). */
	public static function apply_fullwidth_layout( $id ) {
		update_post_meta( $id, 'site-sidebar-layout', 'no-sidebar' );
		update_post_meta( $id, 'ast-site-content-layout', 'full-width-container' );
		update_post_meta( $id, 'site-content-style', 'unboxed' );
		update_post_meta( $id, 'site-sidebar-style', 'unboxed' );
		update_post_meta( $id, 'site-post-title', 'disabled' );
		update_post_meta( $id, 'ast-featured-img', 'disabled' );
	}

	public function assets() {
		if ( $this->current_page() ) {
			wp_enqueue_script( 'ims-auth', IMS_URL . 'assets/ims-auth.js', array(), IMS_VERSION, true );
		}
	}

	public function body_class( $classes ) {
		$page = $this->current_page();
		if ( $page ) {
			$classes[] = 'ims-fullbleed';
			$classes[] = 'ims-page-' . $page;
		}
		return $classes;
	}

	public function url( $slug, $args = array() ) {
		$ids = (array) get_option( 'ims_pages', array() );
		$url = ! empty( $ids[ $slug ] ) ? get_permalink( $ids[ $slug ] ) : home_url( '/' . $slug . '/' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	private function current_page() {
		if ( ! is_page() ) {
			return '';
		}
		$id = get_queried_object_id();
		foreach ( (array) get_option( 'ims_pages', array() ) as $slug => $page_id ) {
			if ( (int) $page_id === $id ) {
				return $slug;
			}
		}
		return '';
	}

	/* ===================================================================
	 * Request handling (runs before any output so we can redirect)
	 * ================================================================= */

	public function handle_request() {
		$page = $this->current_page();
		if ( ! $page ) {
			return;
		}
		nocache_headers();

		// Access rules.
		if ( in_array( $page, array( 'profile', 'change-password' ), true ) && ! is_user_logged_in() ) {
			$this->go( $this->url( 'login', array( 'ims_notice' => 'login_required', 'redirect_to' => rawurlencode( get_permalink() ) ) ) );
		}
		if ( in_array( $page, array( 'login', 'register', 'forgot-password' ), true ) && is_user_logged_in() ) {
			$this->go( $this->url( 'profile' ) );
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['ims_form'] ) ) {
			return;
		}

		$form    = sanitize_key( $_POST['ims_form'] );
		$allowed = array(
			'login'           => array( 'login' ),
			'register'        => array( 'register' ),
			'forgot-password' => array( 'forgot' ),
			'verify-otp'      => array( 'verify_otp', 'resend_otp', 'reset_password' ),
			'change-password' => array( 'change_password' ),
			'profile'         => array( 'profile', 'revoke_sessions' ),
		);
		if ( ! in_array( $form, $allowed[ $page ], true ) ) {
			return;
		}
		if ( ! isset( $_POST['_ims_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_ims_nonce'] ), 'ims_' . $form ) ) {
			$this->errors['_form'] = 'Your session expired. Please try again.';
			return;
		}
		call_user_func( array( $this, 'process_' . $form ) );
	}

	private function go( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	private function post( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/** Raw password field (never sanitized, so special characters survive). */
	private function pass( $key ) {
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** Validate a new password + confirmation. Adds field errors; returns true when valid. */
	private function validate_new_password( $pass, $confirm, $field = 'password', $confirm_field = 'password_confirm' ) {
		if ( '' === $pass ) {
			$this->errors[ $field ] = 'Please enter a password.';
		} elseif ( strlen( $pass ) < 8 ) {
			$this->errors[ $field ] = 'Password must be at least 8 characters long.';
		} elseif ( ! preg_match( '/[A-Za-z]/', $pass ) || ! preg_match( '/\d/', $pass ) ) {
			$this->errors[ $field ] = 'Password must include at least one letter and one number.';
		}
		if ( '' === $confirm ) {
			$this->errors[ $confirm_field ] = 'Please confirm your password.';
		} elseif ( $pass !== $confirm ) {
			$this->errors[ $confirm_field ] = 'Passwords do not match.';
		}
		return ! isset( $this->errors[ $field ] ) && ! isset( $this->errors[ $confirm_field ] );
	}

	/* ---------- Login ---------- */

	private function lockout_message( $seconds ) {
		return sprintf( 'Too many failed login attempts. Please try again in %s or reset your password.', IMS_Security::format_wait( $seconds ) );
	}

	private function process_login() {
		$login = $this->post( 'log' );
		$pass  = $this->pass( 'pwd' );
		if ( '' === $login ) {
			$this->errors['log'] = 'Please enter your email address.';
		} elseif ( ! is_email( $login ) ) {
			$this->errors['log'] = 'Please enter a valid email address, like name@company.com.';
		}
		if ( '' === $pass ) {
			$this->errors['pwd'] = 'Please enter your password.';
		}
		if ( $this->errors ) {
			return;
		}

		// Rate limiting: per IP and per account (database-backed).
		$ip   = IMS_Security::client_ip();
		$wait = max( IMS_Security::blocked_for( 'login_ip', $ip ), IMS_Security::blocked_for( 'login_account', $login ) );
		if ( $wait ) {
			IMS_Security::log( 'login_blocked', 0, $login );
			$this->errors['_form'] = $this->lockout_message( $wait );
			return;
		}

		$remember        = ! empty( $_POST['remember'] );
		$this->own_login = true;
		$user            = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $pass,
				'remember'      => $remember,
			),
			is_ssl()
		);
		$this->own_login = false;

		if ( is_wp_error( $user ) ) {
			if ( 'ims_unverified' === $user->get_error_code() ) {
				$uid = (int) $user->get_error_data();
				IMS_Security::log( 'login_unverified', $uid );
				$this->start_flow( $uid, 'verify' );
				$flow             = $this->get_flow();
				$flow['remember'] = $remember;
				$this->save_flow( $flow );
				$this->go( $this->url( 'verify-otp', array( 'ims_notice' => 'verify_needed' ) ) );
			}
			$known = get_user_by( 'email', $login );
			IMS_Security::hit( 'login_ip', $ip );
			list( , $left, $blocked ) = IMS_Security::hit( 'login_account', $login );
			IMS_Security::log( 'login_failed', $known ? $known->ID : 0, $login );
			if ( $blocked ) {
				IMS_Security::log( 'account_locked', $known ? $known->ID : 0, $login );
				$this->errors['_form'] = $this->lockout_message( $blocked );
				return;
			}
			$this->errors['pwd'] = 'Incorrect email or password. Please try again.';
			if ( $left > 0 && $left <= 2 ) {
				$this->errors['pwd'] .= sprintf( ' %d attempt%s left before login is paused for 15 minutes.', $left, 1 === $left ? '' : 's' );
			}
			return;
		}

		IMS_Security::clear( 'login_account', $login );
		IMS_Security::log( $remember ? 'login_success_remembered' : 'login_success', $user->ID );
		$this->go( $this->after_login_url( $user ) );
	}

	/** Apply the same lockouts to wp-login.php, XML-RPC and any other wp_authenticate() caller. */
	public function enforce_lockout( $user, $username ) {
		if ( $this->own_login || ! $username ) {
			return $user;
		}
		$wait = max( IMS_Security::blocked_for( 'login_ip', IMS_Security::client_ip() ), IMS_Security::blocked_for( 'login_account', $username ) );
		if ( $wait ) {
			return new WP_Error( 'ims_locked', esc_html( $this->lockout_message( $wait ) ) );
		}
		return $user;
	}

	public function on_core_login_failed( $username ) {
		if ( $this->own_login ) {
			return;
		}
		IMS_Security::hit( 'login_ip', IMS_Security::client_ip() );
		IMS_Security::hit( 'login_account', $username );
		IMS_Security::log( 'login_failed', 0, is_email( $username ) ? $username : '' );
	}

	public function on_core_login( $user_login, $user ) {
		if ( $this->own_login ) {
			return;
		}
		IMS_Security::clear( 'login_account', $user->user_email );
		IMS_Security::clear( 'login_account', $user_login );
		IMS_Security::log( 'login_success', $user->ID );
	}

	private function after_login_url( $user ) {
		$default = user_can( $user, IMS_CAP ) ? admin_url( 'admin.php?page=ims' ) : $this->url( 'profile' );
		$to      = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		return $to ? wp_validate_redirect( $to, $default ) : $default;
	}

	/** Stop unverified accounts from logging in anywhere (including wp-login.php). */
	public function block_unverified( $user ) {
		if ( $user instanceof WP_User && '0' === get_user_meta( $user->ID, self::META_VERIFIED, true ) ) {
			return new WP_Error( 'ims_unverified', 'Please verify your email address before logging in.', $user->ID );
		}
		return $user;
	}

	public function filter_login_url( $url, $redirect ) {
		return $this->url( 'login', $redirect ? array( 'redirect_to' => rawurlencode( $redirect ) ) : array() );
	}

	/* ---------- Register ---------- */

	private function process_register() {
		$email    = sanitize_email( $this->post( 'email' ) );
		$first    = $this->post( 'first_name' );
		$last     = $this->post( 'last_name' );
		$pass     = $this->pass( 'password' );

		if ( '' === $this->post( 'email' ) ) {
			$this->errors['email'] = 'Please enter your email address.';
		} elseif ( ! is_email( $email ) ) {
			$this->errors['email'] = 'Please enter a valid email address, like name@company.com.';
		} elseif ( email_exists( $email ) ) {
			$this->errors['email'] = 'An account with this email already exists. Try logging in instead.';
		}
		$this->validate_new_password( $pass, $this->pass( 'password_confirm' ) );
		if ( empty( $_POST['terms'] ) ) {
			$this->errors['terms'] = 'You must accept the terms to create an account.';
		}
		if ( $this->errors ) {
			return;
		}
		$ip   = IMS_Security::client_ip();
		$wait = IMS_Security::blocked_for( 'register_ip', $ip );
		if ( $wait ) {
			$this->errors['_form'] = sprintf( 'Too many accounts have been created from your network. Please try again in %s.', IMS_Security::format_wait( $wait ) );
			return;
		}

		$username = $this->unique_username( $email );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( "$first $last" ) ? trim( "$first $last" ) : $username,
				'role'         => $this->registration_role(),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$this->errors['_form'] = $user_id->get_error_message();
			return;
		}
		update_user_meta( $user_id, self::META_VERIFIED, '0' );
		IMS_Security::hit( 'register_ip', $ip );
		IMS_Security::log( 'register', $user_id );
		$this->start_flow( $user_id, 'verify' );
		$this->go( $this->url( 'verify-otp', array( 'ims_notice' => 'registered' ) ) );
	}

	/**
	 * Role for self-registered users. Never a role that can manage the site or inventory:
	 * an administrator grants inventory access afterwards (Users > Edit > Role).
	 */
	private function registration_role() {
		$role = (string) apply_filters( 'ims_registration_role', 'subscriber' );
		$obj  = get_role( $role );
		$bad  = array( 'manage_options', 'edit_users', 'promote_users', 'ims_manage_stock', 'ims_manage_products', 'ims_manage_suppliers', 'ims_view_security' );
		if ( ! $obj ) {
			return 'subscriber';
		}
		foreach ( $bad as $cap ) {
			if ( $obj->has_cap( $cap ) ) {
				return 'subscriber';
			}
		}
		return $role;
	}

	/** Internal username derived from the email (users never see or type it). */
	private function unique_username( $email ) {
		$base = strtolower( sanitize_user( strstr( $email, '@', true ), true ) );
		$base = substr( preg_replace( '/[^a-z0-9._-]/', '', $base ), 0, 50 );
		if ( strlen( $base ) < 3 ) {
			$base = 'user' . $base;
		}
		$name = $base;
		for ( $i = 2; username_exists( $name ); $i++ ) {
			$name = $base . $i;
		}
		return $name;
	}

	/* ---------- Forgot password ---------- */

	private function process_forgot() {
		$login = $this->post( 'user_login' );
		if ( '' === $login ) {
			$this->errors['user_login'] = 'Please enter your email address.';
			return;
		}
		if ( ! is_email( $login ) ) {
			$this->errors['user_login'] = 'Please enter a valid email address, like name@company.com.';
			return;
		}
		$ip   = IMS_Security::client_ip();
		$wait = IMS_Security::blocked_for( 'forgot_ip', $ip );
		if ( $wait ) {
			$this->errors['_form'] = sprintf( 'Too many reset requests. Please try again in %s.', IMS_Security::format_wait( $wait ) );
			return;
		}
		IMS_Security::hit( 'forgot_ip', $ip );

		$user = get_user_by( 'email', $login );
		if ( $user ) {
			// Per-account throttle: beyond the limit we silently skip the email (the response stays identical).
			if ( IMS_Security::blocked_for( 'forgot_account', $login ) ) {
				$user = false;
			} else {
				IMS_Security::hit( 'forgot_account', $login );
				IMS_Security::log( 'password_reset_requested', $user->ID );
			}
		}

		// Same response whether or not the account exists, so emails can't be discovered.
		$this->start_flow( $user ? $user->ID : 0, 'reset', $login );
		$this->go( $this->url( 'verify-otp', array( 'ims_notice' => 'otp_sent' ) ) );
	}

	/* ===================================================================
	 * OTP flow
	 * ================================================================= */

	private function start_flow( $user_id, $purpose, $email_hint = '' ) {
		$this->clear_flow();
		$token = wp_generate_password( 40, false, false );
		$user  = $user_id ? get_userdata( $user_id ) : false;
		$flow  = array(
			'user'     => $user ? $user->ID : 0,
			'purpose'  => $purpose,
			'email'    => $user ? $user->user_email : $email_hint,
			'hash'     => '',
			'expires'  => 0,
			'attempts' => 0,
			'sent'     => 0,
			'resends'  => 0,
			'verified' => false,
		);
		$this->issue_code( $flow );
		set_transient( 'ims_flow_' . $token, $flow, self::FLOW_TTL );
		setcookie( self::FLOW_COOKIE, $token, time() + self::FLOW_TTL, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::FLOW_COOKIE ] = $token;
	}

	private function flow_token() {
		return isset( $_COOKIE[ self::FLOW_COOKIE ] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_COOKIE[ self::FLOW_COOKIE ] ) ) : '';
	}

	private function get_flow() {
		$token = $this->flow_token();
		return $token ? get_transient( 'ims_flow_' . $token ) : false;
	}

	private function save_flow( $flow ) {
		set_transient( 'ims_flow_' . $this->flow_token(), $flow, self::FLOW_TTL );
	}

	private function clear_flow() {
		$token = $this->flow_token();
		if ( $token ) {
			delete_transient( 'ims_flow_' . $token );
		}
		setcookie( self::FLOW_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		unset( $_COOKIE[ self::FLOW_COOKIE ] );
	}

	/** Generate a new code, store its hash in $flow and email it. */
	private function issue_code( &$flow ) {
		$code             = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$flow['hash']     = wp_hash_password( $code );
		$flow['expires']  = time() + self::OTP_TTL;
		$flow['attempts'] = 0;
		$flow['sent']     = time();

		if ( $flow['user'] ) {
			$this->send_code_email( get_userdata( $flow['user'] ), $code, $flow['purpose'] );
		}
	}

	private function send_code_email( $user, $code, $purpose ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$action  = 'reset' === $purpose ? 'reset your password' : 'verify your email address';
		$subject = sprintf( '[%s] Your verification code: %s', $site, $code );
		$minutes = self::OTP_TTL / 60;
		$body    = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:24px;color:#1e293b">'
			. '<h2 style="margin:0 0 12px">' . esc_html( $site ) . '</h2>'
			. '<p>Hi ' . esc_html( $user->display_name ) . ',</p>'
			. '<p>Use this code to ' . esc_html( $action ) . ':</p>'
			. '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f1f5f9;padding:16px;text-align:center;border-radius:8px">' . esc_html( $code ) . '</p>'
			. '<p>This code expires in ' . (int) $minutes . ' minutes. If you did not request it, you can ignore this email.</p>'
			. '</div>';
		wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	private function process_verify_otp() {
		$flow = $this->get_flow();
		if ( ! $flow ) {
			$this->errors['_form'] = 'Your verification session has expired. Please start again.';
			return;
		}
		$ip   = IMS_Security::client_ip();
		$wait = IMS_Security::blocked_for( 'otp_ip', $ip );
		if ( $wait ) {
			$this->errors['_form'] = sprintf( 'Too many verification attempts. Please try again in %s.', IMS_Security::format_wait( $wait ) );
			return;
		}
		$raw  = isset( $_POST['otp_d'] ) && is_array( $_POST['otp_d'] ) ? implode( '', array_map( 'sanitize_text_field', wp_unslash( $_POST['otp_d'] ) ) ) : $this->post( 'otp' );
		$code = preg_replace( '/\D/', '', $raw );

		if ( $flow['attempts'] >= self::OTP_MAX_TRIES ) {
			$this->errors['otp'] = 'Too many incorrect attempts. Please request a new code.';
			return;
		}
		if ( time() > $flow['expires'] ) {
			$this->errors['otp'] = 'This code has expired. Please request a new one.';
			return;
		}
		if ( '' === $code ) {
			$this->errors['otp'] = 'Please enter the 6-digit code from your email.';
			return;
		}
		if ( 6 !== strlen( $code ) ) {
			$this->errors['otp'] = 'The code must be exactly 6 digits.';
			return;
		}
		if ( ! $flow['user'] || ! wp_check_password( $code, $flow['hash'] ) ) {
			IMS_Security::hit( 'otp_ip', $ip );
			IMS_Security::log( 'otp_failed', (int) $flow['user'] );
			$flow['attempts']++;
			$this->save_flow( $flow );
			$left           = self::OTP_MAX_TRIES - $flow['attempts'];
			$this->errors['otp'] = $left > 0 ? sprintf( 'Incorrect code. %d attempt%s left.', $left, 1 === $left ? '' : 's' ) : 'Too many incorrect attempts. Please request a new code.';
			return;
		}

		if ( 'verify' === $flow['purpose'] ) {
			update_user_meta( $flow['user'], self::META_VERIFIED, '1' );
			IMS_Security::log( 'email_verified', $flow['user'] );
			$this->clear_flow();
			wp_set_current_user( $flow['user'] );
			wp_set_auth_cookie( $flow['user'], ! empty( $flow['remember'] ), is_ssl() );
			$this->go( $this->url( 'profile', array( 'ims_notice' => 'verified' ) ) );
		}

		// Password reset: allow setting a new password for the next 10 minutes.
		IMS_Security::log( 'reset_code_verified', $flow['user'] );
		$flow['verified'] = true;
		$flow['expires']  = time() + self::OTP_TTL;
		$flow['hash']     = '';
		$this->save_flow( $flow );
		$this->go( $this->url( 'verify-otp', array( 'ims_notice' => 'otp_ok' ) ) );
	}

	private function process_resend_otp() {
		$flow = $this->get_flow();
		if ( ! $flow || $flow['verified'] ) {
			$this->errors['_form'] = 'Your verification session has expired. Please start again.';
			return;
		}
		$wait = $flow['sent'] + self::RESEND_WAIT - time();
		if ( $wait > 0 ) {
			$this->errors['_form'] = sprintf( 'Please wait %d seconds before requesting another code.', $wait );
			return;
		}
		if ( $flow['resends'] >= self::MAX_RESENDS ) {
			$this->errors['_form'] = 'Too many codes requested. Please start again later.';
			return;
		}
		$flow['resends']++;
		$this->issue_code( $flow );
		$this->save_flow( $flow );
		$this->go( $this->url( 'verify-otp', array( 'ims_notice' => 'otp_resent' ) ) );
	}

	private function process_reset_password() {
		$flow = $this->get_flow();
		if ( ! $flow || 'reset' !== $flow['purpose'] || ! $flow['verified'] || time() > $flow['expires'] ) {
			$this->errors['_form'] = 'Your reset session has expired. Please start again.';
			return;
		}
		$pass     = $this->pass( 'password' );
		if ( ! $this->validate_new_password( $pass, $this->pass( 'password_confirm' ) ) ) {
			return;
		}
		$user = get_userdata( $flow['user'] );
		reset_password( $user, $pass ); // Also logs out all other sessions.
		update_user_meta( $user->ID, self::META_VERIFIED, '1' ); // They proved they own the email.
		IMS_Security::clear( 'login_account', $user->user_email );
		IMS_Security::log( 'password_reset', $user->ID );
		$this->clear_flow();
		$this->go( $this->url( 'login', array( 'ims_notice' => 'password_reset' ) ) );
	}

	/* ---------- Change password ---------- */

	private function process_change_password() {
		$user    = wp_get_current_user();
		$current = $this->pass( 'current_password' );
		$pass    = $this->pass( 'password' );

		$wait = IMS_Security::blocked_for( 'reauth', $user->ID );
		if ( $wait ) {
			$this->errors['_form'] = sprintf( 'Too many incorrect password attempts. Please try again in %s.', IMS_Security::format_wait( $wait ) );
			return;
		}
		if ( '' === $current ) {
			$this->errors['current_password'] = 'Please enter your current password.';
		} elseif ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			IMS_Security::hit( 'reauth', $user->ID );
			IMS_Security::log( 'reauth_failed', $user->ID );
			$this->errors['current_password'] = 'Your current password is incorrect.';
		}
		$this->validate_new_password( $pass, $this->pass( 'password_confirm' ) );
		if ( ! isset( $this->errors['password'] ) && '' !== $current && $pass === $current ) {
			$this->errors['password'] = 'Your new password must be different from your current password.';
		}
		if ( $this->errors ) {
			return;
		}

		$remembered = IMS_Security::current_session_remembered();
		wp_set_password( $pass, $user->ID );                     // Ends every session (all devices)...
		wp_set_auth_cookie( $user->ID, $remembered, is_ssl() ); // ...then signs this device back in, keeping "Remember me".
		IMS_Security::clear( 'reauth', $user->ID );
		IMS_Security::log( 'password_changed', $user->ID );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail( $user->user_email, "[$site] Your password was changed", "Hi {$user->display_name},\n\nThe password for your account on $site was just changed. If this wasn't you, reset your password immediately: " . $this->url( 'forgot-password' ) );

		$this->go( $this->url( 'profile', array( 'ims_notice' => 'password_saved' ) ) );
	}

	/* ---------- Profile ---------- */

	private function process_profile() {
		$user  = wp_get_current_user();
		$email = sanitize_email( $this->post( 'email' ) );
		$phone = $this->post( 'phone' );
		if ( '' !== $phone && ! preg_match( '/^\+?[0-9\s\-().]{7,20}$/', $phone ) ) {
			$this->errors['phone'] = 'Please enter a valid phone number (digits, spaces, + - ( ) only).';
		}
		$data  = array(
			'ID'           => $user->ID,
			'first_name'   => $this->post( 'first_name' ),
			'last_name'    => $this->post( 'last_name' ),
			'display_name' => $this->post( 'display_name' ) ? $this->post( 'display_name' ) : $user->user_login,
		);

		if ( '' === $this->post( 'email' ) ) {
			$this->errors['email'] = 'Please enter your email address.';
		} elseif ( ! is_email( $email ) ) {
			$this->errors['email'] = 'Please enter a valid email address, like name@company.com.';
		} elseif ( strtolower( $email ) !== strtolower( $user->user_email ) ) {
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $user->ID ) {
				$this->errors['email'] = 'This email is already used by another account.';
			} elseif ( IMS_Security::blocked_for( 'reauth', $user->ID ) ) {
				$this->errors['_form'] = 'Too many incorrect password attempts. Please try again later.';
			} elseif ( ! wp_check_password( $this->pass( 'current_password' ), $user->user_pass, $user->ID ) ) {
				if ( '' !== $this->pass( 'current_password' ) ) {
					IMS_Security::hit( 'reauth', $user->ID );
					IMS_Security::log( 'reauth_failed', $user->ID );
				}
				$this->errors['current_password'] = 'Enter your current password to confirm the email change.';
			} else {
				$data['user_email'] = $email;
			}
		}
		if ( $this->errors ) {
			return;
		}

		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			$this->errors['_form'] = $result->get_error_message();
			return;
		}
		if ( isset( $data['user_email'] ) ) {
			IMS_Security::log( 'email_changed', $user->ID, $data['user_email'] );
		}
		IMS_Security::log( 'profile_updated', $user->ID );
		update_user_meta( $user->ID, 'ims_phone', $this->post( 'phone' ) );
		update_user_meta( $user->ID, 'ims_job_title', $this->post( 'job_title' ) );
		$this->go( $this->url( 'profile', array( 'ims_notice' => 'profile_saved' ) ) );
	}


	private function process_revoke_sessions() {
		$uid = get_current_user_id();
		WP_Session_Tokens::get_instance( $uid )->destroy_others( wp_get_session_token() );
		IMS_Security::log( 'sessions_revoked', $uid );
		$this->go( $this->url( 'profile', array( 'ims_notice' => 'sessions_ended' ) ) );
	}

	/* ===================================================================
	 * Rendering
	 * ================================================================= */

	private function icon( $name, $size = 18 ) {
		return IMS_Icons::get( $name, $size );
	}

	/**
	 * Centred single-card layout for the sign-in pages.
	 */
	private function open_split( $brand, $icon, $title, $subtitle = '' ) {
		ob_start();
		echo '<div class="ims-shell"><div class="ims-split ims-split-' . esc_attr( $brand ) . '">';
		echo '<section class="ims-form-panel"><div class="ims-form-wrap">';
		echo '<h1 class="ims-title">' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p class="ims-subtitle">' . wp_kses( $subtitle, array( 'strong' => array(), 'a' => array( 'href' => array(), 'class' => array() ) ) ) . '</p>';
		}
		$this->messages();
	}

	private function close_split() {
		echo '</div></section></div></div>';
		return ob_get_clean();
	}

	/**
	 * Account layout: settings navigation (side menu on desktop, tabs on smaller screens) + content.
	 */
	private function open_account( $active, $title, $subtitle ) {
		$nav = array(
			array( 'profile', $this->url( 'profile' ), 'user', 'Profile' ),
			array( 'change-password', $this->url( 'change-password' ), 'key', 'Password' ),
		);
		$inventory = get_page_by_path( 'inventory' );
		if ( $inventory && current_user_can( 'ims_view_inventory' ) ) {
			$nav[] = array( 'inventory', get_permalink( $inventory ), 'list', 'Stock list' );
		}
		if ( current_user_can( 'ims_view_inventory' ) ) {
			$nav[] = array( 'dashboard', admin_url( 'admin.php?page=ims' ), 'grid', 'Dashboard' );
		}

		ob_start();
		echo '<div class="ims-shell ims-shell-account"><div class="ims-account">';
		echo '<aside class="ims-acc-nav"><p class="ims-acc-nav-label">Account settings</p><nav class="ims-acc-links" aria-label="Account">';
		foreach ( $nav as $item ) {
			$current = $item[0] === $active;
			printf(
				'<a href="%s"%s>%s<span>%s</span></a>',
				esc_url( $item[1] ),
				$current ? ' class="is-active" aria-current="page"' : '',
				$this->icon( $item[2], 16 ),
				esc_html( $item[3] )
			);
		}
		echo '<a href="' . esc_url( wp_logout_url() ) . '" class="ims-acc-logout">' . $this->icon( 'logout', 16 ) . '<span>Log out</span></a>';
		echo '</nav></aside>';
		echo '<section class="ims-account-main"><header class="ims-acc-head"><h1>' . esc_html( $title ) . '</h1><p>' . wp_kses( $subtitle, array() ) . '</p></header>';
		$this->messages();
	}

	private function close_account() {
		echo '</section></div></div>';
		return ob_get_clean();
	}

	private function messages() {
		$key = isset( $_GET['ims_notice'] ) ? sanitize_key( $_GET['ims_notice'] ) : '';
		if ( ! $this->errors && isset( self::NOTICES[ $key ] ) ) {
			echo '<div class="ims-alert ims-alert-success" role="status">' . $this->icon( 'check' ) . '<div>' . esc_html( self::NOTICES[ $key ] ) . '</div></div>';
		}
		$form_error = $this->errors['_form'] ?? ''; // Field errors are shown under their own fields only.
		if ( $form_error ) {
			echo '<div class="ims-alert ims-alert-error" role="alert"><svg class="ims-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg><div>';
			echo '<p>' . esc_html( $form_error ) . '</p>';
			echo '</div></div>';
		}
	}

	private function form_start( $form, $class = '' ) {
		echo '<form method="post" class="ims-form ' . esc_attr( $class ) . '" novalidate>';
		echo '<input type="hidden" name="ims_form" value="' . esc_attr( $form ) . '">';
		wp_nonce_field( 'ims_' . $form, '_ims_nonce' );
	}

	private function submit( $text, $icon = '' ) {
		echo '<button type="submit" class="ims-btn"><span class="ims-btn-label">' . esc_html( $text ) . '</span><span class="ims-spinner" aria-hidden="true"></span></button>';
	}

	/** Error message slot under a field. Always rendered (hidden when empty) so the browser-side validator can reuse it. */
	private function field_error( $name ) {
		$msg = isset( $this->errors[ $name ] ) ? $this->errors[ $name ] : '';
		return sprintf(
			'<p class="ims-field-error" id="ims-%1$s-error" aria-live="polite"%2$s>%3$s<span>%4$s</span></p>',
			esc_attr( $name ),
			$msg ? '' : ' hidden',
			IMS_Icons::get( 'alert', 14 ),
			esc_html( $msg )
		);
	}

	/** Wrapper class + ARIA attributes for a field in error. */
	private function error_state( $name ) {
		$has = isset( $this->errors[ $name ] );
		return array(
			$has ? ' has-error' : '',
			' aria-describedby="ims-' . esc_attr( $name ) . '-error"' . ( $has ? ' aria-invalid="true"' : '' ),
		);
	}

	/**
	 * Text input with a leading icon and its own error message.
	 *
	 * @param string $extra Static attribute string defined in this file only (validation data-* attributes etc.).
	 */
	private function field( $name, $label, $type, $value, $icon, $extra = '', $aside = '' ) {
		list( $cls, $aria ) = $this->error_state( $name );
		printf(
			'<div class="ims-field%8$s"><div class="ims-label-row"><label for="ims-%1$s">%2$s</label>%7$s</div><div class="ims-input">%6$s<input id="ims-%1$s" name="%1$s" type="%3$s" value="%4$s" %5$s%9$s></div>%10$s</div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$extra,
			$this->icon( $icon ),
			$aside,
			$cls,
			$aria,
			$this->field_error( $name )
		);
	}

	private function password_field( $name, $label, $autocomplete = 'current-password', $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'hint'     => '',
				'strength' => false,
				'match'    => '',
				'aside'    => '',
				'required' => 'Please enter your password.', // Message, or false when optional.
				'extra'    => '',
			)
		);
		list( $cls, $aria ) = $this->error_state( $name );

		$attrs  = 'autocomplete="' . esc_attr( $autocomplete ) . '"';
		$attrs .= $opts['required'] ? ' required data-required="' . esc_attr( $opts['required'] ) . '"' : '';
		$attrs .= $opts['strength'] ? ' data-strength' : '';
		$attrs .= $opts['match'] ? ' data-match="ims-' . esc_attr( $opts['match'] ) . '"' : '';
		$attrs .= $opts['extra'] ? ' ' . $opts['extra'] : '';
		$attrs .= $aria;

		printf(
			'<div class="ims-field%8$s"><div class="ims-label-row"><label for="ims-%1$s">%2$s</label>%3$s</div><div class="ims-input ims-input-pw">%4$s<input id="ims-%1$s" name="%1$s" type="password" %5$s><button type="button" class="ims-pw-toggle" aria-label="Show password" aria-controls="ims-%1$s">%6$s%7$s</button></div>',
			esc_attr( $name ),
			esc_html( $label ),
			$opts['aside'],
			$this->icon( 'lock' ),
			$attrs,
			IMS_Icons::get( 'eye', 18, 'ims-i-show' ),
			IMS_Icons::get( 'eye-off', 18, 'ims-i-hide' ),
			$cls
		);
		if ( $opts['strength'] ) {
			echo '<div class="ims-strength" aria-live="polite"><div class="ims-strength-bar"><span></span><span></span><span></span><span></span></div><small class="ims-strength-text">' . esc_html( $opts['hint'] ) . '</small></div>';
		} elseif ( $opts['match'] ) {
			echo '<small class="ims-hint ims-match-text" aria-live="polite"></small>';
		} elseif ( $opts['hint'] ) {
			echo '<small class="ims-hint">' . esc_html( $opts['hint'] ) . '</small>';
		}
		echo $this->field_error( $name ); // phpcs:ignore -- escaped inside.
		echo '</div>';
	}

	/** Checkbox with custom styling and its own error message. */
	private function checkbox( $name, $label_html, $required_msg = '' ) {
		list( $cls, $aria ) = $this->error_state( $name );
		printf(
			'<div class="ims-field ims-field-check%1$s"><label class="ims-check"><input type="checkbox" id="ims-%2$s" name="%2$s" value="1" %3$s%4$s%5$s><span class="ims-checkbox"></span><span>%6$s</span></label>%7$s</div>',
			$cls,
			esc_attr( $name ),
			checked( ! empty( $_POST[ $name ] ), true, false ),
			$required_msg ? ' data-required="' . esc_attr( $required_msg ) . '"' : '',
			$aria,
			$label_html, // Static markup from this file.
			$this->field_error( $name )
		);
	}

	private function old( $key ) {
		return $this->post( $key );
	}

	private function link( $slug, $text, $class = '' ) {
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $this->url( $slug ) ) . '">' . esc_html( $text ) . '</a>';
	}

	private function divider_foot( $html ) {
		echo '<p class="ims-foot">' . $html . '</p>'; // Built from link() only.
	}

	/* ---------- Login ---------- */

	public function render_login() {
		$this->open_split( 'login', 'user', 'Log in to your account', 'Don&rsquo;t have an account? ' . $this->link( 'register', 'Create one' ) );
		$this->form_start( 'login' );
		if ( ! empty( $_GET['redirect_to'] ) ) {
			echo '<input type="hidden" name="redirect_to" value="' . esc_attr( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) ) . '">';
		}
		$this->field( 'log', 'Email address', 'email', $this->old( 'log' ), 'mail', 'autocomplete="email" placeholder="you@company.com" required autofocus data-required="Please enter your email address." data-email="Please enter a valid email address, like name@company.com."' );
		$this->password_field( 'pwd', 'Password', 'current-password', array( 'aside' => $this->link( 'forgot-password', 'Forgot password?', 'ims-label-link' ) ) );
		$this->checkbox( 'remember', 'Remember me' );
		$this->submit( 'Log in' );
		echo '</form>';
		return $this->close_split();
	}

	/* ---------- Register ---------- */

	public function render_register() {
		$this->open_split( 'register', 'user', 'Create your account', 'Already have an account? ' . $this->link( 'login', 'Log in' ) );
		$this->form_start( 'register' );
		$optional = '<span class="ims-optional">Optional</span>';
		echo '<div class="ims-2col">';
		$this->field( 'first_name', 'First name', 'text', $this->old( 'first_name' ), 'user', 'autocomplete="given-name"', $optional );
		$this->field( 'last_name', 'Last name', 'text', $this->old( 'last_name' ), 'user', 'autocomplete="family-name"', $optional );
		echo '</div>';
		$this->field( 'email', 'Email address', 'email', $this->old( 'email' ), 'mail', 'autocomplete="email" placeholder="name@company.com" required data-required="Please enter your email address." data-email="Please enter a valid email address, like name@company.com."' );
		$this->password_field( 'password', 'Password', 'new-password', array( 'strength' => true, 'hint' => 'At least 8 characters, including a letter and a number.', 'required' => 'Please create a password.' ) );
		$this->password_field( 'password_confirm', 'Confirm password', 'new-password', array( 'match' => 'password', 'required' => 'Please confirm your password.' ) );
		$this->checkbox( 'terms', 'I agree to the Terms of Service and Privacy Policy.', 'You must accept the terms to create an account.' );
		$this->submit( 'Create account' );
		echo '</form>';
		return $this->close_split();
	}

	/* ---------- Forgot password ---------- */

	public function render_forgot_password() {
		$this->open_split( 'security', 'key', 'Forgot your password?', 'No worries. Enter your email address and we&rsquo;ll send you a 6-digit reset code.' );
		$this->form_start( 'forgot' );
		$this->field( 'user_login', 'Email address', 'email', $this->old( 'user_login' ), 'mail', 'autocomplete="email" placeholder="you@company.com" required autofocus data-required="Please enter your email address." data-email="Please enter a valid email address, like name@company.com."' );
		$this->submit( 'Send reset code', 'mail' );
		echo '</form>';
		$this->divider_foot( '<a href="' . esc_url( $this->url( 'login' ) ) . '" class="ims-back">' . $this->icon( 'back', 16 ) . 'Back to log in</a>' );
		return $this->close_split();
	}

	/* ---------- Verify OTP (+ set new password after reset) ---------- */

	private function mask_email( $email ) {
		if ( ! is_email( $email ) ) {
			return 'your email';
		}
		list( $name, $domain ) = explode( '@', $email, 2 );
		return substr( $name, 0, 1 ) . str_repeat( '•', max( 2, min( 6, strlen( $name ) - 1 ) ) ) . '@' . $domain;
	}

	public function render_verify_otp() {
		$flow = $this->get_flow();

		if ( ! $flow ) {
			$this->open_split( 'security', 'clock', 'Session expired', 'There is no verification in progress, or it has expired. Please start again.' );
			echo '<div class="ims-btn-group"><a class="ims-btn" href="' . esc_url( $this->url( 'login' ) ) . '">Go to log in</a><a class="ims-btn ims-btn-ghost" href="' . esc_url( $this->url( 'forgot-password' ) ) . '">Reset password</a></div>';
			return $this->close_split();
		}

		if ( 'reset' === $flow['purpose'] && $flow['verified'] ) {
			$this->open_split( 'security', 'lock', 'Set a new password', 'Your new password must be different from previously used passwords.' );
			$this->form_start( 'reset_password' );
			$this->password_field( 'password', 'New password', 'new-password', array( 'strength' => true, 'hint' => 'Use 8+ characters with a letter and a number.', 'required' => 'Please enter a new password.' ) );
			$this->password_field( 'password_confirm', 'Confirm new password', 'new-password', array( 'match' => 'password', 'required' => 'Please confirm your password.' ) );
			$this->submit( 'Reset password', 'check' );
			echo '</form>';
			return $this->close_split();
		}

		$title = 'reset' === $flow['purpose'] ? 'Check your email' : 'Verify your email';
		$this->open_split( 'security', 'mail', $title, sprintf( 'We sent a 6-digit code to <strong>%s</strong>. Enter it below to continue.', esc_html( $this->mask_email( $flow['email'] ) ) ) );
		$this->form_start( 'verify_otp', 'ims-otp-form' );
		list( $otp_cls, $otp_aria ) = $this->error_state( 'otp' );
		echo '<div class="ims-field ims-field-otp' . esc_attr( $otp_cls ) . '"><fieldset class="ims-otp" id="ims-otp" data-otp data-required="Please enter the 6-digit code from your email." data-incomplete="Please enter all 6 digits of the code."' . $otp_aria . '><legend class="screen-reader-text">Verification code</legend>';
		for ( $i = 1; $i <= 6; $i++ ) {
			printf( '<input type="text" name="otp_d[]" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="%s" aria-label="Digit %d" %s>', 1 === $i ? 'one-time-code' : 'off', (int) $i, 1 === $i ? 'autofocus' : '' );
		}
		echo '</fieldset>' . $this->field_error( 'otp' ) . '</div>'; // phpcs:ignore -- escaped inside.
		echo '<p class="ims-otp-meta">' . $this->icon( 'clock', 14 ) . 'Code expires in ' . (int) ( self::OTP_TTL / 60 ) . ' minutes</p>';
		$this->submit( 'Verify code', 'shield' );
		echo '</form>';

		$wait = max( 0, $flow['sent'] + self::RESEND_WAIT - time() );
		$this->form_start( 'resend_otp', 'ims-resend' );
		echo '<p class="ims-foot">Didn&rsquo;t receive the email? <button type="submit" class="ims-link-btn" data-wait="' . (int) $wait . '">Resend code</button></p></form>';
		$this->divider_foot( '<a href="' . esc_url( $this->url( 'login' ) ) . '" class="ims-back">' . $this->icon( 'back', 16 ) . 'Back to log in</a>' );
		return $this->close_split();
	}

	/* ---------- Change password ---------- */

	public function render_change_password() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$this->open_account( 'change-password', 'Password', 'Change the password you use to log in.' );
		$this->form_start( 'change_password', 'ims-acc-panel' );
		echo '<div class="ims-set"><div class="ims-set-info"><h2>Change password</h2><p>After you change it, every other device signed in to your account is logged out. This device stays signed in, and we email you a confirmation.</p>';
		echo '<ul class="ims-set-tips"><li>At least 8 characters</li><li>Include a letter and a number</li><li>Don&rsquo;t reuse a password from another site</li></ul></div>';
		echo '<div class="ims-set-fields">';
		$this->password_field( 'current_password', 'Current password', 'current-password', array( 'required' => 'Please enter your current password.' ) );
		$this->password_field( 'password', 'New password', 'new-password', array( 'strength' => true, 'hint' => 'Use 8+ characters with a letter and a number.', 'required' => 'Please enter a new password.' ) );
		$this->password_field( 'password_confirm', 'Confirm new password', 'new-password', array( 'match' => 'password', 'required' => 'Please confirm your password.' ) );
		echo '</div></div>';
		echo '<div class="ims-acc-foot"><span>Forgot your current password? <a href="' . esc_url( wp_logout_url( $this->url( 'forgot-password' ) ) ) . '">Reset it by email</a></span><div class="ims-acc-actions">';
		echo '<a class="ims-btn ims-btn-ghost" href="' . esc_url( $this->url( 'profile' ) ) . '">Cancel</a>';
		$this->submit( 'Update password' );
		echo '</div></div></form>';
		return $this->close_account();
	}

	/* ---------- Profile ---------- */

	public function render_profile() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$u        = wp_get_current_user();
		$roles    = array_map( 'translate_user_role', array_intersect_key( wp_roles()->get_names(), array_flip( $u->roles ) ) );
		$verified = '0' !== get_user_meta( $u->ID, self::META_VERIFIED, true );
		$job      = (string) get_user_meta( $u->ID, 'ims_job_title', true );
		$v        = function ( $key, $current ) {
			return isset( $_POST[ $key ] ) ? $this->post( $key ) : $current;
		};

		$this->open_account( 'profile', 'Profile', 'Manage your personal details and how you sign in.' );

		if ( ! current_user_can( 'ims_view_inventory' ) ) {
			echo '<div class="ims-alert ims-alert-info" role="status">' . $this->icon( 'lock' ) . '<div><p><strong>No inventory access yet.</strong> Your account is active, but an administrator needs to give you an inventory role (Viewer, Staff or Manager) before you can see stock.</p></div></div>';
		}

		// Account summary.
		$parts    = preg_split( '/\s+/', trim( $u->display_name ) );
		$initials = strtoupper( mb_substr( $parts[0] ?? '', 0, 1 ) . ( count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '' ) );
		echo '<div class="ims-acc-summary"><span class="ims-acc-avatar" aria-hidden="true">' . esc_html( $initials ? $initials : '?' ) . '</span>';
		echo '<div class="ims-acc-id"><h2>' . esc_html( $u->display_name ) . '</h2><p><span class="ims-acc-email">' . esc_html( $u->user_email ) . '</span>';
		echo $verified ? '<span class="ims-tag ims-tag-ok">Verified</span>' : '<span class="ims-tag ims-tag-warn">Not verified</span>';
		echo '</p></div><dl class="ims-acc-meta">';
		echo '<div><dt>Role</dt><dd>' . esc_html( $roles ? implode( ', ', $roles ) : '—' ) . '</dd></div>';
		echo '<div><dt>Job title</dt><dd>' . esc_html( $job ? $job : '—' ) . '</dd></div>';
		echo '<div><dt>Member since</dt><dd>' . esc_html( date_i18n( 'M j, Y', strtotime( $u->user_registered ) ) ) . '</dd></div>';
		echo '</dl></div>';

		// Details form.
		$this->form_start( 'profile', 'ims-acc-panel' );

		echo '<div class="ims-set"><div class="ims-set-info"><h2>Personal information</h2><p>Your display name is shown to your team and next to the stock movements you record.</p></div><div class="ims-set-fields">';
		echo '<div class="ims-2col">';
		$this->field( 'first_name', 'First name', 'text', $v( 'first_name', $u->first_name ), 'user', 'autocomplete="given-name"' );
		$this->field( 'last_name', 'Last name', 'text', $v( 'last_name', $u->last_name ), 'user', 'autocomplete="family-name"' );
		echo '</div><div class="ims-2col">';
		$this->field( 'display_name', 'Display name', 'text', $v( 'display_name', $u->display_name ), 'user' );
		$this->field( 'job_title', 'Job title', 'text', $v( 'job_title', $job ), 'briefcase', 'placeholder="e.g. Warehouse Manager"' );
		echo '</div></div></div>';

		echo '<div class="ims-set"><div class="ims-set-info"><h2>Contact details</h2><p>Verification codes and security alerts are sent to your email. Changing it requires your current password.</p></div><div class="ims-set-fields">';
		echo '<div class="ims-2col">';
		$this->field( 'email', 'Email address', 'email', $v( 'email', $u->user_email ), 'mail', 'autocomplete="email" required data-required="Please enter your email address." data-email="Please enter a valid email address, like name@company.com." data-original="' . esc_attr( $u->user_email ) . '"' );
		$this->field( 'phone', 'Phone number', 'tel', $v( 'phone', get_user_meta( $u->ID, 'ims_phone', true ) ), 'phone', 'autocomplete="tel" placeholder="+1 202 555 0143" data-phone="Please enter a valid phone number (digits, spaces, + - ( ) only)."', '<span class="ims-optional">Optional</span>' );
		echo '</div>';
		echo '<div class="ims-confirm-pw">';
		$this->password_field( 'current_password', 'Current password', 'current-password', array( 'required' => false, 'hint' => 'Only needed if you change your email address.', 'extra' => 'data-required-if-changed="ims-email" data-required-msg="Enter your current password to confirm the email change."' ) );
		echo '</div></div></div>';

		echo '<div class="ims-acc-foot"><span>Changes apply as soon as you save.</span><div class="ims-acc-actions">';
		$this->submit( 'Save changes' );
		echo '</div></div></form>';

		$this->render_sessions_card( $u->ID );

		return $this->close_account();
	}

	/** "Signed-in devices" panel with a sign-out-everywhere-else action. */
	private function render_sessions_card( $user_id ) {
		$sessions = IMS_Security::sessions( $user_id );
		echo '<div class="ims-acc-panel"><div class="ims-set"><div class="ims-set-info"><h2>Signed-in devices</h2><p>Devices that are signed in to your account right now. &ldquo;Remember me&rdquo; keeps a device signed in for ' . (int) IMS_Security::REMEMBER_DAYS . ' days. If you don&rsquo;t recognise a device, log it out and change your password.</p></div><div class="ims-set-fields">';
		echo '<ul class="ims-devices">';
		foreach ( $sessions as $sn ) {
			$remembered = ( (int) $sn['expiration'] - (int) $sn['login'] ) > 2 * DAY_IN_SECONDS + HOUR_IN_SECONDS;
			printf(
				'<li><div class="ims-device-main"><strong>%s</strong>%s</div><p class="ims-device-meta">%s &middot; Signed in %s ago &middot; %s</p></li>',
				esc_html( IMS_Security::describe_ua( $sn['ua'] ?? '' ) ),
				! empty( $sn['current'] ) ? '<span class="ims-tag ims-tag-ok">This device</span>' : '',
				esc_html( $sn['ip'] ?? '' ),
				esc_html( human_time_diff( (int) $sn['login'] ) ),
				$remembered ? esc_html( 'Remembered until ' . wp_date( 'M j, Y', (int) $sn['expiration'] ) ) : 'Ends when the browser closes'
			);
		}
		echo '</ul>';
		if ( count( $sessions ) > 1 ) {
			$this->form_start( 'revoke_sessions', 'ims-devices-form' );
			echo '<button type="submit" class="ims-btn ims-btn-ghost ims-btn-auto">Log out of all other devices</button></form>';
		} else {
			echo '<p class="ims-devices-empty">This is the only device signed in.</p>';
		}
		echo '</div></div></div>';
	}

	/* ---------- Header menu links ---------- */

	public function menu_items( $items, $args ) {
		if ( ! in_array( $args->theme_location, array( 'primary', 'mobile_menu' ), true ) ) {
			return $items;
		}
		$li = function ( $url, $text, $cls = '' ) {
			return '<li class="menu-item ims-menu-item ' . esc_attr( $cls ) . '"><a class="menu-link" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></li>';
		};
		if ( is_user_logged_in() ) {
			return $items . $li( $this->url( 'profile' ), 'My account', 'ims-menu-cta' );
		}
		return $items . $li( $this->url( 'login' ), 'Log in' ) . $li( $this->url( 'register' ), 'Get started', 'ims-menu-cta' );
	}
}
