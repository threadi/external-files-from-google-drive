<?php
/**
 * Tests for class ExternalFilesFromGoogleDrive\Plugin\Configuration.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit\Plugin;

use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;
use ExternalFilesFromGoogleDrive\GoogleDrive;
use ExternalFilesInMediaLibrary\Services\Services;

/**
 * Object to test functions in class ExternalFilesFromGoogleDrive\Plugin\Configuration.
 */
class Configuration extends externalFilesFromGoogleDriveTests {
	/**
	 * Test the name, title and hints of the configuration.
	 *
	 * @return void
	 */
	public function test_configuration(): void {
		$configuration = new \ExternalFilesFromGoogleDrive\Plugin\Configuration();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\Plugin\Configuration_Base::class, $configuration );
		$this->assertEquals( 'google_drive', $configuration->get_name() );
		$this->assertEquals( 'Use only Google Drive', $configuration->get_title() );
		$this->assertCount( 1, $configuration->get_dialog_hints() );
		$this->assertStringContainsString( 'Google Drive', $configuration->get_dialog_hints()[0] );
	}

	/**
	 * Test if the configuration allows only Google Drive and disables all other services.
	 *
	 * @return void
	 */
	public function test_run(): void {
		update_option( 'eml_disable_plugin_hints', 0 );

		( new \ExternalFilesFromGoogleDrive\Plugin\Configuration() )->run();

		// Google Drive is allowed for its default roles.
		$this->assertEquals( GoogleDrive::get_instance()->get_default_roles(), get_option( 'eml_service_google-drive_allowed_roles' ) );

		// all other services are disabled.
		foreach ( Services::get_instance()->get_services_as_objects() as $service_obj ) {
			if ( ! method_exists( $service_obj, 'get_name' ) || 'google-drive' === $service_obj->get_name() ) {
				continue;
			}
			$this->assertEquals( array(), get_option( 'eml_service_' . $service_obj->get_name() . '_allowed_roles' ), 'Service ' . $service_obj->get_name() . ' is not disabled.' );
		}

		// the hints for other plugins are disabled.
		$this->assertEquals( 1, absint( get_option( 'eml_disable_plugin_hints' ) ) );
	}
}
