<?php
/**
 * Class Test_Woo2Odoo_Plugin
 *
 * @package Woo2Odoo_Plugin
 */
use Woo2Odoo\Woo2Odoo_Plugin;
/**
 * Sample test case.
 */
class Test_Woo2Odoo_Plugin extends WP_UnitTestCase {
	public function set_up() {
        parent::set_up();
        
        // Mock that we're in WP Admin context.
		// See https://wordpress.stackexchange.com/questions/207358/unit-testing-in-the-wordpress-backend-is-admin-is-true
        set_current_screen( 'edit-post' );
        
        $this->woo2odoo_plugin = new Woo2Odoo_Plugin();
    }

    public function tear_down() {
        parent::tear_down();
    }

	public function test_has_correct_token() {
		$has_correct_token = ( 'woo2odoo-plugin' === $this->woo2odoo_plugin->token );
		
		$this->assertTrue( $has_correct_token );
	}

	public function test_has_admin_interface() {
		$has_admin_interface = ( is_a( $this->woo2odoo_plugin->admin, 'Woo2Odoo_Plugin_Admin' ) );
		
		$this->assertTrue( $has_admin_interface );
	}

	public function test_has_settings_interface() {
		$has_settings_interface = ( is_a( $this->woo2odoo_plugin->settings, 'Woo2Odoo_Plugin_Settings' ) );
		
		$this->assertTrue( $has_settings_interface );
	}


	public function test_has_load_plugin_textdomain() {
		$has_load_plugin_textdomain = ( is_int( has_action( 'init', [ $this->woo2odoo_plugin, 'load_plugin_textdomain' ] ) ) );
		
		$this->assertTrue( $has_load_plugin_textdomain );
	}
	
}
