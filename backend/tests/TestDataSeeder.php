<?php
class TestDataSeeder {
    public static function seedDefaultData($db) {
        self::seedWarehouses($db);
        self::seedShippingZones($db);
        self::seedProducts($db);
        self::seedInventories($db);
    }

    public static function seedWarehouses($db) {
        $warehouses = [
            [
                'warehouse_code' => 'USCA',
                'warehouse_name' => '美国加州仓',
                'country' => 'US',
                'state' => 'California',
                'city' => 'Los Angeles',
                'address' => '123 Industrial Blvd, LA, CA',
                'status' => 1,
                'priority' => 100,
            ],
            [
                'warehouse_code' => 'USNJ',
                'warehouse_name' => '美国新泽西仓',
                'country' => 'US',
                'state' => 'New Jersey',
                'city' => 'Newark',
                'address' => '456 Port Ave, Newark, NJ',
                'status' => 1,
                'priority' => 90,
            ],
            [
                'warehouse_code' => 'GBLN',
                'warehouse_name' => '英国伦敦仓',
                'country' => 'GB',
                'state' => 'England',
                'city' => 'London',
                'address' => '789 Logistics Park, London',
                'status' => 1,
                'priority' => 80,
            ],
            [
                'warehouse_code' => 'DEBE',
                'warehouse_name' => '德国柏林仓',
                'country' => 'DE',
                'state' => 'Berlin',
                'city' => 'Berlin',
                'address' => '321 Freight Center, Berlin',
                'status' => 1,
                'priority' => 70,
            ],
        ];
        $db->seedWarehouses($warehouses);
    }

    public static function seedShippingZones($db) {
        $zones = [
            ['warehouse_id' => 1, 'country' => 'US', 'state' => null, 'shipping_days_min' => 2, 'shipping_days_max' => 4, 'shipping_cost_base' => 5.99, 'shipping_cost_per_kg' => 1.50, 'status' => 1],
            ['warehouse_id' => 1, 'country' => 'CA', 'state' => null, 'shipping_days_min' => 5, 'shipping_days_max' => 8, 'shipping_cost_base' => 12.99, 'shipping_cost_per_kg' => 2.00, 'status' => 1],
            ['warehouse_id' => 2, 'country' => 'US', 'state' => 'NY', 'shipping_days_min' => 1, 'shipping_days_max' => 3, 'shipping_cost_base' => 4.99, 'shipping_cost_per_kg' => 1.20, 'status' => 1],
            ['warehouse_id' => 2, 'country' => 'US', 'state' => 'NJ', 'shipping_days_min' => 1, 'shipping_days_max' => 2, 'shipping_cost_base' => 3.99, 'shipping_cost_per_kg' => 1.00, 'status' => 1],
            ['warehouse_id' => 2, 'country' => 'US', 'state' => null, 'shipping_days_min' => 2, 'shipping_days_max' => 4, 'shipping_cost_base' => 5.99, 'shipping_cost_per_kg' => 1.30, 'status' => 1],
            ['warehouse_id' => 3, 'country' => 'GB', 'state' => null, 'shipping_days_min' => 1, 'shipping_days_max' => 3, 'shipping_cost_base' => 4.99, 'shipping_cost_per_kg' => 1.20, 'status' => 1],
            ['warehouse_id' => 3, 'country' => 'DE', 'state' => null, 'shipping_days_min' => 3, 'shipping_days_max' => 5, 'shipping_cost_base' => 8.99, 'shipping_cost_per_kg' => 1.60, 'status' => 1],
            ['warehouse_id' => 4, 'country' => 'DE', 'state' => null, 'shipping_days_min' => 1, 'shipping_days_max' => 2, 'shipping_cost_base' => 3.99, 'shipping_cost_per_kg' => 1.00, 'status' => 1],
            ['warehouse_id' => 4, 'country' => 'FR', 'state' => null, 'shipping_days_min' => 2, 'shipping_days_max' => 4, 'shipping_cost_base' => 6.99, 'shipping_cost_per_kg' => 1.40, 'status' => 1],
        ];
        $db->seedShippingZones($zones);
    }

    public static function seedProducts($db) {
        $products = [
            ['sku' => 'SKU001', 'name' => '无线蓝牙耳机', 'weight' => 0.15, 'volume' => 0.001, 'price' => 29.99, 'status' => 1],
            ['sku' => 'SKU002', 'name' => '智能手表', 'weight' => 0.30, 'volume' => 0.002, 'price' => 89.99, 'status' => 1],
            ['sku' => 'SKU003', 'name' => '便携充电宝', 'weight' => 0.25, 'volume' => 0.0015, 'price' => 25.99, 'status' => 1],
            ['sku' => 'SKU004', 'name' => 'USB-C快充数据线', 'weight' => 0.05, 'volume' => 0.0002, 'price' => 9.99, 'status' => 1],
            ['sku' => 'SKU005', 'name' => '笔记本电脑支架', 'weight' => 0.80, 'volume' => 0.005, 'price' => 35.99, 'status' => 1],
        ];
        $db->seedProducts($products);
    }

    public static function seedInventories($db) {
        $inventories = [
            ['warehouse_id' => 1, 'product_id' => 1, 'sku' => 'SKU001', 'quantity' => 500, 'reserved_quantity' => 0],
            ['warehouse_id' => 1, 'product_id' => 2, 'sku' => 'SKU002', 'quantity' => 200, 'reserved_quantity' => 0],
            ['warehouse_id' => 1, 'product_id' => 3, 'sku' => 'SKU003', 'quantity' => 800, 'reserved_quantity' => 0],
            ['warehouse_id' => 1, 'product_id' => 4, 'sku' => 'SKU004', 'quantity' => 2000, 'reserved_quantity' => 0],
            ['warehouse_id' => 1, 'product_id' => 5, 'sku' => 'SKU005', 'quantity' => 150, 'reserved_quantity' => 0],
            ['warehouse_id' => 2, 'product_id' => 1, 'sku' => 'SKU001', 'quantity' => 600, 'reserved_quantity' => 0],
            ['warehouse_id' => 2, 'product_id' => 2, 'sku' => 'SKU002', 'quantity' => 250, 'reserved_quantity' => 0],
            ['warehouse_id' => 2, 'product_id' => 3, 'sku' => 'SKU003', 'quantity' => 900, 'reserved_quantity' => 0],
            ['warehouse_id' => 2, 'product_id' => 4, 'sku' => 'SKU004', 'quantity' => 2500, 'reserved_quantity' => 0],
            ['warehouse_id' => 3, 'product_id' => 1, 'sku' => 'SKU001', 'quantity' => 300, 'reserved_quantity' => 0],
            ['warehouse_id' => 3, 'product_id' => 2, 'sku' => 'SKU002', 'quantity' => 150, 'reserved_quantity' => 0],
            ['warehouse_id' => 3, 'product_id' => 3, 'sku' => 'SKU003', 'quantity' => 400, 'reserved_quantity' => 0],
            ['warehouse_id' => 4, 'product_id' => 1, 'sku' => 'SKU001', 'quantity' => 350, 'reserved_quantity' => 0],
            ['warehouse_id' => 4, 'product_id' => 5, 'sku' => 'SKU005', 'quantity' => 100, 'reserved_quantity' => 0],
        ];
        $db->seedInventories($inventories);
    }

    public static function createTestOrder($db, $orderNo, $warehouseId = 1, $warehouseCode = 'USCA', $status = 2) {
        return $db->seedOrder([
            'order_no' => $orderNo,
            'external_order_no' => 'EXT' . time(),
            'warehouse_id' => $warehouseId,
            'warehouse_code' => $warehouseCode,
            'warehouse_order_no' => 'WMS' . time(),
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
            'order_status' => $status,
            'fulfillment_status' => 0,
            'estimated_delivery_date' => date('Y-m-d', strtotime('+3 days')),
        ]);
    }
}
