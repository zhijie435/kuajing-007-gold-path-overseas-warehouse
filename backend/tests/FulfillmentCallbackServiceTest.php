<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/FulfillmentCallbackService.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/AuditService.php';

class FulfillmentCallbackServiceTest extends TestCase {
    private $callbackService;
    private $testOrderNo;
    private $testWarehouseCode = 'USCA';

    public function setUp(): void {
        parent::setUp();
        TestDataSeeder::seedDefaultData($this->db);
        $this->callbackService = new FulfillmentCallbackService();
        $this->testOrderNo = 'TEST' . time();
        
        $this->db->seedOrder([
            'order_no' => $this->testOrderNo,
            'external_order_no' => 'EXT123',
            'warehouse_id' => 1,
            'warehouse_code' => $this->testWarehouseCode,
            'warehouse_order_no' => 'WMS123456',
            'customer_name' => '测试用户',
            'customer_phone' => '13800138000',
            'customer_email' => 'test@example.com',
            'shipping_country' => 'US',
            'shipping_state' => 'CA',
            'shipping_city' => 'Los Angeles',
            'shipping_address' => '123 Test Street',
            'shipping_zip' => '90001',
            'total_amount' => 29.99,
            'shipping_cost' => 5.99,
            'weight_total' => 0.15,
            'order_status' => 2,
            'fulfillment_status' => 0,
            'estimated_delivery_date' => date('Y-m-d', strtotime('+3 days')),
        ]);
        
        $this->db->insert('order_items', [
            'order_id' => 1,
            'order_no' => $this->testOrderNo,
            'product_id' => 1,
            'sku' => 'SKU001',
            'product_name' => '无线蓝牙耳机',
            'quantity' => 2,
            'unit_price' => 29.99,
            'weight' => 0.15,
            'subtotal' => 59.98,
        ]);
    }

    public function testValidateTokenSuccess() {
        global $config;
        $config = require __DIR__ . '/../config/config.php';
        
        $token = 'wh_callback_token_2024';
        $result = $this->callbackService->validateToken($token);
        $this->assertTrue($result);
    }

    public function testValidateTokenFailed() {
        $result = $this->callbackService->validateToken('wrong_token');
        $this->assertFalse($result);
    }

    public function testHandleOrderAcceptSuccess() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_order_no' => 'WMS_NEW_001',
            'warehouse_code' => $this->testWarehouseCode,
            'operate_time' => date('Y-m-d H:i:s'),
        ];
        
        $result = $this->callbackService->handle('ORDER_ACCEPT', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['new_status']);
        $this->assertEquals(2, $result['old_status']);
    }

    public function testHandleOrderAcceptOrderNotFound() {
        $data = [
            'order_no' => 'NONEXIST_ORDER',
            'warehouse_order_no' => 'WMS001',
            'warehouse_code' => $this->testWarehouseCode,
        ];
        
        $result = $this->callbackService->handle('ORDER_ACCEPT', $data, '', 'wh_callback_token_2024');
        
        $this->assertFalse($result['success']);
    }

    public function testHandleOrderAcceptDuplicate() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_order_no' => 'WMS001',
            'warehouse_code' => $this->testWarehouseCode,
        ];
        
        $this->callbackService->handle('ORDER_ACCEPT', $data, '', 'wh_callback_token_2024');
        
        $result = $this->callbackService->handle('ORDER_ACCEPT', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertEquals('DUPLICATE_CALLBACK', $result['error_type']);
    }

    public function testHandlePickingStartSuccess() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
            'operator' => '张三',
        ];
        
        $result = $this->callbackService->handle('PICKING_START', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['new_fulfillment_status']);
        $this->assertEquals(0, $result['old_fulfillment_status']);
    }

    public function testHandlePickingStartOrderNotFound() {
        $data = [
            'order_no' => 'NONEXIST_ORDER',
            'warehouse_code' => $this->testWarehouseCode,
        ];
        
        $result = $this->callbackService->handle('PICKING_START', $data, '', 'wh_callback_token_2024');
        
        $this->assertFalse($result['success']);
    }

    public function testHandlePackingStartSuccess() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
            'package_no' => 'PKG001',
        ];
        
        $result = $this->callbackService->handle('PACKING_START', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['new_fulfillment_status']);
    }

    public function testHandleOrderShipSuccess() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
            'tracking_no' => 'TRK1234567890',
            'shipping_carrier' => 'USPS',
        ];
        
        $result = $this->callbackService->handle('ORDER_SHIP', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertEquals(5, $result['new_order_status']);
        $this->assertEquals(3, $result['new_fulfillment_status']);
    }

    public function testHandleOrderShipDuplicate() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
            'tracking_no' => 'TRK1234567890',
            'shipping_carrier' => 'USPS',
        ];
        
        $this->callbackService->handle('ORDER_SHIP', $data, '', 'wh_callback_token_2024');
        
        $result = $this->callbackService->handle('ORDER_SHIP', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
    }

    public function testHandleOrderShipMissingTrackingNo() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
            'shipping_carrier' => 'USPS',
        ];
        
        $result = $this->callbackService->handle('ORDER_SHIP', $data, '', 'wh_callback_token_2024');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('SYSTEM_EXCEPTION', $result['error_type']);
    }

    public function testHandleOrderDeliverSuccess() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
            'deliver_time' => date('Y-m-d H:i:s'),
            'signed_by' => '张三',
        ];
        
        $result = $this->callbackService->handle('ORDER_DELIVER', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertEquals(6, $result['new_order_status']);
        $this->assertEquals(4, $result['new_fulfillment_status']);
    }

    public function testHandleOrderDeliverDuplicate() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
        ];
        
        $this->callbackService->handle('ORDER_DELIVER', $data, '', 'wh_callback_token_2024');
        
        $result = $this->callbackService->handle('ORDER_DELIVER', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
    }

    public function testHandleOrderExceptionSuccess() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
            'exception_type' => 'OUT_OF_STOCK',
            'exception_message' => '商品库存不足',
        ];
        
        $result = $this->callbackService->handle('ORDER_EXCEPTION', $data, '', 'wh_callback_token_2024');
        
        $this->assertTrue($result['success']);
    }

    public function testHandleUnknownCallbackType() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_code' => $this->testWarehouseCode,
        ];
        
        $result = $this->callbackService->handle('UNKNOWN_TYPE', $data, '', 'wh_callback_token_2024');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('UNKNOWN_CALLBACK_TYPE', $result['error_type']);
    }

    public function testHandleMissingRequiredFields() {
        $data = [
            'order_no' => $this->testOrderNo,
        ];
        
        $result = $this->callbackService->handle('ORDER_ACCEPT', $data, '', 'wh_callback_token_2024');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('SYSTEM_EXCEPTION', $result['error_type']);
    }

    public function testCallbackCreatesTrackRecord() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_order_no' => 'WMS001',
            'warehouse_code' => $this->testWarehouseCode,
        ];
        
        $this->callbackService->handle('ORDER_ACCEPT', $data, '', 'wh_callback_token_2024');
        
        $tracks = $this->db->getTableData('fulfillment_tracks');
        $this->assertNotEmpty($tracks);
        $this->assertCount(1, $tracks);
        $this->assertEquals('WMS_ACCEPTED', $tracks[0]['track_type']);
    }

    public function testCallbackLogsToCallbackLogs() {
        $data = [
            'order_no' => $this->testOrderNo,
            'warehouse_order_no' => 'WMS001',
            'warehouse_code' => $this->testWarehouseCode,
        ];
        
        $this->callbackService->handle('ORDER_ACCEPT', $data, '', 'wh_callback_token_2024');
        
        $logs = $this->db->getTableData('warehouse_callback_logs');
        $this->assertNotEmpty($logs);
        $this->assertEquals('ORDER_ACCEPT', $logs[0]['callback_type']);
    }
}
