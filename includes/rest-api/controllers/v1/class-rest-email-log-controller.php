<?php
/**
 * REST API: Log Controller
 *
 * @since 1.0.0
 * @package QuillSMTP
 * @subpackage API
 */

namespace QuillSMTP\REST_API\Controllers\V1;

use QuillSMTP\Abstracts\REST_Controller;
use QuillSMTP\Email_Log\Handler_DB;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST_Email_Log_Controller is REST api controller class for log
 *
 * @since 1.0.0
 */
class REST_Email_Log_Controller extends REST_Controller {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'email-logs';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
				),
			)
		);

		// Get logs count for specific date for chart.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/count',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_count' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<log_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Resent emails.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/resend',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resend_emails' ),
					'permission_callback' => array( $this, 'resend_emails_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Resend emails
	 *
	 * @since 1.7.1
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function resend_emails( $request ) {
		$ids = $request->get_param( 'ids' );
		$ids = explode( ',', $ids );

		if ( empty( $ids ) ) {
			return new WP_Error( 'quillsmtp_logs_no_ids', esc_html__( 'No ids provided', 'quillsmtp' ), array( 'status' => 422 ) );
		}

		$logs = Handler_DB::get( $ids );
		if ( empty( $logs ) ) {
			return new WP_Error( 'quillsmtp_logs_no_logs', esc_html__( 'No logs found', 'quillsmtp' ), array( 'status' => 422 ) );
		}

		foreach ( $logs as $log ) {
			if ( empty( $log ) ) {
				continue;
			}
			$email = [
				'to'          => $log['recipients']['to'],
				'from'        => $log['from'],
				'cc'          => $log['recipients']['cc'],
				'bcc'         => $log['recipients']['bcc'],
				'reply_to'    => $log['recipients']['reply_to'],
				'subject'     => $log['subject'],
				'body'        => $log['body'],
				'headers'     => $log['headers'],
				'attachments' => $log['attachments'],
			];

			$to      = $email['to'];
			$subject = $email['subject'];

			// Message.
			$message = $email['body'];

			// Headers.
			$headers = array();

			// Check if is body text is html.
			if ( $this->is_html( $message ) ) {
				$headers[] = 'Content-Type: text/html; charset=UTF-8';
			} else {
				$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			}

			if ( ! empty( $email['from'] ) ) {
				$headers[] = 'From: ' . $email['from'];
			}

			if ( ! empty( $email['cc'] ) ) {
				$headers[] = 'Cc: ' . $email['cc'];
			}

			if ( ! empty( $email['bcc'] ) ) {
				$headers[] = 'Bcc: ' . $email['bcc'];
			}

			if ( ! empty( $email['reply_to'] ) ) {
				$headers[] = 'Reply-To: ' . $email['reply_to'];
			}

			if ( ! empty( $email['headers'] ) ) {
				$headers[] = $email['headers'];
			}

			// Attachments.
			$attachments = array();
			if ( ! empty( $email['attachments'] ) ) {
				$attachments = $email['attachments'];
			}

			add_filter(
				'quillsmtp_mailer_log_result',
				function( $result, $email_data ) use ( $log ) {
					$resend_count = $log['resend_count'] ?? 0;
					if ( 'succeeded' === $email_data['status'] && 'succeeded' === $log['status'] ) {
						// Update resent count.
						$resend_count = is_numeric( $resend_count ) ? $resend_count + 1 : 1;
					}

					Handler_DB::update(
						$log['log_id'],
						[
							'resend_count' => $resend_count,
							'status'       => $email_data['status'],
							'response'     => $email_data['response'] ?? [],
						]
					);
					return false;
				},
				10,
				2
			);

			// Send email.
			wp_mail( $to, $subject, $message, $headers, $attachments );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Check if string is html
	 *
	 * @param string $string string.
	 * @return bool
	 */
	private function is_html( $string ) {
		return preg_match( '/<[^<]+>/', $string ) !== 0;
	}

	/**
	 * Resend emails permission check
	 *
	 * @since 1.7.1
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function resend_emails_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}

	/**
	 * Get count of logs for specific date
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_count( $request ) {
		$from_date         = $request->get_param( 'start' );
		$to_date           = $request->get_param( 'end' );
		$logs_for_each_day = array();

		if ( $from_date && $to_date ) {
			// Days between two dates.
			$from_date = $this->get_date( $from_date );
			$to_date   = $this->get_date( $to_date, '23:59:59' );
			$from_date = new \DateTime( $from_date );
			$to_date   = new \DateTime( $to_date );
			$interval  = new \DateInterval( 'P1D' );
			$period    = new \DatePeriod( $from_date, $interval, $to_date );

			foreach ( $period as $date ) {
				$logs_for_each_day[ $date->format( 'Y-m-d' ) ] = Handler_DB::get_count( false, $date->format( 'Y-m-d 00:00:00' ), $date->format( 'Y-m-d 23:59:59' ) );
			}
		}

		$success_logs = Handler_DB::get_count( 'succeeded' );
		$error_logs   = Handler_DB::get_count( 'failed' );
		$total_logs   = Handler_DB::get_count();

		$result = array(
			'total'   => $total_logs,
			'success' => $success_logs,
			'failed'  => $error_logs,
			'days'    => $logs_for_each_day,
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get all logs.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$status = $request->get_param( 'status' ) ?? false;

		// check export.
		$export = $request->get_param( 'export' );
		if ( $export ) {
			return $this->export_items( $export, $status );
		}

		$per_page    = $request->get_param( 'per_page' );
		$page        = $request->get_param( 'page' );
		$offset      = $per_page * ( $page - 1 );
		$logs        = [];
		$total_items = [];
		$start_date  = $request->get_param( 'start_date' );
		$end_date    = $request->get_param( 'end_date' );
		$search      = $request->get_param( 'search' );

		if ( $start_date && $end_date ) {
			$start_date  = $this->get_date( $start_date );
			$end_date    = $this->get_date( $end_date, '23:59:59' );
			$logs        = Handler_DB::get_all( $status, $offset, $per_page, $start_date, $end_date );
			$total_items = Handler_DB::get_count( $status, $start_date, $end_date );
		} elseif ( $search ) {
			$search      = sanitize_text_field( $search );
			$logs        = Handler_DB::get_all( $status, $offset, $per_page, false, false, $search );
			$total_items = Handler_DB::get_count( $status, false, false, $search );
		} else {
			$logs        = Handler_DB::get_all( $status, $offset, $per_page );
			$total_items = Handler_DB::get_count( $status );
		}

		$total_pages = ceil( $total_items / $per_page );

		$data = array(
			'items'       => $logs,
			'total_items' => $total_items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Export items
	 *
	 * @since 1.7.1
	 *
	 * @param string $format Format.
	 * @param array  $status status.
	 * @return void|WP_Error|WP_REST_Response
	 */
	private function export_items( $format, $status ) {
		$logs = Handler_DB::get_all( $status );
		if ( empty( $logs ) ) {
			return new WP_Error( 'quillsmtp_cannot_find_logs', esc_html__( 'Cannot find any logs', 'quillsmtp' ), array( 'status' => 404 ) );
		}

		$rows = array();

		// header row.
		$header_row = array_keys( $logs[0] );
		$rows[]     = $header_row;

		// logs rows.
		foreach ( $logs as $log ) {
			$log_row = array_values( $log );
			$rows[]  = $log_row;
		}

		switch ( $format ) {
			case 'json':
				$this->export_json( $rows );
				break;
			default:
				return new WP_Error( 'quillsmtp_unknown_logs_export_format', esc_html__( 'Unknown export format', 'quillsmtp' ), array( 'status' => 422 ) );
		}
	}

	/**
	 * Export rows as json file
	 *
	 * @param array $rows File rows.
	 * @return void
	 */
	private function export_json( $rows ) {
		$filename = esc_html__( 'Logs export', 'quillsmtp' ) . '.json';

		// if ( ini_get( 'display_errors' ) ) {
		// 	ini_set( 'display_errors', '0' );
		// }
		nocache_headers();
		header( 'X-Robots-Tag: noindex', true );
		header( 'Content-Type: application/json' );
		header( 'Content-Description: File Transfer' );
		header( "Content-Disposition: attachment; filename=\"$filename\";" );
		echo wp_json_encode( $rows );
		exit;
	}

	/**
	 * Check if a given request has access to get all items.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}

	/**
	 * Delete items from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_RESPONSE
	 */
	public function delete_items( $request ) {
		if ( isset( $request['ids'] ) ) {
			$ids     = empty( $request['ids'] ) ? array() : $request['ids'];
			$deleted = (bool) Handler_DB::delete( $ids );
		} else {
			$deleted = (bool) Handler_DB::flush();
		}

		return new WP_REST_Response( array( 'success' => $deleted ), 200 );
	}

	/**
	 * Delete items permission check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function delete_items_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}

	/**
	 * Delete one item from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_RESPONSE
	 */
	public function delete_item( $request ) {
		$deleted = Handler_DB::delete( $request->get_param( 'log_id' ) );

		if ( ! $deleted ) {
			return new WP_Error( 'quillsmtp_logs_db_error_on_deleting_log', __( 'Error on deleting log in db!', 'quillsmtp' ), array( 'status' => 422 ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Delete item permission check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function delete_item_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}

	/**
	 * Get valid date
	 *
	 * @param string $date date.
	 * @param string $time time.
	 *
	 * @return string
	 */
	public function get_date( $date, $time = '00:00:00' ) {
		list($month, $day, $year) = explode( '/', $date );
		$value                    = "$year-$month-$day";
		if ( $time ) {
			$value .= " $time";
		}

		return $value;
	}

}
