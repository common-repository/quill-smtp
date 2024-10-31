<?php
/**
 * Account_API class.
 *
 * @since 1.0.0
 * @package QuillSMTP
 * @subpackage mailers
 */

namespace QuillSMTP\Mailers\Mailgun;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Account_API class.
 *
 * @since 1.0.0
 */
class Account_API {

	/**
	 * API
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Domain Name
	 *
	 * @var string
	 */
	protected $domain_name;

	/**
	 * Region
	 *
	 * @var string
	 */
	protected $region;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key API key.
	 * @param string $domain_name Domain name.
	 * @param string $region Region.
	 */
	public function __construct( $api_key, $domain_name, $region ) {
		$this->api_key     = $api_key;
		$this->domain_name = $domain_name;
		$this->region      = $region;
	}

	/**
	 * Send email
	 *
	 * @param array  $args Email arguments.
	 * @param string $content_type Content type.
	 *
	 * @return WP_Error|array
	 */
	public function send( $args, $content_type = '' ) {
		$response = wp_remote_request(
			'eu' === $this->region ? 'https://api.eu.mailgun.net/v3/' . $this->domain_name . '/messages' : 'https://api.mailgun.net/v3/' . $this->domain_name . '/messages',
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $this->api_key ),
					'Content-Type'  => $content_type,
				],
				'body'    => $args,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return new WP_Error( 'empty_response', __( 'Empty response.', 'quillsmtp' ) );
		}

		$body = json_decode( $body, true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response.', 'quillsmtp' ) );
		}

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'send_error', $body['error'] );
		}

		return $body;
	}
}
