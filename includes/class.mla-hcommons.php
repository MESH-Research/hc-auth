<?php
/**
 * Calling the MLA API
 *
 * @package MLA_Hcommons
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

require_once dirname( __FILE__ ) . '/' . 'functions.inc.php';

/**
 * Provides functions that accesses the MLA API to retreive
 * information about MLA members.
 *
 * @package MLA_Hcommons
 */
class MLA_Hcommons {

	/**
	 * MLA API url
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * MLA API key
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * MLA API parameters
	 *
	 * @var array
	 */
	private $api_parameters = array();

	/**
	 * MLA API secret
	 *
	 * @var string
	 */
	private $api_secret;

	/**
	 * Refers to a single instance
	 * of this class
	 *
	 * @var object
	 */
	private static $instance = false;

	/**
	 * Set the api url, key and secret.
	 * Also calls actions and filters.
	 */
	private function __construct() {

		if ( defined( 'CBOX_AUTH_API_URL' ) ) {
			$this->api_url = CBOX_AUTH_API_URL;
		}
		if ( defined( 'CBOX_AUTH_API_KEY' ) ) {
			$this->api_key = CBOX_AUTH_API_KEY;
		}
		if ( defined( 'CBOX_AUTH_API_SECRET' ) ) {
			$this->api_secret = CBOX_AUTH_API_SECRET;
		}
		$this->api_parameters['key'] = $this->api_key;

		$this->_add_actions();
	}

	/**
	 * Change member's username via API.
	 */
	public function change_username() {

		$username = ( ! empty( $_POST['username'] ) ? $_POST['username'] : '' );
		$mla_user_id = ( ! empty( $_POST['mla_user_id'] ) ? $_POST['mla_user_id'] : '' );
		$hc_user_id = ( ! empty( $_POST['hc_user_id'] ) ? $_POST['hc_user_id'] : '' );
		$message = null;

		if ( $this->is_duplicate_username( $username ) ) {
		    $message = '<span class="hc-auth-error">Sorry! This MLA.org username is not available. Email membership@mla.org for more information.</span>';
	 	} else {
		    // Send request to API.
			$request_path = 'members/' . $mla_user_id . '/username';
			$request_body = '{"username":"' . $username . '"}';
			$response = $this->process_request( 'PUT', $request_path, array(), $request_body );

			if ( $this->validate_api_response( $response ) ) {
				update_user_meta( $hc_user_id, 'mla_username', $username );

		    	$message = 'Username changed successfully.';
		    } else {
		    	$message = 'An error occurred. Please e-mail hello@hcommons.org with your username.';
		    }
		}

		echo $message;
		die();
	}

	/**
	 * Get object property or return default value.
	 *
	 * @param object $obj      Object.
	 * @param string $property Property name.
	 * @param mixed  $default  Default value.
	 * @return mixed Property value or default value.
	 */
	protected function get_object_property( $obj, $property, $default ) {
		return ( property_exists( $obj, $property ) ) ? $obj->$property : $default;
	}

	/**
	 * Query API to check if username already exists.
	 *
	 * @param string $username WordPress username/nicename.
	 * @return bool True if username is duplicate.
	 * @throws \Exception Describes API error.
	 */
	public function is_duplicate_username( $username ) {

		// Query API.
		$request_path = 'members';
		$request_params = array(
			'type' => 'duplicate',
			'username' => $username,
		);
		$response = $this->process_request( 'GET', $request_path, $request_params, '' );

		// Validate and extract API response.
		$response = $this->extract_api_response_data( $response, 'username' );
		$is_duplicate = $this->get_object_property( $response->username, 'duplicate', null );

		if ( is_bool( $is_duplicate ) ) {
			return $is_duplicate;
		}

		throw new \Exception( 'API did not return boolean for duplicate status of username ' . $username . '.', 570 );

	}

	/**
	 * Lookup group id by name.
	 *
	 * @param string $group_name the group name.
	 */
	public function lookup_mla_group_id( $group_name ) {

		$managed_group_names = get_transient( 'mla_managed_group_names' );

		if ( ! $managed_group_names ) {

			$bp = buddypress();
			global $wpdb;
			$managed_group_names = array();
			$all_groups = $wpdb->get_results( 'SELECT * FROM ' . $bp->table_prefix . 'bp_groups' );
			foreach ( $all_groups as $group ) {

				$society_id = hcommons_get_group_society_id( $group->id );
				if ( 'mla' === $society_id ) {
					$oid = groups_get_groupmeta( $group->id, 'mla_oid' );
					if ( ! empty( $oid ) && in_array( substr( $oid, 0, 1 ), array( 'D', 'G', 'M' ) ) ) {
						$managed_group_names[ strip_tags( stripslashes( $group->name ) ) ] = $group->id;
					}
				}
			}
			set_transient( 'mla_managed_group_names', $managed_group_names, 24 * HOUR_IN_SECONDS );
		}
		return $managed_group_names[ $group_name ];

	}

	/**
	 * Lookup member data
	 *
	 * @param int  $user_id HC user id.
	 * @param bool $full_check Returns more information if true.
	 */
	public function lookup_mla_member_data( $user_id, $full_check = false ) {

		$request_body = '';
				$member_types = (array) bp_get_member_type( $user_id, false );
		if ( ! in_array( 'mla', $member_types ) && ! $full_check ) {
			return false;
		}
				$mla_oid = get_user_meta( $user_id, 'mla_oid', true );

		if ( ! empty( $mla_oid ) && ! $full_check ) {
				return array( 'mla_member_id' => $mla_oid );
		}
		if ( ! empty( $mla_oid ) ) {
			$api_base_url = $this->api_url . 'members/' . $mla_oid; // Add username.
			// Generate a "signed" request URL.
			$api_request_url = generateRequest( 'GET', $api_base_url, $this->api_secret, $this->api_parameters );
			// Initiate request.
			$api_response = sendRequest( 'GET', $api_request_url, $request_body );

			$json_response = json_decode( $api_response['body'], true );
			$request_status = $json_response['meta']['code'];
		}
		if ( empty( $mla_oid ) || 'API-2100' === $request_status ) {
				$user_emails = (array) maybe_unserialize( get_user_meta( $user_id, 'shib_email', true ) );
			if ( empty( $user_emails ) ) {
					$user_emails = array();
					$user_emails[] = $user->user_email;
			}
			foreach ( array_unique( $user_emails ) as $user_email ) {
				$this->api_parameters['membership_status'] = 'ALL';
				$this->api_parameters['email'] = $user_email;
				$api_base_url = $this->api_url . 'members/'; // Search
				// Generate a "signed" request URL.
				$api_request_url = generateRequest( 'GET', $api_base_url, $this->api_secret, $this->api_parameters );
				// Initiate request.
				$api_response = sendRequest( 'GET', $api_request_url, $request_body );
				$json_response = json_decode( $api_response['body'], true );
				unset( $this->api_parameters['membership_status'] );
				unset( $this->api_parameters['email'] );
				if ( 'API-1000' === $json_response['meta']['code'] ) {
						$search_member_id = $json_response['data'][0]['search_results'][0]['id'];
					$api_base_url = $this->api_url . 'members/' . $search_member_id; // Add member_id.
						// Generate a "signed" request URL.
						$api_request_url = generateRequest( 'GET', $api_base_url, $this->api_secret, $this->api_parameters );
						// Initiate request.
					$api_response = sendRequest( 'GET', $api_request_url, $request_body );
					$json_response = json_decode( $api_response['body'], true );
					if ( 'API-1000' !== $json_response['meta']['code'] ) {
						return false;
					} else {
						break;
					}
				}
			}
		}
		if ( 'API-1000' !== $json_response['meta']['code'] ) {
			return false;
		}
				$mla_member_id = $json_response['data'][0]['id'];
				$mla_username = $json_response['data'][0]['authentication']['username'];
				$mla_membership_status = $json_response['data'][0]['authentication']['membership_status'];
				$mla_expiring_date = $json_response['data'][0]['membership']['expiring_date'];
				$mla_title = $json_response['data'][0]['general']['title'];
				$mla_first_name = $json_response['data'][0]['general']['first_name'];
				$mla_last_name = $json_response['data'][0]['general']['last_name'];
				$mla_suffix = $json_response['data'][0]['general']['suffix'];
				$mla_email = $json_response['data'][0]['general']['email'];
				$mla_joined_commons = $json_response['data'][0]['general']['joined_commons'];
				$member_term = wpmn_get_terms( array( 'taxonomy' => 'hcommons_society_member_id', 'name' => 'mla_' . $mla_member_id ) );
				$ref_user_id = '';
				$mla_ref_user_id = '';
		if ( ! empty( $member_term ) ) {
			$ref_user_id = wpmn_get_objects_in_term( $member_term[0]->term_id, 'hcommons_society_member_id' );
		}
		if ( ! empty( $ref_user_id ) ) {
			$mla_ref_user_id = $ref_user_id[0];
		}

		return array(
				'mla_member_id' => $mla_member_id,
				'mla_username' => $mla_username,
				'mla_membership_status' => $mla_membership_status,
				'mla_expiring_date' => $mla_expiring_date,
				'mla_title' => $mla_title,
				'mla_first_name' => $mla_first_name,
				'mla_last_name' => $mla_last_name,
				'mla_suffix' => $mla_suffix,
				'mla_email' => $mla_email,
				'mla_joined_commons' => $mla_joined_commons,
				'mla_ref_user_id' => $mla_ref_user_id,
				);

	}

	/**
	 * Register member_id taxonomy.
	 */
	public function register_society_member_id_taxonomy() {
			// Add new taxonomy, NOT hierarchical (like tags).
			$labels = array(
					'name'                          => _x( 'Member ids', 'taxonomy general name' ),
					'singular_name'                 => _x( 'Member id', 'taxonomy singular name' ),
					'search_items'                  => null,
					'popular_items'                 => null,
					'all_items'                     => null,
					'parent_item'                   => null,
					'parent_item_colon'             => null,
					'edit_item'                     => null,
					'update_item'                   => null,
					'add_new_item'                  => null,
					'new_item_name'                 => null,
					'separate_items_with_commas'    => null,
					'add_or_remove_items'           => null,
					'choose_from_most_used'         => null,
					'not_found'                     => null,
					'menu_name'                     => null,
			);

			$args = array(
					'public'                        => false,
					'hierarchical'                  => false,
					'labels'                        => $labels,
					'show_ui'                       => false,
					'show_in_nav_menus'             => false,
					'show_admin_column'             => false,
					'update_count_callback'         => '_update_generic_term_count',
					'query_var'                     => 'society_member_id',
					'rewrite'                       => false,
			);

			register_taxonomy( 'hcommons_society_member_id', array( 'user' ), $args );
			register_taxonomy_for_object_type( 'hcommons_society_member_id', 'user' );

	}

	/**
	 * Close request.
	 */
	public function mla_hcommons_bp_before_member_settings_template() {
		$user = wp_get_current_user();

		$mla_member_data = $this->lookup_mla_member_data( $user->ID );

		if ( !empty($mla_member_data['mla_username']) && $mla_member_data['mla_username'] !== $user->user_login ) {
			echo sprintf( '<div id="changeUsername"><p><a href="." class="hc-username" data-username="%s" data-mla_user_id="%s" data-hc_user_id="%s">Set your MLA.org username to your HC username.</a></p></div><br>', $user->user_login, $mla_member_data['mla_member_id'], $user->ID );
	    }
	}

	/**
	 * Singleton
	 *
	 * Returns a single instance of the MLA_Hcommons class.
	 */
	public static function singleton() {

		if ( ! self::$instance ) {
			self::$instance = new MLA_Hcommons; }

		return self::$instance;
	}

	/**
	 * Add Actions
	 *
	 * Defines all the WordPress actions and filters used by this class.
	 */
	private function _add_actions() {

		add_action( 'init', array( $this, 'register_society_member_id_taxonomy' ) );
		add_action( 'wpmn_register_taxonomies', array( $this, 'register_society_member_id_taxonomy' ) );

		if ( class_exists( 'Humanities_Commons' ) && 'mla' === Humanities_Commons::$society_id ) {

			add_action( 'wp_ajax_change_username', array( $this, 'change_username' ) );
			add_action( 'bp_before_member_settings_template', array( $this, 'mla_hcommons_bp_before_member_settings_template' ) );
	    }

		wp_enqueue_script( 'hc-auth-js',plugins_url( '/js/hc-auth.js',__FILE__ ), array( 'jquery' ), '1.0', true );
		wp_enqueue_style( 'hc-auth-css',plugins_url( '/css/hc-auth.css',__FILE__ ), array(), '1.0' );
	}


	/**
	 * Build request URL according to MLA API specifications.
	 *
	 * @param string $http_method  HTTP request method, e.g., 'GET'.
	 * @param string $request_path HTTP request path, e.g., 'members/123'.
	 * @param array  $parameters   HTTP request parameters in key=>value array.
	 * @return string Final request URL.
	 */
	private function build_request_url( $http_method, $request_path, $parameters ) {

		// Append current time to request parameters (seconds from UNIX epoch).
		$parameters['key'] = $this->api_key;
		$parameters['timestamp'] = time();

		// Sort the request parameters.
		ksort( $parameters );

		// Collapse the parameters into a URI query string.
		$query_string = http_build_query( $parameters, '', '&' );

		// Add the request parameters to the base URL.
		$request_url = $this->api_url . $request_path . '?' . $query_string;

		// Compute the request signature (see specification).
		$hash_input = $http_method . '&' . rawurlencode( $request_url );
		$api_signature = hash_hmac( 'sha256', $hash_input, $this->api_secret );

		// Append the signature to the request.
		return $request_url . '&signature=' . $api_signature;
	}

	/**
	 * Close request.
	 *
	 * @param object $handler cURL handler.
	 */
	private function close_request( $handler ) {
		curl_close( $handler ); // @codingStandardsIgnoreLine WordPress.VIP.cURL
	}

	/**
	 * Create handler and set cURL options.
	 *
	 * @param string $http_method  HTTP method, e.g., 'GET'.
	 * @param string $request_url  HTTP request URL.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 * @return object cURL handler.
	 */
	private function create_request( $http_method, $request_url, $request_body ) {

		// @codingStandardsIgnoreStart WordPress.VIP.cURL
		$handler = curl_init();

		curl_setopt( $handler, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $handler, CURLOPT_FAILONERROR, false );
		curl_setopt( $handler, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $handler, CURLOPT_SSL_VERIFYHOST, 2 );

		// Set HTTP method.
		if ( 'PUT' === $http_method || 'DELETE' === $http_method ) {
			curl_setopt( $handler, CURLOPT_CUSTOMREQUEST, $http_method );
		} elseif ( 'POST' === $http_method ) {
			curl_setopt( $handler, CURLOPT_POST, 1 );
		}

		// Set HTTP headers.
		$headers = array( 'Accept: application/json' );

		// Add request body.
		if ( is_string( $request_body ) && strlen( $request_body ) ) {
			$headers[] = 'Content-Length: ' . strlen( $request_body );
			curl_setopt( $handler, CURLOPT_POSTFIELDS, $request_body );
		}

		curl_setopt( $handler, CURLOPT_HTTPHEADER, $headers );

		// Set final request URL.
		curl_setopt( $handler, CURLOPT_URL, $request_url );
		// @codingStandardsIgnoreEnd

		return $handler;

	}

	/**
	 * Validate API raw response and extract data.
	 *
	 * @param object $response Response object (decoded JSON) from MLA API.
	 * @param string $property Property name to validate (as array).
	 * @param bool   $singular Whether the API response should contain only one item.
	 * @return object Validated API response data.
	 * @throws \Exception Describes API error.
	 */
	private function extract_api_response_data( $response, $property, $singular = false ) {

		$this->validate_api_response( $response );

		if ( ! isset( $response->data ) || ! is_array( $response->data ) || count( $response->data ) < 1 ) {
			throw new \Exception( 'API returned no data.', 540 );
		}

		if ( $singular && count( $response->data ) > 1 ) {
			throw new \Exception( 'API response returned more than one item: ' . serialize( $response->data ), 550 );
		}

		if ( ! property_exists( $response->data[0], $property ) ) {
			throw new \Exception( 'API response did not contain property: ' . $property, 560 );
		}

		return $response->data[0];
	}

	/**
	 * Process request for various HTTP verbs.
	 *
	 * @param string $http_method  HTTP request method.
	 * @param string $request_path HTTP request path.
	 * @param array  $parameters   HTTP request parameters.
	 * @param string $request_body HTTP request body as stringifed JSON.
	 * @return object cURL handler.
	 */
	private function process_request( $http_method, $request_path, $parameters, $request_body ) {

		// Build request URL.
		$request_url = $this->build_request_url( $http_method, $request_path, $parameters );

		// Create cURL handler and set options.
		$curl_handler = $this->create_request( $http_method, $request_url, $request_body );

		// Send HTTP request.
		$response_text = $this->send_request( $curl_handler );
		$this->close_request( $curl_handler );

		// Parse response.
		return json_decode( $response_text );
	}

	/**
	 * Send request.
	 *
	 * @param object $handler cURL handler.
	 * @return string Response text.
	 * @throws \Exception Describes HTTP error.
	 */
	private function send_request( $handler ) {

		// Send request.
		// @codingStandardsIgnoreStart WordPress.VIP.cURL
		$response_text = curl_exec( $handler );
		$response_code = curl_getinfo( $handler, CURLINFO_HTTP_CODE );
		// @codingStandardsIgnoreEnd

		// Get cURL error if response is false.
		if ( false === $response_text || 200 !== $response_code ) {
			$this->logger->addError( 'HTTP response code ' . $response_code . ': ' . $response_text );
			throw new \Exception( 'HTTP response code ' . $response_code );
		}

		return $response_text;
	}

	/**
	 * Validate API raw response.
	 *
	 * @param object $response Response object (decoded JSON) from MLA API.
	 * @return bool True if response self-reports as successful.
	 * @throws \Exception Describes API error.
	 */
	private function validate_api_response( $response ) {

		// Check that response has the expected properties.
		if ( $response && isset( $response->meta, $response->meta->status, $response->meta->code ) ) {

			if ( 'success' !== strtolower( $response->meta->status ) ) {
				throw new \Exception( 'API returned non-success ' . $response->meta->status . ': ' . $response->meta->code, 510 );
			}

			if ( 'api-1000' !== strtolower( $response->meta->code ) ) {
				throw new \Exception( 'API returned error code: ' . $response->meta->code, 520 );
			}

			return true;
		}

		throw new \Exception( 'API returned malformed response: ' . serialize( $response ), 530 );
	}
}
