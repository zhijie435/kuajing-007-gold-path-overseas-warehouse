<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/WarehouseRouter.php';
require_once __DIR__ . '/../core/FulfillmentCallbackService.php';
require_once __DIR__ . '/../core/OrderNoGenerator.php';
require_once __DIR__ . '/../core/AuditService.php';
require_once __DIR__ . '/../core/PermissionService.php';

class StatusClosedLoopTest extends TestCase {
    private $orderService;
    private $callbackService;

    public function setUp(): void {
        parent::setUp();
        TestDataSeeder::seedDefaultData($this->db);
        $this->orderService = new OrderService();
        $this->callbackService = new FulfillmentCallbackService();
    }

    private function createTestOrder() {
        $data = [
            'items' => [
                ['sku' => 'SKU001', 'quantity' => 2],
                ['sku' => 'SKU002', 'quantity' => 1],
            ],
            'customer_name' => '张三',
            'customer_phone' => '13800138000',
            'shipping_country' => 'US',
            'shipping_address' => '123 Main Street, Los Angeles, CA 90001',
            'customer_email' => 'test@example.com',
            'shipping_state' => 'CA',
            'shipping_city' => 'Los Angeles',
            'shipping_zip' => '90001',
        ];
        return $this->orderService->createOrder($data);
    }

    public function testFullFulfillmentFlow() {
        $createResult = $this->createTestOrder();
        $this->assertTrue($createResult['success'], '订单创建应该成功');
        $orderNo = $createResult['order_no'];
        $warehouseCode = $createResult['warehouse']['warehouse_code'];
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertNotNull($orderDetail);
        $this->assertEquals(2, $orderDetail['order_status'], '创建后应该是已推送仓库状态');
        $this->assertEquals(0, $orderDetail['fulfillment_status'], '履约状态应该是未开始');
        
        $acceptResult = $this->callbackService->handle('ORDER_ACCEPT', [
            'order_no' => $orderNo,
            'warehouse_order_no' => 'WMS_' . $orderNo,
            'warehouse_code' => $warehouseCode,
        ], '', 'wh_callback_token_2024');
        $this->assertTrue($acceptResult['success']);
        $this->assertEquals(3, $acceptResult['new_status']);
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(3, $orderDetail['order_status'], '接单后应该是仓库已接单状态');
        
        $pickingResult = $this->callbackService->handle('PICKING_START', [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'operator' => '仓管员A',
        ], '', 'wh_callback_token_2024');
        $this->assertTrue($pickingResult['success']);
        $this->assertEquals(1, $pickingResult['new_fulfillment_status']);
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(1, $orderDetail['fulfillment_status'], '拣货开始后履约状态应该是拣货中');
        
        $packingResult = $this->callbackService->handle('PACKING_START', [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'package_no' => 'PKG_' . $orderNo,
        ], '', 'wh_callback_token_2024');
        $this->assertTrue($packingResult['success']);
        $this->assertEquals(2, $packingResult['new_fulfillment_status']);
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(2, $orderDetail['fulfillment_status'], '打包开始后履约状态应该是打包中');
        
        $shipResult = $this->callbackService->handle('ORDER_SHIP', [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'tracking_no' => 'TRK_' . $orderNo,
            'shipping_carrier' => 'USPS',
        ], '', 'wh_callback_token_2024');
        $this->assertTrue($shipResult['success']);
        $this->assertEquals(5, $shipResult['new_order_status']);
        $this->assertEquals(3, $shipResult['new_fulfillment_status']);
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(5, $orderDetail['order_status'], '发货后应该是已发货状态');
        $this->assertEquals(3, $orderDetail['fulfillment_status'], '发货后履约状态应该是已发货');
        $this->assertEquals('TRK_' . $orderNo, $orderDetail['tracking_no']);
        $this->assertEquals('USPS', $orderDetail['shipping_carrier']);
        
        $deliverResult = $this->callbackService->handle('ORDER_DELIVER', [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'deliver_time' => date('Y-m-d H:i:s'),
            'signed_by' => '李四',
        ], '', 'wh_callback_token_2024');
        $this->assertTrue($deliverResult['success']);
        $this->assertEquals(6, $deliverResult['new_order_status']);
        $this->assertEquals(4, $deliverResult['new_fulfillment_status']);
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(6, $orderDetail['order_status'], '签收后应该是已签收状态');
        $this->assertEquals(4, $orderDetail['fulfillment_status'], '签收后履约状态应该是已签收');
        $this->assertNotNull($orderDetail['actual_delivery_date']);
    }

    public function testOrderStatusTransitionMap() {
        $statusMap = OrderService::getOrderStatusMap();
        
        $this->assertEquals('待处理', $statusMap[0]);
        $this->assertEquals('已路由', $statusMap[1]);
        $this->assertEquals('已推送仓库', $statusMap[2]);
        $this->assertEquals('仓库已接单', $statusMap[3]);
        $this->assertEquals('已出库', $statusMap[4]);
        $this->assertEquals('已发货', $statusMap[5]);
        $this->assertEquals('已签收', $statusMap[6]);
        $this->assertEquals('已取消', $statusMap[9]);
    }

    public function testFulfillmentStatusTransitionMap() {
        $statusMap = OrderService::getFulfillmentStatusMap();
        
        $this->assertEquals('未开始', $statusMap[0]);
        $this->assertEquals('拣货中', $statusMap[1]);
        $this->assertEquals('打包中', $statusMap[2]);
        $this->assertEquals('已发货', $statusMap[3]);
        $this->assertEquals('已签收', $statusMap[4]);
        $this->assertEquals('异常', $statusMap[9]);
    }

    public function testTrackRecordsCompleteFlow() {
        $createResult = $this->createTestOrder();
        $orderNo = $createResult['order_no'];
        $warehouseCode = $createResult['warehouse']['warehouse_code'];
        
        $callbacks = [
            ['type' => 'ORDER_ACCEPT', 'data' => ['order_no' => $orderNo, 'warehouse_order_no' => 'WMS001', 'warehouse_code' => $warehouseCode]],
            ['type' => 'PICKING_START', 'data' => ['order_no' => $orderNo, 'warehouse_code' => $warehouseCode]],
            ['type' => 'PACKING_START', 'data' => ['order_no' => $orderNo, 'warehouse_code' => $warehouseCode, 'package_no' => 'PKG001']],
            ['type' => 'ORDER_SHIP', 'data' => ['order_no' => $orderNo, 'warehouse_code' => $warehouseCode, 'tracking_no' => 'TRK001', 'shipping_carrier' => 'USPS']],
            ['type' => 'ORDER_DELIVER', 'data' => ['order_no' => $orderNo, 'warehouse_code' => $warehouseCode, 'signed_by' => '用户']],
        ];
        
        foreach ($callbacks as $cb) {
            $this->callbackService->handle($cb['type'], $cb['data'], '', 'wh_callback_token_2024');
        }
        
        $tracks = $this->db->getTableData('fulfillment_tracks');
        $trackTypes = array_column($tracks, 'track_type');
        
        $this->assertContains('ROUTE_ASSIGNED', $trackTypes, '应该有路由分配的追踪记录');
        $this->assertContains('WMS_PUSHED', $trackTypes, '应该有推送WMS的追踪记录');
        $this->assertContains('WMS_ACCEPTED', $trackTypes, '应该有仓库接单的追踪记录');
        $this->assertContains('PICKING', $trackTypes, '应该有拣货的追踪记录');
        $this->assertContains('PACKING', $trackTypes, '应该有打包的追踪记录');
        $this->assertContains('SHIPPED', $trackTypes, '应该有发货的追踪记录');
        $this->assertContains('DELIVERED', $trackTypes, '应该有签收的追踪记录');
    }

    public function testCancelOrderBeforeShip() {
        $createResult = $this->createTestOrder();
        $orderNo = $createResult['order_no'];
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(2, $orderDetail['order_status']);
        
        $cancelResult = $this->orderService->cancelOrder($orderNo, '用户取消');
        $this->assertTrue($cancelResult['success']);
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(9, $orderDetail['order_status'], '取消后应该是已取消状态');
    }

    public function testCannotCancelAfterShip() {
        $createResult = $this->createTestOrder();
        $orderNo = $createResult['order_no'];
        $warehouseCode = $createResult['warehouse']['warehouse_code'];
        
        $this->callbackService->handle('ORDER_SHIP', [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'tracking_no' => 'TRK001',
            'shipping_carrier' => 'USPS',
        ], '', 'wh_callback_token_2024');
        
        $cancelResult = $this->orderService->cancelOrder($orderNo, '用户取消');
        $this->assertFalse($cancelResult['success']);
        $this->assertEquals('ORDER_ALREADY_SHIPPED', $cancelResult['error_type']);
    }

    public function testExceptionOrderFlow() {
        $createResult = $this->createTestOrder();
        $orderNo = $createResult['order_no'];
        $warehouseCode = $createResult['warehouse']['warehouse_code'];
        
        $exceptionResult = $this->callbackService->handle('ORDER_EXCEPTION', [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'exception_type' => 'DAMAGED',
            'exception_message' => '商品在运输中损坏',
        ], '', 'wh_callback_token_2024');
        
        $this->assertTrue($exceptionResult['success']);
        
        $orderDetail = $this->orderService->getOrderDetail($orderNo);
        $this->assertEquals(9, $orderDetail['fulfillment_status'], '异常后履约状态应该是异常');
    }

    public function testWarehouseRouterIntegrationWithOrder() {
        $router = new WarehouseRouter();
        
        $items = [
            ['sku' => 'SKU001', 'quantity' => 2],
            ['sku' => 'SKU002', 'quantity' => 1],
        ];
        
        $routeResult = $router->route($items, 'US', 'CA');
        $this->assertTrue($routeResult['success']);
        $this->assertNotNull($routeResult['selected_warehouse']);
        
        $createResult = $this->createTestOrder();
        $this->assertTrue($createResult['success']);
        $this->assertEquals(
            $routeResult['selected_warehouse']['warehouse_code'],
            $createResult['warehouse']['warehouse_code'],
            '订单创建的仓库应该与路由结果一致'
        );
    }

    public function testInventoryDeductionOnShip() {
        $createResult = $this->createTestOrder();
        $orderNo = $createResult['order_no'];
        $warehouseCode = $createResult['warehouse']['warehouse_code'];
        $warehouseId = $createResult['warehouse']['warehouse_id'];
        
        $inventoriesBefore = $this->db->getTableData('warehouse_inventories');
        $invBefore = null;
        foreach ($inventoriesBefore as $inv) {
            if ($inv['warehouse_id'] == $warehouseId && $inv['sku'] == 'SKU001') {
                $invBefore = $inv;
                break;
            }
        }
        $this->assertNotNull($invBefore);
        
        $this->callbackService->handle('ORDER_SHIP', [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'tracking_no' => 'TRK001',
            'shipping_carrier' => 'USPS',
        ], '', 'wh_callback_token_2024');
        
        $inventoriesAfter = $this->db->getTableData('warehouse_inventories');
        $invAfter = null;
        foreach ($inventoriesAfter as $inv) {
            if ($inv['warehouse_id'] == $warehouseId && $inv['sku'] == 'SKU001') {
                $invAfter = $inv;
                break;
            }
        }
        $this->assertNotNull($invAfter);
        
        $this->assertEquals(
            $invBefore['reserved_quantity'] - 2,
            $invAfter['reserved_quantity'],
            '发货后预留库存应该减少'
        );
    }

    public function testDuplicateCallbacksIdempotent() {
        $createResult = $this->createTestOrder();
        $orderNo = $createResult['order_no'];
        $warehouseCode = $createResult['warehouse']['warehouse_code'];
        
        $shipData = [
            'order_no' => $orderNo,
            'warehouse_code' => $warehouseCode,
            'tracking_no' => 'TRK001',
            'shipping_carrier' => 'USPS',
        ];
        
        $result1 = $this->callbackService->handle('ORDER_SHIP', $shipData, '', 'wh_callback_token_2024');
        $this->assertTrue($result1['success']);
        $this->assertFalse(isset($result1['skipped']));
        
        $result2 = $this->callbackService->handle('ORDER_SHIP', $shipData, '', 'wh_callback_token_2024');
        $this->assertTrue($result2['success']);
        $this->assertTrue($result2['skipped']);
        
        $tracks = $this->db->getTableData('fulfillment_tracks');
        $shipTracks = array_filter($tracks, function($t) {
            return $t['track_type'] === 'SHIPPED';
        });
        $this->assertCount(1, $shipTracks, '重复发货回调应该只产生一条追踪记录');
    }
}
