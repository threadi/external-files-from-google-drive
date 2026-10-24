<?php
/**
 * Tests for class ExternalFilesFromGoogleDrive\GoogleDrive.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit;

use easyDirectoryListingForWordPress\Crypt;
use ExternalFilesFromGoogleDrive\Dependencies\Google\Service\Drive;
use ExternalFilesFromGoogleDrive\Dependencies\Google\Service\Drive\DriveFile;
use ExternalFilesFromGoogleDrive\GoogleDrive\Client;
use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;

/**
 * Object to test functions in class ExternalFilesFromGoogleDrive\GoogleDrive.
 */
class GoogleDrive extends externalFilesFromGoogleDriveTests {
	/**
	 * Return the GoogleDrive object.
	 *
	 * @return \ExternalFilesFromGoogleDrive\GoogleDrive
	 */
	private function get_google_drive(): \ExternalFilesFromGoogleDrive\GoogleDrive {
		return \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance();
	}

	/**
	 * Return a Drive object with a client, which answers all requests locally.
	 *
	 * @return Drive
	 */
	private function get_drive_service(): Drive {
		$client = ( new Client( $this->get_access_token() ) )->get_client();
		$this->assertInstanceOf( \ExternalFilesFromGoogleDrive\Dependencies\Google\Client::class, $client );
		return new Drive( $client );
	}

	/**
	 * Save the user settings for the actual user like the main plugin does it.
	 *
	 * @param array<string,string> $settings The settings.
	 *
	 * @return void
	 */
	private function save_user_settings( array $settings ): void {
		foreach ( $settings as $name => $value ) {
			update_user_meta( get_current_user_id(), 'efml_' . $name, Crypt::get_instance()->encrypt( $value ) );
		}
	}

	/**
	 * Test if the returned object is always the same and is a service of the main plugin.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$google_drive = $this->get_google_drive();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\Services\Service_Base::class, $google_drive );
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\Services\Service::class, $google_drive );
		$this->assertSame( $google_drive, \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance() );
		$this->assertEquals( 'google-drive', $google_drive->get_name() );
		$this->assertEquals( 'Google Drive', $google_drive->get_label() );
		$this->assertEquals( 'https://drive.google.com/drive/', $google_drive->get_url_mark() );
		$this->assertEquals( 'https://drive.google.com/drive/abc', $google_drive->get_url( 'abc' ) );
	}

	/**
	 * Test if the hooks of the service are registered.
	 *
	 * @return void
	 */
	public function test_init(): void {
		$google_drive = $this->get_google_drive();
		$this->assertIsInt( has_filter( 'efml_protocols', array( $google_drive, 'add_protocol' ) ) );
		$this->assertIsInt( has_filter( 'efml_prevent_import', array( $google_drive, 'check_url' ) ) );
		$this->assertIsInt( has_filter( 'efml_service_modes', array( $google_drive, 'add_direct_input_mode' ) ) );

		// the filters must use the names of the hooks the plugin is using.
		$this->assertIsInt( has_filter( 'efmlgd_google_drive_query_params', array( $google_drive, 'set_query_params' ) ) );
		$this->assertIsInt( has_filter( 'efmlgd_google_drive_hide_file', array( $google_drive, 'prevent_not_allowed_files' ) ) );
	}

	/**
	 * Test the access token in the different modes.
	 *
	 * @return void
	 */
	public function test_get_access_token(): void {
		$google_drive = $this->get_google_drive();
		$token        = $this->get_access_token();
		$this->do_login();

		// test 1: global mode.
		$this->set_mode( 'global' );
		$this->assertEmpty( $google_drive->get_access_token() );
		update_option( 'eml_google_drive_access_tokens', $token );
		$this->assertEquals( $token, $google_drive->get_access_token() );

		// test 2: direct input uses the global token.
		$this->set_mode( 'direct_input' );
		$this->assertEquals( $token, $google_drive->get_access_token() );

		// test 3: user mode does not use the global token, but the encrypted token of the user.
		$this->set_mode( 'user' );
		$this->assertEmpty( $google_drive->get_access_token() );
		update_user_meta( 1, 'efml_google_drive_access_tokens', Crypt::get_instance()->encrypt( (string) wp_json_encode( $token ) ) );
		$this->assertEquals( $token, $google_drive->get_access_token() );

		// test 4: manual mode uses also the token of the user.
		$this->set_mode( 'manually' );
		$this->assertEquals( $token, $google_drive->get_access_token() );

		// test 5: the token of the user is not a valid JSON.
		update_user_meta( 1, 'efml_google_drive_access_tokens', Crypt::get_instance()->encrypt( 'no json' ) );
		$this->assertEmpty( $google_drive->get_access_token() );

		// test 6: unknown mode.
		$this->set_mode( 'unknown' );
		$this->assertEmpty( $google_drive->get_access_token() );
	}

	/**
	 * Test the saving and deletion of the access token in the global mode.
	 *
	 * @return void
	 */
	public function test_set_and_delete_access_token_global(): void {
		$google_drive = $this->get_google_drive();
		$token        = $this->get_access_token();
		$this->set_mode( 'global' );

		$google_drive->set_access_token( $token );
		$this->assertEquals( $token, get_option( 'eml_google_drive_access_tokens' ) );

		$this->assertTrue( $google_drive->delete_access_token() );
		$this->assertEmpty( get_option( 'eml_google_drive_access_tokens' ) );
	}

	/**
	 * Test the saving and deletion of the access token in the user mode.
	 *
	 * @return void
	 */
	public function test_set_and_delete_access_token_user(): void {
		$google_drive = $this->get_google_drive();
		$token        = $this->get_access_token();
		$this->set_mode( 'user' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// test 1: for the actual user.
		$this->do_login();
		$google_drive->set_access_token( $token );
		$this->assertNotEmpty( get_user_meta( 1, 'efml_google_drive_access_tokens', true ) );
		$this->assertStringNotContainsString( 'test-access-token', get_user_meta( 1, 'efml_google_drive_access_tokens', true ) );
		$this->assertEquals( $token, $google_drive->get_access_token() );
		$this->assertEmpty( get_option( 'eml_google_drive_access_tokens' ) );

		// test 2: for another user.
		$google_drive->set_access_token( $token, $user_id );
		$this->assertNotEmpty( get_user_meta( $user_id, 'efml_google_drive_access_tokens', true ) );

		// test 3: for a not existing user.
		$google_drive->set_access_token( $token, 999999 );
		$this->assertEmpty( get_user_meta( 999999, 'efml_google_drive_access_tokens', true ) );

		// test 4: delete the token of the actual user.
		$this->assertTrue( $google_drive->delete_access_token() );
		$this->assertEmpty( get_user_meta( 1, 'efml_google_drive_access_tokens', true ) );
		$this->assertNotEmpty( get_user_meta( $user_id, 'efml_google_drive_access_tokens', true ) );
	}

	/**
	 * Test if the protocol is only added if an access token is set.
	 *
	 * @return void
	 */
	public function test_add_protocol(): void {
		$this->do_login();
		$this->set_mode( 'global' );

		// test 1: without access token.
		$this->assertEquals( array( 'Example\Protocol' ), $this->get_google_drive()->add_protocol( array( 'Example\Protocol' ) ) );

		// test 2: with access token the protocol is added as first.
		$this->connect();
		$protocols = $this->get_google_drive()->add_protocol( array( 'Example\Protocol' ) );
		$this->assertEquals( array( 'ExternalFilesFromGoogleDrive\GoogleDrive\Protocol', 'Example\Protocol' ), $protocols );
		$this->assertTrue( class_exists( $protocols[0] ) );
	}

	/**
	 * Test the check of Google URLs, which cannot be used.
	 *
	 * @return void
	 */
	public function test_check_url(): void {
		$google_drive = $this->get_google_drive();

		// test 1: no Google URL.
		$this->assertFalse( $google_drive->check_url( false, 'https://example.com/file.jpg' ) );
		$this->assertTrue( $google_drive->check_url( true, 'https://example.com/file.jpg' ) );

		// test 2: a usable Google URL.
		$this->assertFalse( $google_drive->check_url( false, 'https://drive.google.com/drive/abc' ) );

		// test 3: a not usable Google URL.
		$this->assertTrue( $google_drive->check_url( false, 'https://drive.google.com/file/d/abc/view' ) );
		$this->assertNotEmpty( $this->get_log_entries( 'https://drive.google.com/file/d/abc/view' ) );
	}

	/**
	 * Test the directory.
	 *
	 * @return void
	 */
	public function test_get_directory(): void {
		$google_drive = $this->get_google_drive();
		$this->assertEquals( 'Google Drive Directory Listing', $google_drive->get_directory() );
		$google_drive->set_directory( 'abc/' );
		$this->assertEquals( 'abc/', $google_drive->get_directory() );
	}

	/**
	 * Test the query params in the global mode.
	 *
	 * @return void
	 */
	public function test_set_query_params_global(): void {
		$google_drive = $this->get_google_drive();
		$this->do_login();
		$this->set_mode( 'global' );

		// test 1: without trashed and shared files.
		$query = $google_drive->set_query_params( array( 'pageSize' => 1000 ) );
		$this->assertEquals( 1000, $query['pageSize'] );
		$this->assertEquals( "trashed = false and 'me' in owners", $query['q'] );

		// test 2: with trashed and shared files.
		update_option( 'eml_google_drive_show_trashed', 1 );
		update_option( 'eml_google_drive_show_shared', 1 );
		$this->assertEquals( '', $google_drive->set_query_params( array() )['q'] );

		// test 3: with a directory.
		$google_drive->set_directory( 'folder123/' );
		$query = $google_drive->set_query_params( array() );
		$this->assertEquals( "'folder123' in parents", $query['q'] );
		$this->assertTrue( $query['includeItemsFromAllDrives'] );
		$this->assertTrue( $query['supportsAllDrives'] );

		// test 4: the main directory is not used as parent.
		$google_drive->set_directory( 'Google Drive Directory Listing' );
		$this->assertEquals( '', $google_drive->set_query_params( array() )['q'] );
	}

	/**
	 * Test the query params in the user mode, which uses the settings saved in the profile of the user.
	 *
	 * @return void
	 */
	public function test_set_query_params_user(): void {
		$google_drive = $this->get_google_drive();
		$this->do_login();
		$this->set_mode( 'user' );

		// the global settings are not used in the user mode.
		update_option( 'eml_google_drive_show_trashed', 1 );
		update_option( 'eml_google_drive_show_shared', 1 );

		// test 1: without trashed and shared files.
		$this->assertEquals( "trashed = false and 'me' in owners", $google_drive->set_query_params( array() )['q'] );

		// test 2: the user has disabled both settings.
		$this->save_user_settings(
			array(
				'google_drive_show_trashed' => '0',
				'google_drive_show_shared'  => '0',
			)
		);
		$this->assertEquals( "trashed = false and 'me' in owners", $google_drive->set_query_params( array() )['q'] );

		// test 3: the user has enabled both settings in the profile.
		$this->save_user_settings(
			array(
				'google_drive_show_trashed' => '1',
				'google_drive_show_shared'  => '1',
			)
		);
		$this->assertEquals( '', $google_drive->set_query_params( array() )['q'] );
	}

	/**
	 * Test the directory listing.
	 *
	 * @return void
	 */
	public function test_get_directory_listing(): void {
		$this->connect();
		$this->add_google_response(
			'GET',
			'files',
			200,
			array(
				'files' => array(
					array(
						'id'           => 'file1',
						'name'         => 'example.jpg',
						'mimeType'     => 'image/jpeg',
						'parents'      => array( 'folder1' ),
						'size'         => '706',
						'createdTime'  => '2024-01-02T03:04:05.000Z',
						'iconLink'     => 'https://drive-thirdparty.googleusercontent.com/16/type/image/jpeg',
						'hasThumbnail' => false,
					),
					array(
						'id'          => 'file2',
						'name'        => 'document.pdf',
						'mimeType'    => 'application/pdf',
						'size'        => '1024',
						'createdTime' => '2024-01-02T03:04:05.000Z',
					),
					array(
						'id'          => 'file3',
						'name'        => 'program.unknownextension',
						'mimeType'    => 'application/octet-stream',
						'createdTime' => '2024-01-02T03:04:05.000Z',
					),
				),
			)
		);
		$this->add_google_response(
			'GET',
			'files/folder1',
			200,
			array(
				'id'   => 'folder1',
				'name' => 'Photos',
			)
		);

		$listing = $this->get_google_drive()->get_directory_listing( 'Google Drive Directory Listing' );

		// check the main directory with the file without parents.
		$this->assertArrayHasKey( 'Google Drive Directory Listing', $listing );
		$this->assertCount( 1, $listing['Google Drive Directory Listing']['files'] );
		$this->assertEquals( 'document.pdf', $listing['Google Drive Directory Listing']['files'][0]['title'] );
		$this->assertEquals( 'application/pdf', $listing['Google Drive Directory Listing']['files'][0]['mime-type'] );
		$this->assertEquals( 1024, $listing['Google Drive Directory Listing']['files'][0]['filesize'] );
		$this->assertArrayHasKey( 'folder1/', $listing['Google Drive Directory Listing']['dirs'] );

		// check the folder with its file.
		$this->assertArrayHasKey( 'folder1/', $listing );
		$this->assertEquals( 'Photos', $listing['folder1/']['title'] );
		$this->assertCount( 1, $listing['folder1/']['files'] );
		$this->assertEquals( 'file1', $listing['folder1/']['files'][0]['file'] );
		$this->assertEquals( 'image/jpeg', $listing['folder1/']['files'][0]['mime-type'] );

		// check the request: the query params of the settings must be used.
		$requests = $this->get_google_requests();
		$this->assertEquals( $this->api_url . 'files', $requests[0]['url'] );
		$this->assertEquals( "trashed = false and 'me' in owners", $requests[0]['query']['q'] );
		$this->assertEquals( 'Bearer test-access-token', $requests[0]['headers']['authorization'][0] ?? $requests[0]['headers']['Authorization'][0] );
	}

	/**
	 * Test if files are hidden in the directory listing, which are not allowed.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_hides_not_allowed_files(): void {
		$this->connect();
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 1 );
		update_option( 'eml_allowed_mime_types', array( 'image/jpeg' ) );
		$this->add_google_response(
			'GET',
			'files',
			200,
			array(
				'files' => array(
					array(
						'id'          => 'file1',
						'name'        => 'example.jpg',
						'createdTime' => '2024-01-02T03:04:05.000Z',
					),
					array(
						'id'          => 'file2',
						'name'        => 'document.pdf',
						'createdTime' => '2024-01-02T03:04:05.000Z',
					),
				),
			)
		);

		$listing = $this->get_google_drive()->get_directory_listing( 'Google Drive Directory Listing' );

		$this->assertCount( 1, $listing['Google Drive Directory Listing']['files'] );
		$this->assertEquals( 'example.jpg', $listing['Google Drive Directory Listing']['files'][0]['title'] );
	}

	/**
	 * Test the directory listing, if Google Drive returns an error or no files.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_with_error(): void {
		// test 1: without access token.
		$this->do_login();
		$this->set_mode( 'global' );
		$this->assertEquals( array(), $this->get_google_drive()->get_directory_listing( 'Google Drive Directory Listing' ) );

		// test 2: Google Drive returns an error.
		$this->connect();
		$this->add_google_response(
			'GET',
			'files',
			403,
			array(
				'error' => array(
					'code'    => 403,
					'message' => 'Forbidden',
					'errors'  => array(
						array(
							'message' => 'Forbidden',
							'reason'  => 'forbidden',
						),
					),
				),
			)
		);
		$this->assertEquals( array(), $this->get_google_drive()->get_directory_listing( 'Google Drive Directory Listing' ) );
		$this->assertStringContainsString( 'List of files could not be loaded', $this->get_log_entries()[0]['log'] );
	}

	/**
	 * Test the directory listing without files.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_without_files(): void {
		$this->connect();
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array() ) );
		$this->assertEquals( array(), $this->get_google_drive()->get_directory_listing( 'Google Drive Directory Listing' ) );
	}

	/**
	 * Test the filter for not allowed files.
	 *
	 * @return void
	 */
	public function test_prevent_not_allowed_files(): void {
		$google_drive = $this->get_google_drive();
		$file         = new DriveFile( array( 'name' => 'document.pdf' ) );
		update_option( 'eml_allowed_mime_types', array( 'image/jpeg' ) );

		// test 1: the setting is disabled.
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 0 );
		$this->assertFalse( $google_drive->prevent_not_allowed_files( false, $file, '' ) );

		// test 2: the setting is enabled.
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 1 );
		$this->assertTrue( $google_drive->prevent_not_allowed_files( false, $file, '' ) );
		$this->assertFalse( $google_drive->prevent_not_allowed_files( false, new DriveFile( array( 'name' => 'example.jpg' ) ), '' ) );
	}

	/**
	 * Test the filter for the requested directory.
	 *
	 * @return void
	 */
	public function test_filter_on_directory_request(): void {
		$google_drive = $this->get_google_drive();
		$listing      = array(
			'Google Drive Directory Listing' => array(
				'title' => 'Google Drive Directory Listing',
				'files' => array(),
				'dirs'  => array(),
			),
			'folder1/'                       => array(
				'title' => 'Photos',
				'files' => array( array( 'title' => 'example.jpg' ) ),
				'dirs'  => array(),
			),
		);

		// test 1: other service.
		$this->assertEquals( $listing, $google_drive->filter_on_directory_request( $listing, 'folder1/', 'other' ) );

		// test 2: the main directory.
		$this->assertEquals( $listing, $google_drive->filter_on_directory_request( $listing, 'Google Drive Directory Listing', 'google-drive' ) );

		// test 3: a sub-directory.
		$result = $google_drive->filter_on_directory_request( $listing, 'folder1/', 'google-drive' );
		$this->assertCount( 1, $result );
		$this->assertCount( 1, $result['folder1/']['files'] );

		// test 4: an unknown directory.
		$this->assertEquals( array(), $google_drive->filter_on_directory_request( $listing, 'unknown/', 'google-drive' ) );
	}

	/**
	 * Test the client with a valid access token.
	 *
	 * @return void
	 */
	public function test_get_client(): void {
		// test 1: without access token.
		$this->assertFalse( ( new Client( array() ) )->get_client() );

		// test 2: with a valid access token.
		$client_obj = new Client( $this->get_access_token() );
		$this->assertInstanceOf( \ExternalFilesFromGoogleDrive\Dependencies\Google\Client::class, $client_obj->get_client() );
		$this->assertFalse( $client_obj->has_token_refreshed() );
		$this->assertEmpty( $this->get_http_requests() );
	}

	/**
	 * Test the refresh of an expired access token via the service of the plugin developer.
	 *
	 * @return void
	 */
	public function test_get_client_with_expired_token(): void {
		$this->connect( array( 'created' => time() - DAY_IN_SECONDS ) );
		$new_token = $this->get_access_token( array( 'access_token' => 'new-access-token' ) );
		$this->add_http_response( EFML_GOOGLE_OAUTH_REFRESH_URL . '?refresh_token=test-refresh-token', 200, array(), (string) wp_json_encode( $new_token ) );

		$client_obj = new Client( $this->get_google_drive()->get_access_token() );
		$client     = $client_obj->get_client();

		$this->assertInstanceOf( \ExternalFilesFromGoogleDrive\Dependencies\Google\Client::class, $client );
		$this->assertTrue( $client_obj->has_token_refreshed() );
		$this->assertEquals( 'new-access-token', $client->getAccessToken()['access_token'] );

		// the new token is saved.
		$this->assertEquals( $new_token, get_option( 'eml_google_drive_access_tokens' ) );
	}

	/**
	 * Test the refresh of an expired access token, if the service returns an error.
	 *
	 * @return void
	 */
	public function test_get_client_with_expired_token_and_error(): void {
		$url = EFML_GOOGLE_OAUTH_REFRESH_URL . '?refresh_token=test-refresh-token';

		// test 1: the service returns an HTTP error.
		$this->connect( array( 'created' => time() - DAY_IN_SECONDS ) );
		$this->add_http_response( $url, 500 );
		$this->assertFalse( ( new Client( $this->get_google_drive()->get_access_token() ) )->get_client() );
		$this->assertNotEmpty( get_option( 'eml_google_drive_access_tokens' ) );

		// test 2: the request fails.
		$this->add_http_response( $url, new \WP_Error( 'http_request_failed', 'Timeout' ) );
		$this->assertFalse( ( new Client( $this->get_google_drive()->get_access_token() ) )->get_client() );

		// test 3: the service returns an error from Google, which removes the token.
		$this->add_http_response( $url, 200, array(), (string) wp_json_encode( array( 'error' => 'invalid_grant' ) ) );
		$this->assertFalse( ( new Client( $this->get_google_drive()->get_access_token() ) )->get_client() );
		$this->assertEmpty( get_option( 'eml_google_drive_access_tokens' ) );
	}

	/**
	 * Test the refresh of an expired access token in the direct input mode, which requests Google directly.
	 *
	 * @return void
	 */
	public function test_get_client_with_expired_token_in_direct_input_mode(): void {
		$this->connect( array( 'created' => time() - DAY_IN_SECONDS ) );
		$this->set_mode( 'direct_input' );
		update_option( 'eml_google_drive_credential_client_id', $this->get_google_drive()->encrypt_value( 'my-client-id' ) );
		update_option( 'eml_google_drive_credential_client_secret', $this->get_google_drive()->encrypt_value( 'my-client-secret' ) );
		$this->add_google_response(
			'POST',
			'https://oauth2.googleapis.com/token',
			200,
			array(
				'access_token' => 'new-access-token',
				'expires_in'   => 3599,
				'token_type'   => 'Bearer',
			)
		);

		$client = ( new Client( $this->get_google_drive()->get_access_token() ) )->get_client();

		$this->assertInstanceOf( \ExternalFilesFromGoogleDrive\Dependencies\Google\Client::class, $client );

		// the request uses the decrypted credentials.
		$requests = $this->get_google_requests();
		$this->assertCount( 1, $requests );
		$body = array();
		parse_str( $requests[0]['body'], $body );
		$this->assertEquals( 'my-client-id', $body['client_id'] );
		$this->assertEquals( 'my-client-secret', $body['client_secret'] );
		$this->assertEquals( 'test-refresh-token', $body['refresh_token'] );

		// the old refresh token is preserved, as Google does not return a new one.
		$token = get_option( 'eml_google_drive_access_tokens' );
		$this->assertEquals( 'new-access-token', $token['access_token'] );
		$this->assertEquals( 'test-refresh-token', $token['refresh_token'] );
	}

	/**
	 * Test the client ID and secret.
	 *
	 * The values are saved encrypted. They are decrypted by the settings object if the settings are registered,
	 * which is only the case for users with the capability for this service.
	 *
	 * @return void
	 */
	public function test_get_client_id_and_secret(): void {
		$google_drive = $this->get_google_drive();

		// test 1: the client ID of the plugin is used in the other modes.
		$this->set_mode( 'global' );
		$this->assertEquals( EFML_GOOGLE_OAUTH_CLIENT_ID, $google_drive->get_client_id() );
		$this->assertEquals( '', $google_drive->get_client_secret() );

		// test 2: direct input without own values.
		$this->set_mode( 'direct_input' );
		$this->assertEquals( EFML_GOOGLE_OAUTH_CLIENT_ID, $google_drive->get_client_id() );
		$this->assertEquals( '', $google_drive->get_client_secret() );

		// test 3: direct input with own values, which are not registered as settings.
		update_option( 'eml_google_drive_credential_client_id', $google_drive->encrypt_value( 'my-client-id' ) );
		update_option( 'eml_google_drive_credential_client_secret', $google_drive->encrypt_value( 'my-client-secret' ) );
		$this->assertEquals( 'my-client-id', $google_drive->get_client_id() );
		$this->assertEquals( 'my-client-secret', $google_drive->get_client_secret() );

		// test 4: direct input with own values, which are registered as settings (they are decrypted on reading).
		add_filter( 'option_eml_google_drive_credential_client_id', array( $google_drive, 'decrypt_value' ) );
		add_filter( 'option_eml_google_drive_credential_client_secret', array( $google_drive, 'decrypt_value' ) );
		$this->assertEquals( 'my-client-id', $google_drive->get_client_id() );
		$this->assertEquals( 'my-client-secret', $google_drive->get_client_secret() );
		remove_filter( 'option_eml_google_drive_credential_client_id', array( $google_drive, 'decrypt_value' ) );
		remove_filter( 'option_eml_google_drive_credential_client_secret', array( $google_drive, 'decrypt_value' ) );
	}

	/**
	 * Test the login.
	 *
	 * @return void
	 */
	public function test_do_login(): void {
		$google_drive = $this->get_google_drive();

		// test 1: without access token.
		$this->do_login();
		$this->set_mode( 'global' );
		$this->assertFalse( $google_drive->do_login( 'Google Drive Directory Listing' ) );
		$this->assertStringContainsString( 'GoogleDrive is not connected', implode( ' ', $this->get_error_messages() ) );

		// test 2: with a valid access token.
		$this->reset_google_drive();
		$this->connect();
		$this->assertTrue( $google_drive->do_login( 'Google Drive Directory Listing' ) );
		$this->assertEmpty( $this->get_error_messages() );

		// test 3: with an expired access token, which could not be refreshed.
		$this->connect( array( 'created' => time() - DAY_IN_SECONDS ) );
		$this->add_http_response( EFML_GOOGLE_OAUTH_REFRESH_URL . '?refresh_token=test-refresh-token', 500 );
		$this->assertFalse( $google_drive->do_login( 'Google Drive Directory Listing' ) );
		$this->assertStringContainsString( 'no longer valid', implode( ' ', $this->get_error_messages() ) );
	}

	/**
	 * Test the check whether a file is public.
	 *
	 * @return void
	 */
	public function test_is_file_public(): void {
		$this->connect();
		$service = $this->get_drive_service();

		// test 1: not public.
		$this->add_google_file( 'private1', 'private.jpg', 'content' );
		$this->assertFalse( $this->get_google_drive()->is_file_public( new DriveFile( array( 'id' => 'private1' ) ), $service ) );

		// test 2: public.
		$this->add_google_file( 'public1', 'public.jpg', 'content', true );
		$this->assertTrue( $this->get_google_drive()->is_file_public( new DriveFile( array( 'id' => 'public1' ) ), $service ) );

		// test 3: error.
		$this->add_google_response(
			'GET',
			'files/error1/permissions',
			404,
			array(
				'error' => array(
					'code'    => 404,
					'message' => 'Not found',
				),
			)
		);
		$this->assertFalse(
			$this->get_google_drive()->is_file_public(
				new DriveFile(
					array(
						'id'   => 'error1',
						'name' => 'error.jpg',
					)
				),
				$service
			)
		);
		$this->assertNotEmpty( $this->get_log_entries( 'error.jpg' ) );
	}

	/**
	 * Test the public URL of a file.
	 *
	 * @return void
	 */
	public function test_get_public_url_for_file_id(): void {
		$this->assertEquals( 'https://drive.usercontent.google.com/download?id=abc&export=download', $this->get_google_drive()->get_public_url_for_file_id( 'abc' ) );
	}

	/**
	 * Test the fields in the different modes.
	 *
	 * @return void
	 */
	public function test_get_fields(): void {
		$google_drive = $this->get_google_drive();

		// test 1: global mode without token.
		$this->do_login();
		$this->set_mode( 'global' );
		$fields = $google_drive->get_fields();
		$this->assertEquals( array( 'access_token', 'folder_id' ), array_keys( $fields ) );
		$this->assertEquals( 'textarea', $fields['access_token']['type'] );
		$this->assertEquals( '', $fields['access_token']['value'] );

		// test 2: global mode with token.
		$this->reset_google_drive();
		$this->connect();
		$fields = $google_drive->get_fields();
		$this->assertEquals( 'hidden', $fields['access_token']['type'] );
		$this->assertStringContainsString( 'test-access-token', $fields['access_token']['value'] );
	}

	/**
	 * Test the user settings.
	 *
	 * @return void
	 */
	public function test_get_user_settings(): void {
		$settings = $this->get_google_drive()->get_user_settings();
		$this->assertEquals( array( 'google_drive_show_shared', 'google_drive_show_trashed' ), array_keys( $settings ) );
		$this->assertEquals( 'checkbox', $settings['google_drive_show_shared']['field'] );
	}

	/**
	 * Test the output of the settings in the profile of the user.
	 *
	 * @return void
	 */
	public function test_add_user_settings(): void {
		$this->do_login();
		$this->set_mode( 'user' );
		update_option( 'eml_user_settings', 1 );
		update_option( 'eml_user_settings_allowed_roles', array( 'administrator' ) );
		$user = wp_get_current_user();

		// test 1: without token.
		ob_start();
		$this->get_google_drive()->add_user_settings( $user );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Connect now', $output );
		$this->assertStringContainsString( 'efml_google_drive_show_shared', $output );

		// test 2: with token.
		$this->get_google_drive()->set_access_token( $this->get_access_token() );
		ob_start();
		$this->get_google_drive()->add_user_settings( $user );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Disconnect', $output );
		$this->assertStringContainsString( 'Created at', $output );

		// test 3: with token without creation date.
		$token = $this->get_access_token();
		unset( $token['created'] );
		$this->get_google_drive()->set_access_token( $token );
		ob_start();
		$this->get_google_drive()->add_user_settings( $user );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Disconnect', $output );
		$this->assertStringNotContainsString( 'Created at', $output );

		// test 4: other mode.
		$this->set_mode( 'global' );
		ob_start();
		$this->get_google_drive()->add_user_settings( $user );
		$this->assertEmpty( ob_get_clean() );
	}

	/**
	 * Test if the direct input mode is only added for this service.
	 *
	 * @return void
	 */
	public function test_add_direct_input_mode(): void {
		$modes = array( 'global' => 'Global' );

		// test 1: for this service.
		$this->assertArrayHasKey( 'direct_input', apply_filters( 'efml_service_modes', $modes, $this->get_google_drive() ) );

		// test 2: for other services of the main plugin.
		foreach ( \ExternalFilesInMediaLibrary\Services\Services::get_instance()->get_services_as_objects() as $service_obj ) {
			if ( $service_obj === $this->get_google_drive() ) {
				continue;
			}
			$this->assertArrayNotHasKey( 'direct_input', apply_filters( 'efml_service_modes', $modes, $service_obj ), 'Direct input mode is added to ' . get_class( $service_obj ) );
		}
	}

	/**
	 * Test the encryption of values.
	 *
	 * @return void
	 */
	public function test_encrypt_and_decrypt_value(): void {
		$google_drive = $this->get_google_drive();
		$this->assertEquals( '', $google_drive->encrypt_value( null ) );
		$this->assertEquals( '', $google_drive->encrypt_value( '' ) );
		$this->assertEquals( '', $google_drive->decrypt_value( null ) );
		$encrypted = $google_drive->encrypt_value( 'secret' );
		$this->assertNotEquals( 'secret', $encrypted );
		$this->assertEquals( 'secret', $google_drive->decrypt_value( $encrypted ) );
	}

	/**
	 * Test the check whether the direct input is available.
	 *
	 * @return void
	 */
	public function test_is_direct_input_available(): void {
		$google_drive = $this->get_google_drive();

		// test 1: without SSL.
		unset( $_SERVER['HTTPS'] );
		$this->assertFalse( $this->call_method( $google_drive, 'is_direct_input_available' ) );

		// test 2: with SSL and different hosts.
		$_SERVER['HTTPS'] = 'on';
		$hosts            = array(
			'https://www.example.de'  => true,
			'https://localhost'       => true,
			'https://example.local'   => false,
			'https://project.test'    => false,
			'https://myserver'        => false,
			'https://shop.example.co' => true,
		);
		foreach ( $hosts as $url => $expected ) {
			update_option( 'siteurl', $url );
			$this->assertEquals( $expected, $this->call_method( $google_drive, 'is_direct_input_available' ), $url );
		}
		unset( $_SERVER['HTTPS'] );
	}

	/**
	 * Test the handling of the return URL of the OAuth connection without valid data.
	 *
	 * @return void
	 */
	public function test_check_for_oauth_return_url(): void {
		$google_drive = $this->get_google_drive();

		// test 1: other requests are not changed.
		$this->assertEquals( 'template.php', $google_drive->check_for_oauth_return_url( 'template.php' ) );

		// test 2: request without access token.
		set_query_var( 'emlgoogledrive', 1 );
		$this->set_mode( 'global' );
		$this->assertNotEquals( 'template.php', $google_drive->check_for_oauth_return_url( 'template.php' ) );
		$this->assertTrue( is_404() );

		// test 3: request without state in direct input mode.
		$this->set_mode( 'direct_input' );
		$this->assertNotEquals( 'template.php', $google_drive->check_for_oauth_return_url( 'template.php' ) );
		$this->assertStringContainsString( 'Missing state', $this->get_log_entries()[0]['log'] );
		set_query_var( 'emlgoogledrive', '' );
	}

	/**
	 * Test the uninstallation.
	 *
	 * @return void
	 */
	public function test_uninstall(): void {
		$this->connect();
		$this->get_google_drive()->uninstall();
		$this->assertEmpty( get_option( 'eml_google_drive_access_tokens' ) );
	}

	/**
	 * Test the export object.
	 *
	 * @return void
	 */
	public function test_get_export_object(): void {
		$this->assertSame( \ExternalFilesFromGoogleDrive\GoogleDrive\Export::get_instance(), $this->get_google_drive()->get_export_object() );
	}
}
