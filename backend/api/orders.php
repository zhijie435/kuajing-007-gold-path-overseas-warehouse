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
require_once __DIR__ . '/../core/OrderService.php';

try {
    $method = Request::getMethod();
    $uri = Request::getUri();

    if ($method === 'POST' && strpos($uri, '/api/orders') !== false && !preg_match('/\/api\/orders\/[^\/]+/', $uri)) {
        $input = Request::getInput();
        $service = new OrderService();
        $result = $service->createOrder($input);
        if ($result['success']) {
            Response::success($result, '订单创建成功');
        } else {
            Response::error($result['message']);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/orders') !== false && !preg_match('/\/api\/orders\/[^\/]+/', $uri)) {
        $service = new OrderService();
        $result = $service->listOrders($_GET);
        $result['order_status_map'] = OrderService::getOrderStatusMap();
        $result['fulfillment_status_map'] = OrderService::getFulfillmentStatusMap();
        Response::success($result);
    }

    if (preg_match('/\/api\/orders\/([^\/]+)$/', $uri, $matches)) {
        $orderNo = $matches[1];
        if ($method === 'GET') {
            $service = new OrderService();
            $detail = $service->getOrderDetail($orderNo);
            if (!$detail) {
                Response::notFound('订单不存在');
            }
            Response::success($detail);
        }
    }

    if ($method === 'POST' && preg_match('/\/api\/orders\/([^\/]+)\/cancel/', $uri, $matches)) {
        $orderNo = $matches[1];
        $input = Request::getInput();
        $service = new OrderService();
        $result = $service->cancelOrder($orderNo, $input['reason'] ?? '');
        if ($result['success']) {
            Response::success($result, $result['message']);
        } else {
            Response::error($result['message']);
        }
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
