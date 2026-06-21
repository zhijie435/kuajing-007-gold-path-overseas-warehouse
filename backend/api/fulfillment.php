<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Callback-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/FulfillmentCallbackService.php';

try {
    $method = Request::getMethod();
    $uri = Request::getUri();

    if ($method === 'POST' && strpos($uri, '/api/fulfillment/callback') !== false) {
        $token = Request::getHeader('X-Callback-Token') ?: ($_GET['token'] ?? '');
        $service = new FulfillmentCallbackService();

        if (!$service->validateToken($token)) {
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

        $result = $service->handle($callbackType, $input, $rawBody);
        if ($result['success']) {
            Response::success($result, $result['message'] ?? '回调处理成功');
        } else {
            Response::error($result['message']);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/fulfillment/callback/logs') !== false) {
        $service = new FulfillmentCallbackService();
        $result = $service->listCallbackLogs($_GET);
        Response::success($result);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
