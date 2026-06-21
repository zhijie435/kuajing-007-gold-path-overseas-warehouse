<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Callback-Token, X-Client-Key, X-API-Secret, X-Request-ID, X-Role, X-Warehouse-Code, X-User-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/FulfillmentCallbackService.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/PermissionGuard.php';
require_once __DIR__ . '/../core/AuditLogger.php';
require_once __DIR__ . '/../core/AuditService.php';

$guard = new PermissionGuard();
$audit = new AuditLogger();
$auditService = new AuditService();
header('X-Trace-Id: ' . $audit->getTraceId());

$currentRole = $guard->getCurrentRole();
$currentWarehouseCode = $guard->getCurrentWarehouseCode();
$currentUserId = $guard->getCurrentUserId();

try {
    $method = Request::getMethod();
    $uri = Request::getUri();
    $config = require __DIR__ . '/../config/config.php';
    $permissionService = new PermissionService();

    $requireAuth = $config['security']['require_api_auth'] ?? false;

    $authenticatedClient = null;
    $authResult = null;
    $clientKey = null;
    $scopeWarehouseCode = null;

    if ($requireAuth) {
        $authResult = $permissionService->authenticateByHeaders();
        $authenticatedClient = $authResult['client'] ?? null;
        $clientKey = $authenticatedClient['client_key'] ?? null;

        if (!$authResult['success']) {
            $audit->log(AuditLogger::ACTION_PERMISSION_DENIED, AuditLogger::RESULT_FAILURE, [
                'user_id' => $clientKey,
                'role' => 'api_client',
                'target_type' => 'auth',
                'target_id' => 'api_authenticate',
                'response_code' => 401,
                'error_message' => $authResult['error_message'] ?? '认证失败',
                'extra_data' => [
                    'auth_method' => 'api_key',
                    'checks' => $authResult['checks'] ?? null,
                ],
            ]);
            Response::unauthorized('[权限拦截] ' . ($authResult['error_message'] ?? '认证失败'));
        }

        if (!empty($authenticatedClient['warehouse_code'])) {
            $scopeWarehouseCode = $authenticatedClient['warehouse_code'];
        }
    }

    if ($currentRole === 'warehouse_operator' && !empty($currentWarehouseCode)) {
        $scopeWarehouseCode = $currentWarehouseCode;
    }

    if ($method === 'POST' && strpos($uri, '/api/fulfillment/callback') !== false) {
        $permCheck = $guard->check('fulfillment_callback');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('fulfillment_callback', $permCheck['reason'] ?? 'unknown', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::unauthorized($permCheck['message']);
        }

        if ($requireAuth && $authenticatedClient) {
            $hasPermission = $permissionService->checkPermission($authenticatedClient, 'fulfillment:callback');
            if (!$hasPermission) {
                $audit->logPermissionDenied('fulfillment:callback', 'api_permission_missing', [
                    'role' => 'api_client',
                    'user_id' => $clientKey,
                    'extra_data' => [
                        'client_permissions' => $authenticatedClient['permissions_array'] ?? [],
                    ],
                ]);
                Response::unauthorized('[权限拦截] 缺少 fulfillment:callback 权限');
            }
        }

        $token = Request::getHeader('X-Callback-Token') ?: ($_GET['token'] ?? '');
        $service = new FulfillmentCallbackService();

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

        $result = $service->handle($callbackType, $input, $rawBody, $token);

        $audit->log(AuditLogger::ACTION_FULFILLMENT_CALLBACK,
            !empty($result['success']) ? AuditLogger::RESULT_SUCCESS : AuditLogger::RESULT_FAILURE,
            [
                'user_id' => $currentUserId,
                'role' => $currentRole,
                'warehouse_code' => $scopeWarehouseCode,
                'target_type' => 'fulfillment_callback',
                'target_id' => $input['order_no'] ?? null,
                'request_params' => [
                    'callback_type' => $callbackType,
                    'order_no' => $input['order_no'] ?? null,
                    'warehouse_code' => $input['warehouse_code'] ?? null,
                ],
                'response_code' => !empty($result['success']) ? 200 : (!empty($result['permission_denied']) ? 403 : 400),
                'error_message' => $result['message'] ?? null,
                'extra_data' => [
                    'callback_type' => $callbackType,
                    'error_type' => $result['error_type'] ?? null,
                    'error_code' => $result['error_code'] ?? null,
                    'permission_denied' => !empty($result['permission_denied']),
                    'permission_scoped' => !empty($scopeWarehouseCode),
                    'scope_warehouse_code' => $scopeWarehouseCode,
                    'token_validated' => empty($result['error_code']) || $result['error_code'] !== 40301,
                ],
            ]
        );

        if (!empty($result['permission_denied']) || !empty($result['error_code'])) {
            $httpCode = 403;
        } else {
            $httpCode = 200;
        }

        if ($result['success']) {
            Response::success($result, $result['message'] ?? '回调处理成功');
        } else {
            Response::error($result['message'], $result['error_code'] ?? 1, $httpCode);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/fulfillment/callback/logs') !== false) {
        $permCheck = $guard->check('fulfillment_callback_logs');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('fulfillment_callback_logs', $permCheck['reason'] ?? 'unknown', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::unauthorized($permCheck['message']);
        }

        if ($requireAuth) {
            if (!$authResult || !$authenticatedClient) {
                $authResult = $permissionService->authenticateByHeaders();
                if (!$authResult['success']) {
                    $audit->log(AuditLogger::ACTION_PERMISSION_DENIED, AuditLogger::RESULT_FAILURE, [
                        'user_id' => $authResult['client']['client_key'] ?? null,
                        'role' => 'api_client',
                        'target_type' => 'auth',
                        'target_id' => 'api_authenticate',
                        'response_code' => 401,
                        'error_message' => $authResult['error_message'] ?? '认证失败',
                    ]);
                    Response::unauthorized('[权限拦截] ' . ($authResult['error_message'] ?? '认证失败'));
                }
                $authenticatedClient = $authResult['client'];
                $clientKey = $authenticatedClient['client_key'] ?? null;
            }
            $hasPermission = $permissionService->checkPermission($authenticatedClient, 'fulfillment:callback:list');
            if (!$hasPermission) {
                $audit->logPermissionDenied('fulfillment:callback:list', 'api_permission_missing', [
                    'role' => 'api_client',
                    'user_id' => $clientKey,
                    'extra_data' => [
                        'client_permissions' => $authenticatedClient['permissions_array'] ?? [],
                    ],
                ]);
                Response::unauthorized('[权限拦截] 缺少 fulfillment:callback:list 权限');
            }
        }

        $queryParams = $_GET;
        if (!empty($scopeWarehouseCode)) {
            $queryParams['scope_warehouse_code'] = $scopeWarehouseCode;
        }

        $service = new FulfillmentCallbackService();
        $result = $service->listCallbackLogs($queryParams, $scopeWarehouseCode);

        $audit->log(AuditLogger::ACTION_FULFILLMENT_CALLBACK_LOGS, AuditLogger::RESULT_SUCCESS, [
            'user_id' => $currentUserId,
            'role' => $currentRole,
            'warehouse_code' => $scopeWarehouseCode,
            'target_type' => 'fulfillment_callback_logs',
            'response_code' => 200,
            'extra_data' => [
                'query_params' => $queryParams,
                'returned_count' => $result['total'] ?? 0,
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
            ],
        ]);

        Response::success($result);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    $audit->log(AuditLogger::ACTION_FULFILLMENT_CALLBACK, AuditLogger::RESULT_FAILURE, [
        'user_id' => $currentUserId,
        'role' => $currentRole,
        'warehouse_code' => $currentWarehouseCode,
        'target_type' => 'system',
        'response_code' => 500,
        'error_message' => $e->getMessage(),
        'extra_data' => ['exception' => $e->getTraceAsString()],
    ]);
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
