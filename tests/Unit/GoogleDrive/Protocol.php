<?php
/**
 * Tests for class ExternalFilesFromGoogleDrive\GoogleDrive\Protocol.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit\GoogleDrive;

use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;

/**
 * Object to test functions in class ExternalFilesFromGoogleDrive\GoogleDrive\Protocol.
 */
class Protocol extends externalFilesFromGoogleDriveTests {
	/**
	 * The URL mark of Google Drive.
	 *
	 * @var string
	 */
	private string $mark = 'https://drive.google.com/drive/';

	/**
	 * Return the protocol object for the given URL.
	 *
	 * @param string $url The URL.
	 *
	 * @return \ExternalFilesFromGoogleDrive\GoogleDrive\Protocol
	 */
	private function get_protocol( string $url ): \ExternalFilesFromGoogleDrive\GoogleDrive\Protocol {
		return new \ExternalFilesFromGoogleDrive\GoogleDrive\Protocol( $url );
	}

	/**
	 * Return the content of the example file.
	 *
	 * @return string
	 */
	private function get_example_content(): string {
		return (string) file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Set the response for the list of files.
	 *
	 * @param array<int,array<string,mixed>> $files The files.
	 *
	 * @return void
	 */
	private function add_file_list( array $files ): void {
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => $files ) );
	}

	/**
	 * Test the basic settings of the protocol.
	 *
	 * @return void
	 */
	public function test_protocol(): void {
		$protocol = $this->get_protocol( $this->mark . 'file1' );
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\ExternalFiles\Protocol_Base::class, $protocol );
		$this->assertEquals( 'google-drive', $protocol->get_name() );
		$this->assertEquals( 'Google Drive', $protocol->get_title() );
		$this->assertTrue( $protocol->is_available() );
		$this->assertTrue( $protocol->should_be_saved_local() );
		$this->assertFalse( $protocol->can_change_hosting() );
		$this->assertFalse( $protocol->can_check_availability() );
		$this->assertFalse( $protocol->is_url_reachable() );
		$this->assertTrue( $protocol->check_url( $this->mark . 'file1' ) );
		$this->assertFalse( $protocol->check_url( '' ) );
	}

	/**
	 * Test if only Google Drive URLs are compatible.
	 *
	 * @return void
	 */
	public function test_is_url_compatible(): void {
		$this->assertTrue( $this->get_protocol( $this->mark . 'file1' )->is_url_compatible() );
		$this->assertFalse( $this->get_protocol( 'https://example.com/file.jpg' )->is_url_compatible() );
		$this->assertFalse( $this->get_protocol( 'https://drive.google.com/file/d/abc/view' )->is_url_compatible() );
	}

	/**
	 * Test the import of a single private file, which is saved local.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_file(): void {
		$this->connect();
		$this->add_google_file( 'file1', 'example.jpg', $this->get_example_content() );

		$infos = $this->get_protocol( $this->mark . 'file1' )->get_url_infos();

		$this->assertCount( 1, $infos );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
		$this->assertTrue( $infos[0]['local'] );
		$this->assertEquals( $this->mark . 'file1', $infos[0]['url'] );
		$this->assertEquals( 'image/jpeg', $infos[0]['mime-type'] );
		$this->assertEquals( 706, $infos[0]['filesize'] );
		$this->assertEquals( strtotime( '2024-01-02T03:04:05.000Z' ), $infos[0]['last-modified'] );
		$this->assertEquals( $this->get_example_content(), file_get_contents( $infos[0]['tmp-file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the import of a single public file, which uses the public URL.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_public_file(): void {
		$this->connect();
		$this->add_google_file( 'file1', 'example.jpg', $this->get_example_content(), true );

		$infos = $this->get_protocol( $this->mark . 'file1' )->get_url_infos();

		$this->assertFalse( $infos[0]['local'] );
		$this->assertEquals( 'https://drive.usercontent.google.com/download?id=file1&export=download', $infos[0]['url'] );
	}

	/**
	 * Test the import without access token or without file ID.
	 *
	 * @return void
	 */
	public function test_get_url_infos_without_token_or_id(): void {
		// test 1: without access token.
		$this->do_login();
		$this->set_mode( 'global' );
		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'file1' )->get_url_infos() );
		$this->assertStringContainsString( 'Access token missing', $this->get_log_entries( $this->mark . 'file1' )[0]['log'] );

		// test 2: without file ID.
		$this->connect();
		$this->assertEquals( array(), $this->get_protocol( $this->mark )->get_url_infos() );
		$this->assertStringContainsString( 'does not contain a file ID', $this->get_log_entries( $this->mark )[0]['log'] );
		$this->assertEmpty( $this->get_google_requests() );
	}

	/**
	 * Test the import of a file, which Google Drive does not deliver.
	 *
	 * @return void
	 */
	public function test_get_url_infos_with_error(): void {
		$this->connect();
		$this->add_google_response(
			'GET',
			'files/file1',
			404,
			array(
				'error' => array(
					'code'    => 404,
					'message' => 'File not found: file1.',
					'errors'  => array(
						array(
							'message' => 'File not found: file1.',
							'reason'  => 'notFound',
						),
					),
				),
			)
		);

		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'file1' )->get_url_infos() );
		$this->assertStringContainsString( 'could not download the requested file', $this->get_log_entries( $this->mark . 'file1' )[0]['log'] );
	}

	/**
	 * Test the import of a directory.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_directory(): void {
		$this->connect();
		$this->add_file_list(
			array(
				array(
					'id'          => 'file1',
					'name'        => 'example.jpg',
					'parents'     => array( 'folder1' ),
					'createdTime' => '2024-01-02T03:04:05.000Z',
				),
				array(
					'id'          => 'file2',
					'name'        => 'other.jpg',
					'parents'     => array( 'folder2' ),
					'createdTime' => '2024-01-02T03:04:05.000Z',
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
		$this->add_google_response(
			'GET',
			'files/folder2',
			200,
			array(
				'id'   => 'folder2',
				'name' => 'Other',
			)
		);
		$this->add_google_file( 'file1', 'example.jpg', $this->get_example_content() );

		$infos = $this->get_protocol( $this->mark . 'folder1/' )->get_url_infos();

		// only the file of the requested directory is imported.
		$this->assertCount( 1, $infos );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
		$this->assertEquals( $this->mark . 'file1', $infos[0]['url'] );
	}

	/**
	 * Test the file list of a folder: only the files of the folder are requested, page by page.
	 *
	 * @return void
	 */
	public function test_get_file_list_of_folder(): void {
		$this->connect();
		$protocol = $this->get_protocol( $this->mark . 'folder1/' );
		$this->assertTrue( $protocol->supports_file_list() );
		$this->assertTrue( $protocol->is_directory() );
		$this->assertTrue( $this->get_protocol( $this->mark . 'eflm-import-all' )->is_directory() );
		$this->assertFalse( $this->get_protocol( $this->mark . 'file1' )->is_directory() );

		// first page.
		$this->add_google_response(
			'GET',
			'files',
			200,
			array(
				'files'         => array(
					array(
						'id'          => 'file1',
						'name'        => 'example.jpg',
						'parents'     => array( 'folder1' ),
						'size'        => '706',
						'createdTime' => '2024-01-02T03:04:05.000Z',
					),
					array(
						'id'          => 'file2',
						'name'        => 'document.unknown',
						'parents'     => array( 'folder1' ),
						'createdTime' => '2024-01-02T03:04:05.000Z',
					),
				),
				'nextPageToken' => 'page-2',
			)
		);
		$page = $protocol->get_file_list( '' );
		$this->assertIsArray( $page );
		$this->assertEquals( 'page-2', $page['cursor'] );
		$this->assertCount( 1, $page['files'] );
		$this->assertEquals( $this->mark . 'file1', $page['files'][0]['url'] );
		$this->assertEquals( \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_public_url_for_file_id( 'file1' ), $page['files'][0]['media-url'] );
		$this->assertEquals( 706, $page['files'][0]['filesize'] );

		// the request contains the folder and the page size.
		$requests = $this->get_google_requests();
		$request  = end( $requests );
		$this->assertStringContainsString( "'folder1' in parents", $request['query']['q'] );
		$this->assertEquals( '500', $request['query']['pageSize'] );
	}

	/**
	 * Test the file list with an error.
	 *
	 * @return void
	 */
	public function test_get_file_list_with_error(): void {
		$this->connect();
		$this->add_google_response( 'GET', 'files', 500, array( 'error' => array( 'code' => 500, 'message' => 'Error' ) ) );
		$this->assertFalse( $this->get_protocol( $this->mark . 'eflm-import-all' )->get_file_list( '' ) );
	}

	/**
	 * Test the import of all files.
	 *
	 * @return void
	 */
	public function test_get_url_infos_import_all(): void {
		$this->connect();
		$this->add_file_list(
			array(
				array(
					'id'            => 'file1',
					'name'          => 'example.jpg',
					'fileExtension' => 'jpg',
					'createdTime'   => '2024-01-02T03:04:05.000Z',
				),
				array(
					'id'            => 'file2',
					'name'          => 'trashed.jpg',
					'fileExtension' => 'jpg',
					'trashed'       => true,
					'createdTime'   => '2024-01-02T03:04:05.000Z',
				),
				array(
					'id'          => 'file3',
					'name'        => 'Google Doc',
					'createdTime' => '2024-01-02T03:04:05.000Z',
				),
			)
		);
		$this->add_google_file( 'file1', 'example.jpg', $this->get_example_content() );

		$infos = $this->get_protocol( $this->mark . 'eflm-import-all' )->get_url_infos();

		// only the file, which is not trashed and has an extension, is imported.
		$this->assertCount( 1, $infos );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
		$this->assertEquals( $this->mark . 'file1', $infos[0]['url'] );
		$this->assertEquals( 706, $infos[0]['filesize'] );

		// the trashed state must be requested, otherwise Google Drive does not deliver it.
		$this->assertStringContainsString( 'trashed', $this->get_google_requests()[0]['query']['fields'] );
	}

	/**
	 * Test the import of all files, if Google Drive returns an error.
	 *
	 * @return void
	 */
	public function test_get_url_infos_import_all_with_error(): void {
		$this->connect();
		$this->add_google_response(
			'GET',
			'files',
			500,
			array(
				'error' => array(
					'code'    => 500,
					'message' => 'Error',
				),
			)
		);

		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'eflm-import-all' )->get_url_infos() );
	}

	/**
	 * Test the temporary file of a private file, which is loaded via the API.
	 *
	 * @return void
	 */
	public function test_get_temp_file_of_private_file(): void {
		$this->connect();
		$url           = 'https://drive.google.com/drive/file123';
		$attachment_id = $this->create_local_attachment();
		update_post_meta( $attachment_id, EFML_POST_META_URL, $url );
		update_post_meta( $attachment_id, 'efml_private_file', 1 );
		update_post_meta( $attachment_id, 'efml_google_drive_file_id', 'file123' );
		$this->add_google_file( 'file123', 'example.jpg', 'my private content' );

		// get the temporary file.
		$protocol = new \ExternalFilesFromGoogleDrive\GoogleDrive\Protocol( $url );
		$tmp_file = $protocol->get_temp_file( $url, \ExternalFilesInMediaLibrary\Plugin\Helper::get_wp_filesystem() );
		$this->assertIsString( $tmp_file );
		$this->assertEquals( 'my private content', file_get_contents( $tmp_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $tmp_file );
	}

	/**
	 * Test the temporary file of a public exported file: it is loaded via the API, so it is delivered even if its
	 * public link does not work (e.g. exported before the files have been shared).
	 *
	 * @return void
	 */
	public function test_get_temp_file_of_public_exported_file(): void {
		$this->connect();
		$url           = 'https://drive.usercontent.google.com/download?id=file123&export=download';
		$attachment_id = $this->create_local_attachment();
		update_post_meta( $attachment_id, EFML_POST_META_URL, $url );
		update_post_meta( $attachment_id, 'efml_google_drive_file_id', 'file123' );
		$this->add_google_file( 'file123', 'example.jpg', 'my public content' );

		// get the temporary file.
		$protocol = new \ExternalFilesFromGoogleDrive\GoogleDrive\Protocol( $url );
		$tmp_file = $protocol->get_temp_file( $url, \ExternalFilesInMediaLibrary\Plugin\Helper::get_wp_filesystem() );
		$this->assertIsString( $tmp_file );
		$this->assertEquals( 'my public content', file_get_contents( $tmp_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $tmp_file );

		// no request via HTTP.
		$this->assertEmpty( $this->get_http_requests() );
	}

	/**
	 * Test the temporary file of a public file, which has not been exported (e.g. imported via its URL): it is
	 * loaded via HTTP, not via the API.
	 *
	 * @return void
	 */
	public function test_get_temp_file_of_imported_public_file(): void {
		$this->connect();
		$url           = 'https://drive.usercontent.google.com/download?id=file456&export=download';
		$attachment_id = $this->create_local_attachment();
		update_post_meta( $attachment_id, EFML_POST_META_URL, $url );
		$this->add_http_response( $url, 404 );

		$protocol = new \ExternalFilesFromGoogleDrive\GoogleDrive\Protocol( $url );
		$protocol->get_temp_file( $url, \ExternalFilesInMediaLibrary\Plugin\Helper::get_wp_filesystem() );
		$this->assertEmpty( $this->get_google_requests() );
		$this->assertNotEmpty( $this->get_http_requests() );
	}

	/**
	 * Test the URL of the platform.
	 *
	 * @return void
	 */
	public function test_get_platform_url(): void {
		$this->assertEquals( 'https://drive.google.com/drive/my-drive', \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_platform_url() );
	}

	/**
	 * Test that the content of a file is saved completely, also if it is larger than the parts it is written in.
	 *
	 * @return void
	 */
	public function test_save_response_to_file(): void {
		$content  = random_bytes( 3 * 1024 * 1024 + 123 );
		$response = new \ExternalFilesFromGoogleDrive\Dependencies\GuzzleHttp\Psr7\Response( 200, array(), $content );
		$file     = wp_tempnam();

		$this->call_method( $this->get_protocol( 'https://drive.google.com/file/d/file1' ), 'save_response_to_file', array( $response, $file ) );

		$this->assertSame( md5( $content ), md5_file( $file ) );
		wp_delete_file( $file );
	}

	/**
	 * Test that a response without content results in an error.
	 *
	 * @return void
	 */
	public function test_save_response_to_file_without_response(): void {
		$file = wp_tempnam();

		try {
			$this->expectException( \Error::class );
			$this->call_method( $this->get_protocol( 'https://drive.google.com/file/d/file1' ), 'save_response_to_file', array( null, $file ) );
		} finally {
			wp_delete_file( $file );
		}
	}

	/**
	 * Return the query for the files of the given folder, like the protocol requests it.
	 *
	 * @param string $folder_id The ID of the folder.
	 *
	 * @return string
	 */
	private function get_folder_query( string $folder_id ): string {
		$query     = apply_filters( 'efmlgd_google_drive_query_params', array(), 'folder1/' );
		$condition = "'" . $folder_id . "' in parents";
		return empty( $query['q'] ) ? $condition : '(' . $query['q'] . ') and ' . $condition;
	}

	/**
	 * Return the data of a file or folder for a response of the file list.
	 *
	 * @param string $id The ID.
	 * @param string $name The name.
	 * @param string $parent The ID of the parent folder.
	 * @param bool   $is_folder Whether this is a folder.
	 *
	 * @return array<string,mixed>
	 */
	private function get_list_entry( string $id, string $name, string $parent, bool $is_folder = false ): array {
		return array(
			'id'          => $id,
			'name'        => $name,
			'mimeType'    => $is_folder ? 'application/vnd.google-apps.folder' : 'image/jpeg',
			'parents'     => array( $parent ),
			'size'        => $is_folder ? null : '706',
			'createdTime' => '2024-01-02T03:04:05.000Z',
		);
	}

	/**
	 * Test the defaults for subdirectories: the main directory and the import of all files use all files, a folder only its own.
	 *
	 * @return void
	 */
	public function test_subdirectories_defaults(): void {
		$this->assertTrue( $this->get_protocol( $this->mark . 'folder1/' )->supports_subdirectories() );
		$this->assertFalse( $this->get_protocol( $this->mark . 'folder1/' )->get_subdirectories_default() );
		$this->assertTrue( $this->get_protocol( $this->mark . 'eflm-import-all' )->get_subdirectories_default() );
	}

	/**
	 * Test the file list of a folder with its subfolders: one folder after the other, excluded folders and folders
	 * deeper than the maximum depth are not used.
	 *
	 * @return void
	 */
	public function test_get_file_list_of_folder_with_subfolders(): void {
		$this->connect();
		$this->add_google_response(
			'GET',
			'files',
			200,
			array(
				'files' => array(
					$this->get_list_entry( 'file1', 'a.jpg', 'folder1' ),
					$this->get_list_entry( 'sub1', 'Holidays', 'folder1', true ),
					$this->get_list_entry( 'excluded', 'node_modules', 'folder1', true ),
				),
			),
			array( 'q' => $this->get_folder_query( 'folder1' ) )
		);
		$this->add_google_response(
			'GET',
			'files',
			200,
			array(
				'files' => array(
					$this->get_list_entry( 'file2', 'b.jpg', 'sub1' ),
					$this->get_list_entry( 'sub2', '2024', 'sub1', true ),
				),
			),
			array( 'q' => $this->get_folder_query( 'sub1' ) )
		);
		$protocol = $this->get_protocol( $this->mark . 'folder1/' );

		add_filter( 'efml_include_subdirectories', '__return_true' );
		add_filter( 'efml_subdirectories_max_depth', static fn() => 1 );

		// the first page contains the file of the folder.
		$page = $protocol->get_file_list( '' );
		$this->assertIsArray( $page );
		$this->assertEquals( array( $this->mark . 'file1' ), array_column( $page['files'], 'url' ) );
		$this->assertNotEmpty( $page['cursor'] );

		// the next page contains the file of the subfolder, its subfolder is too deep.
		$page = $protocol->get_file_list( $page['cursor'] );
		$this->assertIsArray( $page );
		$this->assertEquals( array( $this->mark . 'file2' ), array_column( $page['files'], 'url' ) );
		$this->assertEquals( '', $page['cursor'] );

		remove_filter( 'efml_include_subdirectories', '__return_true' );

		// the excluded and the too deep folders have not been requested.
		$queries = array_column( array_column( $this->get_google_requests(), 'query' ), 'q' );
		$this->assertNotContains( $this->get_folder_query( 'excluded' ), $queries );
		$this->assertNotContains( $this->get_folder_query( 'sub2' ), $queries );
	}

	/**
	 * Test that a subfolder, which could not be read, is skipped and protects the files from the deletion.
	 *
	 * @return void
	 */
	public function test_get_file_list_with_unreadable_subfolder(): void {
		$this->connect();
		$this->add_google_response(
			'GET',
			'files',
			200,
			array( 'files' => array( $this->get_list_entry( 'file1', 'a.jpg', 'folder1' ), $this->get_list_entry( 'sub1', 'Private', 'folder1', true ) ) ),
			array( 'q' => $this->get_folder_query( 'folder1' ) )
		);
		$this->add_google_response( 'GET', 'files', 403, array( 'error' => array( 'code' => 403, 'message' => 'Forbidden' ) ), array( 'q' => $this->get_folder_query( 'sub1' ) ) );
		$protocol = $this->get_protocol( $this->mark . 'folder1/' );

		$skipped  = array();
		$callback = static function ( string $url ) use ( &$skipped ): void {
			$skipped[] = $url;
		};
		add_action( 'efml_subdirectory_skipped', $callback );
		add_filter( 'efml_include_subdirectories', '__return_true' );
		$page = $protocol->get_file_list( '' );
		$this->assertIsArray( $page );
		$page = $protocol->get_file_list( $page['cursor'] );
		remove_filter( 'efml_include_subdirectories', '__return_true' );
		remove_action( 'efml_subdirectory_skipped', $callback );

		// the listing continues without files of the subfolder, all files of Google Drive are protected.
		$this->assertIsArray( $page );
		$this->assertEmpty( $page['files'] );
		$this->assertEquals( '', $page['cursor'] );
		$this->assertEquals( array( $this->mark ), $skipped );
	}

	/**
	 * Test the file list of all files without subfolders: only the files in the root of Google Drive are requested.
	 *
	 * @return void
	 */
	public function test_get_file_list_of_all_files_without_subfolders(): void {
		$this->connect();
		$this->add_file_list( array( $this->get_list_entry( 'file1', 'a.jpg', 'root' ) ) );

		add_filter( 'efml_include_subdirectories', '__return_false' );
		$page = $this->get_protocol( $this->mark . 'eflm-import-all' )->get_file_list( '' );
		remove_filter( 'efml_include_subdirectories', '__return_false' );

		$this->assertIsArray( $page );
		$requests = $this->get_google_requests();
		$request  = end( $requests );
		$this->assertStringContainsString( "'root' in parents", $request['query']['q'] );
	}

	/**
	 * Test that an invalid cursor of a folder listing is an error.
	 *
	 * @return void
	 */
	public function test_get_file_list_with_invalid_folder_cursor(): void {
		$this->connect();
		add_filter( 'efml_include_subdirectories', '__return_true' );
		$this->assertFalse( $this->get_protocol( $this->mark . 'folder1/' )->get_file_list( 'invalid' ) );
		remove_filter( 'efml_include_subdirectories', '__return_true' );
	}

	/**
	 * Test the import of a folder with its subfolders without import run: the files are loaded via the file list.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_folder_with_subfolders(): void {
		$this->connect();
		$this->add_google_response(
			'GET',
			'files',
			200,
			array( 'files' => array( $this->get_list_entry( 'file1', 'a.jpg', 'folder1' ), $this->get_list_entry( 'sub1', 'Holidays', 'folder1', true ) ) ),
			array( 'q' => $this->get_folder_query( 'folder1' ) )
		);
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array( $this->get_list_entry( 'file2', 'b.jpg', 'sub1' ) ) ), array( 'q' => $this->get_folder_query( 'sub1' ) ) );
		$this->add_google_file( 'file1', 'a.jpg', $this->get_example_content() );
		$this->add_google_file( 'file2', 'b.jpg', $this->get_example_content() );

		add_filter( 'efml_include_subdirectories', '__return_true' );
		$infos = $this->get_protocol( $this->mark . 'folder1/' )->get_url_infos();
		remove_filter( 'efml_include_subdirectories', '__return_true' );

		$this->assertEquals( array( $this->mark . 'file1', $this->mark . 'file2' ), array_column( $infos, 'url' ) );
		foreach ( $infos as $info ) {
			wp_delete_file( $info['tmp-file'] );
		}
	}

	/**
	 * Add the response for the data of a file or folder with its parents.
	 *
	 * @param string             $id The ID.
	 * @param string             $name The name.
	 * @param array<int,string>  $parents The IDs of the parents.
	 *
	 * @return void
	 */
	private function add_entry( string $id, string $name, array $parents ): void {
		$this->add_google_response(
			'GET',
			'files/' . $id,
			200,
			array(
				'id'      => $id,
				'name'    => $name,
				'parents' => $parents,
			),
			array( 'fields' => 'id,name,parents' )
		);
	}

	/**
	 * Test the directories of files relative to a folder and to the main directory (via their parent folders).
	 *
	 * @return void
	 */
	public function test_get_relative_directory(): void {
		$this->connect();
		$this->add_entry( 'rd-root', 'My Drive', array() );
		$this->add_entry( 'rd-folder', 'Photos', array( 'rd-root' ) );
		$this->add_entry( 'rd-sub', 'Holidays', array( 'rd-folder' ) );
		$this->add_entry( 'rd-file1', 'a.jpg', array( 'rd-folder' ) );
		$this->add_entry( 'rd-file2', 'b.jpg', array( 'rd-sub' ) );
		$this->add_entry( 'rd-file3', 'c.jpg', array( 'rd-root' ) );

		// relative to the folder.
		$folder = $this->get_protocol( $this->mark . 'rd-folder/' );
		$this->assertEquals( '', $folder->get_relative_directory( $this->mark . 'rd-file1' ) );
		$this->assertEquals( 'Holidays', $folder->get_relative_directory( $this->mark . 'rd-file2' ) );
		$this->assertEquals( '', $folder->get_relative_directory( $this->mark . 'rd-file3' ) );

		// relative to the main directory.
		$all = $this->get_protocol( $this->mark . 'eflm-import-all' );
		$this->assertEquals( '', $all->get_relative_directory( $this->mark . 'rd-file3' ) );
		$this->assertEquals( 'Photos', $all->get_relative_directory( $this->mark . 'rd-file1' ) );
		$this->assertEquals( 'Photos/Holidays', $all->get_relative_directory( $this->mark . 'rd-file2' ) );
	}
}
