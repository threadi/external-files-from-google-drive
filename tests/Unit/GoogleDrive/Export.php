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
		$this->add_google_response(
			'PUT',
			$this->upload_url,
			$status,
			200 === $status ? array( 'id' => 'new123' ) : array(
				'error' => array(
					'code'    => $status,
					'message' => 'Error',
				),
			),
			array( 'upload_id' => 'upload123' )
		);

		// the sharing for anyone with the link (only for public files).
		$this->add_google_response(
			'POST',
			'files/new123/permissions',
			200,
			array(
				'id'   => 'anyoneWithLink',
				'type' => 'anyone',
				'role' => 'reader',
			)
		);
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

		// check the requests: the metadata, the content of the file and the sharing for anyone with the link.
		$requests = $this->get_google_requests();
		$this->assertCount( 3, $requests );
		$this->assertEquals( 'POST', $requests[2]['method'] );
		$this->assertStringEndsWith( 'files/new123/permissions', $requests[2]['url'] );
		$permission = json_decode( $requests[2]['body'], true );
		$this->assertEquals( 'anyone', $permission['type'] );
		$this->assertEquals( 'reader', $permission['role'] );
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
		$this->add_google_response(
			'POST',
			$this->upload_url,
			401,
			array(
				'error' => array(
					'code'    => 401,
					'message' => 'Unauthorized',
				),
			),
			array( 'uploadType' => 'resumable' )
		);

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
	 * Test the export of a public file, which could not be shared for anyone with the link (e.g. not allowed in a
	 * Google Workspace): the export fails and the uploaded file is removed again.
	 *
	 * @return void
	 */
	public function test_export_file_without_sharing(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$this->add_google_response(
			'POST',
			'files/new123/permissions',
			403,
			array(
				'error' => array(
					'code'    => 403,
					'message' => 'The user does not have sufficient permissions for this file.',
				),
			)
		);
		$this->add_upload_responses();
		$this->add_google_response( 'DELETE', 'files/new123', 204 );

		$this->assertFalse( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array() ) );
		$this->assertEmpty( get_post_meta( $attachment_id, 'efml_google_drive_file_id', true ) );

		// the uploaded file has been deleted, the log names the reason.
		$requests = $this->get_google_requests();
		$this->assertEquals( 'DELETE', end( $requests )['method'] );
		$this->assertStringContainsString( 'Save files privately', (string) wp_json_encode( $this->get_log_entries( 'https://drive.google.com/drive/' ) ) );
	}

	/**
	 * Test that private files and sizes of public files are handled correctly regarding the sharing.
	 *
	 * @return void
	 */
	public function test_sharing_of_private_files_and_sizes(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$source        = (string) get_attached_file( $attachment_id, true );

		// test 1: private files are not shared.
		$this->add_upload_responses();
		$this->assertIsString( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array( 'private' => true ) ) );
		foreach ( $this->get_google_requests() as $request ) {
			$this->assertStringNotContainsString( '/permissions', $request['url'] );
		}

		// test 2: sizes of public files are shared, too.
		$count = count( $this->get_google_requests() );
		$this->assertIsString( $this->get_export()->export_size( $attachment_id, $source, 'https://drive.google.com/drive/size.jpg', array() ) );
		$requests = array_slice( $this->get_google_requests(), $count );
		$this->assertStringEndsWith( 'files/new123/permissions', end( $requests )['url'] );
	}

	/**
	 * Create an external source of Google Drive, which belongs to the given user.
	 *
	 * @param int $user_id The ID of the owner.
	 *
	 * @return int
	 */
	private function create_source_of_user( int $user_id ): int {
		$term = wp_insert_term( 'Google Drive of user ' . $user_id, \easyDirectoryListingForWordPress\Taxonomy::get_instance()->get_name() );
		$this->assertIsArray( $term );
		update_term_meta( $term['term_id'], 'type', \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_name() );
		update_term_meta( $term['term_id'], 'user_id', $user_id );
		return $term['term_id'];
	}

	/**
	 * Save the access token of the given user for the mode "user".
	 *
	 * @param int    $user_id      The user ID.
	 * @param string $access_token The access token.
	 *
	 * @return void
	 */
	private function connect_user( int $user_id, string $access_token ): void {
		update_user_meta( $user_id, 'efml_google_drive_access_tokens', \easyDirectoryListingForWordPress\Crypt::get_instance()->encrypt( (string) wp_json_encode( $this->get_access_token( array( 'access_token' => $access_token ) ) ), 'usermeta:efml_google_drive_access_tokens' ) );
	}

	/**
	 * Return the access tokens used in the requests to Google.
	 *
	 * @return array<int,string>
	 */
	private function get_used_access_tokens(): array {
		$tokens = array();
		foreach ( $this->get_google_requests() as $request ) {
			$headers  = array_change_key_case( $request['headers'] );
			$tokens[] = str_replace( 'Bearer ', '', (string) ( $headers['authorization'][0] ?? '' ) );
		}
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Test that the export, the sizes and the deletion use the connection of the owner of the external source, if each
	 * user has its own connection: e.g. for uploads of other users or for exports in the background.
	 *
	 * @return void
	 */
	public function test_export_uses_connection_of_owner(): void {
		$this->set_mode( 'user' );
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->connect_user( $owner_id, 'owner-access-token' );
		$term_id = $this->create_source_of_user( $owner_id );

		// another user without own connection uploads a file, which is exported.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$attachment_id = $this->create_local_attachment();
		$this->add_upload_responses();
		$url = $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array( 'term_id' => $term_id ) );
		$this->assertIsString( $url );
		$this->assertSame( array( 'owner-access-token' ), $this->get_used_access_tokens() );

		// the deletion in the background (without user) uses the connection of the owner, too: here the owner of the
		// external source of the file.
		wp_set_current_user( 0 );
		update_post_meta( $attachment_id, 'efml_export_sources', array( $term_id ) );
		$this->add_google_response( 'DELETE', 'files/new123', 204 );
		$this->assertTrue( $this->get_export()->delete_exported_file( (string) $url, array(), $attachment_id ) );
		$this->assertSame( array( 'owner-access-token' ), $this->get_used_access_tokens() );
	}

	/**
	 * Test that the connection of the website is used in the global mode, regardless of the owner of the external
	 * source.
	 *
	 * @return void
	 */
	public function test_export_uses_global_connection(): void {
		$this->connect();
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->connect_user( $owner_id, 'owner-access-token' );
		$term_id = $this->create_source_of_user( $owner_id );

		$this->assertFalse( \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_owner_of_source( $term_id ) );
		$attachment_id = $this->create_local_attachment();
		$this->add_upload_responses();
		$this->assertIsString( $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array( 'term_id' => $term_id ) ) );
		$this->assertSame( array( 'test-access-token' ), $this->get_used_access_tokens() );
	}

	/**
	 * Test the owner of external sources and files.
	 *
	 * @return void
	 */
	public function test_owner_of_source_and_file(): void {
		$this->set_mode( 'user' );
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id  = $this->create_source_of_user( $owner_id );

		// test 1: the owner of the external source.
		$this->assertSame( $owner_id, \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_owner_of_source( $term_id )->ID );

		// test 2: no owner for external sources of other services or unknown users.
		update_term_meta( $term_id, 'type', 'dropbox' );
		$this->assertFalse( \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_owner_of_source( $term_id ) );
		update_term_meta( $term_id, 'type', \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_name() );
		update_term_meta( $term_id, 'user_id', 999999 );
		$this->assertFalse( \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_owner_of_source( $term_id ) );
		update_term_meta( $term_id, 'user_id', $owner_id );

		// test 3: the owner of a file via its export sources.
		$attachment_id = $this->create_local_attachment();
		$this->assertFalse( \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_owner_of_file( $attachment_id ) );
		update_post_meta( $attachment_id, 'efml_export_sources', array( $term_id ) );
		$this->assertSame( $owner_id, \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_owner_of_file( $attachment_id )->ID );
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
		$this->add_google_response(
			'DELETE',
			'files/new123',
			404,
			array(
				'error' => array(
					'code'    => 404,
					'message' => 'File not found',
				),
			)
		);
		$this->assertFalse( $this->get_export()->delete_exported_file( 'https://example.com/file', array(), $attachment_id ) );
		$this->assertStringContainsString( 'could not be delete', $this->get_log_entries( 'https://example.com/file' )[0]['log'] );

		// test 3: without access token.
		update_option( 'eml_google_drive_access_tokens', array() );
		$this->assertFalse( $this->get_export()->delete_exported_file( 'https://example.com/file', array(), $attachment_id ) );
	}

	/**
	 * Return the query to search a folder in Google Drive.
	 *
	 * @param string $name      The name of the folder.
	 * @param string $parent_id The ID of the parent folder.
	 *
	 * @return string
	 */
	private function get_folder_query( string $name, string $parent_id ): string {
		return "name = '" . $name . "' and mimeType = 'application/vnd.google-apps.folder' and '" . $parent_id . "' in parents and trashed = false";
	}

	/**
	 * Test the export of a file into folders: an existing folder is used, a missing folder is created.
	 *
	 * @return void
	 */
	public function test_export_file_into_folders(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->assertTrue( $this->get_export()->supports_folders() );

		// the folder "2026" exists in the directory, the folder "11" must be created in it.
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array( array( 'id' => 'folder2026' ) ) ), array( 'q' => $this->get_folder_query( '2026', 'folder1' ) ) );
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array() ), array( 'q' => $this->get_folder_query( '11', 'folder2026' ) ) );
		$this->add_google_response( 'POST', 'files', 200, array( 'id' => 'folder11' ) );
		$this->add_upload_responses();

		$url = $this->get_export()->export_file( $attachment_id, 'folder1/2026/11/' . $file_name, array( 'directory' => 'folder1/' ) );
		$this->assertEquals( 'https://drive.usercontent.google.com/download?id=new123&export=download', $url );

		// check the requests: search, search, create the folder, the metadata, the content of the file and the sharing.
		$requests = $this->get_google_requests();
		$this->assertCount( 6, $requests );
		$folder = json_decode( $requests[2]['body'], true );
		$this->assertEquals( '11', $folder['name'] );
		$this->assertEquals( 'application/vnd.google-apps.folder', $folder['mimeType'] );
		$this->assertEquals( array( 'folder2026' ), $folder['parents'] );
		$metadata = json_decode( $requests[3]['body'], true );
		$this->assertEquals( $file_name, $metadata['name'] );
		$this->assertEquals( array( 'folder11' ), $metadata['parents'] );
	}

	/**
	 * Test the export of a file into a folder in the root of Google Drive.
	 *
	 * @return void
	 */
	public function test_export_file_into_folder_in_root(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array( array( 'id' => 'folder2026' ) ) ), array( 'q' => $this->get_folder_query( '2026', 'root' ) ) );
		$this->add_upload_responses();

		// without directory.
		$this->assertIsString( $this->get_export()->export_file( $attachment_id, '/2026/' . $file_name, array( 'directory' => '' ) ) );
		$metadata = json_decode( $this->get_google_requests()[1]['body'], true );
		$this->assertEquals( array( 'folder2026' ), $metadata['parents'] );
	}

	/**
	 * Test the export of a file into the main directory of the directory listing, which is the root of Google Drive.
	 *
	 * @return void
	 */
	public function test_export_file_into_main_directory(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$directory     = \ExternalFilesFromGoogleDrive\GoogleDrive::get_instance()->get_default_directory();
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array( array( 'id' => 'folder2026' ) ) ), array( 'q' => $this->get_folder_query( '2026', 'root' ) ) );
		$this->add_upload_responses();

		// without folder: the file is saved in the root.
		$this->assertIsString( $this->get_export()->export_file( $attachment_id, trailingslashit( $directory ) . $file_name, array( 'directory' => $directory ) ) );
		$metadata = json_decode( $this->get_google_requests()[0]['body'], true );
		$this->assertArrayNotHasKey( 'parents', $metadata );

		// with folder: the folder is searched in the root.
		$this->assertIsString( $this->get_export()->export_file( $attachment_id, trailingslashit( $directory ) . '2026/' . $file_name, array( 'directory' => $directory ) ) );
		$metadata = json_decode( $this->get_google_requests()[4]['body'], true );
		$this->assertEquals( array( 'folder2026' ), $metadata['parents'] );
	}

	/**
	 * Test the export of a file, if a folder could not be created.
	 *
	 * @return void
	 */
	public function test_export_file_into_folder_with_error(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_google_response( 'GET', 'files', 200, array( 'files' => array() ) );
		$this->add_google_response(
			'POST',
			'files',
			403,
			array(
				'error' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
			)
		);

		$target = 'folder1/2026/' . $file_name;
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $target, array( 'directory' => 'folder1/' ) ) );
		$this->assertCount( 2, $this->get_google_requests() );
		$this->assertNotEmpty( $this->get_log_entries( $target ) );
		$this->assertEmpty( get_post_meta( $attachment_id, 'efml_google_drive_file_id', true ) );
	}

	/**
	 * Test the support of private files, which requires a global access token.
	 *
	 * @return void
	 */
	public function test_supports_private_files(): void {
		$this->set_mode( 'global' );
		$this->assertTrue( $this->get_export()->supports_private_files() );
		$this->set_mode( 'user' );
		$this->assertFalse( $this->get_export()->supports_private_files() );
	}

	/**
	 * Test the export of a private file.
	 *
	 * @return void
	 */
	public function test_export_private_file(): void {
		$this->connect();
		$attachment_id = $this->create_local_attachment();
		$this->add_upload_responses();

		$url = $this->get_export()->export_file( $attachment_id, 'https://drive.google.com/drive/', array( 'private' => true ) );

		// the internal URL is used.
		$this->assertEquals( 'https://drive.google.com/drive/new123', $url );
		$this->assertEquals( 'new123', get_post_meta( $attachment_id, 'efml_google_drive_file_id', true ) );
	}
}
