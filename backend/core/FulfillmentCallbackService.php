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
    private $auditContext;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/config.php';
        $this->permissionService = new PermissionService();
        $this->auditService = new AuditService();
        $this->auditContext = [
            'start_time' => microtime(true),
            'client_ip' => PermissionService::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
    }

    public function setAuditContext($context) {
        $this->auditContext = array_merge($this->auditContext, $context);
    }

    private function getDurationMs() {
        return (int)round((microtime(true) - $this->auditContext['start_time']) * 1000);
    }

    /**
     * 验证回调Token - 旧接口兼容，内部委托给 PermissionService
     */
    public function validateToken($token) {
        return $token === $this->config['callback']['token'];
    }

    /**
     * 完整的权限边界校验：仓库Token + IP白名单 + 订单仓库一致性
     */
    public function validateCallbackPermission($warehouseCode, $token, $orderNo = null, &$details = []) {
        $result = [
            'allowed' => false,
            'token_verified' => false,
            'warehouse_found' => false,
            'warehouse_status_ok' => false,
            'ip_verified' => false,
            'order_found' => false,
            'warehouse_matched' => false,
            'order_warehouse_code' => null,
            'error_message' => '',
            'error_code' => null,
        ];

        $tokenDetails = [];
        $tokenResult = $this->permissionService->verifyFulfillmentCallbackToken($warehouseCode, $token, $tokenDetails);
        $details['token_check'] = $tokenDetails;

        $result['token_verified'] = $tokenResult['token_verified'];
        $result['warehouse_found'] = $tokenResult['warehouse_found'];
        $result['warehouse_status_ok'] = $tokenResult['warehouse_status_ok'];
        $result['ip_verified'] = $tokenResult['ip_verified'];

        if (!$tokenResult['success']) {
            $result['error_message'] = $tokenResult['error_message'];
            if (!$tokenResult['warehouse_found']) {
                $result['error_code'] = 'WAREHOUSE_NOT_FOUND';
            } elseif (!$tokenResult['warehouse_status_ok']) {
                $result['error_code'] = 'WAREHOUSE_DISABLED';
            } elseif (!$tokenResult['token_verified']) {
                $result['error_code'] = 'INVALID_CALLBACK_TOKEN';
            } elseif (!$tokenResult['ip_verified']) {
                $result['error_code'] = 'IP_NOT_ALLOWED';
            }
            return $result;
        }

        if (!empty($orderNo)) {
            $matchDetails = [];
            $matchResult = $this->permissionService->verifyWarehouseOrderMatch($warehouseCode, $orderNo, $matchDetails);
            $details['order_match_check'] = $matchDetails;

            $result['order_found'] = $matchResult['order_found'];
            $result['warehouse_matched'] = $matchResult['warehouse_matched'];
            $result['order_warehouse_code'] = $matchResult['order_warehouse_code'];

            if (!$matchResult['success']) {
                $result['error_message'] = $matchResult['error_message'];
                $result['error_code'] = $matchResult['warehouse_matched'] ? null : 'WAREHOUSE_MISMATCH';
                return $result;
            }
        }

        $result['allowed'] = true;
        $result['error_message'] = '权限校验通过';
        return $result;
    }

    /**
     * 统一回调处理入口
     */
    public function handle($callbackType, $data, $rawBody = '') {
        $permissionDetails = [];
        $permissionPassed = true;
        $warehouseCode = $data['warehouse_code'] ?? null;
        $orderNo = $data['order_no'] ?? null;

        $logId = $this->logCallback($callbackType, $data, $rawBody);

        try {
            $orderWarehouseCode = null;
            if (!empty($orderNo)) {
                $order = $this->getOrder($orderNo);
                $orderWarehouseCode = $order['warehouse_code'] ?? null;
            }

            $auditNo = $this->auditService->logFulfillmentCallbackEvent([
                'warehouse_code' => $warehouseCode,
                'order_no' => $orderNo,
                'request_data' => $data,
                'success' => true,
                'permission_check_passed' => $permissionPassed,
                'permission_details' => $permissionDetails,
                'duration_ms' => 0,
                'client_ip' => $this->auditContext['client_ip'],
            ]);

            $oldOrderData = null;
            if (!empty($orderNo)) {
                $oldOrderData = $this->getOrder($orderNo);
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
                    $result = ['success' => false, 'message' => "未知回调类型: {$callbackType}", 'error_type' => 'UNKNOWN_CALLBACK_TYPE'];
            }

            $newOrderData = null;
            if (!empty($orderNo) && !empty($result['success'])) {
                $newOrderData = $this->getOrder($orderNo);
            }

            $this->auditService->logFulfillmentCallbackEvent([
                'audit_no' => $auditNo,
                'warehouse_code' => $warehouseCode,
                'order_no' => $orderNo,
                'request_data' => $data,
                'response_data' => $result,
                'old_data' => $oldOrderData,
                'new_data' => $newOrderData,
                'success' => $result['success'] ?? false,
                'error_message' => $result['message'] ?? null,
                'permission_check_passed' => $permissionPassed,
                'permission_details' => $permissionDetails,
                'duration_ms' => $this->getDurationMs(),
                'client_ip' => $this->auditContext['client_ip'],
            ]);

            $this->updateCallbackLog($logId, $result);
            $this->auditService->updateCallbackLogWithPermission($logId, [
                'audit_no' => $auditNo,
                'order_warehouse_code' => $orderWarehouseCode,
                'client_ip' => $this->auditContext['client_ip'],
                'token_verified' => $permissionDetails['token_check']['token_verified'] ?? null,
                'warehouse_match_verified' => $permissionDetails['order_match_check'][$orderNo]['match'] ?? true,
                'ip_verified' => $permissionDetails['token_check']['ip_verified'] ?? null,
                'permission_check_passed' => (int)$permissionPassed,
                'permission_details' => $permissionDetails,
                'duration_ms' => $this->getDurationMs(),
            ]);

            return $result;

        } catch (Exception $e) {
            $error = ['success' => false, 'message' => '回调处理异常: ' . $e->getMessage(), 'error_type' => 'SYSTEM_EXCEPTION'];

            $this->auditService->logFulfillmentCallbackEvent([
                'warehouse_code' => $warehouseCode,
                'order_no' => $orderNo,
                'request_data' => $data,
                'response_data' => $error,
                'success' => false,
                'error_message' => $e->getMessage(),
                'permission_check_passed' => $permissionPassed,
                'permission_details' => $permissionDetails,
                'duration_ms' => $this->getDurationMs(),
                'client_ip' => $this->auditContext['client_ip'],
            ]);

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
            return ['success' => false, 'message' => '订单不存在', 'error_type' => 'ORDER_NOT_FOUND'];
        }

        if ($order['order_status'] >= 3) {
            return ['success' => true, 'message' => '订单状态已更新，跳过重复回调', 'skipped' => true, 'error_type' => 'DUPLICATE_CALLBACK'];
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
            return ['success' => true, 'message' => '订单已更新为仓库接单', 'old_status' => $order['order_status'], 'new_status' => 3];
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
            return ['success' => false, 'message' => '订单不存在', 'error_type' => 'ORDER_NOT_FOUND'];
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
            return ['success' => true, 'message' => '已更新为拣货中', 'old_fulfillment_status' => $order['fulfillment_status'], 'new_fulfillment_status' => 1];
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
            return ['success' => false, 'message' => '订单不存在', 'error_type' => 'ORDER_NOT_FOUND'];
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
            return ['success' => true, 'message' => '已更新为打包中', 'old_fulfillment_status' => $order['fulfillment_status'], 'new_fulfillment_status' => 2];
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
            return ['success' => false, 'message' => '订单不存在', 'error_type' => 'ORDER_NOT_FOUND'];
        }

        if ($order['order_status'] >= 5) {
            return ['success' => true, 'message' => '订单已发货，跳过重复回调', 'skipped' => true, 'error_type' => 'DUPLICATE_CALLBACK'];
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
            return [
                'success' => true,
                'message' => '订单已更新为已发货',
                'old_order_status' => $order['order_status'],
                'new_order_status' => 5,
                'old_fulfillment_status' => $order['fulfillment_status'],
                'new_fulfillment_status' => 3,
                'items_count' => count($items),
            ];
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
            return ['success' => false, 'message' => '订单不存在', 'error_type' => 'ORDER_NOT_FOUND'];
        }

        if ($order['order_status'] >= 6) {
            return ['success' => true, 'message' => '订单已签收，跳过重复回调', 'skipped' => true, 'error_type' => 'DUPLICATE_CALLBACK'];
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
            return [
                'success' => true,
                'message' => '订单已更新为已签收',
                'old_order_status' => $order['order_status'],
                'new_order_status' => 6,
                'old_fulfillment_status' => $order['fulfillment_status'],
                'new_fulfillment_status' => 4,
            ];
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
            return ['success' => false, 'message' => '订单不存在', 'error_type' => 'ORDER_NOT_FOUND'];
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
            return [
                'success' => true,
                'message' => '异常已记录',
                'old_fulfillment_status' => $order['fulfillment_status'],
                'new_fulfillment_status' => 9,
                'exception_type' => $data['exception_type'],
            ];
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
            'extra_data' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
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
            'client_ip' => $this->auditContext['client_ip'],
            'user_agent' => $this->auditContext['user_agent'],
            'auth_method' => 'CALLBACK_TOKEN',
            'auth_result' => 1,
        ]);
    }

    private function updateCallbackLog($logId, $result, $errorMessage = null) {
        $this->db->update(
            'warehouse_callback_logs',
            [
                'is_processed' => !empty($result['success']) ? 1 : 0,
                'response_body' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'error_message' => $errorMessage ?? (empty($result['success']) ? ($result['message'] ?? null) : null),
                'auth_result' => !empty($result['success']) ? 1 : 0,
            ],
            'id = ?',
            [$logId]
        );
    }

    private function validateOrderWarehouse($orderNo, $warehouseCode) {
        $order = $this->getOrder($orderNo);
        if (!$order) {
            return [
                'allowed' => true,
                'message' => '订单不存在，跳过仓库一致性校验',
                'skipped' => true,
            ];
        }

        if (empty($order['warehouse_code'])) {
            return [
                'allowed' => true,
                'message' => '订单未分配仓库，允许任意仓库接单',
            ];
        }

        if ($order['warehouse_code'] !== $warehouseCode) {
            return [
                'success' => false,
                'allowed' => false,
                'message' => "仓库 [{$warehouseCode}] 无权处理订单 [{$orderNo}]，该订单所属仓库为 [{$order['warehouse_code']}]",
                'error_type' => 'WAREHOUSE_MISMATCH',
                'order_warehouse' => $order['warehouse_code'],
                'callback_warehouse' => $warehouseCode,
            ];
        }

        return [
            'allowed' => true,
            'message' => '订单仓库一致性校验通过',
        ];
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
        if (!empty($params['warehouse_code'])) {
            $where[] = 'warehouse_code = ?';
            $bindParams[] = $params['warehouse_code'];
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
