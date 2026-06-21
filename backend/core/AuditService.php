<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/PermissionService.php';

class AuditService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function logOperation($params) {
        $auditNo = PermissionService::generateAuditNo('OP');
        $requestId = $params['request_id'] ?? (Request::getHeader('X-Request-ID') ?: ($_SERVER['HTTP_X_REQUEST_ID'] ?? null));

        $data = [
            'audit_no' => $auditNo,
            'module' => $params['module'] ?? 'SYSTEM',
            'action' => $params['action'] ?? 'UNKNOWN',
            'operator_type' => $params['operator_type'] ?? 'SYSTEM',
            'operator_id' => $params['operator_id'] ?? null,
            'operator_name' => $params['operator_name'] ?? null,
            'client_ip' => $params['client_ip'] ?? PermissionService::getClientIp(),
            'target_type' => $params['target_type'] ?? null,
            'target_id' => $params['target_id'] ?? null,
            'request_data_json' => isset($params['request_data'])
                ? (is_string($params['request_data']) ? $params['request_data'] : json_encode($params['request_data'], JSON_UNESCAPED_UNICODE))
                : null,
            'response_data_json' => isset($params['response_data'])
                ? (is_string($params['response_data']) ? $params['response_data'] : json_encode($params['response_data'], JSON_UNESCAPED_UNICODE))
                : null,
            'old_data_json' => isset($params['old_data'])
                ? (is_string($params['old_data']) ? $params['old_data'] : json_encode($params['old_data'], JSON_UNESCAPED_UNICODE))
                : null,
            'new_data_json' => isset($params['new_data'])
                ? (is_string($params['new_data']) ? $params['new_data'] : json_encode($params['new_data'], JSON_UNESCAPED_UNICODE))
                : null,
            'success' => isset($params['success']) ? (int)$params['success'] : 1,
            'error_message' => $params['error_message'] ?? null,
            'permission_check_passed' => isset($params['permission_check_passed']) ? (int)$params['permission_check_passed'] : 1,
            'permission_details_json' => isset($params['permission_details'])
                ? (is_string($params['permission_details']) ? $params['permission_details'] : json_encode($params['permission_details'], JSON_UNESCAPED_UNICODE))
                : null,
            'duration_ms' => isset($params['duration_ms']) ? (int)$params['duration_ms'] : null,
            'request_id' => $requestId,
        ];

        try {
            $this->db->insert('operation_audits', $data);
        } catch (Exception $e) {
            error_log('AuditService logOperation failed: ' . $e->getMessage());
        }

        return $auditNo;
    }

    public function logWarehouseRoute($params) {
        $auditNo = PermissionService::generateAuditNo('RT');
        $requestId = $params['request_id'] ?? (Request::getHeader('X-Request-ID') ?: ($_SERVER['HTTP_X_REQUEST_ID'] ?? null));

        $selectedWarehouse = $params['selected_warehouse'] ?? [];

        $data = [
            'audit_no' => $auditNo,
            'client_key' => $params['client_key'] ?? null,
            'client_ip' => $params['client_ip'] ?? PermissionService::getClientIp(),
            'request_id' => $requestId,
            'items_json' => isset($params['items'])
                ? (is_string($params['items']) ? $params['items'] : json_encode($params['items'], JSON_UNESCAPED_UNICODE))
                : null,
            'shipping_country' => $params['shipping_country'] ?? null,
            'shipping_state' => $params['shipping_state'] ?? null,
            'success' => isset($params['success']) ? (int)$params['success'] : 0,
            'error_type' => $params['error_type'] ?? null,
            'error_message' => $params['error_message'] ?? null,
            'selected_warehouse_id' => $selectedWarehouse['warehouse_id'] ?? null,
            'selected_warehouse_code' => $selectedWarehouse['warehouse_code'] ?? null,
            'selected_warehouse_name' => $selectedWarehouse['warehouse_name'] ?? null,
            'shipping_cost' => $selectedWarehouse['shipping_cost'] ?? null,
            'estimated_delivery_date' => $selectedWarehouse['estimated_delivery_date'] ?? null,
            'alternatives_json' => isset($params['alternatives'])
                ? (is_string($params['alternatives']) ? $params['alternatives'] : json_encode($params['alternatives'], JSON_UNESCAPED_UNICODE))
                : null,
            'score_details_json' => isset($params['score_details'])
                ? (is_string($params['score_details']) ? $params['score_details'] : json_encode($params['score_details'], JSON_UNESCAPED_UNICODE))
                : null,
            'duration_ms' => isset($params['duration_ms']) ? (int)$params['duration_ms'] : null,
        ];

        try {
            $this->db->insert('warehouse_route_audits', $data);
        } catch (Exception $e) {
            error_log('AuditService logWarehouseRoute failed: ' . $e->getMessage());
        }

        $this->logOperation([
            'module' => 'WAREHOUSE_ROUTE',
            'action' => 'ROUTE',
            'operator_type' => !empty($params['client_key']) ? 'API_CLIENT' : 'SYSTEM',
            'operator_id' => $params['client_key'] ?? null,
            'target_type' => 'WAREHOUSE',
            'target_id' => $selectedWarehouse['warehouse_id'] ?? null,
            'request_data' => [
                'items' => $params['items'] ?? [],
                'shipping_country' => $params['shipping_country'] ?? null,
                'shipping_state' => $params['shipping_state'] ?? null,
            ],
            'response_data' => [
                'success' => $params['success'] ?? false,
                'selected_warehouse' => $selectedWarehouse,
                'error_type' => $params['error_type'] ?? null,
                'error_message' => $params['error_message'] ?? null,
            ],
            'success' => $params['success'] ?? false,
            'error_message' => $params['error_message'] ?? null,
            'duration_ms' => $params['duration_ms'] ?? null,
            'request_id' => $requestId,
            'client_ip' => $params['client_ip'] ?? null,
        ]);

        return $auditNo;
    }

    public function logFulfillmentCallbackEvent($params) {
        $auditNo = PermissionService::generateAuditNo('FC');
        $requestId = $params['request_id'] ?? (Request::getHeader('X-Request-ID') ?: ($_SERVER['HTTP_X_REQUEST_ID'] ?? null));

        $this->logOperation([
            'audit_no' => $auditNo,
            'module' => 'FULFILLMENT_CALLBACK',
            'action' => 'CALLBACK',
            'operator_type' => 'WAREHOUSE',
            'operator_id' => $params['warehouse_code'] ?? null,
            'operator_name' => $params['warehouse_code'] ?? null,
            'target_type' => 'ORDER',
            'target_id' => $params['order_no'] ?? null,
            'request_data' => $params['request_data'] ?? null,
            'response_data' => $params['response_data'] ?? null,
            'old_data' => $params['old_data'] ?? null,
            'new_data' => $params['new_data'] ?? null,
            'success' => $params['success'] ?? true,
            'error_message' => $params['error_message'] ?? null,
            'permission_check_passed' => $params['permission_check_passed'] ?? true,
            'permission_details' => $params['permission_details'] ?? null,
            'duration_ms' => $params['duration_ms'] ?? null,
            'request_id' => $requestId,
            'client_ip' => $params['client_ip'] ?? null,
        ]);

        return $auditNo;
    }

    public function updateCallbackLogWithPermission($logId, $params) {
        $updateData = [];

        if (isset($params['audit_no'])) {
            $updateData['audit_no'] = $params['audit_no'];
        }
        if (isset($params['order_warehouse_code'])) {
            $updateData['order_warehouse_code'] = $params['order_warehouse_code'];
        }
        if (isset($params['client_ip'])) {
            $updateData['client_ip'] = $params['client_ip'];
        }
        if (isset($params['token_verified'])) {
            $updateData['token_verified'] = (int)$params['token_verified'];
        }
        if (isset($params['warehouse_match_verified'])) {
            $updateData['warehouse_match_verified'] = (int)$params['warehouse_match_verified'];
        }
        if (isset($params['ip_verified'])) {
            $updateData['ip_verified'] = (int)$params['ip_verified'];
        }
        if (isset($params['permission_check_passed'])) {
            $updateData['permission_check_passed'] = (int)$params['permission_check_passed'];
        }
        if (isset($params['permission_details'])) {
            $updateData['permission_details_json'] = is_string($params['permission_details'])
                ? $params['permission_details']
                : json_encode($params['permission_details'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($params['duration_ms'])) {
            $updateData['duration_ms'] = (int)$params['duration_ms'];
        }

        if (!empty($updateData)) {
            try {
                $this->db->update(
                    'warehouse_callback_logs',
                    $updateData,
                    'id = ?',
                    [$logId]
                );
            } catch (Exception $e) {
                error_log('AuditService updateCallbackLogWithPermission failed: ' . $e->getMessage());
            }
        }
    }

    public function queryRouteAudits($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['audit_no'])) {
            $where[] = 'audit_no = ?';
            $bindParams[] = $params['audit_no'];
        }
        if (!empty($params['client_key'])) {
            $where[] = 'client_key = ?';
            $bindParams[] = $params['client_key'];
        }
        if (!empty($params['selected_warehouse_code'])) {
            $where[] = 'selected_warehouse_code = ?';
            $bindParams[] = $params['selected_warehouse_code'];
        }
        if (isset($params['success']) && $params['success'] !== '') {
            $where[] = 'success = ?';
            $bindParams[] = (int)$params['success'];
        }
        if (!empty($params['shipping_country'])) {
            $where[] = 'shipping_country = ?';
            $bindParams[] = $params['shipping_country'];
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
        $countSql = "SELECT COUNT(*) as cnt FROM warehouse_route_audits WHERE $whereSql";
        $totalRow = $this->db->fetchOne($countSql, $bindParams);
        $total = (int)$totalRow['cnt'];

        $sql = "SELECT * FROM warehouse_route_audits WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize";
        $list = $this->db->fetchAll($sql, $bindParams);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize),
        ];
    }

    public function queryOperationAudits($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['module'])) {
            $where[] = 'module = ?';
            $bindParams[] = $params['module'];
        }
        if (!empty($params['action'])) {
            $where[] = 'action = ?';
            $bindParams[] = $params['action'];
        }
        if (!empty($params['operator_type'])) {
            $where[] = 'operator_type = ?';
            $bindParams[] = $params['operator_type'];
        }
        if (!empty($params['operator_id'])) {
            $where[] = 'operator_id = ?';
            $bindParams[] = $params['operator_id'];
        }
        if (!empty($params['target_type'])) {
            $where[] = 'target_type = ?';
            $bindParams[] = $params['target_type'];
        }
        if (!empty($params['target_id'])) {
            $where[] = 'target_id = ?';
            $bindParams[] = $params['target_id'];
        }
        if (isset($params['success']) && $params['success'] !== '') {
            $where[] = 'success = ?';
            $bindParams[] = (int)$params['success'];
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
        $countSql = "SELECT COUNT(*) as cnt FROM operation_audits WHERE $whereSql";
        $totalRow = $this->db->fetchOne($countSql, $bindParams);
        $total = (int)$totalRow['cnt'];

        $sql = "SELECT * FROM operation_audits WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize";
        $list = $this->db->fetchAll($sql, $bindParams);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize),
        ];
    }
}
