<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Timestamp, X-Signature, X-Callback-Token, X-Role, X-Warehouse-Code, X-User-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/PermissionGuard.php';
require_once __DIR__ . '/../core/AuditLogger.php';

$guard = new PermissionGuard();
$audit = new AuditLogger();
header('X-Trace-Id: ' . $audit->getTraceId());

$currentRole = $guard->getCurrentRole();
$currentWarehouseCode = $guard->getCurrentWarehouseCode();
$currentUserId = $guard->getCurrentUserId();

try {
    $method = Request::getMethod();
    $uri = Request::getUri();
    $config = require __DIR__ . '/../config/config.php';

    $requestAt = date('Y-m-d H:i:s');
    $traceId = $audit->getTraceId();
    $apiKey = null;
    $authResult = true;
    $authError = null;
    $appId = null;
    $responseCode = 0;
    $responseMessage = 'success';
    $httpStatusCode = 200;

    $permissionService = new PermissionService();

    $scopeWarehouseCode = null;

    $apiKey = Request::getHeader('X-API-Key');
    $timestamp = Request::getHeader('X-Timestamp');
    $signature = Request::getHeader('X-Signature');

    $requireAuth = !empty($config['security']['require_api_auth']);
    $allowPublic = !empty($config['security']['allow_public_access']);

    if ($requireAuth && !$allowPublic) {
        $auth = $permissionService->authenticateApiKey($apiKey, null, $timestamp, $signature);
        if (!$auth['success']) {
            $authResult = false;
            $authError = $auth['error'];
            $responseCode = 403;
            $responseMessage = $auth['error'];
            $httpStatusCode = 403;

            $permissionService->logApiAccess(
                $traceId, $apiKey, $method, $uri, $_REQUEST,
                $responseCode, $responseMessage, $httpStatusCode,
                $authResult, $authError, $requestAt, date('Y-m-d H:i:s')
            );

            Response::json([
                'trace_id' => $traceId,
                'error_type' => $auth['error_code'] ?? 'AUTH_FAILED'
            ], $responseCode, $responseMessage, $httpStatusCode);
        }
        $appId = $auth['app_id'];

        $authenticatedClient = $auth['client'] ?? null;
        if (!empty($authenticatedClient['warehouse_code'])) {
            $scopeWarehouseCode = $authenticatedClient['warehouse_code'];
        }
    }

    if ($currentRole === 'warehouse_operator' && !empty($currentWarehouseCode)) {
        $scopeWarehouseCode = $currentWarehouseCode;
    }

    if ($method === 'POST' && strpos($uri, '/api/orders') !== false && !preg_match('/\/api\/orders\/[^\/]+/', $uri)) {
        $permCheck = $guard->check('order_create');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('order_create', $permCheck['reason'] ?? 'unknown', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::json([
                'trace_id' => $traceId,
                'error_type' => 'PERMISSION_DENIED'
            ], 403, '[权限拦截] ' . $permCheck['message'], 403);
        }

        if ($requireAuth && !$permissionService->checkPermission($auth['client'] ?? null, 'order:create')) {
            $authResult = false;
            $authError = '无权限创建订单';
            $responseCode = 403;
            $responseMessage = $authError;
            $httpStatusCode = 403;

            $audit->logPermissionDenied('order:create', 'api_permission_missing', [
                'role' => 'api_client',
                'user_id' => $apiKey,
            ]);

            $permissionService->logApiAccess(
                $traceId, $apiKey, $method, $uri, $_REQUEST,
                $responseCode, $responseMessage, $httpStatusCode,
                $authResult, $authError, $requestAt, date('Y-m-d H:i:s')
            );

            Response::json([
                'trace_id' => $traceId,
                'error_type' => 'PERMISSION_DENIED'
            ], $responseCode, '[权限拦截] ' . $responseMessage, $httpStatusCode);
        }

        $input = Request::getInput();
        $service = new OrderService();
        $result = $service->createOrder($input, $traceId, $appId, $scopeWarehouseCode);
        if ($result['success']) {
            $responseMessage = '订单创建成功';
            $audit->log(AuditLogger::ACTION_ORDER_CREATE, AuditLogger::RESULT_SUCCESS, [
                'user_id' => $currentUserId,
                'role' => $currentRole,
                'warehouse_code' => $result['warehouse']['warehouse_code'] ?? null,
                'target_type' => 'order',
                'target_id' => $result['order_no'],
                'request_params' => $input,
                'response_code' => 200,
                'extra_data' => [
                    'order_id' => $result['order_id'],
                    'scope_warehouse_code' => $scopeWarehouseCode,
                    'permission_scoped' => !empty($scopeWarehouseCode),
                ],
            ]);
            Response::success(array_merge($result, ['trace_id' => $traceId]), $responseMessage);
        } else {
            $responseCode = 1;
            $responseMessage = $result['message'];
            $httpStatusCode = 400;
            $errorData = [
                'trace_id' => $traceId,
                'error_type' => $result['error_type'] ?? 'UNKNOWN_ERROR',
                'rollback' => $result['rollback'] ?? false,
                'retryable' => $result['retryable'] ?? false,
                'details' => $result['details'] ?? null
            ];
            $audit->log(AuditLogger::ACTION_ORDER_CREATE, AuditLogger::RESULT_FAILURE, [
                'user_id' => $currentUserId,
                'role' => $currentRole,
                'target_type' => 'order',
                'request_params' => $input,
                'response_code' => 400,
                'error_message' => $result['message'],
                'extra_data' => [
                    'error_type' => $result['error_type'] ?? null,
                    'scope_warehouse_code' => $scopeWarehouseCode,
                    'permission_scoped' => !empty($scopeWarehouseCode),
                ],
            ]);
            Response::json($errorData, $responseCode, $responseMessage, $httpStatusCode);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/orders') !== false && !preg_match('/\/api\/orders\/[^\/]+/', $uri)) {
        $permCheck = $guard->check('order_list');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('order_list', $permCheck['reason'] ?? 'unknown', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::json([
                'trace_id' => $traceId,
                'error_type' => 'PERMISSION_DENIED'
            ], 403, '[权限拦截] ' . $permCheck['message'], 403);
        }

        if ($requireAuth && !$permissionService->checkPermission('order:read')) {
            $authResult = false;
            $authError = '无权限查看订单列表';
            $responseCode = 403;
            $responseMessage = $authError;
            $httpStatusCode = 403;

            $permissionService->logApiAccess(
                $traceId, $apiKey, $method, $uri, $_REQUEST,
                $responseCode, $responseMessage, $httpStatusCode,
                $authResult, $authError, $requestAt, date('Y-m-d H:i:s')
            );

            Response::json([
                'trace_id' => $traceId,
                'error_code' => 'PERMISSION_DENIED'
            ], $responseCode, $responseMessage, $httpStatusCode);
        }

        $service = new OrderService();
        $queryParams = $_GET;
        if (!empty($scopeWarehouseCode)) {
            $queryParams['scope_warehouse_code'] = $scopeWarehouseCode;
        }
        $result = $service->listOrders($queryParams);
        $result['order_status_map'] = OrderService::getOrderStatusMap();
        $result['fulfillment_status_map'] = OrderService::getFulfillmentStatusMap();
        Response::success(array_merge($result, ['trace_id' => $traceId]));
    }

    if (preg_match('/\/api\/orders\/([^\/]+)$/', $uri, $matches)) {
        $orderNo = $matches[1];
        if ($method === 'GET') {
            $permCheck = $guard->check('order_read');
            if (!$permCheck['allowed']) {
                $audit->logPermissionDenied('order_read', $permCheck['reason'] ?? 'unknown', [
                    'role' => $currentRole,
                    'user_id' => $currentUserId,
                ]);
                Response::json([
                    'trace_id' => $traceId,
                    'error_type' => 'PERMISSION_DENIED'
                ], 403, '[权限拦截] ' . $permCheck['message'], 403);
            }

            if ($requireAuth && !$permissionService->checkPermission('order:read')) {
                $authResult = false;
                $authError = '无权限查看订单详情';
                $responseCode = 403;
                $responseMessage = $authError;
                $httpStatusCode = 403;

                $permissionService->logApiAccess(
                    $traceId, $apiKey, $method, $uri, $_REQUEST,
                    $responseCode, $responseMessage, $httpStatusCode,
                    $authResult, $authError, $requestAt, date('Y-m-d H:i:s')
                );

                Response::json([
                    'trace_id' => $traceId,
                    'error_code' => 'PERMISSION_DENIED'
                ], $responseCode, $responseMessage, $httpStatusCode);
            }

            $service = new OrderService();
            $permCheckResult = [];
            $detail = $service->getOrderDetail($orderNo, $scopeWarehouseCode, $permCheckResult);
            if (!$permCheckResult['passed']) {
                $audit->logPermissionDenied('order_read', 'warehouse_scope_mismatch', [
                    'role' => $currentRole,
                    'user_id' => $currentUserId,
                    'target_id' => $orderNo,
                    'extra_data' => $permCheckResult,
                ]);
                Response::json([
                    'trace_id' => $traceId,
                    'error_type' => 'PERMISSION_DENIED'
                ], 403, '[权限拦截] ' . $permCheckResult['message'], 403);
            }
            if (!$detail) {
                Response::notFound('订单不存在');
            }
            Response::success(array_merge($detail, ['trace_id' => $traceId]));
        }
    }

    if ($method === 'POST' && preg_match('/\/api\/orders\/([^\/]+)\/cancel/', $uri, $matches)) {
        $orderNo = $matches[1];
        $permCheck = $guard->check('order_cancel');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('order_cancel', $permCheck['reason'] ?? 'unknown', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::json([
                'trace_id' => $traceId,
                'error_type' => 'PERMISSION_DENIED'
            ], 403, '[权限拦截] ' . $permCheck['message'], 403);
        }

        if ($requireAuth && !$permissionService->checkPermission('order:cancel')) {
            $authResult = false;
            $authError = '无权限取消订单';
            $responseCode = 403;
            $responseMessage = $authError;
            $httpStatusCode = 403;

            $permissionService->logApiAccess(
                $traceId, $apiKey, $method, $uri, $_REQUEST,
                $responseCode, $responseMessage, $httpStatusCode,
                $authResult, $authError, $requestAt, date('Y-m-d H:i:s')
            );

            Response::json([
                'trace_id' => $traceId,
                'error_code' => 'PERMISSION_DENIED'
            ], $responseCode, $responseMessage, $httpStatusCode);
        }

        $input = Request::getInput();
        $service = new OrderService();
        $permCheckResult = [];
        $result = $service->cancelOrder($orderNo, $input['reason'] ?? '', $scopeWarehouseCode, $permCheckResult);
        if (!$permCheckResult['passed']) {
            $audit->logPermissionDenied('order_cancel', 'warehouse_scope_mismatch', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
                'target_id' => $orderNo,
                'extra_data' => $permCheckResult,
            ]);
            Response::json([
                'trace_id' => $traceId,
                'error_type' => 'PERMISSION_DENIED'
            ], 403, '[权限拦截] ' . $permCheckResult['message'], 403);
        }
        if ($result['success']) {
            Response::success(array_merge($result, ['trace_id' => $traceId]), $result['message']);
        } else {
            $responseCode = 1;
            $responseMessage = $result['message'];
            $httpStatusCode = 400;
            Response::error($responseMessage, $responseCode, $httpStatusCode);
        }
    }

    $permissionService->logApiAccess(
        $traceId, $apiKey, $method, $uri, $_REQUEST,
        $responseCode, $responseMessage, $httpStatusCode,
        $authResult, $authError, $requestAt, date('Y-m-d H:i:s')
    );

    Response::notFound('接口不存在');

} catch (Exception $e) {
    if (isset($permissionService) && isset($traceId)) {
        $permissionService->logApiAccess(
            $traceId, $apiKey ?? '', $method ?? '', $uri ?? '', $_REQUEST,
            500, '系统异常: ' . $e->getMessage(), 500,
            $authResult ?? true, null, $requestAt ?? date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
        );
    }
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
