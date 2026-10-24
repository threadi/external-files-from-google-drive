<?php
/**
 * Tests for class ExternalFilesFromGoogleDrive\Plugin\Init.
 *
 * @package external-files-from-google-drive
 */

namespace ExternalFilesFromGoogleDrive\Tests\Unit\Plugin;

use ExternalFilesFromGoogleDrive\Tests\externalFilesFromGoogleDriveTests;
use ExternalFilesFromGoogleDrive\GoogleDrive;

/**
 * Object to test functions in class ExternalFilesFromGoogleDrive\Plugin\Init.
 */
class Init extends externalFilesFromGoogleDriveTests {
	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$instance = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance();
		$this->assertInstanceOf( \ExternalFilesFromGoogleDrive\Plugin\Init::class, $instance );
		$this->assertSame( $instance, \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance() );
	}

	/**
	 * Test if the hooks of this plugin are registered during the initialization.
	 *
	 * @return void
	 */
	public function test_init(): void {
		$init = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance();
		$this->assertIsInt( has_filter( 'efml_services_support', array( $init, 'add_service' ) ) );
		$this->assertIsInt( has_filter( 'efml_service_plugins', array( $init, 'remove_service_plugin' ) ) );
		$this->assertIsInt( has_filter( 'efml_configurations', array( $init, 'add_configuration' ) ) );
		$this->assertIsInt( has_action( 'init', array( $init, 'init_languages' ) ) );
		$this->assertIsInt( has_filter( 'update_plugins_github.com', array( \ExternalFilesFromGoogleDrive\Plugin\Updates::get_instance(), 'check' ) ) );
	}

	/**
	 * Test if the check for the main plugin is working.
	 *
	 * @return void
	 */
	public function test_is_parent_plugin_active(): void {
		$init = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance();

		// test 1: the main plugin is active (the bootstrap marks it as active).
		$this->assertTrue( $init->is_parent_plugin_active() );

		// test 2: the main plugin is not active.
		remove_filter( 'option_active_plugins', '_efmlgd_add_main_plugin_as_active' );
		update_option( 'active_plugins', array( 'hello-dolly/hello.php' ) );
		$this->assertFalse( $init->is_parent_plugin_active() );

		// test 3: the main plugin is active.
		update_option( 'active_plugins', array( 'hello-dolly/hello.php', 'external-files-in-media-library/external-files-in-media-library.php' ) );
		$this->assertTrue( $init->is_parent_plugin_active() );

		// restore the filter of the bootstrap.
		add_filter( 'option_active_plugins', '_efmlgd_add_main_plugin_as_active' );
	}

	/**
	 * Test if the service is added to the list of services.
	 *
	 * @return void
	 */
	public function test_add_service(): void {
		$services = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance()->add_service( array( 'Example\Service' ) );
		$this->assertEquals( array( 'Example\Service', 'ExternalFilesFromGoogleDrive\GoogleDrive' ), $services );
		$this->assertTrue( class_exists( $services[1] ) );
	}

	/**
	 * Test if the main plugin knows the service of this plugin.
	 *
	 * @return void
	 */
	public function test_services_in_main_plugin(): void {
		$services = \ExternalFilesInMediaLibrary\Services\Services::get_instance()->get_services_as_objects();
		$this->assertContains( GoogleDrive::get_instance(), $services );
	}

	/**
	 * Test if this plugin is removed from the list of service plugins of the main plugin.
	 *
	 * @return void
	 */
	public function test_remove_service_plugin(): void {
		$plugins = array(
			'external-files-from-google-drive' => 'google-drive',
			'other-plugin'                     => 'other',
		);
		$result  = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance()->remove_service_plugin( $plugins ); // @phpstan-ignore argument.type
		$this->assertArrayNotHasKey( 'external-files-from-google-drive', $result );
		$this->assertArrayHasKey( 'other-plugin', $result );
	}

	/**
	 * Test if the configuration is added.
	 *
	 * @return void
	 */
	public function test_add_configuration(): void {
		$configurations = \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance()->add_configuration( array( 'Example\Configuration' ) );
		$this->assertCount( 2, $configurations );
		$this->assertContains( '\ExternalFilesFromGoogleDrive\Plugin\Configuration', $configurations );
		$this->assertTrue( class_exists( $configurations[1] ) );
	}

	/**
	 * Test if the activation adds the capability for Google Drive to the default roles.
	 *
	 * @return void
	 */
	public function test_activation(): void {
		get_role( 'administrator' )->remove_cap( 'efml_cap_google-drive' );
		get_role( 'editor' )->remove_cap( 'efml_cap_google-drive' );

		\ExternalFilesFromGoogleDrive\Plugin\Init::get_instance()->activation();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'efml_cap_google-drive' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'efml_cap_google-drive' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'efml_cap_google-drive' ) );
	}

	/**
	 * Test if the plugin is registered for the WP Consent API.
	 *
	 * @return void
	 */
	public function test_register_consent_api(): void {
		$this->assertTrue( \ExternalFilesFromGoogleDrive\Plugin\Init::get_instance()->register_consent_api() );
	}
}
