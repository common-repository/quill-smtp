<?php
/**
 * Account_Controller class.
 *
 * @since 1.0.0
 * @package QuillSMTP
 * @subpackage mailers
 */

namespace QuillSMTP\Mailers\Loops\REST;

use WP_Error;
use WP_REST_Request;
use QuillSMTP\Mailer\Provider\REST\Account_Controller as Abstract_Account_Controller;
use QuillSMTP\Mailer\Provider\REST\Traits\Account_Controller_Creatable;
use QuillSMTP\Mailer\Provider\REST\Traits\Account_Controller_Gettable;

/**
 * Account_Controller class.
 *
 * @since 1.3.0
 */
class Account_Controller extends Abstract_Account_Controller {
	use Account_Controller_Gettable, Account_Controller_Creatable;

	/**
	 * Register controller routes
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		$this->register_gettable_route();
		$this->register_creatable_route();
	}

	/**
	 * Get credentials schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_credentials_schema() {
		return [
			'api_key'          => [
				'type'     => 'string',
				'required' => true,
			],
			'transactional_id' => [
				'type'     => 'string',
				'required' => true,
			],
		];
	}

	/**
	 * Get account id & name
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array|WP_Error array of id & name if success.
	 */
	protected function get_account_info( $request ) {
		$credentials      = $request->get_param( 'credentials' );
		$api_key          = $credentials['api_key'] ?? '';
		$transactional_id = $credentials['transactional_id'] ?? '';
		$account_name     = $request->get_param( 'name' );
		$account_id       = $request->get_param( 'id' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'quillsmtp_loops_api_key_missing', __( 'API key is missing.', 'quillsmtp-pro' ) );
		}

		if ( empty( $transactional_id ) ) {
			return new WP_Error( 'quillsmtp_loops_transactional_id_missing', __( 'Transactional ID is missing.', 'quillsmtp-pro' ) );
		}

		$response = wp_remote_request(
			'https://app.loops.so/api/v1/api-key',
			[
				'method'  => 'GET',
				'headers' => [
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'timeout' => 60,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['success'] ) || $data['success'] !== true ) {
			return new WP_Error( 'quillsmtp_loops_api_key_invalid', __( 'Invalid API key.', 'quillsmtp-pro' ) );
		}

		return [
			'id'   => $account_id,
			'name' => $account_name,
		];
	}

}
