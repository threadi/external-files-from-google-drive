<?php
/**
 * Tests for the multisite support of class ExternalFilesFromGoogleDrive\Plugin\Init.
 *
 * These tests only run in a multisite: "composer test-multisite".
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit\Plugin;

use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;

/**
 * Object to test the multisite support.
 */
class Multisite extends externalFilesFromGoogleDriveTests {
	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only for multisite. Run them with "composer test-multisite".' );
		}
	}

	/**
	 * Test if the main plugin is detected if it is network-wide active.
	 *
	 * @return void
	 */
	public function test_is_parent_plugin_active_in_network(): void {
		$init = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance();

		// the main plugin is not active in the actual blog.
		remove_filter( 'option_active_plugins', '_efmlgd_add_main_plugin_as_active' );
		update_option( 'active_plugins', array() );

		// test 1: the main plugin is not active in the network.
		update_site_option( 'active_sitewide_plugins', array( 'hello-dolly/hello.php' => time() ) );
		$this->assertFalse( $init->is_parent_plugin_active() );

		// test 2: the main plugin is active in the network.
		update_site_option( 'active_sitewide_plugins', array( 'external-files-in-media-library/external-files-in-media-library.php' => time() ) );
		$this->assertTrue( $init->is_parent_plugin_active() );

		// test 3: the list of network-wide plugins is not an array.
		update_site_option( 'active_sitewide_plugins', '' );
		$this->assertFalse( $init->is_parent_plugin_active() );

		// restore the filter of the bootstrap.
		add_filter( 'option_active_plugins', '_efmlgd_add_main_plugin_as_active' );
	}

	/**
	 * Test if the main plugin is detected if it is active in the actual blog of the network.
	 *
	 * @return void
	 */
	public function test_is_parent_plugin_active_in_blog(): void {
		$init = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance();

		remove_filter( 'option_active_plugins', '_efmlgd_add_main_plugin_as_active' );
		update_site_option( 'active_sitewide_plugins', array() );
		update_option( 'active_plugins', array( 'external-files-in-media-library/external-files-in-media-library.php' ) );
		$this->assertTrue( $init->is_parent_plugin_active() );
		add_filter( 'option_active_plugins', '_efmlgd_add_main_plugin_as_active' );
	}
}
