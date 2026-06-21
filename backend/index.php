<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Callback-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/PermissionGuard.php';
require_once __DIR__ . '/core/AuditLogger.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^/backend#', '', $uri);

try {
    if (strpos($uri, '/api/audit/logs') === 0) {
        $guard = new PermissionGuard();
        $audit = new AuditLogger();
        $permCheck = $guard->check('audit_log_view');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('audit_log_view', $permCheck['reason'] ?? 'unknown', [
                'role' => $permCheck['role'],
            ]);
            Response::unauthorized($permCheck['message']);
        }
        $result = $audit->listLogs($_GET);
        Response::success($result);
    } elseif (strpos($uri, '/api/warehouse') === 0 || strpos($uri, '/api/warehouses') === 0) {
        require __DIR__ . '/api/warehouse.php';
    } elseif (strpos($uri, '/api/orders') === 0) {
        require __DIR__ . '/api/orders.php';
    } elseif (strpos($uri, '/api/fulfillment') === 0) {
        require __DIR__ . '/api/fulfillment.php';
    } elseif (strpos($uri, '/api/products') === 0) {
        require __DIR__ . '/api/products.php';
    } elseif ($uri === '/' || $uri === '/api' || $uri === '/api/') {
        Response::success([
            'name' => 'Overseas Warehouse Fulfillment API',
            'version' => '1.0.0',
            'endpoints' => [
                'POST /api/warehouse/route' => '仓库路由计算',
                'GET /api/warehouses' => '获取仓库列表',
                'GET /api/warehouse/{id}/inventory' => '获取仓库库存',
                'POST /api/orders' => '创建订单',
                'GET /api/orders' => '获取订单列表',
                'GET /api/orders/{order_no}' => '获取订单详情',
                'POST /api/orders/{order_no}/cancel' => '取消订单',
                'POST /api/fulfillment/callback' => '履约回调接口',
                'GET /api/fulfillment/callback/logs' => '获取回调日志',
                'GET /api/products' => '获取商品列表',
                'GET /api/products/{sku}' => '获取商品详情',
                'GET /api/audit/logs' => '获取审计日志（仅admin）',
            ],
        ]);
    } else {
        Response::notFound();
    }
} catch (Exception $e) {
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
