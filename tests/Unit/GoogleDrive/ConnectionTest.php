<?php
/**
 * Tests for the complete connection test of Google Drive.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit\GoogleDrive;

use ExternalFilesFromGoogleDrive\GoogleDrive;
use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;

/**
 * Object to test the connection test of Google Drive.
 */
class ConnectionTest extends externalFilesFromGoogleDriveTests {
	/**
	 * The URL for uploads.
	 *
	 * @var string
	 */
	private string $upload_url = 'https://www.googleapis.com/upload/drive/v3/files';

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		GoogleDrive::get_instance()->set_connection_test_directory( '' );
		parent::tear_down();
	}

	/**
	 * Add the responses for the upload and the deletion of the test file.
	 *
	 * @param bool $is_public Whether the file is public.
	 *
	 * @return void
	 */
	private function add_file_responses( bool $is_public ): void {
		$this->add_google_response( 'POST', $this->upload_url, 200, array( 'id' => 'testfile1' ), array( 'uploadType' => 'multipart' ) );
		$this->add_google_response( 'GET', 'files/testfile1/permissions', 200, array( 'permissions' => $is_public ? array( array( 'type' => 'anyone', 'role' => 'reader' ) ) : array() ) );
		$this->add_google_response( 'DELETE', 'files/testfile1', 204 );
	}

	/**
	 * Test the connection test in a folder.
	 *
	 * @return void
	 */
	public function test_connection_test(): void {
		$this->connect();
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array() ), array( 'q' => "'folder1' in parents" ) );
		$this->add_file_responses( true );
		GoogleDrive::get_instance()->set_connection_test_directory( 'folder1/' );

		$steps = GoogleDrive::get_instance()->run_connection_test();

		$this->assertTrue( GoogleDrive::get_instance()->supports_connection_test() );
		foreach ( $steps as $step ) {
			$this->assertTrue( $step['success'], $step['title'] . ': ' . $step['message'] );
		}

		// the file is uploaded into the folder.
		$requests = $this->get_google_requests();
		$this->assertEquals( array( 'GET', 'POST', 'GET', 'DELETE' ), array_column( $requests, 'method' ) );
		$this->assertStringContainsString( '"parents":["folder1"]', $requests[1]['body'] );
	}

	/**
	 * Test the connection test with a file, which is not public.
	 *
	 * @return void
	 */
	public function test_connection_test_with_private_file(): void {
		$this->connect();
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array() ) );
		$this->add_file_responses( false );

		$steps = GoogleDrive::get_instance()->run_connection_test();

		$this->assertTrue( $steps[1]['success'] );
		$this->assertFalse( $steps[2]['success'] );
		$this->assertTrue( $steps[3]['success'] );

		// without folder no parents are used.
		$this->assertStringNotContainsString( 'parents', $this->get_google_requests()[1]['body'] );
	}

	/**
	 * Test the connection test with errors.
	 *
	 * @return void
	 */
	public function test_connection_test_with_errors(): void {
		$this->connect();
		$this->add_google_response( 'GET', 'files', 403, array( 'error' => array( 'code' => 403, 'message' => 'Forbidden' ) ) );

		$steps = GoogleDrive::get_instance()->run_connection_test();

		$this->assertFalse( $steps[0]['success'] );
		$this->assertStringContainsString( 'could not be read', $steps[0]['message'] );
		$this->assertTrue( $steps[1]['skipped'] );
	}

	/**
	 * Test the connection test without connection to Google Drive.
	 *
	 * @return void
	 */
	public function test_connection_test_without_connection(): void {
		$this->do_login();
		$this->set_mode( 'global' );

		$steps = GoogleDrive::get_instance()->run_connection_test();

		$this->assertFalse( $steps[0]['success'] );
		$this->assertStringContainsString( 'not connected', $steps[0]['message'] );
	}

	/**
	 * Test the global actions offer the connection test.
	 *
	 * @return void
	 */
	public function test_global_actions(): void {
		$this->connect();
		$actions = $this->call_method( GoogleDrive::get_instance(), 'get_global_actions' );
		$this->assertContains( 'Run connection test', array_column( $actions, 'label' ) );
	}
}
