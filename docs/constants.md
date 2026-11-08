# Define credentials via constants

You can define the credentials of Google Drive as constants, e.g. in your `wp-config.php`. This keeps them out of the database and out of database backups, and allows different values per environment.

Requires _External Files in Media Library_ 5.7.0 or newer. See also the [documentation of the main plugin](https://github.com/threadi/external-files-in-media-library/blob/master/docs/constants.md).

## How it works

* **All required fields:** The constants are only used if a constant is defined for all required fields (below without "optional"). If one is missing, no constant is used and the settings page shows which are missing.
* The location of the credentials could not be changed then. The settings show hints instead of input fields.
* Google Drive uses OAuth. Only the API credentials of your own OAuth client could be defined as constants. The location of the credentials is then fixed to "Direct input of API credentials", the redirect URL to enter in your OAuth client is shown in the settings. The connection itself is still made via "Connect now", the tokens are saved in the database, as Google refreshes them regularly. The direct input requires an SSL certificate for a public domain.

The name of each constant is `EFML_` + the name of the service + `_` + the name of the field, all in uppercase.

## Constants

```php
define( 'EFML_GOOGLE_DRIVE_CLIENT_ID', 'xy.apps.googleusercontent.com' );
define( 'EFML_GOOGLE_DRIVE_CLIENT_SECRET', 'your-client-secret' );
```
