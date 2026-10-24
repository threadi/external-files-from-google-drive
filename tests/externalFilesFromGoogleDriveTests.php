<?php
/**
 * File to handle the main object for each test class.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests;

use ExternalFilesFromGoogleDrive\Dependencies\Google\Client;
use ExternalFilesFromGoogleDrive\Dependencies\GuzzleHttp\HandlerStack;
use ExternalFilesFromGoogleDrive\Dependencies\GuzzleHttp\Promise\Create;
use ExternalFilesFromGoogleDrive\Dependencies\GuzzleHttp\Psr7\Response;
use ExternalFilesFromGoogleDrive\Dependencies\Psr\Http\Message\RequestInterface;
use ExternalFilesFromGoogleDrive\GoogleDrive;
use ReflectionClass;
use WP_Error;
use WP_HTTP_Requests_Response;
use WP_UnitTestCase;

/**
 * Object to handle the preparations for each test class.
 */
abstract class externalFilesFromGoogleDriveTests extends WP_UnitTestCase {
	/**
	 * The base URL of the Google Drive API.
	 *
	 * @var string
	 */
	protected string $api_url = 'https://www.googleapis.com/drive/v3/';

	/**
	 * List of HTTP responses the actual test expects, with the URL as index.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $http_responses = array();

	/**
	 * List of HTTP requests via WordPress during the actual test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $http_requests = array();

	/**
	 * List of responses for requests to the Google API during the actual test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $google_responses = array();

	/**
	 * List of requests to the Google API during the actual test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $google_requests = array();

	/**
	 * Prepare the test environment for each test class.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// prepare to load just one time.
		if ( ! did_action( 'efmlgd_test_preparation_loaded' ) ) {
			// enable error reporting.
			error_reporting( E_ALL );

			// enable the extended log mode.
			update_option( 'eml_log_mode', 2 );

			// initialize the main plugin.
			\ExternalFilesInMediaLibrary\Plugin\Install::get_instance()->activation();

			// initialize this plugin (adds the capability for Google Drive).
			\ExternalFilesFromGoogleDrive\Plugin\Init::get_instance()->activation();

			// start with an empty log, as its table is kept between test runs.
			\ExternalFilesInMediaLibrary\Plugin\Log::get_instance()->truncate_log();

			// the service only registers its hooks for users with the capability for it, so we initialize it as admin.
			wp_set_current_user( 1 );
			GoogleDrive::get_instance()->init();

			// run initialization.
			do_action( 'init' );
			wp_set_current_user( 0 );

			// prevent any HTTP requests to external sources.
			add_filter( 'pre_http_request', array( self::class, 'filter_http_requests' ), 10, 3 );

			// prevent sending of emails.
			add_filter( 'wp_mail', array( self::class, 'filter_wp_mail' ) );

			// mark as loaded.
			do_action( 'efmlgd_test_preparation_loaded' );
		}
	}

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// the GoogleDrive object is a singleton, so we reset its state before each test.
		$this->reset_google_drive();

		// reset the lists for the mocks.
		self::$http_responses   = array();
		self::$http_requests    = array();
		self::$google_responses = array();
		self::$google_requests  = array();

		// answer all requests to the Google API locally (the hooks are restored after each test).
		add_filter( 'efmlgd_google_drive_client', array( self::class, 'replace_http_client' ), PHP_INT_MAX );
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->do_logout();
		parent::tear_down();
	}

	/**
	 * Set an HTTP client on the Google Client, which answers all requests locally.
	 *
	 * @param Client $client The Google Client.
	 *
	 * @return Client
	 */
	public static function replace_http_client( Client $client ): Client {
		$client->setHttpClient(
			new \ExternalFilesFromGoogleDrive\Dependencies\GuzzleHttp\Client(
				array(
					'handler'     => HandlerStack::create( array( self::class, 'handle_google_request' ) ),
					'http_errors' => false,
				)
			)
		);
		return $client;
	}

	/**
	 * Answer a request to the Google API with the configured response.
	 *
	 * @param RequestInterface    $request The request.
	 * @param array<string,mixed> $options The options.
	 *
	 * @return \ExternalFilesFromGoogleDrive\Dependencies\GuzzleHttp\Promise\PromiseInterface
	 */
	public static function handle_google_request( RequestInterface $request, array $options ): \ExternalFilesFromGoogleDrive\Dependencies\GuzzleHttp\Promise\PromiseInterface {
		// get the query.
		$query = array();
		parse_str( $request->getUri()->getQuery(), $query );

		// get the URL without query.
		$url = (string) $request->getUri()->withQuery( '' );

		// log the request.
		self::$google_requests[] = array(
			'method'  => $request->getMethod(),
			'url'     => $url,
			'query'   => $query,
			'headers' => $request->getHeaders(),
			'body'    => (string) $request->getBody(),
		);

		// search the response for this request.
		foreach ( self::$google_responses as $response ) {
			// bail if method or URL does not match.
			if ( $response['method'] !== $request->getMethod() || $response['url'] !== $url ) {
				continue;
			}

			// bail if the query does not match.
			if ( array_intersect_assoc( $response['query'], $query ) !== $response['query'] ) {
				continue;
			}

			// return the response.
			return Create::promiseFor( new Response( $response['status'], $response['headers'], $response['body'] ) );
		}

		// show error if no response is configured for this request.
		self::fail( 'Trying a request to the Google API, which is not configured in the test: ' . $request->getMethod() . ' ' . $request->getUri() );
	}

	/**
	 * Set the response for a request to the Google API.
	 *
	 * The first matching response is used.
	 *
	 * @param string               $method  The HTTP method.
	 * @param string               $url     The URL without query. Relative URLs are relative to the Drive API.
	 * @param int                  $status  The HTTP status.
	 * @param array<mixed>|string  $body    The body (arrays will be converted to JSON).
	 * @param array<string,string> $query   Query params the request must contain.
	 * @param array<string,string> $headers The response headers.
	 *
	 * @return void
	 */
	protected function add_google_response( string $method, string $url, int $status, array|string $body = '', array $query = array(), array $headers = array() ): void {
		// add the API URL to relative URLs.
		if ( ! str_starts_with( $url, 'https://' ) ) {
			$url = $this->api_url . $url;
		}

		// convert arrays to JSON.
		if ( is_array( $body ) ) {
			$body                    = (string) wp_json_encode( $body );
			$headers['Content-Type'] = 'application/json; charset=UTF-8';
		}

		self::$google_responses[] = array(
			'method'  => $method,
			'url'     => $url,
			'query'   => $query,
			'status'  => $status,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * Set the responses for a single file in Google Drive: the metadata, the content and the permissions.
	 *
	 * @param string $file_id   The file ID.
	 * @param string $name      The file name.
	 * @param string $content   The file content.
	 * @param bool   $is_public Whether the file is public.
	 *
	 * @return void
	 */
	protected function add_google_file( string $file_id, string $name, string $content, bool $is_public = false ): void {
		$this->add_google_response( 'GET', 'files/' . $file_id, 200, $content, array( 'alt' => 'media' ) );
		$this->add_google_response(
			'GET',
			'files/' . $file_id,
			200,
			array(
				'id'          => $file_id,
				'name'        => $name,
				'createdTime' => '2024-01-02T03:04:05.000Z',
			)
		);
		$permissions = array(
			array(
				'type' => 'user',
				'role' => 'owner',
			),
		);
		if ( $is_public ) {
			$permissions[] = array(
				'type' => 'anyone',
				'role' => 'reader',
			);
		}
		$this->add_google_response( 'GET', 'files/' . $file_id . '/permissions', 200, array( 'permissions' => $permissions ) );
	}

	/**
	 * Return the requests to the Google API during the actual test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_google_requests(): array {
		return self::$google_requests;
	}

	/**
	 * Filter any HTTP request during the tests to prevent any external communication.
	 *
	 * Tests can set the response for a specific URL with add_http_response().
	 *
	 * @param false|array|WP_Error $response    The return value.
	 * @param array                $parsed_args The used parameters.
	 * @param string               $url         The requested URL.
	 *
	 * @return false|array|WP_Error
	 */
	public static function filter_http_requests( false|array|WP_Error $response, array $parsed_args, string $url ): false|array|WP_Error {
		// let requests through, which a test has already answered.
		if ( false !== $response ) {
			return $response;
		}

		// log the request.
		self::$http_requests[] = array(
			'url'    => $url,
			'method' => isset( $parsed_args['method'] ) ? (string) $parsed_args['method'] : 'GET',
			'args'   => $parsed_args,
		);

		// show error if no response is configured for this URL, as we do not want any external URL connection during PHP Unit Tests.
		if ( ! isset( self::$http_responses[ $url ] ) ) {
			self::fail( 'Trying an external URL connection, which should not happen during PHP Unit Tests. Used URL: ' . $url );
		}

		// return the configured response.
		$configured_response = self::$http_responses[ $url ];
		if ( $configured_response['code'] instanceof WP_Error ) {
			return $configured_response['code'];
		}
		return self::get_http_response( $url, $configured_response['code'], $configured_response['headers'], $configured_response['body'] );
	}

	/**
	 * Filter any email communication from WordPress.
	 *
	 * @param array $args The used arguments.
	 *
	 * @return array
	 */
	public static function filter_wp_mail( array $args ): array {
		$args['to'] = 'info@example.com';
		return $args;
	}

	/**
	 * Set the response for an HTTP request via WordPress on the given URL for the actual test.
	 *
	 * @param string               $url         The URL.
	 * @param int|WP_Error         $status_code The HTTP status code or an error.
	 * @param array<string,string> $headers     The response headers.
	 * @param string               $body        The response body.
	 *
	 * @return void
	 */
	protected function add_http_response( string $url, int|WP_Error $status_code, array $headers = array(), string $body = '' ): void {
		self::$http_responses[ $url ] = array(
			'code'    => $status_code,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * Return the list of HTTP requests via WordPress during the actual test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_http_requests(): array {
		return self::$http_requests;
	}

	/**
	 * Return a response array, like WordPress returns it for an HTTP request.
	 *
	 * @param string $url         The requested URL.
	 * @param int    $status_code The HTTP status code.
	 * @param array  $headers     The response headers.
	 * @param string $body        The response body.
	 *
	 * @return array
	 */
	protected static function get_http_response( string $url, int $status_code, array $headers = array(), string $body = '' ): array {
		$requests_response              = new \WpOrg\Requests\Response();
		$requests_response->status_code = $status_code;
		$requests_response->url         = $url;
		$requests_response->body        = $body;
		$requests_response->headers     = new \WpOrg\Requests\Response\Headers( $headers );
		$requests_response->success     = $status_code < 400;

		$http_response             = new WP_HTTP_Requests_Response( $requests_response, null );
		$response                  = $http_response->to_array();
		$response['http_response'] = $http_response;
		return $response;
	}

	/**
	 * Return a valid access token.
	 *
	 * @param array<string,mixed> $values Values to change in the token.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_access_token( array $values = array() ): array {
		return array_merge(
			array(
				'access_token'  => 'test-access-token',
				'expires_in'    => 3599,
				'refresh_token' => 'test-refresh-token',
				'scope'         => 'https://www.googleapis.com/auth/drive',
				'token_type'    => 'Bearer',
				'created'       => time(),
			),
			$values
		);
	}

	/**
	 * Set the mode, where the credentials are stored.
	 *
	 * @param string $mode The mode (global, user, manually or direct_input).
	 *
	 * @return void
	 */
	protected function set_mode( string $mode ): void {
		update_option( 'eml_google-drive_credentials_vault', $mode );
	}

	/**
	 * Login as admin and save a valid access token for the global mode.
	 *
	 * @param array<string,mixed> $values Values to change in the token.
	 *
	 * @return void
	 */
	protected function connect( array $values = array() ): void {
		$this->do_login();
		$this->set_mode( 'global' );
		update_option( 'eml_google_drive_access_tokens', $this->get_access_token( $values ) );
	}

	/**
	 * Reset the state of the GoogleDrive object.
	 *
	 * @return void
	 */
	protected function reset_google_drive(): void {
		$google_drive = GoogleDrive::get_instance();
		$this->set_property( $google_drive, 'fields', array() );
		$this->set_property( $google_drive, 'directory', '' );
		$this->set_property( $google_drive, 'errors', array() );
		$this->set_property( $google_drive, 'user', false );
	}

	/**
	 * Set the value of a (not public) property of the given object.
	 *
	 * @param object $obj      The object.
	 * @param string $property The name of the property.
	 * @param mixed  $value    The value.
	 *
	 * @return void
	 */
	protected function set_property( object $obj, string $property, mixed $value ): void {
		$reflection = new ReflectionClass( $obj );
		while ( ! $reflection->hasProperty( $property ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$reflection->getProperty( $property )->setValue( $obj, $value );
	}

	/**
	 * Call a not public method of the given object.
	 *
	 * @param object           $obj    The object.
	 * @param string           $method The name of the method.
	 * @param array<int,mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	protected function call_method( object $obj, string $method, array $args = array() ): mixed {
		return ( new \ReflectionMethod( $obj, $method ) )->invokeArgs( $obj, $args );
	}

	/**
	 * Return the error messages of the GoogleDrive object.
	 *
	 * @return array<int,string>
	 */
	protected function get_error_messages(): array {
		$messages = array();
		foreach ( GoogleDrive::get_instance()->get_errors() as $error ) {
			$messages = array_merge( $messages, $error->get_error_messages() );
		}
		return $messages;
	}

	/**
	 * Return the log entries with the given URL, the newest first.
	 *
	 * @param string $url The URL.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_log_entries( string $url = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eml_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT `url`, `log`, `state` FROM `$table` WHERE `url` = %s ORDER BY `id` DESC", $url ), ARRAY_A );
	}

	/**
	 * Login in this session as admin.
	 *
	 * @return void
	 */
	protected function do_login(): void {
		wp_set_current_user( 1, 'admin' );
	}

	/**
	 * Logout from this session.
	 *
	 * @return void
	 */
	protected function do_logout(): void {
		wp_set_current_user( 0 );
	}

	/**
	 * Create an attachment with a local file and return its ID.
	 *
	 * @param string $file_name The name of the file in tests/Data/.
	 *
	 * @return int
	 */
	protected function create_local_attachment( string $file_name = 'example.jpg' ): int {
		return (int) self::factory()->attachment->create_upload_object( UNIT_TESTS_DATA_PLUGIN_DIR . $file_name );
	}
}
