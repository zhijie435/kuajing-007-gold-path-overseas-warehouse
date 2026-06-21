<?php
require_once __DIR__ . '/Database.php';

class AuditService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function generateAuditNo($prefix = 'AUD') {
        return $prefix . date('YmdHis') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function updateCallbackLogWithPermission($logId, $data) {
        $updateData = [];

        if (isset($data['order_warehouse_code'])) {
            $updateData['order_warehouse_code'] = $data['order_warehouse_code'];
        }
        if (isset($data['client_ip'])) {
            $updateData['client_ip'] = $data['client_ip'];
        }
        if (isset($data['token_verified'])) {
            $updateData['token_verified'] = $data['token_verified'] ? 1 : 0;
        }
        if (isset($data['warehouse_match_verified'])) {
            $updateData['warehouse_match_verified'] = $data['warehouse_match_verified'] ? 1 : 0;
        }
        if (isset($data['ip_verified'])) {
            $updateData['ip_verified'] = $data['ip_verified'] ? 1 : 0;
        }
        if (isset($data['permission_check_passed'])) {
            $updateData['permission_check_passed'] = $data['permission_check_passed'] ? 1 : 0;
        }
        if (isset($data['permission_details'])) {
            $updateData['permission_details'] = json_encode($data['permission_details'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['audit_no'])) {
            $updateData['audit_no'] = $data['audit_no'];
        }
        if (isset($data['duration_ms'])) {
            $updateData['duration_ms'] = (int)$data['duration_ms'];
        }

        if (empty($updateData)) {
            return 0;
        }

        $set = [];
        $params = [];
        foreach ($updateData as $field => $value) {
            $set[] = "`$field` = :set_$field";
            $params[":set_$field"] = $value;
        }
        $params[':id'] = $logId;

        $sql = sprintf('UPDATE `warehouse_callback_logs` SET %s WHERE id = :id', implode(', ', $set));
        return $this->db->query($sql, $params)->rowCount();
    }

    public function logFulfillmentCallbackEvent($data) {
        $auditNo = $data['audit_no'] ?? $this->generateAuditNo('FCB');

        $logData = [
            'trace_id' => $data['trace_id'] ?? null,
            'audit_no' => $auditNo,
            'action' => 'fulfillment_callback',
            'result' => !empty($data['success']) ? 'success' : 'failure',
            'warehouse_code' => $data['warehouse_code'] ?? null,
            'order_no' => $data['order_no'] ?? null,
            'target_type' => $data['target_type'] ?? 'ORDER',
            'target_id' => $data['order_no'] ?? null,
            'client_ip' => $data['client_ip'] ?? null,
            'request_data' => !empty($data['request_data'])
                ? json_encode($data['request_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'response_data' => !empty($data['response_data'])
                ? json_encode($data['response_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'old_data' => !empty($data['old_data'])
                ? json_encode($data['old_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'new_data' => !empty($data['new_data'])
                ? json_encode($data['new_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'permission_check_passed' => isset($data['permission_check_passed'])
                ? ($data['permission_check_passed'] ? 1 : 0)
                : 1,
            'permission_details' => !empty($data['permission_details'])
                ? json_encode($data['permission_details'], JSON_UNESCAPED_UNICODE)
                : null,
            'error_message' => $data['error_message'] ?? null,
            'duration_ms' => isset($data['duration_ms']) ? (int)$data['duration_ms'] : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $this->db->insert('fulfillment_callback_audit_logs', $logData);
        } catch (Exception $e) {
            error_log('Fulfillment callback audit log failed: ' . $e->getMessage());
        }

        return $auditNo;
    }

    public function logWarehouseRouteEvent($data) {
        $auditNo = $data['audit_no'] ?? $this->generateAuditNo('WRT');

        $logData = [
            'trace_id' => $data['trace_id'] ?? null,
            'audit_no' => $auditNo,
            'app_id' => $data['app_id'] ?? null,
            'request_items' => !empty($data['request_items'])
                ? json_encode($data['request_items'], JSON_UNESCAPED_UNICODE)
                : null,
            'shipping_country' => $data['shipping_country'] ?? null,
            'shipping_state' => $data['shipping_state'] ?? null,
            'total_weight' => $data['total_weight'] ?? null,
            'selected_warehouse_id' => $data['selected_warehouse_id'] ?? null,
            'selected_warehouse_code' => $data['selected_warehouse_code'] ?? null,
            'selected_warehouse_name' => $data['selected_warehouse_name'] ?? null,
            'shipping_cost' => $data['shipping_cost'] ?? null,
            'avg_shipping_days' => $data['avg_shipping_days'] ?? null,
            'estimated_delivery_date' => $data['estimated_delivery_date'] ?? null,
            'alternatives' => !empty($data['alternatives'])
                ? json_encode($data['alternatives'], JSON_UNESCAPED_UNICODE)
                : null,
            'route_result' => !empty($data['route_result']) ? 1 : 0,
            'error_type' => $data['error_type'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'client_ip' => $data['client_ip'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'request_at' => $data['request_at'] ?? date('Y-m-d H:i:s'),
            'response_at' => $data['response_at'] ?? date('Y-m-d H:i:s'),
            'duration_ms' => isset($data['duration_ms']) ? (int)$data['duration_ms'] : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $this->db->insert('warehouse_route_audit_logs', $logData);
        } catch (Exception $e) {
            error_log('Warehouse route audit log failed: ' . $e->getMessage());
        }

        return $auditNo;
    }

    public function logOperation($data) {
        $logData = [
            'trace_id' => $data['trace_id'] ?? null,
            'action' => ($data['module'] ?? 'SYSTEM') . ':' . ($data['action'] ?? 'UNKNOWN'),
            'result' => !empty($data['success']) ? 'success' : 'failure',
            'user_id' => $data['operator_id'] ?? null,
            'role' => $data['operator_type'] ?? null,
            'warehouse_code' => $data['warehouse_code'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'client_ip' => $data['client_ip'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'request_uri' => $data['request_uri'] ?? null,
            'request_method' => $data['request_method'] ?? null,
            'request_params' => !empty($data['request_data'])
                ? json_encode($data['request_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'response_code' => isset($data['response_code']) ? (int)$data['response_code'] : 0,
            'error_message' => $data['error_message'] ?? null,
            'extra_data' => !empty($data['response_data'])
                ? json_encode($data['response_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $this->db->insert('audit_logs', $logData);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('Operation audit log failed: ' . $e->getMessage());
            return false;
        }
    }

    public function queryRouteAudits($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['warehouse_code'])) {
            $where[] = 'selected_warehouse_code = ?';
            $bindParams[] = $params['warehouse_code'];
        }
        if (!empty($params['shipping_country'])) {
            $where[] = 'shipping_country = ?';
            $bindParams[] = $params['shipping_country'];
        }
        if (isset($params['route_result']) && $params['route_result'] !== '') {
            $where[] = 'route_result = ?';
            $bindParams[] = (int)$params['route_result'];
        }
        if (!empty($params['app_id'])) {
            $where[] = 'app_id = ?';
            $bindParams[] = $params['app_id'];
        }
        if (!empty($params['start_time'])) {
            $where[] = 'created_at >= ?';
            $bindParams[] = $params['start_time'];
        }
        if (!empty($params['end_time'])) {
            $where[] = 'created_at <= ?';
            $bindParams[] = $params['end_time'];
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) as cnt FROM warehouse_route_audit_logs WHERE $whereSql";
        $totalRow = $this->db->fetchOne($countSql, $bindParams);
        $total = (int)$totalRow['cnt'];

        $sql = "SELECT * FROM warehouse_route_audit_logs WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize";
        $list = $this->db->fetchAll($sql, $bindParams);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function queryOperationAudits($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['action'])) {
            $where[] = 'action = ?';
            $bindParams[] = $params['action'];
        }
        if (!empty($params['module'])) {
            $where[] = 'action LIKE ?';
            $bindParams[] = $params['module'] . ':%';
        }
        if (!empty($params['user_id'])) {
            $where[] = 'user_id = ?';
            $bindParams[] = $params['user_id'];
        }
        if (!empty($params['target_type'])) {
            $where[] = 'target_type = ?';
            $bindParams[] = $params['target_type'];
        }
        if (!empty($params['target_id'])) {
            $where[] = 'target_id = ?';
            $bindParams[] = $params['target_id'];
        }
        if (isset($params['result']) && $params['result'] !== '') {
            $where[] = 'result = ?';
            $bindParams[] = $params['result'];
        }
        if (!empty($params['start_time'])) {
            $where[] = 'created_at >= ?';
            $bindParams[] = $params['start_time'];
        }
        if (!empty($params['end_time'])) {
            $where[] = 'created_at <= ?';
            $bindParams[] = $params['end_time'];
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) as cnt FROM audit_logs WHERE $whereSql";
        $totalRow = $this->db->fetchOne($countSql, $bindParams);
        $total = (int)$totalRow['cnt'];

        $sql = "SELECT * FROM audit_logs WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize";
        $list = $this->db->fetchAll($sql, $bindParams);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }
}
