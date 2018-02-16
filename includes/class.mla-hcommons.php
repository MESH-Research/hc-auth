<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

require_once dirname( __FILE__ ) . '/' . 'functions.inc.php';

class Mla_Hcommons {

	private $api_url;
	private $api_key;
	private $api_parameters = array();
	private $api_secret;

	   public static function init() {
           $class = __CLASS__;
           new $class;
       }

        public function __construct() {

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

                add_action( 'init', array( $this, 'register_society_member_id_taxonomy' ) );
                add_action( 'wpmn_register_taxonomies', array( $this, 'register_society_member_id_taxonomy' ) );
                add_action( 'wp_ajax_change_username', array( $this, 'hc_auth_change_username') );

                wp_enqueue_script( 'hc-auth-js',plugins_url('/js/hc-auth.js',__FILE__), array( 'jquery' ), '2.0', true );

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
         * Change member's username via API.
         *
         * @param string $user_id      Member ID number.
         * @param string $new_username Requested new username.
         * @return bool True if response indicates success.
         */
        public function hc_auth_change_username() {

        	$username = $_POST['username'];
			$mla_user_id = $_POST['mla_user_id'];
			$hc_user_id = $_POST['hc_user_id'];
			$message = null;

        	if( $this->is_duplicate_username($username) ) {
    		     $message = "Sorry! This MLA.org username is not available. Email membership@mla.org for more information";
    	 	} else {
			  //Send request to API.
              	 $request_path = 'members/' . $mla_user_id . '/username';
              	 $request_body = '{"username":"' . $username . '"}';
              	 $response = $this->process_request( 'PUT', $request_path, array(), $request_body );

              	 update_user_meta($hc_user_id, 'mla_username', $username);

              	if ( $this->validate_api_response( $response ) ) {
			     	$message = "Username changed successfully";
			  	}

			}

			echo $message;
			die();
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
	 */
	public function lookup_mla_group_id( $group_name ) {

		$managed_group_names = get_transient( 'mla_managed_group_names' );

		if ( ! $managed_group_names ) {

			$bp = buddypress();
			global $wpdb;
			$managed_group_names = array();
			$all_groups = $wpdb->get_results( 'SELECT * FROM ' . $bp->table_prefix . 'bp_groups' );
			foreach ( $all_groups as $group ) {

				$society_id = bp_groups_get_group_type( $group->id, true );
				if ( 'mla' === $society_id ) {
					$oid = groups_get_groupmeta( $group->id, 'mla_oid' );
					if ( ! empty( $oid ) && in_array( substr( $oid, 0, 1 ), array( 'D', 'G', 'M' ) ) ) {
						$managed_group_names[strip_tags( stripslashes( $group->name ) )] = $group->id;
					}

				}

			}
			set_transient( 'mla_managed_group_names', $managed_group_names, 24 * HOUR_IN_SECONDS );
		}
		return $managed_group_names[$group_name];

	}

	/**
	 * Lookup member data
	 */
	public function lookup_mla_member_data( $user_id, $full_check = false ) {

		$request_body = '';
                $member_types = (array)bp_get_member_type( $user_id, false );
		if ( ! in_array( 'mla', $member_types ) && ! $full_check ) {
			return false;
		}
                $mla_oid = get_user_meta( $user_id, 'mla_oid', true );

                if ( ! empty( $mla_oid ) && ! $full_check ) {
                        return array( 'mla_member_id' => $mla_oid );
                }
		if ( ! empty( $mla_oid ) ) {
			$api_base_url = $this->api_url . 'members/' . $mla_oid; // add username
			// Generate a "signed" request URL.
			$api_request_url = generateRequest( 'GET', $api_base_url, $this->api_secret, $this->api_parameters );
			// Initiate request.
			$api_response = sendRequest( 'GET', $api_request_url, $request_body );
			//echo var_export( $api_response, true ), "\n";
			// Server response.
			$json_response = json_decode( $api_response['body'], true );
			$request_status = $json_response['meta']['code'];
		}
                if ( empty( $mla_oid ) || 'API-2100' === $request_status ) {
                        $user_emails = (array)maybe_unserialize( get_user_meta( $user_id, 'shib_email', true ) );
                        if ( empty( $user_emails ) ) {
                                $user_emails = array();
                                $user_emails[] = $user->user_email;
                        }
                        foreach( array_unique( $user_emails ) as $user_email ) {
				$this->api_parameters['membership_status'] = 'ALL';
                        	$this->api_parameters['email'] = $user_email;
                        	$api_base_url = $this->api_url . 'members/'; // search
                        	// Generate a "signed" request URL.
                        	$api_request_url = generateRequest( 'GET', $api_base_url, $this->api_secret, $this->api_parameters );
                        	// Initiate request.
                        	$api_response = sendRequest( 'GET', $api_request_url, $request_body );
                		//echo var_export( $api_response, true ), "\n";
                        	$json_response = json_decode( $api_response['body'], true );
                        	unset( $this->api_parameters['membership_status'] );
                        	unset( $this->api_parameters['email'] );
				if ( 'API-1000' === $json_response['meta']['code'] ) {
                                	$search_member_id = $json_response['data'][0]['search_results'][0]['id'];
					$api_base_url = $this->api_url . 'members/' . $search_member_id; // add member_id
                                	// Generate a "signed" request URL.
                                	$api_request_url = generateRequest( 'GET', $api_base_url, $this->api_secret, $this->api_parameters );
                                	// Initiate request.
					$api_response = sendRequest( 'GET', $api_request_url, $request_body );
					//echo var_export( $api_response, true ), "\n";
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
		//echo var_export( $member_term, true );
		$ref_user_id = '';
		$mla_ref_user_id = '';
		if ( ! empty( $member_term ) ) {
			$ref_user_id = wpmn_get_objects_in_term( $member_term[0]->term_id, 'hcommons_society_member_id' );
		}
		if ( ! empty( $ref_user_id ) ) {
			$mla_ref_user_id = $ref_user_id[0];
		}
		//echo $mla_member_id, ',', $mla_username, ',', $mla_membership_status, ',', $mla_expiring_date, ',', $mla_crossref_user_id, ',', $mla_joined_commons;
		return array( 'mla_member_id' => $mla_member_id,
				'mla_username' => $mla_username,
				'mla_membership_status' => $mla_membership_status,
				'mla_expiring_date' => $mla_expiring_date,
				'mla_title' => $mla_title,
				'mla_first_name' => $mla_first_name,
				'mla_last_name' => $mla_last_name,
				'mla_suffix' => $mla_suffix,
				'mla_email' => $mla_email,
				'mla_joined_commons' => $mla_joined_commons,
				'mla_ref_user_id' => $mla_ref_user_id );

	}

}
