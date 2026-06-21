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
require_once __DIR__ . '/../core/WarehouseRouter.php';

try {
    $method = Request::getMethod();
    $uri = Request::getUri();

    if ($method === 'POST' && strpos($uri, '/api/warehouse/route') !== false) {
        $input = Request::getInput();
        $router = new WarehouseRouter();
        $result = $router->route(
            $input['items'] ?? [],
            $input['shipping_country'] ?? '',
            $input['shipping_state'] ?? null
        );
        if ($result['success']) {
            Response::success($result);
        } else {
            Response::error($result['message']);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/warehouses') !== false) {
        $router = new WarehouseRouter();
        $status = isset($_GET['status']) ? (int)$_GET['status'] : 1;
        $list = $router->listWarehouses($status);
        Response::success(['list' => $list]);
    }

    if ($method === 'GET' && strpos($uri, '/api/warehouse/') !== false && strpos($uri, '/inventory') !== false) {
        $uriParts = explode('/', trim($uri, '/'));
        $warehouseId = null;
        foreach ($uriParts as $i => $part) {
            if ($part === 'warehouse' && isset($uriParts[$i + 1]) && is_numeric($uriParts[$i + 1])) {
                $warehouseId = (int)$uriParts[$i + 1];
                break;
            }
        }
        if (!$warehouseId) {
            preg_match('/\/warehouse\/(\d+)\/inventory/', $uri, $matches);
            $warehouseId = isset($matches[1]) ? (int)$matches[1] : null;
        }
        if (!$warehouseId) {
            Response::error('缺少仓库ID参数');
        }
        $router = new WarehouseRouter();
        $sku = $_GET['sku'] ?? null;
        $list = $router->getWarehouseInventory($warehouseId, $sku);
        Response::success(['list' => $list]);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
