<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/OrderNoGenerator.php';
require_once __DIR__ . '/../core/WarehouseRouter.php';

class OrderService {
    private $db;
    private $router;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->router = new WarehouseRouter();
    }

    /**
     * 创建订单：包含路由决策、库存锁定、订单持久化
     */
    public function createOrder($data) {
        $required = ['items', 'customer_name', 'customer_phone', 'shipping_country', 'shipping_address'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "参数 {$field} 不能为空"];
            }
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            return ['success' => false, 'message' => '订单商品不能为空'];
        }

        $routeResult = $this->router->route(
            $data['items'],
            $data['shipping_country'],
            $data['shipping_state'] ?? null
        );

        if (!$routeResult['success']) {
            return ['success' => false, 'message' => '仓库路由失败: ' . $routeResult['message']];
        }

        $warehouse = $routeResult['selected_warehouse'];

        $this->db->beginTransaction();

        try {
            $orderNo = OrderNoGenerator::generate();
            $totalAmount = 0;
            $totalWeight = 0;
            $orderItems = [];

            foreach ($data['items'] as $item) {
                $product = $this->db->fetchOne(
                    "SELECT * FROM products WHERE sku = ? AND status = 1",
                    [$item['sku']]
                );
                if (!$product) {
                    throw new Exception("商品 SKU {$item['sku']} 不存在或已下架");
                }

                $qty = (int)($item['quantity'] ?? 1);
                if ($qty <= 0) {
                    throw new Exception("商品 SKU {$item['sku']} 数量不合法");
                }

                $this->lockInventory($warehouse['warehouse_id'], $product['id'], $qty);

                $subtotal = round($product['price'] * $qty, 2);
                $totalAmount += $subtotal;
                $totalWeight += $product['weight'] * $qty;

                $orderItems[] = [
                    'sku' => $product['sku'],
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'quantity' => $qty,
                    'unit_price' => $product['price'],
                    'weight' => $product['weight'],
                    'subtotal' => $subtotal,
                ];
            }

            $shippingCost = $warehouse['shipping_cost'];
            $orderId = $this->db->insert('orders', [
                'order_no' => $orderNo,
                'external_order_no' => $data['external_order_no'] ?? null,
                'warehouse_id' => $warehouse['warehouse_id'],
                'warehouse_code' => $warehouse['warehouse_code'],
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'shipping_country' => $data['shipping_country'],
                'shipping_state' => $data['shipping_state'] ?? null,
                'shipping_city' => $data['shipping_city'] ?? null,
                'shipping_address' => $data['shipping_address'],
                'shipping_zip' => $data['shipping_zip'] ?? null,
                'total_amount' => round($totalAmount, 2),
                'shipping_cost' => $shippingCost,
                'weight_total' => round($totalWeight, 2),
                'order_status' => 1,
                'fulfillment_status' => 0,
                'estimated_delivery_date' => $warehouse['estimated_delivery_date'],
                'remark' => $data['remark'] ?? null,
            ]);

            foreach ($orderItems as $item) {
                $this->db->insert('order_items', [
                    'order_id' => $orderId,
                    'order_no' => $orderNo,
                    'product_id' => $item['product_id'],
                    'sku' => $item['sku'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'weight' => $item['weight'],
                    'subtotal' => $item['subtotal'],
                ]);
            }

            $this->addTrack($orderId, $orderNo, 'ROUTE_ASSIGNED', 'success', 'SYSTEM',
                "路由分配仓库: {$warehouse['warehouse_name']} ({$warehouse['warehouse_code']})",
                ['warehouse' => $warehouse]
            );

            $this->pushToWarehouse($orderId, $orderNo, $warehouse, $orderItems, $data);

            $this->db->commit();

            return [
                'success' => true,
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'warehouse' => $warehouse,
                'total_amount' => round($totalAmount, 2),
                'shipping_cost' => $shippingCost,
                'estimated_delivery_date' => $warehouse['estimated_delivery_date'],
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 锁定库存
     */
    private function lockInventory($warehouseId, $productId, $quantity) {
        $sql = "UPDATE warehouse_inventories
                SET quantity = quantity - :qty, reserved_quantity = reserved_quantity + :qty
                WHERE warehouse_id = :wid AND product_id = :pid AND quantity >= :qty";
        $stmt = $this->db->query($sql, [
            ':qty' => $quantity,
            ':wid' => $warehouseId,
            ':pid' => $productId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("库存锁定失败，商品ID {$productId} 库存不足");
        }
    }

    /**
     * 模拟推送订单到仓库WMS系统
     */
    private function pushToWarehouse($orderId, $orderNo, $warehouse, $items, $shippingInfo) {
        $warehouseOrderNo = 'WMS' . date('YmdHis') . mt_rand(1000, 9999);

        $this->db->update(
            'orders',
            ['warehouse_order_no' => $warehouseOrderNo, 'order_status' => 2],
            'id = ?',
            [$orderId]
        );

        $this->addTrack($orderId, $orderNo, 'WMS_PUSHED', 'success', 'SYSTEM',
            "订单已推送到仓库 WMS，仓库单号: {$warehouseOrderNo}",
            ['warehouse_order_no' => $warehouseOrderNo]
        );

        return $warehouseOrderNo;
    }

    private function addTrack($orderId, $orderNo, $type, $status, $operator, $description, $extra = null) {
        return $this->db->insert('fulfillment_tracks', [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'track_type' => $type,
            'track_status' => $status,
            'operator' => $operator,
            'description' => $description,
            'extra_data' => $extra ? json_encode($extra) : null,
        ]);
    }

    /**
     * 查询订单列表
     */
    public function listOrders($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['order_no'])) {
            $where[] = 'order_no = ?';
            $bindParams[] = $params['order_no'];
        }
        if (!empty($params['external_order_no'])) {
            $where[] = 'external_order_no = ?';
            $bindParams[] = $params['external_order_no'];
        }
        if (isset($params['order_status']) && $params['order_status'] !== '') {
            $where[] = 'order_status = ?';
            $bindParams[] = (int)$params['order_status'];
        }
        if (isset($params['warehouse_id']) && $params['warehouse_id'] !== '') {
            $where[] = 'warehouse_id = ?';
            $bindParams[] = (int)$params['warehouse_id'];
        }
        if (!empty($params['customer_phone'])) {
            $where[] = 'customer_phone LIKE ?';
            $bindParams[] = '%' . $params['customer_phone'] . '%';
        }
        if (!empty($params['start_date'])) {
            $where[] = 'created_at >= ?';
            $bindParams[] = $params['start_date'] . ' 00:00:00';
        }
        if (!empty($params['end_date'])) {
            $where[] = 'created_at <= ?';
            $bindParams[] = $params['end_date'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) as cnt FROM orders WHERE $whereSql";
        $totalRow = $this->db->fetchOne($countSql, $bindParams);
        $total = (int)$totalRow['cnt'];

        $sql = "SELECT o.*, w.warehouse_name as warehouse_name
                FROM orders o
                LEFT JOIN warehouses w ON o.warehouse_id = w.id
                WHERE $whereSql
                ORDER BY o.id DESC
                LIMIT $offset, $pageSize";

        $list = $this->db->fetchAll($sql, $bindParams);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize),
        ];
    }

    /**
     * 查询订单详情
     */
    public function getOrderDetail($orderNo) {
        $order = $this->db->fetchOne(
            "SELECT o.*, w.warehouse_name as warehouse_name
             FROM orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             WHERE o.order_no = ?",
            [$orderNo]
        );

        if (!$order) {
            return null;
        }

        $items = $this->db->fetchAll(
            "SELECT * FROM order_items WHERE order_no = ?",
            [$orderNo]
        );

        $tracks = $this->db->fetchAll(
            "SELECT * FROM fulfillment_tracks WHERE order_no = ? ORDER BY id ASC",
            [$orderNo]
        );

        $order['items'] = $items;
        $order['tracks'] = $tracks;

        return $order;
    }

    /**
     * 取消订单
     */
    public function cancelOrder($orderNo, $reason = '') {
        $order = $this->db->fetchOne("SELECT * FROM orders WHERE order_no = ?", [$orderNo]);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['order_status'] >= 4) {
            return ['success' => false, 'message' => '订单已出库，无法取消'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update(
                'orders',
                ['order_status' => 9],
                'order_no = ?',
                [$orderNo]
            );

            $items = $this->db->fetchAll(
                "SELECT oi.*, wi.id as inv_id
                 FROM order_items oi
                 JOIN warehouse_inventories wi ON oi.product_id = wi.product_id AND wi.warehouse_id = ?
                 WHERE oi.order_no = ?",
                [$order['warehouse_id'], $orderNo]
            );

            foreach ($items as $item) {
                $this->db->query(
                    "UPDATE warehouse_inventories
                     SET quantity = quantity + ?, reserved_quantity = reserved_quantity - ?
                     WHERE warehouse_id = ? AND product_id = ?",
                    [$item['quantity'], $item['quantity'], $order['warehouse_id'], $item['product_id']]
                );
            }

            $this->addTrack($order['id'], $orderNo, 'CANCELLED', 'success', 'SYSTEM',
                '订单已取消' . ($reason ? ": {$reason}" : ''),
                ['reason' => $reason]
            );

            $this->db->commit();
            return ['success' => true, 'message' => '订单已取消'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function getOrderStatusMap() {
        return [
            0 => '待处理',
            1 => '已路由',
            2 => '已推送仓库',
            3 => '仓库已接单',
            4 => '已出库',
            5 => '已发货',
            6 => '已签收',
            9 => '已取消',
        ];
    }

    public static function getFulfillmentStatusMap() {
        return [
            0 => '未开始',
            1 => '拣货中',
            2 => '打包中',
            3 => '已发货',
            4 => '已签收',
            9 => '异常',
        ];
    }
}
