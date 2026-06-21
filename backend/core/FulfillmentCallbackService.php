<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/AuditService.php';

class FulfillmentCallbackService {
    private $db;
    private $config;
    private $permissionService;
    private $auditService;
    private $startTime;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/config.php';
        $this->permissionService = new PermissionService();
        $this->auditService = new AuditService();
        $this->startTime = microtime(true);
    }

    private function getDurationMs() {
        return (int)round((microtime(true) - $this->startTime) * 1000);
    }

    /**
     * 验证回调Token（兼容旧接口，保留向后兼容）
     */
    public function validateToken($token) {
        return $token === $this->config['callback']['token'];
    }

    /**
     * 执行完整的权限边界校验：Token + IP白名单 + 仓库订单一致性
     */
    public function validateCallbackPermission($warehouseCode, $orderNo, $token, &$permissionDetails = []) {
        $result = [
            'success' => false,
            'token_verified' => false,
            'warehouse_found' => false,
            'warehouse_status_ok' => false,
            'ip_verified' => true,
            'warehouse_match_verified' => false,
            'order_warehouse_code' => null,
            'error_message' => '',
            'error_code' => '',
        ];

        $tokenDetails = [];
        $tokenVerify = $this->permissionService->verifyFulfillmentCallbackToken($warehouseCode, $token, $tokenDetails);
        $permissionDetails['token_verify'] = $tokenDetails;

        $result['token_verified'] = $tokenVerify['token_verified'];
        $result['warehouse_found'] = $tokenVerify['warehouse_found'];
        $result['warehouse_status_ok'] = $tokenVerify['warehouse_status_ok'];
        $result['ip_verified'] = $tokenVerify['ip_verified'];

        if (!$tokenVerify['success']) {
            $result['error_message'] = $tokenVerify['error_message'];
            $result['error_code'] = 'TOKEN_OR_IP_VERIFY_FAILED';
            return $result;
        }

        if (!empty($orderNo)) {
            $matchDetails = [];
            $matchVerify = $this->permissionService->verifyWarehouseOrderMatch($warehouseCode, $orderNo, $matchDetails);
            $permissionDetails['warehouse_order_match'] = $matchDetails;

            $result['warehouse_match_verified'] = $matchVerify['warehouse_matched'];
            $result['order_warehouse_code'] = $matchVerify['order_warehouse_code'];

            if (!$matchVerify['success']) {
                $result['error_message'] = $matchVerify['error_message'];
                $result['error_code'] = 'WAREHOUSE_ORDER_MISMATCH';
                return $result;
            }
        } else {
            $result['warehouse_match_verified'] = true;
            $permissionDetails['warehouse_order_match'] = ['note' => '订单号为空，跳过仓库一致性校验'];
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * 统一回调处理入口
     */
    public function handle($callbackType, $data, $rawBody = '', $token = '') {
        $warehouseCode = $data['warehouse_code'] ?? '';
        $orderNo = $data['order_no'] ?? '';

        $permissionDetails = [];
        $permissionCheck = $this->validateCallbackPermission($warehouseCode, $orderNo, $token, $permissionDetails);

        $logId = $this->logCallback($callbackType, $data, $rawBody);
        $this->auditService->updateCallbackLogWithPermission($logId, [
            'order_warehouse_code' => $permissionCheck['order_warehouse_code'],
            'client_ip' => PermissionService::getClientIp(),
            'token_verified' => $permissionCheck['token_verified'],
            'warehouse_match_verified' => $permissionCheck['warehouse_match_verified'],
            'ip_verified' => $permissionCheck['ip_verified'],
            'permission_check_passed' => $permissionCheck['success'],
            'permission_details' => $permissionDetails,
        ]);

        $auditNo = $this->auditService->logFulfillmentCallbackEvent([
            'warehouse_code' => $warehouseCode,
            'order_no' => $orderNo,
            'request_data' => $data,
            'success' => false,
            'permission_check_passed' => $permissionCheck['success'],
            'permission_details' => $permissionDetails,
            'error_message' => $permissionCheck['success'] ? null : $permissionCheck['error_message'],
            'client_ip' => PermissionService::getClientIp(),
        ]);

        $this->auditService->updateCallbackLogWithPermission($logId, ['audit_no' => $auditNo]);

        if (!$permissionCheck['success']) {
            $permissionError = [
                'success' => false,
                'message' => '[权限拦截] ' . $permissionCheck['error_message'],
                'error_code' => $permissionCheck['error_code'] ?? 'PERMISSION_DENIED',
                'permission_denied' => true,
            ];
            $this->updateCallbackLog($logId, $permissionError, $permissionCheck['error_message']);
            $this->auditService->logFulfillmentCallbackEvent([
                'audit_no' => $auditNo,
                'warehouse_code' => $warehouseCode,
                'order_no' => $orderNo,
                'request_data' => $data,
                'response_data' => $permissionError,
                'success' => false,
                'permission_check_passed' => false,
                'permission_details' => $permissionDetails,
                'error_message' => $permissionCheck['error_message'],
                'duration_ms' => $this->getDurationMs(),
                'client_ip' => PermissionService::getClientIp(),
            ]);
            return $permissionError;
        }

        try {
            $oldOrder = null;
            if (!empty($orderNo)) {
                $oldOrder = $this->getOrder($orderNo);
            }

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

            $newOrder = null;
            if (!empty($orderNo) && $result['success']) {
                $newOrder = $this->getOrder($orderNo);
            }

            $this->updateCallbackLog($logId, $result);

            $this->auditService->updateCallbackLogWithPermission($logId, [
                'duration_ms' => $this->getDurationMs(),
            ]);

            $this->auditService->logFulfillmentCallbackEvent([
                'audit_no' => $auditNo,
                'warehouse_code' => $warehouseCode,
                'order_no' => $orderNo,
                'target_type' => 'ORDER',
                'target_id' => $orderNo,
                'request_data' => $data,
                'response_data' => $result,
                'old_data' => $oldOrder,
                'new_data' => $newOrder,
                'success' => $result['success'],
                'error_message' => $result['success'] ? null : ($result['message'] ?? null),
                'permission_check_passed' => true,
                'permission_details' => $permissionDetails,
                'duration_ms' => $this->getDurationMs(),
                'client_ip' => PermissionService::getClientIp(),
            ]);

            return $result;

        } catch (Exception $e) {
            $error = ['success' => false, 'message' => '回调处理异常: ' . $e->getMessage()];
            $this->updateCallbackLog($logId, $error, $e->getMessage());
            $this->auditService->logFulfillmentCallbackEvent([
                'audit_no' => $auditNo,
                'warehouse_code' => $warehouseCode,
                'order_no' => $orderNo,
                'target_type' => 'ORDER',
                'target_id' => $orderNo,
                'request_data' => $data,
                'response_data' => $error,
                'success' => false,
                'error_message' => $e->getMessage(),
                'permission_check_passed' => true,
                'permission_details' => $permissionDetails,
                'duration_ms' => $this->getDurationMs(),
                'client_ip' => PermissionService::getClientIp(),
            ]);
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

        if (!empty($params['warehouse_code'])) {
            $where[] = 'warehouse_code = ?';
            $bindParams[] = $params['warehouse_code'];
        }
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
        if (!empty($params['warehouse_code'])) {
            $where[] = 'warehouse_code = ?';
            $bindParams[] = $params['warehouse_code'];
        }
        if (isset($params['permission_check_passed']) && $params['permission_check_passed'] !== '') {
            $where[] = 'permission_check_passed = ?';
            $bindParams[] = (int)$params['permission_check_passed'];
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
