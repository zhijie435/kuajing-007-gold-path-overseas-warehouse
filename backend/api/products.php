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
require_once __DIR__ . '/../core/Database.php';

try {
    $method = Request::getMethod();
    $uri = Request::getUri();
    $db = Database::getInstance();

    if ($method === 'GET' && strpos($uri, '/api/products') !== false) {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $pageSize = isset($_GET['page_size']) ? min(100, (int)$_GET['page_size']) : 50;
        $keyword = $_GET['keyword'] ?? '';
        $offset = ($page - 1) * $pageSize;

        $where = ['status = 1'];
        $params = [];
        if (!empty($keyword)) {
            $where[] = '(name LIKE ? OR sku LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }
        $whereSql = implode(' AND ', $where);

        $totalRow = $db->fetchOne("SELECT COUNT(*) as cnt FROM products WHERE $whereSql", $params);
        $total = (int)$totalRow['cnt'];

        $list = $db->fetchAll(
            "SELECT * FROM products WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize",
            $params
        );

        foreach ($list as &$item) {
            $inv = $db->fetchAll(
                "SELECT wi.warehouse_id, wi.quantity, w.warehouse_code, w.warehouse_name
                 FROM warehouse_inventories wi
                 JOIN warehouses w ON wi.warehouse_id = w.id
                 WHERE wi.sku = ? AND wi.quantity > 0",
                [$item['sku']]
            );
            $item['inventories'] = $inv;
            $item['total_stock'] = array_sum(array_column($inv, 'quantity'));
        }

        Response::success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    if ($method === 'GET' && preg_match('/\/api\/products\/([^\/]+)/', $uri, $matches)) {
        $sku = $matches[1];
        $product = $db->fetchOne("SELECT * FROM products WHERE sku = ? AND status = 1", [$sku]);
        if (!$product) {
            Response::notFound('商品不存在');
        }
        $inv = $db->fetchAll(
            "SELECT wi.warehouse_id, wi.quantity, wi.reserved_quantity, w.warehouse_code, w.warehouse_name
             FROM warehouse_inventories wi
             JOIN warehouses w ON wi.warehouse_id = w.id
             WHERE wi.sku = ?",
            [$sku]
        );
        $product['inventories'] = $inv;
        Response::success($product);
    }

    Response::notFound('接口不存在');

} catch (Exception $e) {
    Response::error('系统异常: ' . $e->getMessage(), 500, 500);
}
