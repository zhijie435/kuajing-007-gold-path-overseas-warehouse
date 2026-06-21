<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Request.php';

class AuditLogger {
    const ACTION_WAREHOUSE_ROUTE = 'warehouse_route';
    const ACTION_WAREHOUSE_LIST = 'warehouse_list';
    const ACTION_WAREHOUSE_INVENTORY = 'warehouse_inventory';
    const ACTION_FULFILLMENT_CALLBACK = 'fulfillment_callback';
    const ACTION_FULFILLMENT_CALLBACK_LOGS = 'fulfillment_callback_logs';
    const ACTION_PERMISSION_DENIED = 'permission_denied';

    const RESULT_SUCCESS = 'success';
    const RESULT_FAILURE = 'failure';

    private $db;
    private $traceId;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->traceId = $this->generateTraceId();
    }

    private function generateTraceId() {
        return 'AUD' . date('YmdHis') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function getTraceId() {
        return $this->traceId;
    }

    private function getClientIp() {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip ?: '0.0.0.0';
    }

    public function log($action, $result, $context = []) {
        $data = [
            'trace_id' => $this->traceId,
            'action' => $action,
            'result' => $result,
            'user_id' => $context['user_id'] ?? 'anonymous',
            'role' => $context['role'] ?? 'unknown',
            'warehouse_code' => $context['warehouse_code'] ?? null,
            'target_type' => $context['target_type'] ?? null,
            'target_id' => $context['target_id'] ?? null,
            'client_ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'request_params' => !empty($context['request_params'])
                ? json_encode($context['request_params'], JSON_UNESCAPED_UNICODE)
                : null,
            'response_code' => $context['response_code'] ?? 0,
            'error_message' => $context['error_message'] ?? null,
            'extra_data' => !empty($context['extra_data'])
                ? json_encode($context['extra_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            return $this->db->insert('audit_logs', $data);
        } catch (Exception $e) {
            error_log('Audit log failed: ' . $e->getMessage());
            return false;
        }
    }

    public function logPermissionDenied($permission, $reason, $context = []) {
        return $this->log(
            self::ACTION_PERMISSION_DENIED,
            self::RESULT_FAILURE,
            array_merge($context, [
                'target_type' => 'permission',
                'target_id' => $permission,
                'response_code' => 403,
                'error_message' => "Permission denied: {$permission}, reason: {$reason}",
                'extra_data' => [
                    'permission' => $permission,
                    'reason' => $reason,
                ],
            ])
        );
    }

    public function logWarehouseAccessDenied($currentWarehouse, $targetWarehouse, $reason, $context = []) {
        return $this->log(
            self::ACTION_PERMISSION_DENIED,
            self::RESULT_FAILURE,
            array_merge($context, [
                'target_type' => 'warehouse',
                'target_id' => $targetWarehouse,
                'warehouse_code' => $currentWarehouse,
                'response_code' => 403,
                'error_message' => "Warehouse access denied: target={$targetWarehouse}, current={$currentWarehouse}, reason={$reason}",
                'extra_data' => [
                    'current_warehouse' => $currentWarehouse,
                    'target_warehouse' => $targetWarehouse,
                    'reason' => $reason,
                ],
            ])
        );
    }

    public function listAuditLogs($params = []) {
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $pageSize = isset($params['page_size']) ? min(100, max(1, (int)$params['page_size'])) : 20;
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $bindParams = [];

        if (!empty($params['action'])) {
            $where[] = 'action = ?';
            $bindParams[] = $params['action'];
        }
        if (!empty($params['user_id'])) {
            $where[] = 'user_id = ?';
            $bindParams[] = $params['user_id'];
        }
        if (!empty($params['warehouse_code'])) {
            $where[] = 'warehouse_code = ?';
            $bindParams[] = $params['warehouse_code'];
        }
        if (isset($params['result']) && $params['result'] !== '') {
            $where[] = 'result = ?';
            $bindParams[] = $params['result'];
        }
        if (!empty($params['target_type'])) {
            $where[] = 'target_type = ?';
            $bindParams[] = $params['target_type'];
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
