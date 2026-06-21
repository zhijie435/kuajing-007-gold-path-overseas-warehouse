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
}
