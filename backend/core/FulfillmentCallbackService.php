<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/OrderService.php';

class FulfillmentCallbackService {
    private $db;
    private $config;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    /**
     * 验证回调Token
     */
    public function validateToken($token) {
        return $token === $this->config['callback']['token'];
    }

    /**
     * 统一回调处理入口
     */
    public function handle($callbackType, $data, $rawBody = '') {
        $logId = $this->logCallback($callbackType, $data, $rawBody);

        try {
            switch ($callbackType) {
                case 'ORDER_ACCEPT':
                    $result = $this->handleOrderAccept($data);
                    break;
                case 'PICKING_START':
                    $result = $this->handlePickingStart($data);
                    break;
                case 'PACKING_START':
                    $result = $this->handlePackingStart($data);
                    break;
                case 'ORDER_SHIP':
                    $result = $this->handleOrderShip($data);
                    break;
                case 'ORDER_DELIVER':
                    $result = $this->handleOrderDeliver($data);
                    break;
                case 'ORDER_EXCEPTION':
                    $result = $this->handleOrderException($data);
                    break;
                default:
                    $result = ['success' => false, 'message' => "未知回调类型: {$callbackType}"];
            }

            $this->updateCallbackLog($logId, $result);
            return $result;

        } catch (Exception $e) {
            $error = ['success' => false, 'message' => '回调处理异常: ' . $e->getMessage()];
            $this->updateCallbackLog($logId, $error, $e->getMessage());
            return $error;
        }
    }

    /**
     * 仓库接单回调
     */
    private function handleOrderAccept($data) {
        $this->validateRequiredFields($data, ['order_no', 'warehouse_order_no', 'warehouse_code']);

        $order = $this->getOrder($data['order_no']);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['order_status'] >= 3) {
            return ['success' => true, 'message' => '订单状态已更新，跳过重复回调', 'skipped' => true];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update(
                'orders',
                [
                    'order_status' => 3,
                    'warehouse_order_no' => $data['warehouse_order_no'],
                    'warehouse_code' => $data['warehouse_code'],
                ],
                'order_no = ?',
                [$data['order_no']]
            );

            $this->addTrack($order['id'], $order['order_no'], 'WMS_ACCEPTED', 'success',
                $data['warehouse_code'],
                "仓库已接单，操作时间: " . ($data['operate_time'] ?? date('Y-m-d H:i:s')),
                $data
            );

            $this->db->commit();
            return ['success' => true, 'message' => '订单已更新为仓库接单'];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * 开始拣货
     */
    private function handlePickingStart($data) {
        $this->validateRequiredFields($data, ['order_no', 'warehouse_code']);

        $order = $this->getOrder($data['order_no']);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update(
                'orders',
                ['fulfillment_status' => 1],
                'order_no = ?',
                [$data['order_no']]
            );

            $this->addTrack($order['id'], $order['order_no'], 'PICKING', 'started',
                $data['warehouse_code'],
                "仓库开始拣货" . (isset($data['operator']) ? "，操作员: {$data['operator']}" : ''),
                $data
            );

            $this->db->commit();
            return ['success' => true, 'message' => '已更新为拣货中'];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * 开始打包
     */
    private function handlePackingStart($data) {
        $this->validateRequiredFields($data, ['order_no', 'warehouse_code']);

        $order = $this->getOrder($data['order_no']);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update(
                'orders',
                ['fulfillment_status' => 2],
                'order_no = ?',
                [$data['order_no']]
            );

            $this->addTrack($order['id'], $order['order_no'], 'PACKING', 'started',
                $data['warehouse_code'],
                "仓库开始打包" . (isset($data['package_no']) ? "，包裹号: {$data['package_no']}" : ''),
                $data
            );

            $this->db->commit();
            return ['success' => true, 'message' => '已更新为打包中'];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * 发货回调
     */
    private function handleOrderShip($data) {
        $this->validateRequiredFields($data, ['order_no', 'warehouse_code', 'tracking_no', 'shipping_carrier']);

        $order = $this->getOrder($data['order_no']);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['order_status'] >= 5) {
            return ['success' => true, 'message' => '订单已发货，跳过重复回调', 'skipped' => true];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update(
                'orders',
                [
                    'order_status' => 5,
                    'fulfillment_status' => 3,
                    'tracking_no' => $data['tracking_no'],
                    'shipping_carrier' => $data['shipping_carrier'],
                ],
                'order_no = ?',
                [$data['order_no']]
            );

            $items = $this->db->fetchAll(
                "SELECT * FROM order_items WHERE order_no = ?",
                [$data['order_no']]
            );
            foreach ($items as $item) {
                $this->db->query(
                    "UPDATE warehouse_inventories
                     SET reserved_quantity = reserved_quantity - ?
                     WHERE warehouse_id = ? AND product_id = ?",
                    [$item['quantity'], $order['warehouse_id'], $item['product_id']]
                );
            }

            $this->addTrack($order['id'], $order['order_no'], 'SHIPPED', 'success',
                $data['warehouse_code'],
                "商品已发货，物流商: {$data['shipping_carrier']}，运单号: {$data['tracking_no']}",
                $data
            );

            $this->db->commit();
            return ['success' => true, 'message' => '订单已更新为已发货'];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * 签收回调
     */
    private function handleOrderDeliver($data) {
        $this->validateRequiredFields($data, ['order_no', 'warehouse_code']);

        $order = $this->getOrder($data['order_no']);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['order_status'] >= 6) {
            return ['success' => true, 'message' => '订单已签收，跳过重复回调', 'skipped' => true];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update(
                'orders',
                [
                    'order_status' => 6,
                    'fulfillment_status' => 4,
                    'actual_delivery_date' => $data['deliver_time'] ?? date('Y-m-d'),
                ],
                'order_no = ?',
                [$data['order_no']]
            );

            $this->addTrack($order['id'], $order['order_no'], 'DELIVERED', 'success',
                $data['warehouse_code'],
                "订单已签收" . (isset($data['signed_by']) ? "，签收人: {$data['signed_by']}" : ''),
                $data
            );

            $this->db->commit();
            return ['success' => true, 'message' => '订单已更新为已签收'];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * 异常回调
     */
    private function handleOrderException($data) {
        $this->validateRequiredFields($data, ['order_no', 'warehouse_code', 'exception_type', 'exception_message']);

        $order = $this->getOrder($data['order_no']);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update(
                'orders',
                ['fulfillment_status' => 9],
                'order_no = ?',
                [$data['order_no']]
            );

            $this->addTrack($order['id'], $order['order_no'], 'EXCEPTION', $data['exception_type'],
                $data['warehouse_code'],
                "异常 [{$data['exception_type']}]: {$data['exception_message']}",
                $data
            );

            $this->db->commit();
            return ['success' => true, 'message' => '异常已记录'];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function getOrder($orderNo) {
        return $this->db->fetchOne("SELECT * FROM orders WHERE order_no = ?", [$orderNo]);
    }

    private function validateRequiredFields($data, $fields) {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                throw new Exception("缺少必填字段: {$field}");
            }
        }
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

    private function logCallback($callbackType, $data, $rawBody) {
        return $this->db->insert('warehouse_callback_logs', [
            'callback_type' => $callbackType,
            'warehouse_code' => $data['warehouse_code'] ?? null,
            'warehouse_order_no' => $data['warehouse_order_no'] ?? null,
            'order_no' => $data['order_no'] ?? null,
            'request_body' => $rawBody ?: json_encode($data, JSON_UNESCAPED_UNICODE),
            'is_processed' => 0,
        ]);
    }

    private function updateCallbackLog($logId, $result, $errorMessage = null) {
        $this->db->update(
            'warehouse_callback_logs',
            [
                'is_processed' => $result['success'] ? 1 : 0,
                'response_body' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'error_message' => $errorMessage ?? ($result['success'] ? null : $result['message']),
            ],
            'id = ?',
            [$logId]
        );
    }

    public function listCallbackLogs($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['order_no'])) {
            $where[] = 'order_no = ?';
            $bindParams[] = $params['order_no'];
        }
        if (!empty($params['callback_type'])) {
            $where[] = 'callback_type = ?';
            $bindParams[] = $params['callback_type'];
        }
        if (isset($params['is_processed']) && $params['is_processed'] !== '') {
            $where[] = 'is_processed = ?';
            $bindParams[] = (int)$params['is_processed'];
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) as cnt FROM warehouse_callback_logs WHERE $whereSql";
        $totalRow = $this->db->fetchOne($countSql, $bindParams);
        $total = (int)$totalRow['cnt'];

        $sql = "SELECT * FROM warehouse_callback_logs WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize";
        $list = $this->db->fetchAll($sql, $bindParams);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }
}
