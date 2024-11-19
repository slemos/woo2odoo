<?php

use PHPUnit\Framework\TestCase;
use Woo2Odoo\Woo2Odoo_Client;

class OdooClientTest extends TestCase {

    private $odooClient;

    protected function setUp(): void {

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

    public function testGetOdooSkus() {
        // Create a two mock product
        $product1 = $this->createMock(WC_Product::class);
        $product1->method('get_sku')->willReturn('GELCOL-100');
        $product1->method('get_id')->willReturn(1);
        $product1->method('get_name')->willReturn('Product 1');
        $product1->method('get_price')->willReturn(1000);
        $product1->method('get_tax_class')->willReturn('IVA');

        $product2 = $this->createMock(WC_Product::class);
        $product2->method('get_sku')->willReturn('GELCOL-001');
        $product2->method('get_id')->willReturn(2);
        $product2->method('get_name')->willReturn('Product 2');
        $product2->method('get_price')->willReturn(2000);
        $product2->method('get_tax_class')->willReturn('IVA');

        // create a mock item for each product
        $item1 = $this->createMock(WC_Order_Item_Product::class);
        $item1->method('get_product')->willReturn($product1);
        $item1->method('get_quantity')->willReturn(1);
        $item1->method('get_subtotal')->willReturn(1000);
        $item1->method('get_total')->willReturn(1000);
        $item1->method('get_subtotal_tax')->willReturn(190);
        $item1->method('get_total_tax')->willReturn(190);
        $item1->method('get_taxes')->willReturn([
            [
                'id' => 1,
                'total' => 190
            ]
        ]);

        $item2 = $this->createMock(WC_Order_Item_Product::class);
        $item2->method('get_product')->willReturn($product2);
        $item2->method('get_quantity')->willReturn(1);
        $item2->method('get_subtotal')->willReturn(2000);
        $item2->method('get_total')->willReturn(2000);
        $item2->method('get_subtotal_tax')->willReturn(380);
        $item2->method('get_total_tax')->willReturn(380);
        $item2->method('get_taxes')->willReturn([
            [
                'id' => 1,
                'total' => 380
            ]
        ]);
        
        // Create a mock order and add the items
        $order = $this->createMock(WC_Order::class);
        $order->method('get_items')->willReturn([$item1, $item2]);

        $result = $this->odooClient->get_odoo_skus($order);
        $this->assertIsArray($result);
        $this->assertIsObject($result['GELCOL-001']);
    }

    public function testOdooTaxData() {
        $result = $this->odooClient->search_read( 'account.tax', [['country_code', '=', 'CL'], ['type_tax_use', '=', 'sale'], ['amount', '=', '19']], ['id'], null, 1, null, ['single' => 'true']);
        xdebug_break();
        $this->assertIsObject($result);
        $this->assertIsNumeric($result->id);
    }

}