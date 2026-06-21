<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Callback-Token, X-Role, X-Warehouse-Code, X-User-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/FulfillmentCallbackService.php';
require_once __DIR__ . '/../core/PermissionGuard.php';
require_once __DIR__ . '/../core/AuditLogger.php';

$guard = new PermissionGuard();
$audit = new AuditLogger();
header('X-Trace-Id: ' . $audit->getTraceId());

try {
    $method = Request::getMethod();
    $uri = Request::getUri();

    if ($method === 'POST' && strpos($uri, '/api/fulfillment/callback') !== false) {
        $permCheck = $guard->check('fulfillment_callback');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('fulfillment_callback', $permCheck['reason'] ?? 'unknown', [
                'user_id' => $guard->getCurrentUserId(),
                'role' => $permCheck['role'],
            ]);
            Response::unauthorized($permCheck['message']);
        }

        $token = Request::getHeader('X-Callback-Token') ?: ($_GET['token'] ?? '');
        $service = new FulfillmentCallbackService();

        if (!$service->validateToken($token)) {
            $audit->log(AuditLogger::ACTION_FULFILLMENT_CALLBACK, AuditLogger::RESULT_FAILURE, [
                'user_id' => $guard->getCurrentUserId(),
                'role' => $permCheck['role'],
                'target_type' => 'fulfillment_callback',
                'response_code' => 401,
                'error_message' => '回调Token验证失败',
                'extra_data' => ['reason' => 'invalid_callback_token'],
            ]);
            Response::unauthorized('回调Token验证失败');
        }

        $rawBody = file_get_contents('php://input');
        $input = Request::getInput();
        $callbackType = $input['callback_type'] ?? '';

        if (empty($callbackType)) {
            preg_match('/\/api\/fulfillment\/callback\/([a-zA-Z_]+)/', $uri, $matches);
            $callbackType = $matches[1] ?? '';
        }

        if (empty($callbackType)) {
            Response::error('缺少回调类型 callback_type');
        }

        $callbackWarehouseCode = $input['warehouse_code'] ?? null;
        $warehouseCheck = $guard->validateCallbackWarehouse($callbackWarehouseCode);
        if (!$warehouseCheck['allowed']) {
            $audit->logWarehouseAccessDenied(
                $guard->getCurrentWarehouseCode(),
                $callbackWarehouseCode,
                $warehouseCheck['reason'] ?? 'unknown',
                [
                    'user_id' => $guard->getCurrentUserId(),
                    'role' => $permCheck['role'],
                    'target_type' => 'fulfillment_callback',
                    'extra_data' => ['callback_type' => $callbackType],
                ]
            );
            Response::unauthorized($warehouseCheck['message']);
        }

        $result = $service->handle($callbackType, $input, $rawBody);

        $audit->log(AuditLogger::ACTION_FULFILLMENT_CALLBACK,
            $result['success'] ? AuditLogger::RESULT_SUCCESS : AuditLogger::RESULT_FAILURE,
            [
                'user_id' => $guard->getCurrentUserId(),
                'role' => $permCheck['role'],
                'warehouse_code' => $callbackWarehouseCode,
                'target_type' => 'fulfillment_callback',
                'target_id' => $input['order_no'] ?? null,
                'request_params' => $input,
                'response_code' => $result['success'] ? 200 : 400,
                'error_message' => $result['message'] ?? null,
                'extra_data' => [
                    'callback_type' => $callbackType,
                    'warehouse_order_no' => $input['warehouse_order_no'] ?? null,
                    'skipped' => $result['skipped'] ?? false,
                ],
            ]
        );

        if ($result['success']) {
            Response::success($result, $result['message'] ?? '回调处理成功');
        } else {
            Response::error($result['message']);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/fulfillment/callback/logs') !== false) {
        $permCheck = $guard->check('fulfillment_callback_logs');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('fulfillment_callback_logs', $permCheck['reason'] ?? 'unknown', [
                'user_id' => $guard->getCurrentUserId(),
                'role' => $permCheck['role'],
            ]);
            Response::unauthorized($permCheck['message']);
        }

        $params = $_GET;

        $currentWarehouse = $guard->getCurrentWarehouseCode();
        $role = $guard->getCurrentRole();
        if ($role === 'warehouse_operator' && !empty($currentWarehouse)) {
            $params['warehouse_code'] = $currentWarehouse;
        }

        $service = new FulfillmentCallbackService();
        $result = $service->listCallbackLogs($params);

        $audit->log(AuditLogger::ACTION_FULFILLMENT_CALLBACK_LOGS, AuditLogger::RESULT_SUCCESS, [
            'user_id' => $guard->getCurrentUserId(),
            'role' => $permCheck['role'],
            'warehouse_code' => $currentWarehouse,
            'target_type' => 'fulfillment_callback_logs',
            'response_code' => 200,
            'extra_data' => [
                'total' => $result['total'] ?? 0,
                'filtered_by_warehouse' => $role === 'warehouse_operator',
            ],
        ]);

        Response::success($result);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    $audit->log(AuditLogger::ACTION_FULFILLMENT_CALLBACK, AuditLogger::RESULT_FAILURE, [
        'user_id' => $guard->getCurrentUserId(),
        'role' => $guard->getCurrentRole(),
        'warehouse_code' => $guard->getCurrentWarehouseCode(),
        'target_type' => 'system',
        'response_code' => 500,
        'error_message' => $e->getMessage(),
        'extra_data' => ['exception' => $e->getTraceAsString()],
    ]);
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
