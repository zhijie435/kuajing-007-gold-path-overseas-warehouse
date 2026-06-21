<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Timestamp, X-Signature, X-Callback-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/PermissionService.php';

try {
    $method = Request::getMethod();
    $uri = Request::getUri();
    $config = require __DIR__ . '/../config/config.php';

    $requestAt = date('Y-m-d H:i:s');
    $traceId = null;
    $apiKey = null;
    $authResult = true;
    $authError = null;
    $appId = null;
    $responseCode = 0;
    $responseMessage = 'success';
    $httpStatusCode = 200;

    $permissionService = new PermissionService();
    $traceId = $permissionService->generateTraceId();
    header('X-Trace-Id: ' . $traceId);

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
                'error_code' => $auth['error_code'] ?? 'AUTH_FAILED'
            ], $responseCode, $responseMessage, $httpStatusCode);
        }
        $appId = $auth['app_id'];
    }

    if ($method === 'POST' && strpos($uri, '/api/orders') !== false && !preg_match('/\/api\/orders\/[^\/]+/', $uri)) {
        if ($requireAuth && !$permissionService->checkPermission('order:create')) {
            $authResult = false;
            $authError = '无权限创建订单';
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
        $result = $service->createOrder($input, $traceId, $appId);
        if ($result['success']) {
            $responseMessage = '订单创建成功';
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
            Response::json($errorData, $responseCode, $responseMessage, $httpStatusCode);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/orders') !== false && !preg_match('/\/api\/orders\/[^\/]+/', $uri)) {
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
        $result = $service->listOrders($_GET);
        $result['order_status_map'] = OrderService::getOrderStatusMap();
        $result['fulfillment_status_map'] = OrderService::getFulfillmentStatusMap();
        Response::success(array_merge($result, ['trace_id' => $traceId]));
    }

    if (preg_match('/\/api\/orders\/([^\/]+)$/', $uri, $matches)) {
        $orderNo = $matches[1];
        if ($method === 'GET') {
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
            $detail = $service->getOrderDetail($orderNo);
            if (!$detail) {
                Response::notFound('订单不存在');
            }
            Response::success(array_merge($detail, ['trace_id' => $traceId]));
        }
    }

    if ($method === 'POST' && preg_match('/\/api\/orders\/([^\/]+)\/cancel/', $uri, $matches)) {
        $orderNo = $matches[1];
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
        $result = $service->cancelOrder($orderNo, $input['reason'] ?? '');
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
