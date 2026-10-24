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
		$this->add_google_response( 'GET', 'files/file1', 404, array( 'error' => array( 'code' => 404, 'message' => 'File not found: file1.', 'errors' => array( array( 'message' => 'File not found: file1.', 'reason' => 'notFound' ) ) ) ) );

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
		$this->add_google_response( 'GET', 'files/folder1', 200, array( 'id' => 'folder1', 'name' => 'Photos' ) );
		$this->add_google_response( 'GET', 'files/folder2', 200, array( 'id' => 'folder2', 'name' => 'Other' ) );
		$this->add_google_file( 'file1', 'example.jpg', $this->get_example_content() );

		$infos = $this->get_protocol( $this->mark . 'folder1/' )->get_url_infos();

		// only the file of the requested directory is imported.
		$this->assertCount( 1, $infos );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
		$this->assertEquals( $this->mark . 'file1', $infos[0]['url'] );
	}

	/**
	 * Test the limit of files during an import of a directory via AJAX.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_directory_with_limit(): void {
		$this->connect();
		update_option( 'eml_google_drive_limit', 2 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$files = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$files[] = array(
				'id'          => 'file' . $i,
				'name'        => 'example' . $i . '.jpg',
				'parents'     => array( 'folder1' ),
				'createdTime' => '2024-01-02T03:04:05.000Z',
			);
			$this->add_google_file( 'file' . $i, 'example' . $i . '.jpg', $this->get_example_content() );
		}
		$this->add_file_list( $files );
		$this->add_google_response( 'GET', 'files/folder1', 200, array( 'id' => 'folder1', 'name' => 'Photos' ) );

		$infos = $this->get_protocol( $this->mark . 'folder1/' )->get_url_infos();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		// the limit is used and a marker to load more is set.
		$this->assertTrue( $infos['load_more'] );
		unset( $infos['load_more'] );
		$this->assertCount( 2, $infos );
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
		$this->add_google_response( 'GET', 'files', 500, array( 'error' => array( 'code' => 500, 'message' => 'Error' ) ) );

		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'eflm-import-all' )->get_url_infos() );
	}
}
