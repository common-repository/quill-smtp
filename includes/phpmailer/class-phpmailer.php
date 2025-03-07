<?php
/**
 * Class PHPMailer
 *
 * @since 1.0.0
 *
 * @package QuillSMTP
 * @subpackage phpmailer
 */

namespace QuillSMTP\PHPMailer;

defined( 'ABSPATH' ) || exit;

use QuillSMTP\Settings;
use QuillSMTP\Mailers\Mailers;

/**
 * PHPMailer class.
 * Override the default PHPMailer class to catch emails.
 *
 * @since 1.0.0
 */
class PHPMailer extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Modify the default send method to catch emails.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function send() {
		do_action( 'quillsmtp_before_get_settings' );
		$connections            = Settings::get( 'connections' ) ?? [];
		$default_connection_id  = apply_filters( 'quillsmtp_default_connection', Settings::get( 'default_connection' ) );
		$fallback_connection_id = Settings::get( 'fallback_connection' );
		$first_connection_id    = array_key_first( $connections );
		$default_connection_id  = $default_connection_id ?: $first_connection_id;
		$default_connection     = $connections[ $default_connection_id ] ?? null;
		$fallback_connection    = $connections[ $fallback_connection_id ] ?? null;
		do_action( 'quillsmtp_after_get_settings' );

		if ( ! $default_connection ) {
			return parent::send();
		}

		$mailer = Mailers::get_mailer( $default_connection['mailer'] );
		if ( ! $mailer ) {
			return false;
		}
		$result = $mailer->process( $this, $default_connection_id, $default_connection )->send();

		if ( ! $result && $fallback_connection ) {
			$mailer = Mailers::get_mailer( $fallback_connection['mailer'] );
			if ( ! $mailer ) {
				return false;
			}
			$result = $mailer->process( $this, $fallback_connection_id, $fallback_connection )->send();
		}

		return $result;
	}
}
