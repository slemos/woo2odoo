<?php
namespace Woo2Odoo;

use PHPUnit\Framework\TestCase;
use Woo2Odoo\Woo2Odoo_Client;

/** 
 * @covers Woo2Odoo\Woo2Odoo_Client
 * @uses Woo2Odoo\Woo2Odoo_ClientFactory
 * @uses Woo2Odoo\Woo2Odoo_Plugin_Settings
 * @uses Woo2Odoo\Woo2Odoo_Plugin
 */
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
            'export_order_journal' => '9',
            'export_order_use_journal_zero' => 'false',
            'export_order_journal_zero' => '19'
        ));

        $this->odooClient = new Woo2Odoo_Client();
    }


    public function testConstructor() {
        $this->assertInstanceOf(Woo2Odoo_Client::class, $this->odooClient);
    }

    /**  
     * @covers Woo2Odoo\Woo2Odoo_Client 
     **/
    public function testSearchRead() {
        // test normal search
        $result = $this->odooClient->search_read('res.company', [['id', '=', '1']], ['id'], null, 1, null, ['single' => true]);
        $this->assertEquals(1, $result->id);

        // test search with cache
        $result = $this->odooClient->search_read('res.company', [['id', '=', '1']], ['id'], null, 1, null, ['single' => true]);
        $this->assertEquals(1, $result->id);

        // test search with no results
        $result = $this->odooClient->search_read('res.company', [['id', '=', '-1']], ['id'], null, 1, null, ['single' => true]);
        $this->assertNull($result);

        // test search with malformed query
        $result = $this->odooClient->search_read('res.company', [['id', '==', '1']], ['id'], null, 1, null, ['single' => true]);
        $this->assertFalse($result);
    }

    /**  
     * @covers Woo2Odoo\Woo2Odoo_Client 
     **/
    public function testAuthenticate() {
        
        $result = $this->odooClient->authenticate();
        $this->assertTrue($result);

    }

    /**  
     * @covers Woo2Odoo\Woo2Odoo_Client 
     **/
    public function testOdooTaxData() {
        $result = $this->odooClient->search_read( 'account.tax', [['country_code', '=', 'CL'], ['type_tax_use', '=', 'sale'], ['amount', '=', '19']], ['id'], null, 1, null, ['single' => 'true']);
        // xdebug_break();
        $this->assertIsObject($result);
        $this->assertIsNumeric($result->id);
    }
 
    public function testSearchReadOrder() {
        $odoo_order = $this->odooClient->search_read(
            'sale.order',
            array(
                array(
                    'origin',
                    '=',
                    8444,
                ),
            ),
            array( 'id', 'amount_total', 'state', 'invoice_status' ),
            null,
            1,
            null,
            array( 'single' => true )
        );
        $this->assertIsObject($odoo_order);
        $this->assertEquals(853, $odoo_order->id);
    }

    public function testUpdateRecord() {
        $data = ['name' => 'Updated Customer'];
        $result = $this->odooClient->update_record('res.partner', 1, $data);
        $this->assertTrue($result);
    }

    /**  
     * @covers Woo2Odoo\Woo2Odoo_Client 
     **/
    public function testCreateRecord() {
        $data = ['name' => 'New Customer'];
        $result = $this->odooClient->create_record('res.partner', $data);
        $this->assertIsNumeric($result);
    }

    public function testLogException() {
        $this->odooClient->log_exception('Test log_exception', new \Exception('Test exception'));
        $this->assertTrue(true);
    }

    public function testLogWarning() {
        $this->odooClient->log_warning('Test log_warning', ['key' => 'value']);
        $this->assertTrue(true);
    }

    public function testLogInfo() {
        $this->odooClient->log_info('Test log_info', ['key' => 'value']);
        $this->assertTrue(true);
    }

    public function testLogDebug() {
        $this->odooClient->log_debug('Test log_debug', ['key' => 'value']);
        $this->assertTrue(true);
    }

    public function testLogError() {
        $this->odooClient->log_error('Test log_error', ['key' => 'value']);
        $this->assertTrue(true);
    }

}