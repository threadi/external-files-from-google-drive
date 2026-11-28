# Changelog

## [2.1.0] - tbd

### Added

- Added the directories of files relative to their external source, for the folder structure in folder plugins of External Files in Media Library 6.1.0
- Added support for the option to synchronize subdirectories of External Files in Media Library 6.1.0: folders could be synchronized with their subfolders, the main directory without subfolders uses only the files in the root of Google Drive
- Added skipping of subfolders, which could not be read: no file is deleted after such a synchronization

## [2.0.0] - 22.11.2026

### Added

Most of the following requires External Files in Media Library 6.0.0.

- Added connection of Google Drive within the quickstart
- Added export of image sizes and original images
- Added private files, if the connection is saved for the whole website
- Added folder structure for exported files
- Added API credentials via constants
- Added setup help "Where do I find these details?"
- Added connection test in the directory listing
- Added link to Google Drive for each file
- Added import of large folders file by file, also in the background
- Added faster uploads in parts of 8 MB
- Added search terms for the command palette
- Added new hooks

### Changed

- Encrypted values are bound to their purpose
- Removed the setting "Max. files to load per iteration"
- The import of a folder requests only its own files
- Updated German translations

### Fixed

- Fixed missing security checks during the connection with Google Drive
- Fixed exported files not being publicly available, if the folder was not shared
- Fixed missing delivery of exported files via the proxy
- Fixed only the first 1,000 files of a folder being used
- Fixed export and deletion with a separate connection per user
- Fixed delivery of private files to visitors and users without the capability for Google Drive
- Fixed export into the main directory of Google Drive
- Fixed high memory usage during the import of large files
- Fixed imports in scheduled tasks without logged-in user

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
