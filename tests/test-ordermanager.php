<?php
namespace Woo2Odoo;

use PHPUnit\Framework\TestCase;
use Woo2Odoo\Woo2Odoo_Order_Manager;

function wc_get_order($order_id) {
    if (OrderManagerTest::$mockOrder) {
        if ($order_id == OrderManagerTest::$mockOrder->get_id()) {
            return OrderManagerTest::$mockOrder;
        }
    }
    return false;
}

/** 
 * @covers Woo2Odoo\Woo2Odoo_Order_Manager
 * @uses Woo2Odoo\Woo2Odoo_ClientFactory
 * @uses Woo2Odoo\Woo2Odoo_Plugin_Settings
 * @uses Woo2Odoo\Woo2Odoo_Plugin
 * @uses Woo2Odoo\Woo2Odoo_Client
 */
class OrderManagerTest extends TestCase {
    public static $mockOrder;
    private $orderManager;
    private $currentOrder;

    private function getNextOrderId() {
        $file = __DIR__ . '/last_order_id.txt';
        if (file_exists($file)) {
            $orderId = (int)file_get_contents($file) + 1;
        } else {
            $orderId = 100;
        }
        file_put_contents($file, $orderId);
        return $orderId;
    }

    protected function setUp(): void {
        // Setup similar to OdooClientTest but initializing OrderManager
        // ...existing setup code...
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
            'export_order_journal' => '9',
            'export_order_use_journal_zero' => 'false',
            'export_order_journal_zero' => '19'
        ));
        $this->orderManager = new Woo2Odoo_Order_Manager();
    }

    public function testConstructor() {
        $this->assertInstanceOf(Woo2Odoo_Order_Manager::class, $this->orderManager);
        $this->assertNotNull($this->orderManager->default_mapping);
    }

    public function testGetCustomerData() {
        $user = $this->createMock(\WP_User::class);
        $user->method('__get')->willReturnMap([
            ['user_email', 'slemos.satue@gmail.com'],
            ['ID', 1]
        ]);

        // Assert that the user_email property is set correctly
        $this->assertEquals('slemos.satue@gmail.com', $user->user_email);
        
        $order = $this->createMock(\WC_Order::class);
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

        $result = $this->orderManager->get_customer_data( $order );
        $this->assertIsArray($result);
        $this->assertIsNumeric($result['id']);
        $this->assertIsNumeric($result['invoice_id']);
        $this->assertIsNumeric($result['shipping_id']);
    }

    public function testFormatRut() {
        $result = $this->orderManager->format_rut('12345678');
        $this->assertEquals('1234567-8', $result);
        $result = $this->orderManager->format_rut('1.23.4.56-78');
        $this->assertEquals('1234567-8', $result);
    }

    public function testGetStateAndCountryCodes() {
        $result = $this->orderManager->get_state_and_country_codes('Región Metropolitana de Santiago', 'CL');
        $this->assertIsArray($result);
        //Chile country id in odoo
        $this->assertEquals('46', $result['country']);
        // Region Metropolitana id in odoo
        $this->assertEquals('1186', $result['state']);
    }

    public function testGetOdooSkus() {
        // Create a two mock product
        $product1 = $this->createMock(\WC_Product::class);
        $product1->method('get_sku')->willReturn('GELCOL-100');
        $product1->method('get_id')->willReturn(1);
        $product1->method('get_name')->willReturn('Product 1');
        $product1->method('get_price')->willReturn(1000);
        $product1->method('get_tax_class')->willReturn('IVA');

        $product2 = $this->createMock(\WC_Product::class);
        $product2->method('get_sku')->willReturn('GELCOL-001');
        $product2->method('get_id')->willReturn(2);
        $product2->method('get_name')->willReturn('Product 2');
        $product2->method('get_price')->willReturn(2000);
        $product2->method('get_tax_class')->willReturn('IVA');

        // create a mock item for each product
        $item1 = $this->createMock(\WC_Order_Item_Product::class);
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

        $item2 = $this->createMock(\WC_Order_Item_Product::class);
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
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_items')->willReturn([$item1, $item2]);

        $result = $this->orderManager->get_odoo_skus($order);
        $this->assertIsArray($result);
        $this->assertIsObject($result['GELCOL-001']);
    }

    public function testGetLastL10nLatamDocumentNumber() {
        $result = $this->orderManager->get_last_l10n_latam_document_number(5);
        $this->assertIsNumeric($result);
        $this->assertGreaterThan(0, $result);

        $result = $this->orderManager->get_last_l10n_latam_document_number(1);
        $this->assertIsNumeric($result);
        $this->assertGreaterThan(0, $result);

        $result = $this->orderManager->get_last_l10n_latam_document_number(22);
        $this->assertIsNumeric($result);
        $this->assertEquals(0, $result);

        $result = $this->orderManager->get_last_l10n_latam_document_number(1022);
        $this->assertIsNumeric($result);
        $this->assertEquals(0, $result);

    }

    
    public function testFailOrderSync() {
        $result = $this->orderManager->order_sync(1);
        $this->assertFalse($result);
    }

    private function createMockOrder($orderId, $items, $user) {
        $mockOrder = $this->createMock(\WC_Order::class);
        $mockOrder->method('get_id')->willReturn($orderId);
        $mockOrder->method('get_status')->willReturn('processing');
        $mockOrder->method('get_date_created')->willReturn(new \DateTime());
        $mockOrder->method('get_shipping_total')->willReturn(4202.0);
        $mockOrder->method('get_items')->willReturn($items);
        $mockOrder->method('get_address')->willReturnMap([
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
        $mockOrder->method('get_user')->willReturn($user);

        return $mockOrder;
    }

    // Test to create a new order in WooCommerce and sync it with Odoo
    public function testOrderSync() {

        // Create a two mock product
        $product1 = $this->createMock(\WC_Product::class);
        $product1->method('get_sku')->willReturn('GELCOL-100');
        $product1->method('get_id')->willReturn(1);
        $product1->method('get_name')->willReturn('Product 1');
        $product1->method('get_price')->willReturn(1000);
        $product1->method('get_tax_class')->willReturn('IVA');

        $product2 = $this->createMock(\WC_Product::class);
        $product2->method('get_sku')->willReturn('GELCOL-001');
        $product2->method('get_id')->willReturn(2);
        $product2->method('get_name')->willReturn('Product 2');
        $product2->method('get_price')->willReturn(2000);
        $product2->method('get_tax_class')->willReturn('IVA');

        // create a mock item for each product
        $item1 = $this->createMock(\WC_Order_Item_Product::class);
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

        $item2 = $this->createMock(\WC_Order_Item_Product::class);
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
        $user = $this->createMock(\WP_User::class);
        $user->method('__get')->willReturnMap([
            ['user_email', 'slemos.satue@gmail.com'],
            ['ID', 1]
        ]);

        // Assert that the user_email property is set correctly
        $this->assertEquals('slemos.satue@gmail.com', $user->user_email);
        
        $orderId = $this->getNextOrderId();

        self::$mockOrder = $this->createMockOrder($orderId, [$item1, $item2], $user);
        
        $result = wc_get_order($orderId);
        $this->assertNotNull($result);

        $result = $this->orderManager->order_sync($orderId);
        $this->assertTrue($result);

        //Let's update the mock order with a new item
        $product3 = $this->createMock(\WC_Product::class);
        $product3->method('get_sku')->willReturn('GELCOL-002');
        $product3->method('get_id')->willReturn(3);
        $product3->method('get_name')->willReturn('Product 3');
        $product3->method('get_price')->willReturn(3000);
        $product3->method('get_tax_class')->willReturn('IVA');

        // create a mock item for each product
        $item3 = $this->createMock(\WC_Order_Item_Product::class);
        $item3->method('get_product')->willReturn($product3);
        $item3->method('get_quantity')->willReturn(1);
        $item3->method('get_subtotal')->willReturn(3000);
        $item3->method('get_total')->willReturn(3000);
        $item3->method('get_subtotal_tax')->willReturn(570);
        $item3->method('get_total_tax')->willReturn(570);
        $item3->method('get_taxes')->willReturn([
            [
                'id' => 1,
                'total' => 570
            ]
        ]);

        // add the new item3 to current mockOrder
        $items = self::$mockOrder->get_items();
        $items[] = $item3;
        self::$mockOrder = $this->createMockOrder($orderId, $items, $user);

        $this->assertEquals(3, count(self::$mockOrder->get_items()));
        $result = $this->orderManager->order_sync($orderId);
        $this->assertTrue($result, 'Order sync with added item failed');

        // Let's update the mock order with a customer note
        self::$mockOrder->method('get_customer_note')->willReturn('Customer note');
        $result = $this->orderManager->order_sync($orderId);
        $this->assertTrue($result, 'Order sync with customer note failed');
    }
    /**  
     * @covers Woo2Odoo\Woo2Odoo_Client 
     **/
    public function testCreateAddressData() {
        $address_data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address_1' => '123 Main St',
            'address_2' => '',
            'city' => 'Anytown',
            'postcode' => '12345',
            'state' => 'CA',
            'country' => 'US',
            'email' => 'john.doe@example.com',
            'phone' => '555-555-5555'
        ];
        $result = $this->orderManager->create_address_data('invoice', $address_data, 1);
        $this->assertIsArray($result);
        $this->assertEquals('John Doe', $result['name']);
    }

    /* @covers Woo2Odoo\Woo2Odoo_Client::prepare_order_data */
    public function testPrepareOrderData() {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_data')->willReturn(['id' => 1, 'status' => 'completed']);
        $order->method('get_items')->willReturn([]);

        $result = $this->orderManager->prepare_order_data($order);
        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
    }

    /* @covers Woo2Odoo\Woo2Odoo_Client::get_or_create_address */
    public function testGetOrCreateAddress() {
        $address_data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address_1' => '123 Main St',
            'address_2' => '',
            'city' => 'Anytown',
            'postcode' => '12345',
            'state' => 'CA',
            'country' => 'US',
            'email' => 'john.doe@example.com',
            'phone' => '555-555-5555'
        ];
        $result = $this->orderManager->get_or_create_address('invoice', $address_data, 1);
        $this->assertIsNumeric($result);
    }

    public function testFailAddOrderLineItems() {
        // Test a fake product SKU
        $product1 = $this->createMock(\WC_Product::class);
        $product1->method('get_sku')->willReturn('GELCOL-999999');
        $product1->method('get_id')->willReturn(1);
        $product1->method('get_name')->willReturn('Product 1');
        $product1->method('get_price')->willReturn(1000);
        $product1->method('get_tax_class')->willReturn('IVA');


        // create a mock item for each product
        $item1 = $this->createMock(\WC_Order_Item_Product::class);
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

        // Create a mock order and add the items
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_items')->willReturn([$item1]);

        // create a odoo_order array with id
        $odoo_order = 956;  // intentionally non-existent order to test failure path

        $result = $this->orderManager->add_order_line_items($order, $odoo_order, 1);
        $this->assertFalse($result);
    }


    public function testAddOrderLineItems() {
        // Create a two mock product
        $product1 = $this->createMock(\WC_Product::class);
        $product1->method('get_sku')->willReturn('GELCOL-100');
        $product1->method('get_id')->willReturn(1);
        $product1->method('get_name')->willReturn('Product 1');
        $product1->method('get_price')->willReturn(1000);
        $product1->method('get_tax_class')->willReturn('IVA');

        $product2 = $this->createMock(\WC_Product::class);
        $product2->method('get_sku')->willReturn('GELCOL-001');
        $product2->method('get_id')->willReturn(2);
        $product2->method('get_name')->willReturn('Product 2');
        $product2->method('get_price')->willReturn(2000);
        $product2->method('get_tax_class')->willReturn('IVA');

        // create a mock item for each product
        $item1 = $this->createMock(\WC_Order_Item_Product::class);
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

        $item2 = $this->createMock(\WC_Order_Item_Product::class);
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
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_items')->willReturn([$item1, $item2]);

        // Use a draft order in arm-testing Odoo that accepts new line items
        $odoo_order = 2388;

        $result = $this->orderManager->add_order_line_items($order, $odoo_order, 1);
        $this->assertTrue($result);
    }

    public function testOdoo_states() {
        $result = $this->orderManager->odoo_states('quote_order', 'order_state');
        $this->assertEquals($result, 'sale');
    }
}
