# Changelog

## [Unreleased]

### Added

- Added export of the sizes of images and their original image together with them (requires External Files in Media Library 6.0.0): they are delivered like the images themselves, via the proxy or from Google Drive
- Added help "Where do I find these details?" to the form (requires External Files in Media Library 6.0.0): how to connect Google Drive, find the ID of a folder and use an own OAuth client
- Added possibility to define the API credentials for the direct input via constants, e.g. in wp-config.php (requires External Files in Media Library 6.0.0)
- Added documentation for the constants in docs/constants.md
- Added support for the folder structure of exported files, missing folders are created (requires External Files in Media Library 6.0.0)
- Added support for private files, if the access token is saved for the complete website (requires External Files in Media Library 6.0.0): files are delivered via the cache of the proxy, which loads them via the API
- Added link to Google Drive in the info box of each file (requires External Files in Media Library 6.0.0)
- Added support for the connection test in the directory listing (requires External Files in Media Library 6.0.0)
- Directories and the import of all files are imported file by file via the import of External Files in Media Library 6.0.0: also with tens of thousands of files, in the background if necessary
- Added search terms for the command palette of WordPress, e.g. other names of the service (requires External Files in Media Library 6.0.0)
- Google Drive could be connected within the quickstart of External Files in Media Library 6.0.0, afterwards the quickstart continues

### Changed

- Encrypted settings and access data are bound to their purpose (requires External Files in Media Library 6.0.0): an encrypted value copied into another field cannot be decrypted there; values saved by older versions stay readable and are bound to their purpose when they are saved again
- Updated German translations, incl. the texts of earlier versions which were not translated yet
- Removed the setting "Max. files to load per iteration", it is replaced by the import in the background
- The import of a folder requests only the files of this folder from Google Drive

### Fixed

- Fixed public exports, which were not public available (HTTP status 404, e.g. via the proxy), if the folder in Google Drive was not shared: exported files and their sizes are shared for anyone with the link now; if this is not allowed (e.g. by a Google Workspace), the uploaded file is removed again and the log names the reason and the option "Save files privately"
- Exported files are loaded via the API of Google Drive for the proxy, so also files exported before without sharing are delivered again (requires External Files in Media Library 6.0.0 for the sizes)
- Fixed export, export of the sizes and deletion of files, if each user has its own connection to Google Drive: the connection of the owner of the external source is used now, also for uploads of other users and in the background (requires External Files in Media Library 6.0.0)
- Fixed delivery of private files via the proxy for visitors and users without the capability for Google Drive, and exports of uploads by such users (requires External Files in Media Library 6.0.0): the protocol is known in each request now, imports still require the capability for Google Drive
- Fixed missing checks during the connection with Google Drive: the response of Google OAuth is only accepted for a logged-in user, who has started the connection in the last 30 minutes and is allowed to change it (before, a forged response could replace the access token for the whole website); connecting and disconnecting require the capabilities for external files and Google Drive, the connection for the whole website the capability to manage the settings
- Fixed export of files into the main directory of Google Drive
- Fixed missing delivery of exported files via the proxy, as the files could not be loaded for its cache
- Fixed directory listing and import of more than 1000 files, only the first 1000 files have been used
- Fixed high memory usage during the import of large files, they are now saved in parts into a local file

- Fixed imports from Google Drive in scheduled tasks without logged-in user (e.g. via the queue or WP Cron)
## [1.3.0] - 24.10.2026

### Added

- Added support for automatic updates via GitHub ("Update URI")
- Added PHPUnit tests and a GitHub workflow to run them
- Added hook "efmlgd_google_drive_client" to customize the Google Client object

### Changed

- Updates are now checked via the WordPress hook for the "Update URI", the data of the latest release is cached
- Set compatibility for WordPress 7.2
- Uninstall now removes the cached update data

### Fixed

- Fixed settings for shared and trashed files not being used during directory listing
- Fixed user-specific settings for shared and trashed files not being read
- Fixed decryption of Client ID and Client Secret in direct input mode
- Fixed "Direct input of API credentials" being offered for all services
- Fixed wrong URLs of files imported via "import all files"
- Fixed trashed files being imported via "import all files"
- Fixed the limit of files per iteration loading one file too many
- Fixed PHP deprecation and warning hints (FILTER_SANITIZE_STRING, missing token date, missing icon link, export of missing files)

## [1.2.0] - 16.08.2026

### Changed

- Now requires PHP 8.2 or newer
- Compatibility with WordPress 7.1
- Changed the path of composer packages to be compatible with other plugins,
  which are using other psr/monolog-versions

## [1.1.0] - 11.07.2026

### Added

- Added support for direct input of Google Drive credentials
- Added some more hooks

### Changed

- Optimized check for given state on OAuth response
- Updates translations
- Refresh the permalinks after this plugin has been updated

### Removed

- Removed some now unused composer packages from build processes

## [1.0.1] - 29.03.2026

### Added

- Added translations for all 5 german language packages in WordPress

### Changed

- Updated the settings library
- Set compatibility with WordPress 7.0

### Fixed

- Fixed wrong used text domain

## [1.0.0] - 14.01.2026

- Initial release
