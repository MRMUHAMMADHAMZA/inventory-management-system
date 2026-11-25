<?php
/**
 * Sends all WordPress email (OTP codes, password notices, core emails) through the Resend API.
 *
 * Configure in wp-config.php (never in the plugin, so the key isn't shipped with the code):
 *   define( 'IMS_RESEND_API_KEY', 're_...' );
 *   define( 'IMS_MAIL_FROM', 'info@yourdomain.com' );      // Must be on a domain verified in Resend.
 *   define( 'IMS_MAIL_FROM_NAME', 'Your Company' );          // Optional; defaults to the site name.
 *
 * Without IMS_RESEND_API_KEY, WordPress sends email the normal way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMS_Mailer {

	const ENDPOINT = 'https://api.resend.com/emails';

	public static function init() {
		if ( self::enabled() ) {
			add_filter( 'pre_wp_mail', array( __CLASS__, 'send' ), 10, 2 );
		}
	}

	public static function enabled() {
		return defined( 'IMS_RESEND_API_KEY' ) && '' !== IMS_RESEND_API_KEY && defined( 'IMS_MAIL_FROM' ) && is_email( IMS_MAIL_FROM );
	}

	private static function from() {
		$name = defined( 'IMS_MAIL_FROM_NAME' ) && IMS_MAIL_FROM_NAME ? IMS_MAIL_FROM_NAME : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name = trim( str_replace( array( '"', '<', '>', "\r", "\n" ), '', $name ) );
		return $name ? sprintf( '%s <%s>', $name, IMS_MAIL_FROM ) : IMS_MAIL_FROM;
	}

	/** Split "a@x.com, B <b@y.com>" or arrays into a clean list of addresses. */
	private static function addresses( $value ) {
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out  = array();
		foreach ( $list as $item ) {
			$item = trim( (string) $item );
			if ( preg_match( '/<([^>]+)>/', $item, $m ) ) {
				$item = $m[1];
			}
			$item = sanitize_email( $item );
			if ( is_email( $item ) ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Short-circuits wp_mail(). Returning true/false stops WordPress from sending it again.
	 *
	 * @param null|bool $return Null unless another plugin already handled the email.
	 * @param array     $atts   to, subject, message, headers, attachments.
	 */
	public static function send( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		$headers = $atts['headers'] ?? array();
		$headers = is_array( $headers ) ? $headers : preg_split( "/\r\n|\n|\r/", (string) $headers );
		$cc      = array();
		$bcc     = array();
		$reply   = array();
		$type    = 'text/plain';
		foreach ( $headers as $header ) {
			if ( false === strpos( (string) $header, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );
			switch ( strtolower( $name ) ) {
				case 'content-type':
					$type = strtolower( trim( explode( ';', $value )[0] ) );
					break;
				case 'cc':
					$cc = array_merge( $cc, self::addresses( $value ) );
					break;
				case 'bcc':
					$bcc = array_merge( $bcc, self::addresses( $value ) );
					break;
				case 'reply-to':
					$reply = array_merge( $reply, self::addresses( $value ) );
					break;
			}
		}
		$type = apply_filters( 'wp_mail_content_type', $type );

		$to = self::addresses( $atts['to'] ?? array() );
		if ( ! $to ) {
			return self::fail( 'No valid recipient.', $atts );
		}

		$payload = array(
			'from'    => self::from(),
			'to'      => $to,
			'subject' => wp_specialchars_decode( (string) ( $atts['subject'] ?? '' ), ENT_QUOTES ),
		);
		if ( 'text/html' === $type ) {
			$payload['html'] = (string) $atts['message'];
			$payload['text'] = wp_strip_all_tags( preg_replace( '/<(br|\/p|\/div|\/h\d)[^>]*>/i', "\n", (string) $atts['message'] ) );
		} else {
			$payload['text'] = (string) $atts['message'];
		}
		if ( $cc ) {
			$payload['cc'] = $cc;
		}
		if ( $bcc ) {
			$payload['bcc'] = $bcc;
		}
		if ( $reply ) {
			$payload['reply_to'] = $reply;
		}

		$files = (array) ( $atts['attachments'] ?? array() );
		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_readable( $file ) && filesize( $file ) <= 10 * MB_IN_BYTES ) {
				$payload['attachments'][] = array(
					'filename' => basename( $file ),
					'content'  => base64_encode( (string) file_get_contents( $file ) ), // phpcs:ignore
				);
			}
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . IMS_RESEND_API_KEY,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( 'Resend request failed: ' . $response->get_error_message(), $atts );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : 'HTTP ' . $code;
			return self::fail( 'Resend rejected the email: ' . $msg, $atts );
		}

		update_option( 'ims_mail_last', array( 'ok' => true, 'id' => $body['id'] ?? '', 'time' => time() ), false );
		do_action( 'wp_mail_succeeded', $atts );
		return true;
	}

	private static function fail( $message, $atts ) {
		update_option( 'ims_mail_last', array( 'ok' => false, 'error' => $message, 'time' => time() ), false );
		error_log( '[Inventory] ' . $message ); // phpcs:ignore
		do_action( 'wp_mail_failed', new WP_Error( 'ims_resend_failed', $message, $atts ) );
		return false;
	}
}
