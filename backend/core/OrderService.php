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

    private function validateOrderData($data) {
        $errors = [];
        $clean = [];

        $requiredFields = [
            'items' => '订单商品',
            'customer_name' => '收件人姓名',
            'customer_phone' => '联系电话',
            'shipping_country' => '收货国家',
            'shipping_address' => '详细地址'
        ];

        foreach ($requiredFields as $field => $label) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $errors[] = "{$label}不能为空";
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => implode('；', $errors),
                'error_type' => 'VALIDATION_ERROR',
                'errors' => $errors
            ];
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            return [
                'success' => false,
                'message' => '订单商品不能为空，请至少添加一个商品',
                'error_type' => 'EMPTY_ITEMS'
            ];
        }

        $skuList = [];
        foreach ($data['items'] as $index => $item) {
            if (empty($item['sku'])) {
                return [
                    'success' => false,
                    'message' => "第" . ($index + 1) . "个商品的 SKU 不能为空",
                    'error_type' => 'INVALID_SKU',
                    'item_index' => $index
                ];
            }

            $qty = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            if ($qty <= 0) {
                return [
                    'success' => false,
                    'message' => "商品 SKU {$item['sku']} 的数量必须大于 0",
                    'error_type' => 'INVALID_QUANTITY',
                    'sku' => $item['sku']
                ];
            }

            if ($qty > 999) {
                return [
                    'success' => false,
                    'message' => "商品 SKU {$item['sku']} 的数量不能超过 999",
                    'error_type' => 'QUANTITY_TOO_LARGE',
                    'sku' => $item['sku']
                ];
            }

            $skuList[] = trim($item['sku']);
        }

        $nameLen = mb_strlen(trim($data['customer_name']));
        if ($nameLen < 2 || $nameLen > 50) {
            return [
                'success' => false,
                'message' => '收件人姓名长度必须在 2-50 个字符之间',
                'error_type' => 'INVALID_NAME'
            ];
        }

        $phone = trim($data['customer_phone']);
        if (!preg_match('/^[\d\s\-+()]{6,20}$/', $phone)) {
            return [
                'success' => false,
                'message' => '联系电话格式不正确，请输入有效的电话号码',
                'error_type' => 'INVALID_PHONE'
            ];
        }

        if (!empty($data['customer_email'])) {
            $email = trim($data['customer_email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => '邮箱格式不正确',
                    'error_type' => 'INVALID_EMAIL'
                ];
            }
            $clean['customer_email'] = $email;
        } else {
            $clean['customer_email'] = null;
        }

        $country = trim($data['shipping_country']);
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return [
                'success' => false,
                'message' => '国家代码格式不正确，请使用 2 位大写字母 ISO 代码',
                'error_type' => 'INVALID_COUNTRY'
            ];
        }

        $addressLen = mb_strlen(trim($data['shipping_address']));
        if ($addressLen < 5) {
            return [
                'success' => false,
                'message' => '详细地址长度不能少于 5 个字符',
                'error_type' => 'INVALID_ADDRESS'
            ];
        }

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            $cleanItems[] = [
                'sku' => trim($item['sku']),
                'quantity' => (int)$item['quantity']
            ];
        }

        $clean['items'] = $cleanItems;
        $clean['customer_name'] = trim($data['customer_name']);
        $clean['customer_phone'] = $phone;
        $clean['shipping_country'] = $country;
        $clean['shipping_state'] = !empty($data['shipping_state']) ? trim($data['shipping_state']) : null;
        $clean['shipping_city'] = !empty($data['shipping_city']) ? trim($data['shipping_city']) : null;
        $clean['shipping_address'] = trim($data['shipping_address']);
        $clean['shipping_zip'] = !empty($data['shipping_zip']) ? trim($data['shipping_zip']) : null;
        $clean['external_order_no'] = !empty($data['external_order_no']) ? trim($data['external_order_no']) : null;
        $clean['remark'] = !empty($data['remark']) ? trim($data['remark']) : null;

        return [
            'success' => true,
            'data' => $clean
        ];
    }

    public function createOrder($data, $traceId = null, $appId = null, $scopeWarehouseCode = null) {
        $validateResult = $this->validateOrderData($data);
        if (!$validateResult['success']) {
            return $validateResult;
        }

        $cleanData = $validateResult['data'];

        if (!empty($scopeWarehouseCode)) {
            $this->router->setPermissionContext('warehouse_operator', $scopeWarehouseCode);
        }

        $routeResult = $this->router->route(
            $cleanData['items'],
            $cleanData['shipping_country'],
            $cleanData['shipping_state'] ?? null,
            $traceId,
            $appId
        );

        if (!$routeResult['success']) {
            return [
                'success' => false,
                'message' => '仓库路由失败: ' . $routeResult['message'],
                'error_type' => 'ROUTING_ERROR',
                'rollback' => true,
                'retryable' => true,
                'details' => $routeResult
            ];
        }

        if (!isset($routeResult['selected_warehouse']) || empty($routeResult['selected_warehouse'])) {
            return [
                'success' => false,
                'message' => '未找到匹配的仓库，请检查商品库存或收货地址',
                'error_type' => 'NO_WAREHOUSE_MATCHED',
                'rollback' => true,
                'retryable' => false,
                'details' => $routeResult
            ];
        }

        $warehouse = $routeResult['selected_warehouse'];

        if (!empty($scopeWarehouseCode) && $warehouse['warehouse_code'] !== $scopeWarehouseCode) {
            return [
                'success' => false,
                'message' => "仓库路由结果与分配仓库不匹配，路由选择了仓库 [{$warehouse['warehouse_code']}]，但您仅可操作 [{$scopeWarehouseCode}]",
                'error_type' => 'WAREHOUSE_SCOPE_MISMATCH',
                'rollback' => true,
                'retryable' => false,
                'details' => [
                    'route_result' => $warehouse['warehouse_code'],
                    'scope_warehouse' => $scopeWarehouseCode
                ]
            ];
        }

        $this->db->beginTransaction();

        try {
            $orderNo = OrderNoGenerator::generate();
            $totalAmount = 0;
            $totalWeight = 0;
            $orderItems = [];

            foreach ($cleanData['items'] as $item) {
                $product = $this->db->fetchOne(
                    "SELECT * FROM products WHERE sku = ? AND status = 1",
                    [$item['sku']]
                );
                if (!$product) {
                    throw new Exception("商品 SKU {$item['sku']} 不存在或已下架");
                }

                $qty = $item['quantity'];

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
                'external_order_no' => $cleanData['external_order_no'],
                'warehouse_id' => $warehouse['warehouse_id'],
                'warehouse_code' => $warehouse['warehouse_code'],
                'customer_name' => $cleanData['customer_name'],
                'customer_phone' => $cleanData['customer_phone'],
                'customer_email' => $cleanData['customer_email'],
                'shipping_country' => $cleanData['shipping_country'],
                'shipping_state' => $cleanData['shipping_state'],
                'shipping_city' => $cleanData['shipping_city'],
                'shipping_address' => $cleanData['shipping_address'],
                'shipping_zip' => $cleanData['shipping_zip'],
                'total_amount' => round($totalAmount, 2),
                'shipping_cost' => $shippingCost,
                'weight_total' => round($totalWeight, 2),
                'order_status' => 1,
                'fulfillment_status' => 0,
                'estimated_delivery_date' => $warehouse['estimated_delivery_date'],
                'remark' => $cleanData['remark'],
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

            $this->pushToWarehouse($orderId, $orderNo, $warehouse, $orderItems, $cleanData);

            $this->db->commit();

            return [
                'success' => true,
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'warehouse' => $warehouse,
                'total_amount' => round($totalAmount, 2),
                'shipping_cost' => $shippingCost,
                'estimated_delivery_date' => $warehouse['estimated_delivery_date'],
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'ORDER_CREATE_FAILED',
                'rollback' => true,
                'retryable' => true,
                'details' => [
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]
            ];
        }
    }

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

    public function listOrders($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['order_no'])) {
            $where[] = 'o.order_no = ?';
            $bindParams[] = $params['order_no'];
        }
        if (!empty($params['external_order_no'])) {
            $where[] = 'o.external_order_no = ?';
            $bindParams[] = $params['external_order_no'];
        }
        if (isset($params['order_status']) && $params['order_status'] !== '') {
            $where[] = 'o.order_status = ?';
            $bindParams[] = (int)$params['order_status'];
        }
        if (isset($params['warehouse_id']) && $params['warehouse_id'] !== '') {
            $where[] = 'o.warehouse_id = ?';
            $bindParams[] = (int)$params['warehouse_id'];
        }
        if (!empty($params['scope_warehouse_code'])) {
            $where[] = 'w.warehouse_code = ?';
            $bindParams[] = $params['scope_warehouse_code'];
        }
        if (!empty($params['customer_phone'])) {
            $where[] = 'o.customer_phone LIKE ?';
            $bindParams[] = '%' . $params['customer_phone'] . '%';
        }
        if (!empty($params['start_date'])) {
            $where[] = 'o.created_at >= ?';
            $bindParams[] = $params['start_date'] . ' 00:00:00';
        }
        if (!empty($params['end_date'])) {
            $where[] = 'o.created_at <= ?';
            $bindParams[] = $params['end_date'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) as cnt FROM orders o LEFT JOIN warehouses w ON o.warehouse_id = w.id WHERE $whereSql";
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
            'permission_scoped' => !empty($params['scope_warehouse_code']),
            'scope_warehouse_code' => $params['scope_warehouse_code'] ?? null,
        ];
    }

    public function getOrderDetail($orderNo, $scopeWarehouseCode = null, &$permissionCheck = []) {
        $permissionCheck = [
            'passed' => true,
            'order_found' => false,
            'in_scope' => true,
            'scope_warehouse_code' => $scopeWarehouseCode,
            'actual_warehouse_code' => null,
            'message' => '',
        ];

        $order = $this->db->fetchOne(
            "SELECT o.*, w.warehouse_name as warehouse_name
             FROM orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             WHERE o.order_no = ?",
            [$orderNo]
        );

        $permissionCheck['order_found'] = !empty($order);

        if (!$order) {
            $permissionCheck['passed'] = false;
            $permissionCheck['message'] = '订单不存在';
            return null;
        }

        $permissionCheck['actual_warehouse_code'] = $order['warehouse_code'];

        if (!empty($scopeWarehouseCode) && $order['warehouse_code'] !== $scopeWarehouseCode) {
            $permissionCheck['passed'] = false;
            $permissionCheck['in_scope'] = false;
            $permissionCheck['message'] = "无权查看订单 [{$orderNo}]，该订单属于仓库 [{$order['warehouse_code']}]，当前仅可查看 [{$scopeWarehouseCode}]";
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

        $permissionCheck['passed'] = true;
        $permissionCheck['message'] = '权限校验通过';
        return $order;
    }

    public function cancelOrder($orderNo, $reason = '', $scopeWarehouseCode = null, &$permissionCheck = []) {
        $permissionCheck = [
            'passed' => true,
            'order_found' => false,
            'in_scope' => true,
            'scope_warehouse_code' => $scopeWarehouseCode,
            'actual_warehouse_code' => null,
            'message' => '',
        ];

        $order = $this->db->fetchOne("SELECT * FROM orders WHERE order_no = ?", [$orderNo]);

        $permissionCheck['order_found'] = !empty($order);

        if (!$order) {
            $permissionCheck['passed'] = false;
            $permissionCheck['message'] = '订单不存在';
            return ['success' => false, 'message' => '订单不存在', 'error_type' => 'ORDER_NOT_FOUND'];
        }

        $permissionCheck['actual_warehouse_code'] = $order['warehouse_code'];

        if (!empty($scopeWarehouseCode) && $order['warehouse_code'] !== $scopeWarehouseCode) {
            $permissionCheck['passed'] = false;
            $permissionCheck['in_scope'] = false;
            $permissionCheck['message'] = "无权取消订单 [{$orderNo}]，该订单属于仓库 [{$order['warehouse_code']}]，当前仅可操作 [{$scopeWarehouseCode}]";
            return [
                'success' => false,
                'message' => $permissionCheck['message'],
                'error_type' => 'WAREHOUSE_SCOPE_MISMATCH'
            ];
        }

        if ($order['order_status'] >= 4) {
            $permissionCheck['passed'] = false;
            $permissionCheck['message'] = '订单已出库，无法取消';
            return ['success' => false, 'message' => '订单已出库，无法取消', 'error_type' => 'ORDER_ALREADY_SHIPPED'];
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
            $permissionCheck['passed'] = true;
            $permissionCheck['message'] = '订单已取消';
            return ['success' => true, 'message' => '订单已取消'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage(), 'error_type' => 'ORDER_CANCEL_FAILED'];
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
