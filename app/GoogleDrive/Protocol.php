<?php
/**
 * File, which handles the Google Drive support as own protocol.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\GoogleDrive;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Error;
use ExternalFilesFromGoogleDrive\GoogleDrive;
use ExternalFilesInMediaLibrary\ExternalFiles\File;
use ExternalFilesInMediaLibrary\ExternalFiles\Files;
use ExternalFilesInMediaLibrary\ExternalFiles\Import;
use ExternalFilesInMediaLibrary\ExternalFiles\Protocol_Base;
use ExternalFilesInMediaLibrary\ExternalFiles\Protocols\Http;
use ExternalFilesInMediaLibrary\ExternalFiles\Results;
use ExternalFilesInMediaLibrary\ExternalFiles\Results\Url_Result;
use ExternalFilesInMediaLibrary\Plugin\Helper;
use ExternalFilesInMediaLibrary\Plugin\Log;
use ExternalFilesFromGoogleDrive\Dependencies\Google\Service\Drive;
use ExternalFilesFromGoogleDrive\Dependencies\Google\Service\Exception;
use JsonException;
use WP_Filesystem_Base;

/**
 * Object to handle different protocols.
 */
class Protocol extends Protocol_Base {
	/**
	 * Prefix of the cursor for the listing of a folder with its subfolders.
	 */
	private const FOLDER_CURSOR_PREFIX = 'efmlgd-folders:';

	/**
	 * The MIME type of folders in Google Drive.
	 */
	private const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

	/**
	 * The names and parents of files and folders in Google Drive, loaded during this request.
	 *
	 * @var array<string,array{name: string, parents: array<int,string>}>
	 */
	private static array $drive_entries = array();

	/**
	 * The internal protocol name.
	 *
	 * @var string
	 */
	protected string $name = 'google-drive';

	/**
	 * Return whether files could be imported from Google Drive in the actual request.
	 *
	 * The protocol is also registered for visitors (e.g. to deliver private files via the proxy), but only users
	 * with the capability for Google Drive are allowed to import files (as well as cron and WP CLI).
	 *
	 * @since 1.4.0 Available since 1.4.0.
	 *
	 * @return bool
	 */
	public function is_import_allowed_for_user(): bool {
		// use the check of the service, if it is available (External Files in Media Library 6.0.0 or newer).
		$service_obj = GoogleDrive::get_instance();
		if ( method_exists( $service_obj, 'is_import_permitted' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return $service_obj->is_import_permitted();
		}

		// otherwise check the capability for the service.
		return defined( 'EFML_SYNC_RUNNING' ) || wp_doing_cron() || Helper::is_cli() || current_user_can( 'efml_cap_' . $service_obj->get_name() );
	}

	/**
	 * Return whether the file using this protocol is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Check if URL is compatible with the given protocol.
	 *
	 * @return bool
	 */
	public function is_url_compatible(): bool {
		// bail if this is not a Google Drive URL.
		if ( ! str_starts_with( $this->get_url(), GoogleDrive::get_instance()->get_url_mark() ) ) {
			return false;
		}

		// return true to use this protocol.
		return true;
	}

	/**
	 * Check format of given URL.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return bool
	 */
	public function check_url( string $url ): bool {
		// bail if empty URL is given.
		if ( empty( $url ) ) {
			return false;
		}

		// return true as Google Drive URLs are available.
		return true;
	}

	/**
	 * Return infos to each given URL.
	 *
	 * @return array<int|string,array<string,mixed>|bool> List of files with its infos.
	 * @throws JsonException Could throw exception.
	 */
	public function get_url_infos(): array {
		// get the URL.
		$url = $this->get_url();

		// get the Google Drive object.
		$google_drive_obj = GoogleDrive::get_instance();

		// get the access token of the actual user.
		$access_token = $google_drive_obj->get_access_token();

		// bail if no access token is given.
		if ( empty( $access_token ) ) {
			// log event.
			Log::get_instance()->create( __( 'Access token missing to connect to Google Drive!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error' );

			// return an empty list as we could not analyse the file.
			return array();
		}

		// get the ID from the URL.
		$file_id = str_replace( $google_drive_obj->get_url_mark(), '', $url );

		// use the file list, if the use of subdirectories differs from the default (e.g. during a synchronization).
		if ( $this->is_directory() && $this->use_subdirectories() !== $this->get_subdirectories_default() ) {
			return $this->get_url_infos_via_file_list( $access_token );
		}

		// if file id is "eflm-import-all" do this.
		if ( 'eflm-import-all' === $file_id ) {
			return $this->import_all_files();
		}

		// bail if no file id could be loaded.
		if ( empty( $file_id ) ) {
			// log event.
			Log::get_instance()->create( __( 'Specified URL does not contain a file ID from Google Drive!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );

			// return an empty list as we could not analyse the file.
			return array();
		}

		// check for duplicate.
		if ( $this->check_for_duplicate( $url ) ) {
			Log::get_instance()->create( __( 'Specified URL already exist in your media library.', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );

			// return an empty list as we could not analyse the file.
			return array();
		}

		// get the client.
		$client_obj = new Client( $access_token );
		$client     = $client_obj->get_client();

		// bail if client is not a Client object.
		if ( ! $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			// log event.
			Log::get_instance()->create( __( 'Google Drive client could not be initiated!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );

			// return an empty list as we could not analyse the file.
			return array();
		}

		// connect to Google Drive.
		$service = new Drive( $client );

		// get the file or directory.
		try {
			// get directory data.
			if ( str_ends_with( $file_id, '/' ) ) {
				// get complete directory.
				$directories = GoogleDrive::get_instance()->get_directory_listing( $file_id );

				// collect the results.
				$results = array();

				// get the user ID.
				$user_id = get_current_user_id();

				/**
				 * Run action if we have files to check via Google Drive-protocol.
				 *
				 * @since 5.0.0 Available since 5.0.0.
				 *
				 * @param string $url   The URL to import.
				 * @param array<int|string,mixed> $entries List of matches (the URLs).
				 */
				do_action( 'efml_google_drive_directory_import_files', $url, $directories );

				// loop through the result to get the requested directory with its files.
				foreach ( $directories as $directory => $settings ) {
					// bail if directory does not match and if it is not the main directory.
					if ( $directory !== $file_id && false === stripos( $file_id, GoogleDrive::get_instance()->get_directory() ) ) {
						continue;
					}

					/**
					 * Run action just before the file check via Google Drive-protocol.
					 *
					 * @since 5.0.0 Available since 5.0.0.
					 *
					 * @param int|string $directory   The URL to import.
					 */
					do_action( 'efml_google_drive_directory_import_file_check', $directory );

					// save count of URLs.
					update_option( 'eml_import_url_max_' . $user_id, count( $settings['files'] ) );

					// add the files.
					foreach ( $settings['files'] as $file ) {
						// bail if this an AJAX-request and the file already exist in media library.
						if ( wp_doing_ajax() && Files::get_instance()->get_file_by_title( $file['title'] ) ) {
							continue;
						}

						// get the file data.
						$entry = $this->get_file_data( $service, $file['file'] );

						// bail if entry is empty.
						if ( empty( $entry ) ) {
							continue;
						}

						// add file to the list.
						$results[] = $entry;
					}
				}

				// return the resulting list of files to import.
				return $results;
			}

			// get the single file data.
			$entry = $this->get_file_data( $service, $file_id );

			// return the resulting array as list of files (although it is only one).
			return array(
				$entry,
			);
		} catch ( Exception $e ) {
			// log event.
			Log::get_instance()->create( __( 'Google Drive client could not download the requested file! Error:', 'external-files-from-google-drive' ) . ' <code>' . wp_json_encode( $e->getErrors() ) . '</code>', esc_url( $this->get_url() ), 'error' );

			// return an empty list as we could not analyse the file.
			return array();
		}
	}

	/**
	 * Return the files of the directory of the URL of this object via its file list, with their data for the import.
	 *
	 * Used for imports without import run, if the files of subdirectories should be used other than by default.
	 *
	 * @param array<string,mixed> $access_token The access token.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_url_infos_via_file_list( array $access_token ): array {
		// get the client.
		$client = ( new Client( $access_token ) )->get_client();

		// bail if client is not a Client object.
		if ( ! $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			Log::get_instance()->create( __( 'Google Drive client could not be initiated!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );
			return array();
		}

		// connect to Google Drive.
		$service = new Drive( $client );

		// get the URL mark.
		$url_mark = GoogleDrive::get_instance()->get_url_mark();

		// collect the files page by page.
		$results = array();
		$cursor  = '';
		do {
			// get the page.
			$page = $this->get_file_list( $cursor );

			// bail on errors.
			if ( false === $page ) {
				return $results;
			}

			// add the data of each file.
			foreach ( $page['files'] as $file ) {
				// bail if the file already exists in the media library.
				if ( $this->check_for_duplicate( $file['url'] ) ) {
					continue;
				}

				// get the file data.
				try {
					$entry = $this->get_file_data( $service, str_replace( $url_mark, '', $file['url'] ) );
				} catch ( Exception $e ) {
					Log::get_instance()->create( __( 'Google Drive client could not download the requested file! Error:', 'external-files-from-google-drive' ) . ' <code>' . wp_json_encode( $e->getErrors() ) . '</code>', esc_url( $file['url'] ), 'error' );
					continue;
				}

				// add it, if it could be loaded.
				if ( ! empty( $entry ) ) {
					$results[] = $entry;
				}
			}

			// get the next page.
			$cursor = $page['cursor'];
		} while ( '' !== $cursor );

		// return the files.
		return $results;
	}

	/**
	 * Return whether the file should be saved local (true) or not (false).
	 *
	 * @return bool
	 */
	public function should_be_saved_local(): bool {
		return true;
	}

	/**
	 * Return whether this URL could change its hosting.
	 *
	 * @return bool
	 */
	public function can_change_hosting(): bool {
		return false;
	}

	/**
	 * Return whether this protocol could list the files of a directory page by page.
	 *
	 * @return bool
	 */
	public function supports_file_list(): bool {
		return true;
	}

	/**
	 * Return the ID of the file or directory in the URL of this object.
	 *
	 * @return string
	 */
	private function get_file_id(): string {
		return str_replace( GoogleDrive::get_instance()->get_url_mark(), '', $this->get_url() );
	}

	/**
	 * Return whether the URL of this object is the main directory or the import of all files.
	 *
	 * @return bool
	 */
	private function is_main_directory(): bool {
		// get the ID.
		$file_id = $this->get_file_id();

		// return whether all files or the main directory should be used.
		return 'eflm-import-all' === $file_id || false !== stripos( $file_id, GoogleDrive::get_instance()->get_directory() );
	}

	/**
	 * Return whether this protocol could use the files of subdirectories during a synchronization.
	 *
	 * @since 2.1.0 Available since 2.1.0.
	 *
	 * @return bool
	 */
	public function supports_subdirectories(): bool {
		return true;
	}

	/**
	 * Return whether this protocol uses the files of subdirectories by default.
	 *
	 * The main directory and the import of all files have always used all files of Google Drive, a folder only its
	 * own files.
	 *
	 * @since 2.1.0 Available since 2.1.0.
	 *
	 * @return bool
	 */
	public function get_subdirectories_default(): bool {
		return $this->is_main_directory();
	}

	/**
	 * Return the path of the directory of the given file URL relative to the folder of the URL of this object.
	 *
	 * The URLs of files in Google Drive contain only their ID. So the path is created from the names of the parent
	 * folders up to the folder of this object (or up to the root of Google Drive for the main directory). The data
	 * of the folders is loaded once per request.
	 *
	 * @since 2.1.0 Available since 2.1.0.
	 *
	 * @param string $file_url The URL of the file.
	 *
	 * @return string
	 */
	public function get_relative_directory( string $file_url ): string {
		// get the URL mark.
		$url_mark = GoogleDrive::get_instance()->get_url_mark();

		// bail if this is not a URL of Google Drive.
		if ( ! str_starts_with( $file_url, $url_mark ) ) {
			return '';
		}

		// get the ID of the file and of the folder of this object (empty for the main directory).
		$file_id   = rtrim( substr( $file_url, strlen( $url_mark ) ), '/' );
		$folder_id = $this->is_main_directory() ? '' : rtrim( $this->get_file_id(), '/' );

		// collect the names of the parent folders.
		$names   = array();
		$current = $file_id;
		for ( $level = 0; $level < 50; $level++ ) {
			// get the data of the actual entry.
			$entry = $this->get_drive_entry( $current );

			// bail if it could not be loaded or has no parent.
			if ( false === $entry || empty( $entry['parents'] ) ) {
				// the root of Google Drive is reached: the path is complete for the main directory.
				return '' === $folder_id && false !== $entry ? implode( '/', array_reverse( array_slice( $names, 0, -1 ) ) ) : '';
			}

			// get the first parent.
			$parent_id = $entry['parents'][0];

			// the folder of this object is reached: the path is complete.
			if ( '' !== $folder_id && $parent_id === $folder_id ) {
				return implode( '/', array_reverse( $names ) );
			}

			// get the parent folder.
			$parent = $this->get_drive_entry( $parent_id );
			if ( false === $parent ) {
				return '';
			}

			// add its name and continue with it.
			$names[] = $parent['name'];
			$current = $parent_id;
		}

		// the path is too deep.
		return '';
	}

	/**
	 * Return the name and the parents of the given file or folder in Google Drive, loaded once per request.
	 *
	 * @param string $id The ID of the file or folder.
	 *
	 * @return array{name: string, parents: array<int,string>}|false
	 */
	private function get_drive_entry( string $id ): array|false {
		// return the data, if it has already been loaded.
		if ( isset( self::$drive_entries[ $id ] ) ) {
			return self::$drive_entries[ $id ];
		}

		// get the access token.
		$access_token = GoogleDrive::get_instance()->get_access_token();
		if ( empty( $access_token ) ) {
			return false;
		}

		// get the client.
		$client = ( new Client( $access_token ) )->get_client();
		if ( ! $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			return false;
		}

		// load the data.
		try {
			$file_obj = ( new Drive( $client ) )->files->get( $id, array( 'fields' => 'id,name,parents' ) );
		} catch ( Exception $e ) {
			Log::get_instance()->create( __( 'The folder of a file could not be loaded from Google Drive. Error:', 'external-files-from-google-drive' ) . ' <code>' . wp_json_encode( $e->getErrors() ) . '</code>', esc_url( $this->get_url() ), 'error', 2 );
			return false;
		}

		// save and return the data.
		self::$drive_entries[ $id ] = array(
			'name'    => (string) $file_obj->getName(),
			'parents' => array_values( array_map( 'strval', (array) $file_obj->getParents() ) ),
		);
		return self::$drive_entries[ $id ];
	}

	/**
	 * Return whether the files of subdirectories should be used.
	 *
	 * Older versions of "External Files in Media Library" do not know this setting: the default is used then like
	 * before.
	 *
	 * @return bool
	 */
	private function use_subdirectories(): bool {
		// bail if the main plugin does not support the setting.
		if ( ! method_exists( $this, 'is_subdirectories_enabled' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return $this->get_subdirectories_default();
		}

		// return the setting.
		return $this->is_subdirectories_enabled();
	}

	/**
	 * Return the state of the listing of a folder with its subfolders from the given cursor.
	 *
	 * The state contains the folders, which are still to be listed (with their depth), and the token for the next
	 * page of the first of them. It is saved in the cursor between the pages.
	 *
	 * @param string $cursor    The cursor.
	 * @param string $folder_id The ID of the requested folder.
	 *
	 * @return array{f: array<int,array{id: string, d: int}>, t: string}|false
	 */
	private function get_folder_state( string $cursor, string $folder_id ): array|false {
		// start with the requested folder.
		if ( '' === $cursor ) {
			return array(
				'f' => array(
					array(
						'id' => $folder_id,
						'd'  => 0,
					),
				),
				't' => '',
			);
		}

		// bail if this is not a cursor of this listing.
		if ( ! str_starts_with( $cursor, self::FOLDER_CURSOR_PREFIX ) ) {
			return false;
		}

		// get the state.
		$state = json_decode( (string) base64_decode( substr( $cursor, strlen( self::FOLDER_CURSOR_PREFIX ) ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		// bail if the state is not usable.
		if ( ! is_array( $state ) || empty( $state['f'] ) || ! is_array( $state['f'] ) ) {
			return false;
		}

		// collect the folders.
		$folders = array();
		foreach ( $state['f'] as $folder ) {
			if ( is_array( $folder ) && ! empty( $folder['id'] ) ) {
				$folders[] = array(
					'id' => (string) $folder['id'],
					'd'  => absint( $folder['d'] ?? 0 ),
				);
			}
		}

		// bail if no folder is left.
		if ( empty( $folders ) ) {
			return false;
		}

		// return the state.
		return array(
			'f' => $folders,
			't' => (string) ( $state['t'] ?? '' ),
		);
	}

	/**
	 * Return the cursor for the given state of the listing of a folder with its subfolders.
	 *
	 * @param array{f: array<int,array{id: string, d: int}>, t: string} $state The state.
	 *
	 * @return string
	 */
	private function get_folder_cursor( array $state ): string {
		// bail if no folder is left.
		if ( empty( $state['f'] ) ) {
			return '';
		}

		// return the state as cursor.
		return self::FOLDER_CURSOR_PREFIX . base64_encode( (string) wp_json_encode( $state ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Return whether the URL of this object is a directory or the import of all files.
	 *
	 * @return bool
	 */
	public function is_directory(): bool {
		// get the ID.
		$file_id = $this->get_file_id();

		// return whether all files or a directory should be imported.
		return 'eflm-import-all' === $file_id || str_ends_with( $file_id, '/' );
	}

	/**
	 * Return one page of the files in the requested directory, in the main directory or of all files.
	 *
	 * Like the import of a directory: a folder contains only its own files, the main directory all files. During a
	 * synchronization this could be changed: a folder could also contain the files of its subfolders (one folder
	 * after the other, the cursor contains the folders to list), the main directory only its own files.
	 *
	 * @param string $cursor The cursor for the requested page.
	 *
	 * @return array{files: array<int,array{url: string, media-url?: string, filesize?: int, last-modified?: int}>, cursor: string}|false
	 */
	public function get_file_list( string $cursor ): array|false {
		// get the Google Drive object.
		$google_drive_obj = GoogleDrive::get_instance();

		// get the access token of the actual user.
		$access_token = $google_drive_obj->get_access_token();

		// bail if no access token is given.
		if ( empty( $access_token ) ) {
			Log::get_instance()->create( __( 'Access token missing to connect to Google Drive!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );
			return false;
		}

		// get the client.
		$client = ( new Client( $access_token ) )->get_client();

		// bail if client is not a Client object.
		if ( ! $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			Log::get_instance()->create( __( 'Google Drive client could not be initiated!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );
			return false;
		}

		// connect to Google Drive.
		$service = new Drive( $client );

		// get the requested ID and the mode.
		$file_id    = $this->get_file_id();
		$import_all = 'eflm-import-all' === $file_id;
		$folder_id  = ! $import_all && false === stripos( $file_id, $google_drive_obj->get_directory() ) ? rtrim( $file_id, '/' ) : '';

		// get whether the files of subdirectories should be used.
		$use_subdirectories = $this->use_subdirectories();

		// get the folder, whose files are requested, and the token of the page.
		$state      = false;
		$parent_id  = $folder_id;
		$page_token = $cursor;
		if ( '' === $folder_id && ! $use_subdirectories ) {
			// use only the files in the root of Google Drive.
			$parent_id = 'root';
		} elseif ( '' !== $folder_id && $use_subdirectories ) {
			// use the folder and its subfolders, one after the other.
			$state = $this->get_folder_state( $cursor, $folder_id );
			if ( false === $state ) {
				Log::get_instance()->create( __( 'The cursor for the list of files from Google Drive is not valid.', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );
				return false;
			}
			$parent_id  = $state['f'][0]['id'];
			$page_token = $state['t'];
		}

		// collect the request query.
		$query = array(
			'fields'   => 'files(id,name,mimeType,parents,fileExtension,createdTime,modifiedTime,size,trashed),nextPageToken',
			'pageSize' => min( 1000, $this->get_file_list_page_size() ),
			'orderBy'  => 'name_natural',
		);

		// filter the query like for the directory listing.
		if ( ! $import_all ) {
			/** This filter is documented in app/GoogleDrive.php */
			$query = apply_filters( 'efmlgd_google_drive_query_params', $query, $file_id );
		}

		// load only the files of the requested folder.
		if ( '' !== $parent_id ) {
			$condition  = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $parent_id ) . "' in parents";
			$query['q'] = empty( $query['q'] ) ? $condition : '(' . $query['q'] . ') and ' . $condition;
		}

		// set the token for the requested page.
		if ( '' !== $page_token ) {
			$query['pageToken'] = $page_token;
		}

		// get the page.
		try {
			$results = $service->files->listFiles( $query );
		} catch ( Exception $e ) {
			// skip a subfolder, which could not be read, and continue with the next folder.
			if ( false !== $state && $state['f'][0]['d'] > 0 ) {
				return $this->skip_folder( $state, $e );
			}

			Log::get_instance()->create( __( 'List of files could not be loaded from Google Drive. Error:', 'external-files-from-google-drive' ) . ' <code>' . wp_json_encode( $e->getErrors() ) . '</code>', esc_url( $this->get_url() ), 'error', 0, Import::get_instance()->get_identifier() );
			return false;
		}

		// get the files.
		$files = $results->getFiles();
		if ( ! $import_all ) {
			/** This filter is documented in app/GoogleDrive.php */
			$files = apply_filters( 'efmlgd_google_drive_files', $files );
		}

		// collect the files.
		$list = array();
		foreach ( $files as $file_obj ) {
			// remember subfolders, whose files should be used, and use them after the actual folder.
			if ( false !== $state && self::FOLDER_MIME_TYPE === $file_obj->getMimeType() ) {
				$depth = $state['f'][0]['d'] + 1;
				if ( $depth <= $this->get_subdirectories_max_depth() && ! in_array( (string) $file_obj->getName(), $this->get_excluded_directories(), true ) ) {
					$state['f'][] = array(
						'id' => (string) $file_obj->getId(),
						'd'  => $depth,
					);
				}
				continue;
			}

			// check the file like the import of all files (trashed files are hidden in the directory listing via the query).
			if ( $import_all && ( $file_obj->getTrashed() || empty( $file_obj->getFileExtension() ) ) ) {
				continue;
			}
			if ( ! $import_all ) {
				$false = false;
				/** This filter is documented in app/GoogleDrive.php */
				if ( apply_filters( 'efmlgd_google_drive_hide_file', $false, $file_obj, $file_id ) ) {
					continue;
				}

				// bail if file type is not allowed.
				$mime_type = wp_check_filetype( $file_obj->getName() );
				if ( empty( $mime_type['type'] ) ) {
					continue;
				}
			}

			// add the file, which the import saves with its public URL, if it is public.
			$list[] = array(
				'url'           => $google_drive_obj->get_url_mark() . $file_obj->getId(),
				'media-url'     => $google_drive_obj->get_public_url_for_file_id( $file_obj->getId() ),
				'filesize'      => absint( $file_obj->getSize() ),
				'last-modified' => absint( strtotime( (string) $file_obj->getCreatedTime() ) ),
			);
		}

		// get the token for the next page.
		$token = (string) $results->getNextPageToken();

		// return the page of a folder with its subfolders: continue with the next page or the next folder.
		if ( false !== $state ) {
			if ( '' !== $token && $token !== $page_token ) {
				$state['t'] = $token;
			} else {
				array_shift( $state['f'] );
				$state['t'] = '';
			}

			return array(
				'files'  => $list,
				'cursor' => $this->get_folder_cursor( $state ),
			);
		}

		// return the page.
		return array(
			'files'  => $list,
			'cursor' => $token !== $cursor ? $token : '',
		);
	}

	/**
	 * Skip the actual folder of the given state, as it could not be read, and return an empty page for the next folder.
	 *
	 * Files in Google Drive have no path in their URL. So no file of the external source is deleted during this
	 * synchronization, as the files of the skipped folder could not be recognized.
	 *
	 * @param array{f: array<int,array{id: string, d: int}>, t: string} $state The state of the listing.
	 * @param Exception                                                 $e     The error.
	 *
	 * @return array{files: array<int,array{url: string, media-url?: string, filesize?: int, last-modified?: int}>, cursor: string}
	 */
	private function skip_folder( array $state, Exception $e ): array {
		// get the URL of the folder.
		$url = GoogleDrive::get_instance()->get_url_mark() . $state['f'][0]['id'] . '/';

		// log this event.
		Log::get_instance()->create( __( 'The folder could not be read from Google Drive and has been skipped. Error:', 'external-files-from-google-drive' ) . ' <code>' . wp_json_encode( $e->getErrors() ) . '</code>', esc_url( $url ), 'error', 0, Import::get_instance()->get_identifier() );

		// protect all files of Google Drive from the deletion after the synchronization.
		$this->skip_subdirectory( GoogleDrive::get_instance()->get_url_mark() );

		// continue with the next folder.
		array_shift( $state['f'] );
		$state['t'] = '';

		// return the empty page.
		return array(
			'files'  => array(),
			'cursor' => $this->get_folder_cursor( $state ),
		);
	}

	/**
	 * Import all files from Google Drive.
	 *
	 * @return array<int,array<string,mixed>>
	 * @throws JsonException Could throw exception.
	 */
	private function import_all_files(): array {
		// get the Google Drive object.
		$google_drive_obj = GoogleDrive::get_instance();

		// get the access token of the actual user.
		$access_token = $google_drive_obj->get_access_token();

		// bail if no access token is given.
		if ( empty( $access_token ) ) {
			// log event.
			Log::get_instance()->create( __( 'Access token missing to connect to Google Drive!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error' );

			// return an empty list and to nothing more.
			return array();
		}

		// get the client.
		$client_obj = new Client( $access_token );
		$client     = $client_obj->get_client();

		// bail if client is not a Client object.
		if ( ! $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			// log event.
			Log::get_instance()->create( __( 'Google Drive client could not be initiated!', 'external-files-from-google-drive' ), esc_url( $this->get_url() ), 'error' );

			// return an empty list as we could not analyse the file.
			return array();
		}

		// connect to Google Drive.
		$service = new Drive( $client );

		// collect the request query.
		$query = array(
			'fields'   => 'files(fileExtension,iconLink,id,imageMediaMetadata(height,rotation,width,time),mimeType,createdTime,modifiedTime,name,parents,size,hasThumbnail,thumbnailLink,trashed),nextPageToken',
			'pageSize' => 1000,
			'orderBy'  => 'name_natural',
		);

		// get the files (all parts of the list).
		try {
			$files = GoogleDrive::get_instance()->get_all_files( $service, $query );
		} catch ( Exception $e ) {
			// log event.
			Log::get_instance()->create( __( 'List of files could not be loaded from Google Drive. Error:', 'external-files-from-google-drive' ), esc_url( $this->get_url() ) . ' <code>' . wp_json_encode( $e->getErrors() ) . '</code>', 'error' );

			// return an empty list as we could not analyse the file.
			return array();
		}

		// bail if list is empty.
		if ( empty( $files ) ) {
			return array();
		}

		// create list of files to import.
		$list = array();

		// loop through the files and add them to the list.
		foreach ( $files as $file_obj ) {
			// hide trashed files.
			if ( $file_obj->getTrashed() ) {
				continue;
			}

			// bail if file has no extension.
			if ( empty( $file_obj->getFileExtension() ) ) {
				continue;
			}

			// check for duplicate.
			if ( $this->check_for_duplicate( $google_drive_obj->get_url_mark() . $file_obj->getId() ) ) {
				Log::get_instance()->create( __( 'Specified URL already exist in your media library.', 'external-files-from-google-drive' ), esc_url( $google_drive_obj->get_url_mark() . $file_obj->getId() ), 'error' );

				continue;
			}

			// get the file.
			try {
				$response = $service->files->get( $file_obj->getId(), array( 'alt' => 'media' ) );
			} catch ( Exception $e ) {
				// log event.
				Log::get_instance()->create( __( 'Google Drive client could not download a requested file during mass-import! Error:', 'external-files-from-google-drive' ) . ' <code>' . wp_json_encode( $e->getErrors() ) . '</code><br>' . __( 'Requested file:', 'external-files-from-google-drive' ) . ' <code>' . wp_json_encode( $file_obj ) . '</code>', esc_url( $google_drive_obj->get_url_mark() . $file_obj->getId() ), 'error' );

				continue;
			}

			// initialize basic array for file data.
			$entry = array(
				'title'         => $file_obj->getName(),
				'local'         => true,
				'url'           => $google_drive_obj->get_url_mark() . $file_obj->getId(),
				'last-modified' => absint( strtotime( $file_obj->getCreatedTime() ) ),
			);

			// get WP Filesystem-handler.
			$wp_filesystem = Helper::get_wp_filesystem();

			// set the file as tmp-file for import.
			$entry['tmp-file'] = wp_tempnam();

			// and save the file there.
			try {
				$this->save_response_to_file( $response, $entry['tmp-file'] );
			} catch ( Error $e ) {
				// clean up the tmp file.
				$wp_filesystem->delete( $entry['tmp-file'] );

				// create the error entry.
				$error_obj = new Url_Result();
				/* translators: %1$s will be replaced by a URL. */
				$error_obj->set_result_text( sprintf( __( 'Error occurred during requesting this file. Check the <a href="%1$s" target="_blank">log</a> for detailed information.', 'external-files-from-google-drive' ), Helper::get_log_url( $this->get_url() ) ) );
				$error_obj->set_url( $this->get_url() );
				$error_obj->set_error( true );

				// add the error object to the list of errors.
				Results::get_instance()->add( $error_obj );

				// add log entry.
				Log::get_instance()->create( __( 'The following error occurred:', 'external-files-from-google-drive' ) . ' <code>' . $e->getMessage() . '</code>', $this->get_url(), 'error' );

				// do nothing more.
				return array();
			}

			// set the file size.
			$entry['filesize'] = $wp_filesystem->size( $entry['tmp-file'] );

			// set the mime type.
			$mime_type          = wp_check_filetype( $entry['title'] );
			$entry['mime-type'] = $mime_type['type'];

			// add entry to the list.
			$list[] = $entry;
		}

		// return resulting list of files.
		return $list;
	}

	/**
	 * Return temp file from given URL.
	 *
	 * Exported files (private and public) are loaded via the API with the connection of the owner of their external
	 * source (or of the website), so they are delivered even if their public link does not work (e.g. files exported
	 * before they have been shared). Other files and exported files without usable connection are loaded via HTTP.
	 *
	 * @param string             $url The given URL.
	 * @param WP_Filesystem_Base $filesystem The file system handler.
	 *
	 * @return bool|string
	 */
	public function get_temp_file( string $url, WP_Filesystem_Base $filesystem ): bool|string {
		// bail if URL is empty.
		if ( empty( $url ) ) {
			return false;
		}

		// get the external file for this URL (or the file, which this protocol is used for, e.g. for its sizes).
		$external_file_obj = Files::get_instance()->get_file_by_url( $url );
		if ( ! $external_file_obj instanceof File && method_exists( $this, 'get_external_file' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$external_file_obj = $this->get_external_file();
		}

		// check whether the file is private.
		$is_private = $external_file_obj instanceof File && method_exists( $external_file_obj, 'is_private' ) && $external_file_obj->is_private(); // @phpstan-ignore function.alreadyNarrowedType

		// get the Google Drive object.
		$google_drive_obj = GoogleDrive::get_instance();
		$google_drive_obj->set_fields( $this->get_fields() );

		// get the ID of the file in Google Drive and the client with the connection of the owner of its external
		// source, if it is an exported file.
		$file_id = '';
		$client  = false;
		if ( $external_file_obj instanceof File ) {
			$file_id = $this->get_file_id_of_exported_file( $url, $external_file_obj );
			if ( '' !== $file_id ) {
				$client = $google_drive_obj->get_client_for_user( $google_drive_obj->get_owner_of_file( $external_file_obj->get_id() ) );
			}
		}

		// load the exported file via the API.
		if ( $client instanceof \ExternalFilesFromGoogleDrive\Dependencies\Google\Client ) {
			$tmp_file = $this->load_via_api( $client, $file_id, $url, $is_private );

			// return the file, if it could be loaded or if it is private (it could not be loaded via HTTP).
			if ( false !== $tmp_file || $is_private ) {
				return $tmp_file;
			}
		}

		// bail for private files without client.
		if ( $is_private ) {
			// log event.
			Log::get_instance()->create( __( 'Google Drive client could not be initiated!', 'external-files-from-google-drive' ), esc_url( $url ), 'error' );

			// do nothing more.
			return false;
		}

		// use the HTTP handler for all other files.
		$http_protocol_handler = new Http( $url );
		$http_protocol_handler->set_fields( $this->get_fields() );
		return $http_protocol_handler->get_temp_file( $url, $filesystem );
	}

	/**
	 * Return the ID in Google Drive of the given URL of an exported file (or of one of its sizes), empty for files,
	 * which have not been exported.
	 *
	 * @param string $url               The URL.
	 * @param File   $external_file_obj The external file.
	 *
	 * @return string
	 */
	private function get_file_id_of_exported_file( string $url, File $external_file_obj ): string {
		// bail if the file has not been exported to Google Drive.
		$exported_file_id = get_post_meta( $external_file_obj->get_id(), 'efml_google_drive_file_id', true );
		if ( ! is_string( $exported_file_id ) || '' === $exported_file_id ) {
			return '';
		}

		// get the ID from the URL: the internal URL of private files or the public URL with the parameter "id".
		$google_drive_obj = GoogleDrive::get_instance();
		if ( str_starts_with( $url, $google_drive_obj->get_url_mark() ) ) {
			$file_id = substr( $url, strlen( $google_drive_obj->get_url_mark() ) );
		} else {
			$query = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$file_id = isset( $query['id'] ) && is_string( $query['id'] ) ? $query['id'] : '';
		}

		// use the ID saved during the export, if the URL does not contain one.
		return '' !== $file_id ? $file_id : $exported_file_id;
	}

	/**
	 * Load the given file via the API of Google Drive into a temporary file and return its path, false on errors.
	 *
	 * @param \ExternalFilesFromGoogleDrive\Dependencies\Google\Client $client     The client.
	 * @param string                                                   $file_id    The ID of the file.
	 * @param string                                                   $url        The URL of the file (for the log).
	 * @param bool                                                     $is_private Whether the file is private.
	 *
	 * @return string|false
	 */
	private function load_via_api( \ExternalFilesFromGoogleDrive\Dependencies\Google\Client $client, string $file_id, string $url, bool $is_private ): string|false {
		// get the tmp file name.
		$tmp_file = wp_tempnam();

		// load the file and save it in parts, so it is not loaded completely into memory.
		try {
			$service  = new Drive( $client );
			$response = $service->files->get( $file_id, array( 'alt' => 'media' ) );
			$this->save_response_to_file( $response, $tmp_file );
		} catch ( \Exception | Error $e ) {
			// log this event: as error for private files, as info for public files (they are loaded via HTTP then).
			if ( $is_private ) {
				Log::get_instance()->create( __( 'Private file could not be loaded from Google Drive:', 'external-files-from-google-drive' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', esc_url( $url ), 'error' );
			} else {
				Log::get_instance()->create( __( 'File could not be loaded via the API of Google Drive, its public link is used:', 'external-files-from-google-drive' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', esc_url( $url ), 'info', 2 );
			}

			// delete the tmp file.
			wp_delete_file( $tmp_file );

			// return false as we do not get the file.
			return false;
		}

		// return the path to the tmp file.
		return $tmp_file;
	}

	/**
	 * Return the data-array for a single file from Google Drive.
	 *
	 * @param Drive  $service The Drive object.
	 * @param string $file_id The requested file ID.
	 *
	 * @return array<string,mixed>
	 * @throws Exception Could throw exception.
	 */
	private function get_file_data( Drive $service, string $file_id ): array {
		// get the Google Drive object.
		$google_drive_object = GoogleDrive::get_instance();

		// get file data.
		$file_obj  = $service->files->get( $file_id );
		$response  = $service->files->get( $file_id, array( 'alt' => 'media' ) );
		$file_data = $service->files->get( $file_id, array( 'fields' => 'createdTime,name' ) );

		// check if file could be saved local and set the URL.
		$local = ! $google_drive_object->is_file_public( $file_obj, $service );
		$url   = $google_drive_object->get_url_mark() . $file_id;
		if ( ! $local ) {
			$url = $google_drive_object->get_public_url_for_file_id( $file_id );
		}

		// get the actual user.
		$user_id = get_current_user_id();

		// update title for progress.
		/* translators: %1$s will be replaced by the URL which is imported. */
		update_option( 'eml_import_title_' . $user_id, sprintf( __( 'Get URL %1$s from Google Drive', 'external-files-from-google-drive' ), esc_html( Helper::shorten_url( $file_data->getName() ) ) ) );

		// update counter for URLs.
		update_option( 'eml_import_url_count_' . $user_id, absint( get_option( 'eml_import_url_count_' . $user_id, 0 ) ) + 1 );

		// initialize basic array for file data.
		$entry = array(
			'title'         => $file_data->getName(),
			'local'         => $local,
			'url'           => $url,
			'last-modified' => absint( strtotime( $file_data->getCreatedTime() ) ),
		);

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// set the file as tmp-file for import.
		$entry['tmp-file'] = wp_tempnam();

		// and save the file there.
		try {
			$this->save_response_to_file( $response, $entry['tmp-file'] );
		} catch ( Error $e ) {
			// clean up the tmp file.
			$wp_filesystem->delete( $entry['tmp-file'] );

			// create the error entry.
			$error_obj = new Url_Result();
			/* translators: %1$s will be replaced by a URL. */
			$error_obj->set_result_text( sprintf( __( 'Error occurred during requesting this file. Check the <a href="%1$s" target="_blank">log</a> for detailed information.', 'external-files-from-google-drive' ), Helper::get_log_url( $this->get_url() ) ) );
			$error_obj->set_url( $this->get_url() );
			$error_obj->set_error( true );

			// add the error object to the list of errors.
			Results::get_instance()->add( $error_obj );

			// add log entry.
			Log::get_instance()->create( __( 'The following error occurred:', 'external-files-from-google-drive' ) . ' <code>' . $e->getMessage() . '</code>', $this->get_url(), 'error' );

			// do nothing more.
			return array();
		}

		// set the file size.
		$entry['filesize'] = $wp_filesystem->size( $entry['tmp-file'] );

		// set the mime type.
		$mime_type          = wp_check_filetype( $entry['title'] );
		$entry['mime-type'] = $mime_type['type'];

		// return the entry.
		return $entry;
	}

	/**
	 * Return whether this URL could be checked for availability.
	 *
	 * @return bool
	 */
	public function can_check_availability(): bool {
		return false;
	}

	/**
	 * Return whether URLs with this protocol are reachable via HTTP.
	 *
	 * This is not the availability of the URL.
	 *
	 * @return bool
	 */
	public function is_url_reachable(): bool {
		return false;
	}

	/**
	 * Return the title of this protocol object.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return 'Google Drive';
	}

	/**
	 * Save the body of the given response of Google Drive in the given file.
	 *
	 * The body is read and written in parts, so the file is not loaded completely into memory.
	 *
	 * @since 1.4.0 Available since 1.4.0.
	 *
	 * @param mixed  $response The response of the request for the content of a file ("alt" = "media").
	 * @param string $file     The absolute path to the file.
	 *
	 * @return void
	 * @throws Error If the response has no body or the file could not be written.
	 */
	private function save_response_to_file( mixed $response, string $file ): void {
		// bail if the response has no body.
		if ( ! $response instanceof \ExternalFilesFromGoogleDrive\Dependencies\Psr\Http\Message\ResponseInterface ) {
			throw new Error( 'Google Drive returns no file content.' );
		}

		// get the body.
		$body = $response->getBody();

		// open the file.
		$handle = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// bail if the file could not be opened.
		if ( false === $handle ) {
			throw new Error( 'Local file could not be opened for the download.' );
		}

		// rewind the body, if possible, to save it completely.
		if ( $body->isSeekable() ) {
			$body->rewind();
		}

		// save the file in parts.
		while ( ! $body->eof() ) {
			$written = fwrite( $handle, $body->read( 1024 * 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			// bail if the file could not be written.
			if ( false === $written ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				throw new Error( 'Local file could not be written.' );
			}
		}

		// close the file.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
