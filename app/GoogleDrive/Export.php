<?php
/**
 * File to handle export tasks for Google Drive.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\GoogleDrive;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Exception;
use ExternalFilesFromGoogleDrive\GoogleDrive;
use ExternalFilesInMediaLibrary\ExternalFiles\Export_Base;
use ExternalFilesInMediaLibrary\Plugin\Log;
use ExternalFilesFromGoogleDrive\Dependencies\Google\Http\MediaFileUpload;
use ExternalFilesFromGoogleDrive\Dependencies\Google\Service\Drive;
use ExternalFilesFromGoogleDrive\Dependencies\Google\Service\Drive\DriveFile;

/**
 * Object for export files to GoogleDrive.
 */
class Export extends Export_Base {
	/**
	 * Instance of actual object.
	 *
	 * @var Export|null
	 */
	private static ?Export $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Export
	 */
	public static function get_instance(): Export {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Export a file to this service. Returns the external URL if it was successfully and false if not.
	 *
	 * @param int                 $attachment_id The attachment ID.
	 * @param string              $target The target.
	 * @param array<string,mixed> $credentials The credentials.
	 * @return string|bool
	 */
	public function export_file( int $attachment_id, string $target, array $credentials ): string|bool {
		// get the main object.
		$google_drive_object = GoogleDrive::get_instance();

		// set the fields on it.
		$google_drive_object->set_fields( isset( $credentials['fields'] ) ? $credentials['fields'] : array() );

		// get the client.
		$client_obj = new Client( $google_drive_object->get_access_token() );
		$client     = $client_obj->get_client();

		// bail if client is not a Client object.
		if ( ! $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			return false;
		}

		// get the file path.
		$file_path = get_attached_file( $attachment_id, true );

		// bail if file path could not be loaded or the file does not exist.
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			// log this event.
			Log::get_instance()->create( __( 'Could not load file path for given attachment ID.', 'external-files-from-google-drive' ), $target, 'error' );

			// do nothing more.
			return false;
		}

		// get the file mime type.
		$mime_type = get_post_mime_type( $attachment_id );

		// bail if mime type could not be loaded.
		if ( ! is_string( $mime_type ) ) {
			return false;
		}

		// connect to Google Drive.
		$service = new Drive( $client );

		// set the query for the file metadata.
		$query = array(
			'name' => basename( $file_path ),
		);

		// add the directory, if set and if it is not the root of Google Drive.
		if ( ! empty( $credentials['directory'] ) && $google_drive_object->get_default_directory() !== $credentials['directory'] ) {
			$query['parents'] = array( str_replace( '/', '', $credentials['directory'] ) );
		}

		// get the folders of the target below the directory (e.g., for the folder structure of exports).
		$folders = $this->get_folders_from_target( $target, $credentials );

		// get or create the folders and use the last one as parent of the file.
		if ( ! empty( $folders ) ) {
			try {
				$query['parents'] = array( $this->get_folder_id( $service, isset( $query['parents'] ) ? $query['parents'][0] : 'root', $folders ) );
			} catch ( Exception $e ) {
				// log this event.
				Log::get_instance()->create( __( 'Folder for the file could not be created in Google Drive. Error:', 'external-files-from-google-drive' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', $target, 'error' );

				// do nothing more.
				return false;
			}
		}

		// set defer for the client to accept big files.
		$client->setDefer( true );

		// set the file metadata.
		$file_meta_data = new DriveFile( $query );

		// create the file per request.
		try {
			$request = $service->files->create( $file_meta_data, array( 'fields' => 'id' ) );

			// set chunk size for upload.
			$chunk_size = 1024 * 1024;
			$media      = new MediaFileUpload(
				$client,
				$request,
				$mime_type,
				'',
				true,
				$chunk_size
			);

			// set the file size.
			$media->setFileSize( absint( filesize( $file_path ) ) );

			/**
			 * Transfer the file per chunk.
			 *
			 * Hint: This is not compatible with WCS, but we need to read chunks here, which is not possible with WP_Filesystem (at present).
			 */
			$result = null;
			$handle = fopen( $file_path, 'rb' );
			if ( ! $handle ) {
				return false;
			}
			while ( ! feof( $handle ) ) {
				$chunk  = fread( $handle, $chunk_size );
				$result = $media->nextChunk( $chunk );
			}
			fclose( $handle );

			// disable defer for the client.
			$client->setDefer( false );

			// bail if result code is not 200.
			if ( 200 !== $media->getHttpResultCode() ) {
				// log this event.
				Log::get_instance()->create( __( 'File could not be uploaded to Google Drive. HTTP result code was:', 'external-files-from-google-drive' ) . ' <code>' . $media->getHttpResultCode() . '</code>', $target, 'error' );

				// do nothing more.
				return false;
			}

			// bail if result is not a DriveFile.
			if ( ! $result instanceof DriveFile ) {
				return false;
			}

			// get the file ID.
			$file_id = $result->id;

			// save the used file ID.
			update_post_meta( $attachment_id, 'efml_google_drive_file_id', $file_id );

			// return the internal URL for this file, if it should be saved privately.
			if ( ! empty( $credentials['private'] ) ) {
				return $google_drive_object->get_url_mark() . $file_id;
			}

			// return the public URL for this file.
			return $google_drive_object->get_public_url_for_file_id( $file_id );
		} catch ( Exception $e ) {
			// log this event.
			Log::get_instance()->create( __( 'File could not be uploaded to Google Drive. Error:', 'external-files-from-google-drive' ) . ' <code>' . $e->getMessage() . '</code>', $target, 'error' );

			// return false as we do not get anything.
			return false;
		}
	}

	/**
	 * Delete an exported file.
	 *
	 * @param string              $url           The URL to delete.
	 * @param array<string,mixed> $credentials   The credentials to use.
	 * @param int                 $attachment_id The attachment ID.
	 *
	 * @return bool
	 */
	public function delete_exported_file( string $url, array $credentials, int $attachment_id ): bool {
		// get the main object.
		$google_drive_object = GoogleDrive::get_instance();

		// set the fields on it.
		$google_drive_object->set_fields( isset( $credentials['fields'] ) ? $credentials['fields'] : array() );

		// get the client.
		$client_obj = new Client( $google_drive_object->get_access_token() );
		$client     = $client_obj->get_client();

		// bail if client is not a Client object.
		if ( ! $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			return false;
		}

		// get the file ID.
		$file_id = get_post_meta( $attachment_id, 'efml_google_drive_file_id', true );

		// bail if file ID is not set.
		if ( empty( $file_id ) ) {
			return false;
		}

		// connect to Google Drive.
		$service = new Drive( $client );

		// delete the file.
		try {
			$service->files->delete( $file_id );

			// return true for success.
			return true;
		} catch ( Exception $e ) {
			// log this event.
			Log::get_instance()->create( __( 'File could not be delete from Google Drive. Error:', 'external-files-from-google-drive' ) . ' <code>' . $e->getMessage() . '</code>', $url, 'error' );

			// return false as we do not get anything.
			return false;
		}
	}

	/**
	 * Return whether this service supports folders for exported files.
	 *
	 * @return bool
	 */
	public function supports_folders(): bool {
		return true;
	}

	/**
	 * Return the folders of the given target below the directory of the external source.
	 *
	 * Returns an empty list if the target is located directly in the directory of the external source
	 * or not located in it at all.
	 *
	 * @param string              $target The target path of the file.
	 * @param array<string,mixed> $credentials The credentials of the external source.
	 *
	 * @return array<int,string>
	 */
	private function get_folders_from_target( string $target, array $credentials ): array {
		// get the directory from the credentials.
		$directory = trailingslashit( isset( $credentials['directory'] ) && is_string( $credentials['directory'] ) ? $credentials['directory'] : '' );

		// bail if the target is not located in the directory.
		if ( ! str_starts_with( $target, $directory ) ) {
			return array();
		}

		// get the folders of the target below the directory.
		$folders = array();
		foreach ( explode( '/', dirname( substr( $target, strlen( $directory ) ) ) ) as $folder ) {
			// bail on relative folders.
			if ( '..' === $folder ) {
				return array();
			}

			// skip empty folders.
			if ( '' === $folder || '.' === $folder ) {
				continue;
			}

			// add the folder.
			$folders[] = $folder;
		}

		// return the list of folders.
		return $folders;
	}

	/**
	 * Return the ID of the last of the given folders below the given parent folder.
	 *
	 * Missing folders are created.
	 *
	 * @param Drive             $service The Google Drive service.
	 * @param string            $parent_id The ID of the parent folder.
	 * @param array<int,string> $folders The list of folder names.
	 *
	 * @return string
	 * @throws Exception Could throw exception.
	 */
	private function get_folder_id( Drive $service, string $parent_id, array $folders ): string {
		foreach ( $folders as $folder ) {
			// search the folder in the parent folder.
			$result = $service->files->listFiles(
				array(
					'q'        => "name = '" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $folder ) . "' and mimeType = 'application/vnd.google-apps.folder' and '" . $parent_id . "' in parents and trashed = false",
					'fields'   => 'files(id)',
					'pageSize' => 1,
				)
			);

			// use the existing folder.
			$files = $result->getFiles();
			if ( ! empty( $files ) ) {
				$parent_id = (string) $files[0]->getId();
				continue;
			}

			// create the folder.
			$created_folder = $service->files->create(
				new DriveFile(
					array(
						'name'     => $folder,
						'mimeType' => 'application/vnd.google-apps.folder',
						'parents'  => array( $parent_id ),
					)
				),
				array( 'fields' => 'id' )
			);
			$parent_id      = (string) $created_folder->getId();
		}

		// return the ID of the last folder.
		return $parent_id;
	}

	/**
	 * Return whether this service could save exported files privately.
	 *
	 * Private files are delivered via the proxy of External Files in Media Library from its cache. This requires
	 * the access token to be saved globally, as the files are loaded without a logged-in user.
	 *
	 * @since 1.4.0 Available since 1.4.0.
	 *
	 * @return bool
	 */
	public function supports_private_files(): bool {
		return GoogleDrive::get_instance()->is_access_token_global();
	}
}
