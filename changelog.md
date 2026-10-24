# Changelog

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
