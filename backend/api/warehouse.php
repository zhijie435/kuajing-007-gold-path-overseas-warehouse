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
require_once __DIR__ . '/../core/WarehouseRouter.php';
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

    if ($method === 'POST' && strpos($uri, '/api/warehouse/route') !== false) {
        $permCheck = $guard->check('warehouse_route');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('warehouse_route', $permCheck['reason'] ?? 'unknown', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::unauthorized($permCheck['message']);
        }

        if ($requireAuth && $authenticatedClient) {
            $hasPermission = $permissionService->checkPermission($authenticatedClient, 'warehouse:route');
            if (!$hasPermission) {
                $audit->logPermissionDenied('warehouse:route', 'api_permission_missing', [
                    'role' => 'api_client',
                    'user_id' => $clientKey,
                    'extra_data' => [
                        'client_permissions' => $authenticatedClient['permissions_array'] ?? [],
                    ],
                ]);
                Response::unauthorized('[权限拦截] 缺少 warehouse:route 权限');
            }
        }

        $input = Request::getInput();
        $router = new WarehouseRouter();
        $router->setAuditContext([
            'client_key' => $clientKey,
            'user_id' => $currentUserId,
            'role' => $currentRole,
        ]);
        if (!empty($scopeWarehouseCode)) {
            $router->setPermissionContext($currentRole, $scopeWarehouseCode);
        }
        $result = $router->route(
            $input['items'] ?? [],
            $input['shipping_country'] ?? '',
            $input['shipping_state'] ?? null
        );

        $audit->log(AuditLogger::ACTION_WAREHOUSE_ROUTE,
            !empty($result['success']) ? AuditLogger::RESULT_SUCCESS : AuditLogger::RESULT_FAILURE,
            [
                'user_id' => $currentUserId,
                'role' => $currentRole,
                'warehouse_code' => $scopeWarehouseCode,
                'target_type' => 'warehouse_route',
                'target_id' => $result['selected_warehouse']['warehouse_code'] ?? null,
                'request_params' => $input,
                'response_code' => !empty($result['success']) ? 200 : 400,
                'error_message' => $result['message'] ?? null,
                'extra_data' => [
                    'shipping_country' => $input['shipping_country'] ?? null,
                    'shipping_state' => $input['shipping_state'] ?? null,
                    'selected_warehouse' => $result['selected_warehouse']['warehouse_code'] ?? null,
                    'error_type' => $result['error_type'] ?? null,
                    'item_count' => count($input['items'] ?? []),
                    'permission_scoped' => !empty($scopeWarehouseCode),
                    'scope_warehouse_code' => $scopeWarehouseCode,
                    'alternatives_count' => count($result['alternatives'] ?? []),
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
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::unauthorized($permCheck['message']);
        }

        if ($requireAuth && $authenticatedClient) {
            $hasPermission = $permissionService->checkPermission($authenticatedClient, 'warehouse:list');
            if (!$hasPermission) {
                Response::unauthorized('[权限拦截] 缺少 warehouse:list 权限');
            }
        }

        $router = new WarehouseRouter();
        $status = isset($_GET['status']) ? (int)$_GET['status'] : 1;
        $list = $router->listWarehouses($status, $scopeWarehouseCode);

        $audit->log(AuditLogger::ACTION_WAREHOUSE_LIST, AuditLogger::RESULT_SUCCESS, [
            'user_id' => $currentUserId,
            'role' => $currentRole,
            'warehouse_code' => $scopeWarehouseCode,
            'target_type' => 'warehouse_list',
            'response_code' => 200,
            'extra_data' => [
                'status' => $status,
                'returned_count' => count($list),
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
            ],
        ]);

        Response::success([
            'list' => $list,
            'total' => count($list),
            'permission_scoped' => !empty($scopeWarehouseCode),
            'scope_warehouse_code' => $scopeWarehouseCode,
        ]);
    }

    if ($method === 'GET' && strpos($uri, '/api/warehouse/') !== false && strpos($uri, '/inventory') !== false) {
        $permCheck = $guard->check('warehouse_inventory');
        if (!$permCheck['allowed']) {
            $audit->logPermissionDenied('warehouse_inventory', $permCheck['reason'] ?? 'unknown', [
                'role' => $currentRole,
                'user_id' => $currentUserId,
            ]);
            Response::unauthorized($permCheck['message']);
        }

        if ($requireAuth && $authenticatedClient) {
            $hasPermission = $permissionService->checkPermission($authenticatedClient, 'warehouse:inventory');
            if (!$hasPermission) {
                Response::unauthorized('[权限拦截] 缺少 warehouse:inventory 权限');
            }
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

        $router = new WarehouseRouter();
        $sku = $_GET['sku'] ?? null;
        $permCheckResult = [];
        $list = $router->getWarehouseInventory($warehouseId, $sku, $scopeWarehouseCode, $permCheckResult);

        if (!$permCheckResult['passed']) {
            $audit->logWarehouseAccessDenied(
                $scopeWarehouseCode,
                $permCheckResult['actual_warehouse_code'] ?? ('warehouse_' . $warehouseId),
                $permCheckResult['message'] ?? 'permission_check_failed',
                [
                    'role' => $currentRole,
                    'target_type' => 'warehouse_inventory',
                    'user_id' => $currentUserId,
                    'extra_data' => [
                        'warehouse_id' => $warehouseId,
                        'permission_check' => $permCheckResult,
                    ],
                ]
            );
            Response::unauthorized('[权限拦截] ' . $permCheckResult['message']);
        }

        $audit->log(AuditLogger::ACTION_WAREHOUSE_INVENTORY, AuditLogger::RESULT_SUCCESS, [
            'user_id' => $currentUserId,
            'role' => $currentRole,
            'warehouse_code' => $permCheckResult['actual_warehouse_code'] ?? $scopeWarehouseCode,
            'target_type' => 'warehouse_inventory',
            'target_id' => $warehouseId,
            'response_code' => 200,
            'extra_data' => [
                'warehouse_id' => $warehouseId,
                'warehouse_code' => $permCheckResult['actual_warehouse_code'],
                'sku_filter' => $sku,
                'item_count' => count($list),
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
                'consistency_verified' => true,
            ],
        ]);

        Response::success([
            'list' => $list,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => $permCheckResult['actual_warehouse_code'],
            'total' => count($list),
            'permission_scoped' => !empty($scopeWarehouseCode),
        ]);
    }

    if ($method === 'GET' && strpos($uri, '/api/warehouse/audits/route') !== false) {
        if ($requireAuth && $authenticatedClient) {
            $hasPermission = $permissionService->checkPermission($authenticatedClient, 'audit:view');
            if (!$hasPermission) {
                Response::unauthorized('[权限拦截] 缺少 audit:view 权限');
            }
        }
        $result = $auditService->queryRouteAudits($_GET);
        Response::success($result);
    }

    if ($method === 'GET' && strpos($uri, '/api/audits') !== false) {
        if ($requireAuth && $authenticatedClient) {
            $hasPermission = $permissionService->checkPermission($authenticatedClient, 'audit:view');
            if (!$hasPermission) {
                Response::unauthorized('[权限拦截] 缺少 audit:view 权限');
            }
        }
        $result = $auditService->queryOperationAudits($_GET);
        Response::success($result);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    $audit->log(AuditLogger::ACTION_WAREHOUSE_ROUTE, AuditLogger::RESULT_FAILURE, [
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
