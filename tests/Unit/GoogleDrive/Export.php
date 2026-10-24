<?php
/**
 * Tests for class ExternalFilesFromGoogleDrive\GoogleDrive\Export.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit\GoogleDrive;

use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;

/**
 * Object to test functions in class ExternalFilesFromGoogleDrive\GoogleDrive\Export.
 */
class Export extends externalFilesFromGoogleDriveTests {
	/**
	 * The URL for uploads.
	 *
	 * @var string
	 */
	private string $upload_url = 'https://www.googleapis.com/upload/drive/v3/files';

	/**
	 * Return the export object.
	 *
	 * @return \ExternalFilesFromGoogleDrive\GoogleDrive\Export
	 */
	private function get_export(): \ExternalFilesFromGoogleDrive\GoogleDrive\Export {
		return \ExternalFilesFromGoogleDrive\GoogleDrive\Export::get_instance();
	}

	/**
	 * Set the responses for a resumable upload.
	 *
	 * @param int $status The HTTP status of the upload.
	 *
	 * @return void
	 */
	private function add_upload_responses( int $status = 200 ): void {
		$this->add_google_response( 'POST', $this->upload_url, 200, '', array( 'uploadType' => 'resumable' ), array( 'Location' => $this->upload_url . '?uploadType=resumable&upload_id=upload123' ) );
		$this->add_google_response( 'PUT', $this->upload_url, $status, 200 === $status ? array( 'id' => 'new123' ) : array( 'error' => array( 'code' => $status, 'message' => 'Error' ) ), array( 'upload_id' => 'upload123' ) );
	}

	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$export = $this->get_export();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\ExternalFiles\Export_Base::class, $export );
		$this->assertSame( $export, \ExternalFilesFromGoogleDrive\GoogleDrive\Export::get_instance() );
	}

	/**
	 * Test the export of a file.
	 *
	 * @return void
	 */
	public function test_export_file(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$this->add_upload_responses();

		$url = $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array() );

		$this->assertEquals( 'https://drive.usercontent.google.com/download?id=new123&export=download', $url );
		$this->assertEquals( 'new123', get_post_meta( $attachment_id, 'efml_google_drive_file_id', true ) );

		// check the requests: the metadata and the content of the file.
		$requests = $this->get_google_requests();
		$this->assertCount( 2, $requests );
		$metadata = json_decode( $requests[0]['body'], true );
		$this->assertEquals( basename( (string) get_attached_file( $attachment_id, true ) ), $metadata['name'] );
		$this->assertArrayNotHasKey( 'parents', $metadata );
		$this->assertEquals( 'PUT', $requests[1]['method'] );
		$this->assertEquals( file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), $requests[1]['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the export of a file into a directory.
	 *
	 * @return void
	 */
	public function test_export_file_into_directory(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$this->add_upload_responses();

		$this->assertIsString( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/folder1/', array( 'directory' => 'folder1/' ) ) );

		$metadata = json_decode( $this->get_google_requests()[0]['body'], true );
		$this->assertEquals( array( 'folder1' ), $metadata['parents'] );
	}

	/**
	 * Test the export of a file, if Google Drive returns an error.
	 *
	 * @return void
	 */
	public function test_export_file_with_error(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();

		// test 1: the upload of the content fails.
		$this->add_upload_responses( 403 );
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array() ) );
		$this->assertNotEmpty( $this->get_log_entries( 'https://drive.google.com/drive/' ) );
		$this->assertEmpty( get_post_meta( $attachment_id, 'efml_google_drive_file_id', true ) );
	}

	/**
	 * Test the export of a file, if the resumable upload could not be started.
	 *
	 * @return void
	 */
	public function test_export_file_without_upload_url(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$this->add_google_response( 'POST', $this->upload_url, 401, array( 'error' => array( 'code' => 401, 'message' => 'Unauthorized' ) ), array( 'uploadType' => 'resumable' ) );

		$this->assertFalse( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array() ) );
		$this->assertStringContainsString( 'File could not be uploaded to Google Drive', $this->get_log_entries( 'https://drive.google.com/drive/' )[0]['log'] );
	}

	/**
	 * Test the export without access token or without file.
	 *
	 * @return void
	 */
	public function test_export_file_without_token_or_file(): void {
		// test 1: without access token.
		$this->do_login();
		$this->set_mode( 'global' );
		$attachment_id = $this->create_local_attachment();
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array() ) );

		// test 2: attachment with a not existing file.
		$this->connect();
		update_attached_file( $attachment_id, '/not/existing/example.jpg' );
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array() ) );
		$this->assertStringContainsString( 'Could not load file path', $this->get_log_entries( 'https://drive.google.com/drive/' )[0]['log'] );

		$this->assertEmpty( $this->get_google_requests() );
	}

	/**
	 * Test the deletion of an exported file.
	 *
	 * @return void
	 */
	public function test_delete_exported_file(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		update_post_meta( $attachment_id, 'efml_google_drive_file_id', 'new123' );
		$this->add_google_response( 'DELETE', 'files/new123', 204 );

		$this->assertTrue( $this->get_export()->delete_exported_file( 'https://drive.usercontent.google.com/download?id=new123&export=download', array(), $attachment_id ) );
		$this->assertCount( 1, $this->get_google_requests() );
	}

	/**
	 * Test the deletion of an exported file with errors.
	 *
	 * @return void
	 */
	public function test_delete_exported_file_with_error(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();

		// test 1: without file ID.
		$this->assertFalse( $this->get_export()->delete_exported_file( 'https://example.com', array(), $attachment_id ) );
		$this->assertEmpty( $this->get_google_requests() );

		// test 2: Google Drive returns an error.
		update_post_meta( $attachment_id, 'efml_google_drive_file_id', 'new123' );
		$this->add_google_response( 'DELETE', 'files/new123', 404, array( 'error' => array( 'code' => 404, 'message' => 'File not found' ) ) );
		$this->assertFalse( $this->get_export()->delete_exported_file( 'https://example.com/file', array(), $attachment_id ) );
		$this->assertStringContainsString( 'could not be delete', $this->get_log_entries( 'https://example.com/file' )[0]['log'] );

		// test 3: without access token.
		update_option( 'eml_google_drive_access_tokens', array() );
		$this->assertFalse( $this->get_export()->delete_exported_file( 'https://example.com/file', array(), $attachment_id ) );
	}
}
