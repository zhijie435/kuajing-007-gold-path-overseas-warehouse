<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Role, X-Warehouse-Code, X-User-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/WarehouseRouter.php';
require_once __DIR__ . '/../core/PermissionGuard.php';
require_once __DIR__ . '/../core/AuditLogger.php';

$guard = new PermissionGuard();
$audit = new AuditLogger();
header('X-Trace-Id: ' . $audit->getTraceId());

try {
    $method = Request::getMethod();
    $uri = Request::getUri();

    if ($method === 'POST' && strpos($uri, '/api/warehouse/route') !== false) {
        $permCheck = $guard->check('warehouse_route');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('warehouse_route', $permCheck['reason'] ?? 'unknown', [
                'role' => $permCheck['role'],
            ]);
            Response::unauthorized($permCheck['message']);
        }

        $input = Request::getInput();
        $router = new WarehouseRouter();
        $result = $router->route(
            $input['items'] ?? [],
            $input['shipping_country'] ?? '',
            $input['shipping_state'] ?? null
        );

        $audit->log(AuditLogger::ACTION_WAREHOUSE_ROUTE,
            $result['success'] ? AuditLogger::RESULT_SUCCESS : AuditLogger::RESULT_FAILURE,
            [
                'user_id' => $guard->getCurrentUserId(),
                'role' => $permCheck['role'],
                'target_type' => 'warehouse_route',
                'request_params' => $input,
                'response_code' => $result['success'] ? 200 : 400,
                'error_message' => $result['message'] ?? null,
                'extra_data' => [
                    'shipping_country' => $input['shipping_country'] ?? null,
                    'shipping_state' => $input['shipping_state'] ?? null,
                    'selected_warehouse' => $result['selected_warehouse']['warehouse_code'] ?? null,
                    'error_type' => $result['error_type'] ?? null,
                    'item_count' => count($input['items'] ?? []),
                ],
            ]
        );

        if ($result['success']) {
            Response::success($result);
        } else {
            Response::error($result['message']);
        }
    }

    if ($method === 'GET' && strpos($uri, '/api/warehouses') !== false) {
        $permCheck = $guard->check('warehouse_list');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('warehouse_list', $permCheck['reason'] ?? 'unknown', [
                'role' => $permCheck['role'],
            ]);
            Response::unauthorized($permCheck['message']);
        }

        $router = new WarehouseRouter();
        $status = isset($_GET['status']) ? (int)$_GET['status'] : 1;
        $list = $router->listWarehouses($status);

        $filteredList = $guard->filterWarehouseList($list);

        $audit->log(AuditLogger::ACTION_WAREHOUSE_LIST, AuditLogger::RESULT_SUCCESS, [
            'user_id' => $guard->getCurrentUserId(),
            'role' => $permCheck['role'],
            'target_type' => 'warehouse_list',
            'response_code' => 200,
            'extra_data' => [
                'status' => $status,
                'total_count' => count($list),
                'filtered_count' => count($filteredList),
            ],
        ]);

        Response::success(['list' => $filteredList]);
    }

    if ($method === 'GET' && strpos($uri, '/api/warehouse/') !== false && strpos($uri, '/inventory') !== false) {
        $permCheck = $guard->check('warehouse_inventory');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('warehouse_inventory', $permCheck['reason'] ?? 'unknown', [
                'role' => $permCheck['role'],
            ]);
            Response::unauthorized($permCheck['message']);
        }

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

        $warehouseCheck = $guard->validateWarehouseAccess($warehouseId);
        if (!$warehouseCheck['allowed']) {
            $warehouseCode = '';
            $router = new WarehouseRouter();
            $audit->logWarehouseAccessDenied(
                $guard->getCurrentWarehouseCode(),
                'warehouse_' . $warehouseId,
                $warehouseCheck['reason'] ?? 'unknown',
                [
                    'role' => $permCheck['role'],
                    'target_type' => 'warehouse_inventory',
                    'user_id' => $guard->getCurrentUserId(),
                ]
            );
            Response::unauthorized($warehouseCheck['message']);
        }

        $router = new WarehouseRouter();
        $sku = $_GET['sku'] ?? null;
        $list = $router->getWarehouseInventory($warehouseId, $sku);

        $audit->log(AuditLogger::ACTION_WAREHOUSE_INVENTORY, AuditLogger::RESULT_SUCCESS, [
            'user_id' => $guard->getCurrentUserId(),
            'role' => $permCheck['role'],
            'warehouse_code' => $guard->getCurrentWarehouseCode(),
            'target_type' => 'warehouse_inventory',
            'target_id' => $warehouseId,
            'response_code' => 200,
            'extra_data' => [
                'warehouse_id' => $warehouseId,
                'sku_filter' => $sku,
                'item_count' => count($list),
            ],
        ]);

        Response::success(['list' => $list]);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    $audit->log(AuditLogger::ACTION_WAREHOUSE_ROUTE, AuditLogger::RESULT_FAILURE, [
        'user_id' => $guard->getCurrentUserId(),
        'role' => $guard->getCurrentRole(),
        'target_type' => 'system',
        'response_code' => 500,
        'error_message' => $e->getMessage(),
        'extra_data' => ['exception' => $e->getTraceAsString()],
    ]);
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
