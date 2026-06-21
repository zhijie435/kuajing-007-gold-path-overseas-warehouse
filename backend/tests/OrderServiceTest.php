<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/WarehouseRouter.php';
require_once __DIR__ . '/../core/OrderNoGenerator.php';
require_once __DIR__ . '/../core/AuditService.php';
require_once __DIR__ . '/../core/PermissionService.php';

class OrderServiceTest extends TestCase {
    private $orderService;

    public function setUp(): void {
        parent::setUp();
        TestDataSeeder::seedDefaultData($this->db);
        $this->orderService = new OrderService();
    }

    private function getValidOrderData() {
        return [
            'items' => [
                ['sku' => 'SKU001', 'quantity' => 2],
            ],
            'customer_name' => '张三',
            'customer_phone' => '13800138000',
            'shipping_country' => 'US',
            'shipping_address' => '123 Main Street, Los Angeles, CA',
            'customer_email' => 'test@example.com',
            'shipping_state' => 'CA',
            'shipping_city' => 'Los Angeles',
            'shipping_zip' => '90001',
            'external_order_no' => 'EXT123456',
            'remark' => '测试订单',
        ];
    }

    public function testCreateOrderSuccess() {
        $data = $this->getValidOrderData();
        $result = $this->orderService->createOrder($data);
        
        $this->assertTrue($result['success'], '订单创建应该成功');
        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('order_no', $result);
        $this->assertNotEmpty($result['order_no']);
        $this->assertArrayHasKey('warehouse', $result);
        $this->assertNotNull($result['warehouse']);
        $this->assertGreaterThan(0, $result['total_amount']);
    }

    public function testCreateOrderEmptyItems() {
        $data = $this->getValidOrderData();
        $data['items'] = [];
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('EMPTY_ITEMS', $result['error_type']);
    }

    public function testCreateOrderMissingCustomerName() {
        $data = $this->getValidOrderData();
        unset($data['customer_name']);
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('VALIDATION_ERROR', $result['error_type']);
    }

    public function testCreateOrderInvalidCustomerNameTooShort() {
        $data = $this->getValidOrderData();
        $data['customer_name'] = 'A';
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_NAME', $result['error_type']);
    }

    public function testCreateOrderInvalidPhone() {
        $data = $this->getValidOrderData();
        $data['customer_phone'] = 'abc';
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_PHONE', $result['error_type']);
    }

    public function testCreateOrderInvalidEmail() {
        $data = $this->getValidOrderData();
        $data['customer_email'] = 'invalid-email';
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_EMAIL', $result['error_type']);
    }

    public function testCreateOrderInvalidCountry() {
        $data = $this->getValidOrderData();
        $data['shipping_country'] = 'usa';
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_COUNTRY', $result['error_type']);
    }

    public function testCreateOrderInvalidAddress() {
        $data = $this->getValidOrderData();
        $data['shipping_address'] = '123';
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_ADDRESS', $result['error_type']);
    }

    public function testCreateOrderInvalidSku() {
        $data = $this->getValidOrderData();
        $data['items'] = [['sku' => '', 'quantity' => 1]];
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_SKU', $result['error_type']);
    }

    public function testCreateOrderInvalidQuantityZero() {
        $data = $this->getValidOrderData();
        $data['items'] = [['sku' => 'SKU001', 'quantity' => 0]];
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_QUANTITY', $result['error_type']);
    }

    public function testCreateOrderQuantityTooLarge() {
        $data = $this->getValidOrderData();
        $data['items'] = [['sku' => 'SKU001', 'quantity' => 1000]];
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('QUANTITY_TOO_LARGE', $result['error_type']);
    }

    public function testCreateOrderNoWarehouseMatched() {
        $data = $this->getValidOrderData();
        $data['shipping_country'] = 'XX';
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('ROUTING_ERROR', $result['error_type']);
    }

    public function testCreateOrderInsufficientStock() {
        $data = $this->getValidOrderData();
        $data['items'] = [['sku' => 'SKU001', 'quantity' => 9999]];
        $result = $this->orderService->createOrder($data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('ROUTING_ERROR', $result['error_type']);
    }

    public function testCreateOrderMultipleItems() {
        $data = $this->getValidOrderData();
        $data['items'] = [
            ['sku' => 'SKU001', 'quantity' => 2],
            ['sku' => 'SKU002', 'quantity' => 1],
            ['sku' => 'SKU003', 'quantity' => 3],
        ];
        $result = $this->orderService->createOrder($data);
        
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['total_amount']);
    }

    public function testCreateOrderWithScopeWarehouse() {
        $data = $this->getValidOrderData();
        $result = $this->orderService->createOrder($data, null, null, 'USCA');
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['permission_scoped']);
        $this->assertEquals('USCA', $result['scope_warehouse_code']);
        $this->assertEquals('USCA', $result['warehouse']['warehouse_code']);
    }

    public function testCreateOrderScopeMismatch() {
        $data = $this->getValidOrderData();
        $data['shipping_country'] = 'GB';
        $result = $this->orderService->createOrder($data, null, null, 'USCA');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('ROUTING_ERROR', $result['error_type']);
    }

    public function testListOrders() {
        $data = $this->getValidOrderData();
        $this->orderService->createOrder($data);
        $this->orderService->createOrder($data);
        
        $result = $this->orderService->listOrders();
        
        $this->assertArrayHasKey('list', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThanOrEqual(2, $result['total']);
    }

    public function testListOrdersWithPagination() {
        $result = $this->orderService->listOrders(['page' => 1, 'page_size' => 10]);
        
        $this->assertArrayHasKey('page', $result);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(10, $result['page_size']);
    }

    public function testCancelOrderSuccess() {
        $data = $this->getValidOrderData();
        $createResult = $this->orderService->createOrder($data);
        $orderNo = $createResult['order_no'];
        
        $cancelResult = $this->orderService->cancelOrder($orderNo, '测试取消');
        
        $this->assertTrue($cancelResult['success']);
    }

    public function testCancelOrderNotFound() {
        $result = $this->orderService->cancelOrder('NONEXIST_ORDER', '测试取消');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('ORDER_NOT_FOUND', $result['error_type']);
    }

    public function testCancelOrderWithScopeSuccess() {
        $data = $this->getValidOrderData();
        $createResult = $this->orderService->createOrder($data, null, null, 'USCA');
        $orderNo = $createResult['order_no'];
        
        $cancelResult = $this->orderService->cancelOrder($orderNo, '测试取消', 'USCA');
        
        $this->assertTrue($cancelResult['success']);
    }

    public function testCancelOrderWithScopeMismatch() {
        $data = $this->getValidOrderData();
        $createResult = $this->orderService->createOrder($data);
        $orderNo = $createResult['order_no'];
        
        $cancelResult = $this->orderService->cancelOrder($orderNo, '测试取消', 'GBLN');
        
        $this->assertFalse($cancelResult['success']);
        $this->assertEquals('WAREHOUSE_SCOPE_MISMATCH', $cancelResult['error_type']);
    }

    public function testGetOrderStatusMap() {
        $map = OrderService::getOrderStatusMap();
        
        $this->assertNotEmpty($map);
        $this->assertArrayHasKey(0, $map);
        $this->assertArrayHasKey(1, $map);
        $this->assertArrayHasKey(9, $map);
    }

    public function testGetFulfillmentStatusMap() {
        $map = OrderService::getFulfillmentStatusMap();
        
        $this->assertNotEmpty($map);
        $this->assertArrayHasKey(0, $map);
        $this->assertArrayHasKey(3, $map);
        $this->assertArrayHasKey(9, $map);
    }
}
