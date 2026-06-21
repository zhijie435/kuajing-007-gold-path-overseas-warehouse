<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/WarehouseRouter.php';
require_once __DIR__ . '/../core/AuditService.php';
require_once __DIR__ . '/../core/PermissionService.php';

class WarehouseRouterTest extends TestCase {
    private $router;

    public function setUp(): void {
        parent::setUp();
        TestDataSeeder::seedDefaultData($this->db);
        $this->router = new WarehouseRouter();
    }

    public function testRouteBasicSuccess() {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 2],
        ];
        $result = $this->router->route($items, 'US');
        
        $this->assertTrue($result['success'], '路由应该成功');
        $this->assertArrayHasKey('selected_warehouse', $result);
        $this->assertNotNull($result['selected_warehouse']);
        $this->assertNotEmpty($result['selected_warehouse']['warehouse_code']);
    }

    public function testRouteEmptyItems() {
        $result = $this->router->route([], 'US');
        
        $this->assertFalse($result['success'], '空商品应该路由失败');
        $this->assertEquals('EMPTY_ITEMS', $result['error_type']);
    }

    public function testRouteEmptyCountry() {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, '');
        
        $this->assertFalse($result['success'], '空国家应该路由失败');
        $this->assertEquals('EMPTY_COUNTRY', $result['error_type']);
    }

    public function testRouteInvalidSku() {
        $items = [
            ['sku' => '', 'quantity' => 1],
        ];
        $result = $this->router->route($items, 'US');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_SKU', $result['error_type']);
    }

    public function testRouteInvalidQuantity() {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 0],
        ];
        $result = $this->router->route($items, 'US');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_QUANTITY', $result['error_type']);
    }

    public function testRouteInsufficientStock() {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 10000],
        ];
        $result = $this->router->route($items, 'US');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INSUFFICIENT_STOCK', $result['error_type']);
        $this->assertArrayHasKey('missing_skus', $result);
    }

    public function testRouteNoShippingZone() {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 1],
        ];
        $result = $this->router->route($items, 'XX');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('NO_SHIPPING_ZONE', $result['error_type']);
    }

    public function testRouteMultipleItems() {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 2],
            ['sku' => 'SKU002', 'quantity' => 1],
            ['sku' => 'SKU003', 'quantity' => 3],
        ];
        $result = $this->router->route($items, 'US');
        
        $this->assertTrue($result['success'], '多商品路由应该成功');
        $this->assertNotNull($result['selected_warehouse']);
        $this->assertGreaterThan(0, $result['total_weight']);
    }

    public function testRouteReturnsAlternatives() {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US');
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('alternatives', $result);
        $this->assertIsArray($result['alternatives']);
    }

    public function testRouteScoringConsidersCost() {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US');
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('shipping_cost', $result['selected_warehouse']);
        $this->assertGreaterThan(0, $result['selected_warehouse']['shipping_cost']);
    }

    public function testRouteHasEstimatedDeliveryDate() {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US');
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['selected_warehouse']['estimated_delivery_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['selected_warehouse']['estimated_delivery_date']);
    }

    public function testPermissionScopeWarehouse() {
        $this->router->setPermissionContext('warehouse_operator', 'USCA');
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US');
        
        $this->assertTrue($result['success']);
        $this->assertEquals('USCA', $result['selected_warehouse']['warehouse_code']);
        $this->assertTrue($result['permission_scoped']);
        $this->assertEquals('USCA', $result['scope_warehouse_code']);
    }

    public function testPermissionScopeWrongWarehouseNoStock() {
        $this->router->setPermissionContext('warehouse_operator', 'DEBE');
        $items = [['sku' => 'SKU002', 'quantity' => 1]];
        $result = $this->router->route($items, 'DE');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INSUFFICIENT_STOCK', $result['error_type']);
    }

    public function testListWarehouses() {
        $result = $this->router->listWarehouses();
        $this->assertNotEmpty($result);
        $this->assertCount(4, $result);
    }

    public function testListWarehousesWithScope() {
        $result = $this->router->listWarehouses(1, 'USCA');
        $this->assertNotEmpty($result);
        $this->assertCount(1, $result);
        $this->assertEquals('USCA', $result[0]['warehouse_code']);
    }

    public function testGetWarehouseByCode() {
        $warehouse = $this->router->getWarehouseByCode('USCA');
        $this->assertNotNull($warehouse);
        $this->assertEquals('USCA', $warehouse['warehouse_code']);
        $this->assertEquals('美国加州仓', $warehouse['warehouse_name']);
    }

    public function testGetWarehouseByCodeNotFound() {
        $warehouse = $this->router->getWarehouseByCode('NONEXIST');
        $this->assertNull($warehouse);
    }
}
