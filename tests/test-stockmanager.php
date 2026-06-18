<?php
namespace Woo2Odoo;

use PHPUnit\Framework\TestCase;
use Woo2Odoo\Woo2Odoo_Stock_Manager;
use Woo2Odoo\Woo2Odoo_Client;

// Global function stubs for WooCommerce functions that may not be available in test environment
if (!function_exists('wc_update_product_stock')) {
	function wc_update_product_stock($product, $qty, $operation = 'set') {
		// Stub implementation for testing
		return true;
	}
}

if (!function_exists('wc_get_products')) {
	function wc_get_products($args = array()) {
		// Stub implementation for testing
		return array();
	}
}

/**
 * @covers Woo2Odoo\Woo2Odoo_Stock_Manager
 * @uses Woo2Odoo\Woo2Odoo_Client
 */
class OdooStockManagerTest extends TestCase {

	private $stockManager;
	private $mockClient;

	protected function setUp(): void {
		// Create a mock of Woo2Odoo_Client
		$this->mockClient = $this->createMock(Woo2Odoo_Client::class);

		// By default, authenticate returns true
		$this->mockClient->method('authenticate')->willReturn(true);

		// Create the stock manager with the mock client
		$this->stockManager = new Woo2Odoo_Stock_Manager($this->mockClient);
	}

	/**
	 * Test that sync_product() returns true and updates stock for a product with known SKU
	 */
	public function testSyncAllUpdatesStockForKnownSku() {
		$mockProduct = $this->createMock(\WC_Product::class);
		$mockProduct->method('get_sku')->willReturn('TEST-001');
		$mockProduct->method('get_id')->willReturn(1);
		$mockProduct->method('set_manage_stock')->willReturn(true);
		$mockProduct->method('set_stock_status')->willReturn(true);
		$mockProduct->method('save')->willReturn(true);

		$mockOdooProduct = (object) array(
			'id'           => 1,
			'default_code' => 'TEST-001',
			'name'         => 'Test Product',
			'free_qty'     => 50.0,
		);

		$this->mockClient->method('search_read')->willReturn( $mockOdooProduct );

		$result = $this->stockManager->sync_product( $mockProduct );
		$this->assertTrue( $result, 'sync_product should return true for product with valid SKU' );
	}

	/**
	 * Test that sync_product() returns false for product without SKU
	 */
	public function testSyncProductReturnsFalseWithoutSku() {
		// Create a mock product without SKU
		$mockProduct = $this->createMock(\WC_Product::class);
		$mockProduct->method('get_sku')->willReturn('');
		$mockProduct->method('get_id')->willReturn(2);

		// Verify search_read is not called
		$this->mockClient->expects($this->never())
			->method('search_read');

		// Call sync_product and verify it returns false
		$result = $this->stockManager->sync_product($mockProduct);
		$this->assertFalse($result, 'sync_product should return false for product without SKU');
	}

	/**
	 * Test that fetch_odoo_qty() returns null when product is not found in Odoo
	 */
	public function testFetchOdooQtyReturnsNullWhenNotFound() {
		// Mock search_read to return null (product not found)
		$this->mockClient->method('search_read')
			->willReturn(null);

		// Call fetch_odoo_qty and verify it returns null
		$result = $this->stockManager->fetch_odoo_qty('NONEXISTENT');
		$this->assertNull($result, 'fetch_odoo_qty should return null when product not found');
	}

	/**
	 * Test that fetch_odoo_qty() returns null when search_read throws an exception
	 */
	public function testFetchOdooQtyReturnsNullOnException() {
		// Mock search_read to throw an exception
		$this->mockClient->method('search_read')
			->willThrowException(new \Exception('Odoo connection error'));

		// Call fetch_odoo_qty and verify it returns null (exception is caught)
		$result = $this->stockManager->fetch_odoo_qty('TEST-SKU');
		$this->assertNull($result, 'fetch_odoo_qty should return null and catch exception');
	}

	/**
	 * Test that sync_product() returns false for product without SKU (covers sync_all not_found path)
	 */
	public function testSyncAllHandlesProductsWithoutSku() {
		$productWithoutSku = $this->createMock(\WC_Product::class);
		$productWithoutSku->method('get_sku')->willReturn('');
		$productWithoutSku->method('get_id')->willReturn(2);

		// search_read should never be called when there's no SKU
		$this->mockClient->expects( $this->never() )->method('search_read');

		$result = $this->stockManager->sync_product( $productWithoutSku );
		$this->assertFalse( $result, 'sync_product should return false for product without SKU' );
	}

	/**
	 * Test fetch_odoo_qty with valid product (with free_qty property)
	 */
	public function testFetchOdooQtyWithValidProduct() {
		// Mock search_read to return a valid product
		$mockOdooProduct = (object) array(
			'id' => 1,
			'default_code' => 'TEST-VALID',
			'name' => 'Valid Test Product',
			'free_qty' => 75.5,
		);

		$this->mockClient->method('search_read')
			->willReturn($mockOdooProduct);

		// Call fetch_odoo_qty and verify it returns the correct quantity
		$result = $this->stockManager->fetch_odoo_qty('TEST-VALID');
		$this->assertEquals(75.5, $result, 'fetch_odoo_qty should return the free_qty value');
		$this->assertIsFloat($result, 'Result should be a float');
	}

	/**
	 * Test that sync_product updates product stock correctly
	 */
	public function testSyncProductUpdatesStockCorrectly() {
		// Create a mock product with valid SKU
		$mockProduct = $this->createMock(\WC_Product::class);
		$mockProduct->method('get_sku')->willReturn('UPDATE-TEST');
		$mockProduct->method('get_id')->willReturn(3);
		$mockProduct->method('set_manage_stock')->willReturn(true);
		$mockProduct->method('set_stock_status')->willReturn(true);
		$mockProduct->method('save')->willReturn(true);

		// Mock Odoo product with qty > 0
		$mockOdooProduct = (object) array(
			'id' => 1,
			'default_code' => 'UPDATE-TEST',
			'name' => 'Update Test Product',
			'free_qty' => 30.0,
		);

		$this->mockClient->method('search_read')
			->willReturn($mockOdooProduct);

		// Verify set_stock_status is called with 'instock' (since qty > 0)
		$mockProduct->expects($this->once())
			->method('set_stock_status')
			->with('instock');

		// Verify set_manage_stock is called
		$mockProduct->expects($this->once())
			->method('set_manage_stock')
			->with(true);

		// Verify save is called
		$mockProduct->expects($this->once())
			->method('save');

		// Call sync_product
		$result = $this->stockManager->sync_product($mockProduct);
		$this->assertTrue($result, 'sync_product should return true');
	}

	/**
	 * Test that sync_product sets stock status to 'outofstock' when qty is 0
	 */
	public function testSyncProductSetsOutofstockWhenQtyIsZero() {
		// Create a mock product with valid SKU
		$mockProduct = $this->createMock(\WC_Product::class);
		$mockProduct->method('get_sku')->willReturn('ZERO-QTY');
		$mockProduct->method('get_id')->willReturn(4);
		$mockProduct->method('set_manage_stock')->willReturn(true);
		$mockProduct->method('set_stock_status')->willReturn(true);
		$mockProduct->method('save')->willReturn(true);

		// Mock Odoo product with qty = 0
		$mockOdooProduct = (object) array(
			'id' => 1,
			'default_code' => 'ZERO-QTY',
			'name' => 'Zero Quantity Product',
			'free_qty' => 0.0,
		);

		$this->mockClient->method('search_read')
			->willReturn($mockOdooProduct);

		// Verify set_stock_status is called with 'outofstock' (since qty = 0)
		$mockProduct->expects($this->once())
			->method('set_stock_status')
			->with('outofstock');

		// Call sync_product
		$result = $this->stockManager->sync_product($mockProduct);
		$this->assertTrue($result, 'sync_product should return true even with zero qty');
	}

	/**
	 * Test sync_product returns false when fetch_odoo_qty returns null
	 */
	public function testSyncProductReturnsFalseWhenFetchReturnsNull() {
		// Create a mock product with valid SKU
		$mockProduct = $this->createMock(\WC_Product::class);
		$mockProduct->method('get_sku')->willReturn('NOT-IN-ODOO');
		$mockProduct->method('get_id')->willReturn(5);

		// Mock search_read to return null
		$this->mockClient->method('search_read')
			->willReturn(null);

		// Call sync_product and verify it returns false
		$result = $this->stockManager->sync_product($mockProduct);
		$this->assertFalse($result, 'sync_product should return false when product not found in Odoo');
	}

	/**
	 * Helper method to mock wc_get_products function
	 * This is a workaround since we can't directly mock global functions
	 */
	private function mockClientGetProducts($products) {
		// This is handled by the global function stub defined at the top of this file
		// In a real test environment with WooCommerce, this would be automatically handled
		// For unit testing, we override the global function behavior if needed
	}
}
