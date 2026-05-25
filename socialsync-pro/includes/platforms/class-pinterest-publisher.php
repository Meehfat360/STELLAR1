<?php
/**
 * SocialSync Pro - Pinterest Publisher
 *
 * @package SSP\Platforms
 */

namespace SSP\Platforms;

use SSP\Exceptions\APIException;

/**
 * Pinterest Publisher - Publishes to Pinterest using Pinterest API v5
 *
 * @class Pinterest_Publisher
 */
class Pinterest_Publisher extends Abstract_Publisher {

	/**
	 * Base API URL
	 *
	 * @var string
	 */
	private $api_base = 'https://api.pinterest.com/v5';

	/**
	 * Access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Board ID
	 *
	 * @var string
	 */
	private $board_id;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->platform = 'pinterest';

		$settings = get_option( 'ssp_settings', [] );

		$this->access_token = $settings['pinterest_access_token'] ?? '';
		$this->board_id     = $settings['pinterest_board_id'] ?? '';
	}

	/**
	 * Publish standard post (pin)
	 *
	 * @param array $post_data Post data.
	 *
	 * @return string Platform post ID.
	 * @throws APIException
	 */
	public function publish_post( array $post_data ) {
		try {
			$pin_data = [
				'board_id'      => $this->board_id,
				'media_source'  => [
					'source_type' => 'image_url',
					'url'         => $post_data['image_url'],
				],
				'title'         => $post_data['headline'] ?? '',
				'description'   => $this->build_description( $post_data ),
				'link'          => $post_data['product_url'] ?? '',
			];

			$response = $this->api_request( '/pins', 'POST', $pin_data );

			$pin_id = $response['id'] ?? null;

			if ( ! $pin_id ) {
				throw new APIException( 'No pin ID returned from Pinterest API' );
			}

			$this->log( 'info', "Pin published successfully: {$pin_id}" );

			return $pin_id;

		} catch ( \Exception $e ) {
			$this->handle_api_error(
				[
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
				],
				'publish_post'
			);
		}
	}

	/**
	 * Publish reel - create pin with [REEL] prefix
	 *
	 * @param array $post_data Post data.
	 *
	 * @return string Platform post ID.
	 * @throws APIException
	 */
	public function publish_reel( array $post_data ) {
		try {
			$headline = '[REEL] ' . ( $post_data['headline'] ?? 'Check out this video' );

			$pin_data = [
				'board_id'      => $this->board_id,
				'media_source'  => [
					'source_type' => 'image_url',
					'url'         => $post_data['image_url'],
				],
				'title'         => $headline,
				'description'   => $this->build_description( $post_data ),
				'link'          => $post_data['product_url'] ?? '',
			];

			$response = $this->api_request( '/pins', 'POST', $pin_data );

			$pin_id = $response['id'] ?? null;

			if ( ! $pin_id ) {
				throw new APIException( 'No pin ID returned from Pinterest API' );
			}

			$this->log( 'info', "Reel pin published successfully: {$pin_id}" );

			return $pin_id;

		} catch ( \Exception $e ) {
			$this->handle_api_error(
				[
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
				],
				'publish_reel'
			);
		}
	}

	/**
	 * Publish story - create pin with [STORY] prefix
	 *
	 * @param array $post_data Post data.
	 *
	 * @return string Platform post ID.
	 * @throws APIException
	 */
	public function publish_story( array $post_data ) {
		try {
			$headline = '[STORY] ' . ( $post_data['headline'] ?? 'Exclusive story' );

			$pin_data = [
				'board_id'      => $this->board_id,
				'media_source'  => [
					'source_type' => 'image_url',
					'url'         => $post_data['image_url'],
				],
				'title'         => $headline,
				'description'   => $this->build_description( $post_data ),
				'link'          => $post_data['product_url'] ?? '',
			];

			$response = $this->api_request( '/pins', 'POST', $pin_data );

			$pin_id = $response['id'] ?? null;

			if ( ! $pin_id ) {
				throw new APIException( 'No pin ID returned from Pinterest API' );
			}

			$this->log( 'info', "Story pin published successfully: {$pin_id}" );

			return $pin_id;

		} catch ( \Exception $e ) {
			$this->handle_api_error(
				[
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
				],
				'publish_story'
			);
		}
	}

	/**
	 * Validate credentials
	 *
	 * @return bool
	 */
	public function validate_credentials() {
		try {
			$response = $this->api_request( '/me?fields=id,username', 'GET' );
			return isset( $response['id'] );
		} catch ( \Exception $e ) {
			$this->log( 'warning', 'Credential validation failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get Pinterest boards for dropdown
	 *
	 * @return array
	 */
	public function get_boards() {
		try {
			$response = $this->api_request( '/me/boards?fields=id,name', 'GET' );
			return $response['items'] ?? [];
		} catch ( \Exception $e ) {
			$this->log( 'warning', 'Failed to fetch boards: ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Make API request
	 *
	 * @param string $endpoint API endpoint.
	 * @param string $method HTTP method.
	 * @param array  $args Request arguments.
	 *
	 * @return array Response.
	 * @throws APIException
	 */
	private function api_request( $endpoint, $method = 'GET', $args = [] ) {
		$url = $this->api_base . $endpoint;

		if ( 'GET' === $method ) {
			$url = add_query_arg( 'access_token', $this->access_token, $url );
		}

		$headers = [
			'Authorization' => 'Bearer ' . $this->access_token,
			'Content-Type'  => 'application/json',
		];

		$response = wp_remote_request(
			$url,
			[
				'method'      => $method,
				'timeout'     => 30,
				'headers'     => $headers,
				'body'        => 'POST' === $method || 'PUT' === $method ? wp_json_encode( $args ) : null,
				'blocking'    => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new APIException( 'Request failed: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		// Check for rate limiting (Pinterest: 1000 pins/day)
		$retry_after = $this->check_rate_limit( wp_remote_retrieve_headers( $response ) );
		if ( $retry_after > 0 ) {
			sleep( $retry_after );
			return $this->api_request( $endpoint, $method, $args );
		}

		// Check for errors
		if ( $status_code >= 400 ) {
			throw new APIException(
				$data['message'] ?? 'API error',
				$status_code
			);
		}

		return $data;
	}

	/**
	 * Build description from post data
	 *
	 * @param array $post_data Post data.
	 *
	 * @return string
	 */
	private function build_description( array $post_data ) {
		$description = $post_data['primary_text'] ?? '';

		if ( ! empty( $post_data['hook'] ) ) {
			$description .= "\n\n" . $post_data['hook'];
		}

		if ( ! empty( $post_data['hashtags'] ) && is_array( $post_data['hashtags'] ) ) {
			$description .= "\n\n" . implode( ' ', $post_data['hashtags'] );
		}

		return $description;
	}
}
