<?php

use PHPUnit\Framework\TestCase;
use Woo2Odoo\Woo2Odoo_Client;

class OdooClientTest extends TestCase {

    private $odooClient;

    protected function setUp(): void {
        require_once './classes/class-woo2odoo-client.php';

        // Retrieve values from environment variables
        $odoo_url = $_ENV['ODOO_URL'];
        $dbname = $_ENV['ODOO_DBNAME'];
        $odoo_user = $_ENV['ODOO_USER'];
        $odoo_password = $_ENV['ODOO_PASSWORD'];
        
        // Set the options used by the plugin
        update_option( 'Woo2Odoo-plugin-connection', array(
            'odoo_url' => $odoo_url,
            'dbname' => $dbname,
            'odoo_user' => $odoo_user,
            'odoo_password' => $odoo_password,
            'odoo_connected' => 'true'
        ));
        
        update_option( 'Woo2Odoo-plugin-export', array(
            'export_order' => 'true',
            'export_order_invoice' => 'true',
            'export_order_company' => '1',
            'export_order_journal' => '1',
            'export_order_use_journal_zero' => 'false',
            'export_order_journal_zero' => '2'
        ));

        $this->odooClient = new Woo2odoo_Client();
    }

    public function testConstructor() {
        $this->assertInstanceOf(Woo2odoo_Client::class, $this->odooClient);
        $this->assertNotNull($this->odooClient->default_mapping);
    }

    public function testSearchRead() {
        $result = $this->odooClient->search_read('res.company', [['id', '=', '1']], ['id'], null, 1, null, ['single' => true]);
        $this->assertEquals(1, $result->id);

        $result = $this->odooClient->search_read('res.company', [['id', '=', '-1']], ['id'], null, 1, null, ['single' => true]);
        $this->assertNull($result);
    }

    public function testAuthenticate() {
        
        $result = $this->odooClient->authenticate();
        $this->assertTrue($result);

    }

    public function testGetCustomerData() {
        $user = $this->createMock(WP_User::class);
        $user->method('__get')->willReturnMap([
            ['user_email', 'slemos.satue@gmail.com'],
            ['ID', 1]
        ]);

        // Assert that the user_email property is set correctly
        $this->assertEquals('slemos.satue@gmail.com', $user->user_email);

        $order = $this->createMock(WC_Order::class);
        $order->method('get_address')->willReturnMap([
            ['billing', [
                'first_name' => 'Sebastian',
                'last_name' => 'Lemos',
                'company' => 'Company',
                'address_1' => 'La CaPitanía 81',
                'address_2' => '',
                'city' => 'Santiago',
                'state' => 'Región Metropolitana de Santiago',
                'postcode' => '',
                'country' => 'CL',
                'email' => 'slemos.satue@gmail.com',
                'phone' => '555-555-5555'
            ]],
            ['shipping', [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'company' => 'Company',
                'address_1' => 'La Capitanía 81',
                'address_2' => '',
                'city' => 'Santiago',
                'state' => 'Región Metropolitana de Santiago',
                'postcode' => '',
                'country' => 'CL',
                'email' => 'slemos.satue@gmail.com',
                'phone' => '555-555-5556'
            ]]
        ]);
        $order->method('get_user')->willReturn( $user );

        $result = $this->odooClient->get_customer_data( $order );
        $this->assertIsArray($result);
        $this->assertIsNumeric($result['id']);
        $this->assertIsNumeric($result['invoice_id']);
        $this->assertIsNumeric($result['shipping_id']);
    }

    public function testFormatRut() {
        $result = $this->odooClient->format_rut('12345678');
        $this->assertEquals('1234567-8', $result);
        $result = $this->odooClient->format_rut('1.23.4.56-78');
        $this->assertEquals('1234567-8', $result);
    }

    public function testGetStateAndCountryCodes() {
        $result = $this->odooClient->get_state_and_country_codes('Región Metropolitana de Santiago', 'CL');
        $this->assertIsArray($result);
        //Chile country id in odoo
        $this->assertEquals('46', $result['country']);
        // Region Metropolitana id in odoo
        $this->assertEquals('1186', $result['state']);
    }

}