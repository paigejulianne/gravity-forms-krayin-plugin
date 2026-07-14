<?php
/**
 * Minimal REST API client for Krayin CRM (krayin/rest-api v1 endpoints).
 *
 * @package GravityForms_Krayin_CRM
 */

defined( 'ABSPATH' ) || die();

class Krayin_API_Client {

	/**
	 * Base URL of the Krayin installation, no trailing slash, e.g. https://crm.example.com
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Krayin user email used to authenticate against the API.
	 *
	 * @var string
	 */
	private $email;

	/**
	 * Krayin user password used to authenticate against the API.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * WordPress option name used to cache the Sanctum token between requests.
	 *
	 * @var string
	 */
	const TOKEN_OPTION = 'gfkrayincrm_auth_token';

	/**
	 * Device name sent to Krayin's login endpoint, shown against the issued token.
	 *
	 * @var string
	 */
	const DEVICE_NAME = 'gravityforms-wordpress';

	public function __construct( $base_url, $email, $password ) {
		$this->base_url = rtrim( trim( (string) $base_url ), '/' );
		$this->email    = trim( (string) $email );
		$this->password = (string) $password;
	}

	/**
	 * Hash identifying the current credentials, used to invalidate a cached token
	 * when the connection settings change.
	 *
	 * @return string
	 */
	private function credentials_hash() {
		return md5( $this->base_url . '|' . $this->email . '|' . $this->password );
	}

	/**
	 * Authenticates against Krayin and caches the issued token.
	 *
	 * @return string|WP_Error
	 */
	private function login() {
		if ( empty( $this->base_url ) || empty( $this->email ) || empty( $this->password ) ) {
			return new WP_Error( 'gfkrayincrm_missing_settings', __( 'Krayin CRM connection settings are incomplete.', 'gravityforms-krayin-crm' ) );
		}

		$response = wp_remote_post(
			$this->base_url . '/api/v1/login',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'email'       => $this->email,
					'password'    => $this->password,
					'device_name' => self::DEVICE_NAME,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $data['token'] ) ) {
			$message = ! empty( $data['message'] ) ? $data['message'] : __( 'Invalid email or password.', 'gravityforms-krayin-crm' );

			return new WP_Error( 'gfkrayincrm_login_failed', $message, array( 'status' => $code ) );
		}

		update_option(
			self::TOKEN_OPTION,
			array(
				'hash'  => $this->credentials_hash(),
				'token' => $data['token'],
			),
			false
		);

		return $data['token'];
	}

	/**
	 * Returns a valid Sanctum token, reusing the cached one when the credentials
	 * haven't changed, and forcing a fresh login otherwise.
	 *
	 * @param bool $force_refresh Bypass the cache and log in again.
	 *
	 * @return string|WP_Error
	 */
	private function get_token( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_option( self::TOKEN_OPTION );

			if ( is_array( $cached ) && ! empty( $cached['token'] ) && rgar( $cached, 'hash' ) === $this->credentials_hash() ) {
				return $cached['token'];
			}
		}

		return $this->login();
	}

	/**
	 * Performs an authenticated request against the Krayin API, automatically
	 * retrying once with a fresh token if the cached one was rejected.
	 *
	 * @param string $method       HTTP method.
	 * @param string $path         Path relative to /api/v1, e.g. 'leads'.
	 * @param array  $body         Request payload for POST/PUT requests.
	 * @param bool   $is_retry     Internal flag to prevent infinite retry loops.
	 *
	 * @return array|WP_Error Decoded JSON body on success (2xx), WP_Error otherwise.
	 */
	public function request( $method, $path, $body = null, $is_retry = false ) {
		$token = $this->get_token( $is_retry );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$url      = $this->base_url . '/api/v1/' . ltrim( $path, '/' );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code && ! $is_retry ) {
			return $this->request( $method, $path, $body, true );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = ! empty( $decoded['message'] ) ? $decoded['message'] : sprintf(
				/* translators: %d: HTTP status code */
				__( 'Krayin CRM API request failed with status %d.', 'gravityforms-krayin-crm' ),
				$code
			);

			if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
				$field_messages = array();

				foreach ( $decoded['errors'] as $field_errors ) {
					if ( is_array( $field_errors ) ) {
						$field_messages = array_merge( $field_messages, $field_errors );
					}
				}

				if ( $field_messages ) {
					$message .= ' ' . implode( ' ', $field_messages );
				}
			}

			return new WP_Error( 'gfkrayincrm_request_failed', $message, array(
				'status' => $code,
				'body'   => $decoded,
			) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Verifies the connection settings by attempting a fresh login.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$token = $this->login();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return true;
	}

	/**
	 * @return array|WP_Error List of ['id' => int, 'name' => string] lead sources.
	 */
	public function get_sources() {
		$result = $this->request( 'GET', 'settings/sources?pagination=0' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['data'] ) ? $result['data'] : array();
	}

	/**
	 * @return array|WP_Error List of ['id' => int, 'name' => string] lead types.
	 */
	public function get_types() {
		$result = $this->request( 'GET', 'settings/types?pagination=0' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['data'] ) ? $result['data'] : array();
	}

	/**
	 * @return array|WP_Error List of ['id' => int, 'name' => string] lead pipeline stages,
	 *                        flattened across all pipelines and prefixed with the pipeline name.
	 */
	public function get_stages() {
		$result = $this->request( 'GET', 'settings/pipelines?pagination=0' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pipelines = isset( $result['data'] ) ? $result['data'] : array();
		$stages    = array();

		foreach ( (array) $pipelines as $pipeline ) {
			foreach ( (array) ( $pipeline['stages'] ?? array() ) as $stage ) {
				$stages[] = array(
					'id'   => $stage['id'] ?? null,
					'name' => ( $pipeline['name'] ?? '' ) . ' → ' . ( $stage['name'] ?? '' ),
				);
			}
		}

		return $stages;
	}

	/**
	 * @param array $data Person payload (name, emails, contact_numbers, organization_id, ...).
	 *
	 * @return array|WP_Error Decoded response containing the created person under 'data'.
	 */
	public function create_person( $data ) {
		return $this->request( 'POST', 'contacts/persons', $data );
	}

	/**
	 * @param array $data Lead payload (title, description, lead_value, lead_source_id, lead_type_id, person, ...).
	 *
	 * @return array|WP_Error Decoded response containing the created lead under 'data'.
	 */
	public function create_lead( $data ) {
		return $this->request( 'POST', 'leads', $data );
	}

	/**
	 * @param array $data Organization payload (name, ...).
	 *
	 * @return array|WP_Error Decoded response containing the created organization under 'data'.
	 */
	public function create_organization( $data ) {
		return $this->request( 'POST', 'contacts/organizations', $data );
	}
}
