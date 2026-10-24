<?php
/**
 * Tests for class ExternalFilesFromGoogleDrive\Plugin\Uninstall.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit\Plugin;

use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;

/**
 * Object to test functions in class ExternalFilesFromGoogleDrive\Plugin\Uninstall.
 */
class Uninstall extends externalFilesFromGoogleDriveTests {
	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$instance = \ExternalFilesFromGoogleDrive\Plugin\Uninstall::get_instance();
		$this->assertInstanceOf( \ExternalFilesFromGoogleDrive\Plugin\Uninstall::class, $instance );
		$this->assertSame( $instance, \ExternalFilesFromGoogleDrive\Plugin\Uninstall::get_instance() );
	}

	/**
	 * Test if the uninstallation removes the cached release data.
	 *
	 * @return void
	 */
	public function test_run(): void {
		set_site_transient( 'efmlgd_latest_release', array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );

		\ExternalFilesFromGoogleDrive\Plugin\Uninstall::get_instance()->run();

		$this->assertFalse( get_site_transient( 'efmlgd_latest_release' ) );
	}
}
