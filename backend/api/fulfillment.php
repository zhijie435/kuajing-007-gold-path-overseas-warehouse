<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Callback-Token, X-Client-Key, X-API-Secret, X-Request-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/FulfillmentCallbackService.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/AuditService.php';

try {
    $method = Request::getMethod();
    $uri = Request::getUri();
    $config = require __DIR__ . '/../config/config.php';
    $permissionService = new PermissionService();
    $auditService = new AuditService();

    $requireAuth = $config['security']['require_api_auth'] ?? false;

    if ($method === 'POST' && strpos($uri, '/api/fulfillment/callback') !== false) {
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
        if ($requireAuth) {
            $authResult = $permissionService->authenticateByHeaders();
            if (!$authResult['success']) {
                Response::unauthorized('[权限拦截] ' . ($authResult['error_message'] ?? '认证失败'));
            }
            $client = $authResult['client'];
            $hasPermission = $permissionService->checkPermission($client, 'fulfillment:callback:list');
            if (!$hasPermission) {
                Response::unauthorized('[权限拦截] 缺少 fulfillment:callback:list 权限');
            }
        }
        $service = new FulfillmentCallbackService();
        $result = $service->listCallbackLogs($_GET);
        Response::success($result);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
