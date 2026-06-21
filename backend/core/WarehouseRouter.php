<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/AuditService.php';
require_once __DIR__ . '/../core/PermissionService.php';

class WarehouseRouter {
    private $db;
    private $auditService;
    private $auditContext;
    private $permissionContext;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auditService = new AuditService();
        $this->auditContext = [
            'start_time' => microtime(true),
            'client_key' => null,
            'client_ip' => PermissionService::getClientIp(),
        ];
        $this->permissionContext = [
            'role' => null,
            'warehouse_code' => null,
        ];
    }

    public function setAuditContext($context) {
        $this->auditContext = array_merge($this->auditContext, $context);
    }

    public function setPermissionContext($role = null, $warehouseCode = null) {
        $this->permissionContext = [
            'role' => $role,
            'warehouse_code' => $warehouseCode,
        ];
    }

    private function getDurationMs() {
        return (int)round((microtime(true) - $this->auditContext['start_time']) * 1000);
    }

    private function returnWithAudit($result, $items, $shippingCountry, $shippingState = null) {
        try {
            $auditParams = [
                'client_key' => $this->auditContext['client_key'] ?? null,
                'client_ip' => $this->auditContext['client_ip'] ?? PermissionService::getClientIp(),
                'items' => $items,
                'shipping_country' => $shippingCountry,
                'shipping_state' => $shippingState,
                'success' => $result['success'] ?? false,
                'error_type' => $result['error_type'] ?? null,
                'error_message' => $result['message'] ?? null,
                'duration_ms' => $this->getDurationMs(),
            ];

            if (!empty($result['selected_warehouse'])) {
                $auditParams['selected_warehouse'] = $result['selected_warehouse'];
            }
            if (!empty($result['alternatives'])) {
                $auditParams['alternatives'] = $result['alternatives'];
            }

            $this->auditService->logWarehouseRoute($auditParams);
        } catch (Exception $e) {
            error_log('WarehouseRouter returnWithAudit exception: ' . $e->getMessage());
        }

        return $result;
    }

    private function writeAuditForResult($result, $items, $shippingCountry, $shippingState = null) {
        return $this->returnWithAudit($result, $items, $shippingCountry, $shippingState);
    }

    /**
     * 路由决策：根据商品、收货地址选择最优仓库
     * 算法优先级：1.库存充足 2.配送区域匹配 3.运费最低 4.时效最快 5.优先级最高
     *
     * 权限边界：对于 warehouse_operator 角色，仅允许选择分配给自己的仓库
     */
    public function route($items, $shippingCountry, $shippingState = null) {
        if (empty($items) || !is_array($items)) {
            return $this->writeAuditForResult([
                'success' => false,
                'message' => '商品信息不能为空',
                'error_type' => 'EMPTY_ITEMS',
            ], $items, $shippingCountry, $shippingState);
        }

        if (empty($shippingCountry)) {
            return $this->writeAuditForResult([
                'success' => false,
                'message' => '收货国家不能为空',
                'error_type' => 'EMPTY_COUNTRY',
            ], $items, $shippingCountry, $shippingState);
        }

        $invalidSkus = [];
        $invalidQtys = [];
        foreach ($items as $idx => $item) {
            if (empty($item['sku'])) {
                $invalidSkus[] = ($idx + 1);
            }
            $qty = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            if ($qty <= 0) {
                $invalidQtys[] = $item['sku'] ?? ('第' . ($idx + 1) . '个商品');
            }
        }

        if (!empty($invalidSkus)) {
            return $this->writeAuditForResult([
                'success' => false,
                'message' => '第 ' . implode('、', $invalidSkus) . ' 个商品的 SKU 不能为空',
                'error_type' => 'INVALID_SKU',
                'item_indexes' => $invalidSkus,
            ], $items, $shippingCountry, $shippingState);
        }

        if (!empty($invalidQtys)) {
            return $this->writeAuditForResult([
                'success' => false,
                'message' => '商品 ' . implode('、', $invalidQtys) . ' 的数量必须大于 0',
                'error_type' => 'INVALID_QUANTITY',
                'skus' => $invalidQtys,
            ], $items, $shippingCountry, $shippingState);
        }

        $skus = array_column($items, 'sku');
        $skuQuantities = [];
        foreach ($items as $item) {
            $sku = trim($item['sku']);
            $skuQuantities[$sku] = isset($skuQuantities[$sku])
                ? $skuQuantities[$sku] + (int)$item['quantity']
                : (int)$item['quantity'];
        }

        $role = $this->permissionContext['role'] ?? null;
        $scopeWarehouseCode = $this->permissionContext['warehouse_code'] ?? null;

        $stockCheck = $this->checkAllWarehousesStock($skus, $skuQuantities, $scopeWarehouseCode);
        if (!$stockCheck['all_available']) {
            return $this->returnWithAudit([
                'success' => false,
                'message' => $stockCheck['message'],
                'error_type' => 'INSUFFICIENT_STOCK',
                'stock_details' => $stockCheck['details'],
                'missing_skus' => $stockCheck['missing_skus'],
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
            ], $items, $shippingCountry, $shippingState);
        }

        $candidateWarehouses = $this->findWarehousesWithAllStock($skus, $skuQuantities, $scopeWarehouseCode);

        if (empty($candidateWarehouses)) {
            return $this->returnWithAudit([
                'success' => false,
                'message' => !empty($scopeWarehouseCode)
                    ? "分配仓库 [{$scopeWarehouseCode}] 没有同时拥有所有商品的充足库存，请联系管理员"
                    : '没有仓库同时拥有所有商品的充足库存，请尝试分拆订单或减少数量',
                'error_type' => 'NO_WAREHOUSE_WITH_ALL_STOCK',
                'stock_details' => $stockCheck['details'],
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
            ], $items, $shippingCountry, $shippingState);
        }

        $warehouseIds = array_keys($candidateWarehouses);

        $shippingZones = $this->getMatchingShippingZones($warehouseIds, $shippingCountry, $shippingState);

        if (empty($shippingZones)) {
            $warehouseNames = [];
            foreach ($candidateWarehouses as $w) {
                $warehouseNames[] = $w['warehouse_name'] . '(' . $w['warehouse_code'] . ')';
            }
            return $this->writeAuditForResult([
                'success' => false,
                'message' => !empty($scopeWarehouseCode)
                    ? "分配仓库 [{$scopeWarehouseCode}] 不支持配送到 {$shippingCountry}" . ($shippingState ? '/' . $shippingState : '') . "，请联系管理员"
                    : '候选仓库（' . implode('、', $warehouseNames) . '）均不支持配送到 ' . $shippingCountry . ($shippingState ? '/' . $shippingState : ''),
                'error_type' => 'NO_SHIPPING_ZONE',
                'shipping_country' => $shippingCountry,
                'shipping_state' => $shippingState,
                'candidate_warehouses' => $warehouseNames,
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
            ], $items, $shippingCountry, $shippingState);
        }

        $totalWeight = $this->calculateTotalWeight($items);

        $scored = [];
        foreach ($shippingZones as $zone) {
            $warehouseId = $zone['warehouse_id'];
            if (!isset($candidateWarehouses[$warehouseId])) {
                continue;
            }

            $warehouse = $candidateWarehouses[$warehouseId];
            $shippingCost = $this->calculateShippingCost($zone, $totalWeight);
            $avgShippingDays = ($zone['shipping_days_min'] + $zone['shipping_days_max']) / 2;

            $score = 0;
            $score -= $shippingCost * 10;
            $score -= $avgShippingDays * 5;
            $score += $warehouse['priority'] * 0.5;

            $scored[] = [
                'warehouse_id' => $warehouseId,
                'warehouse_code' => $warehouse['warehouse_code'],
                'warehouse_name' => $warehouse['warehouse_name'],
                'country' => $warehouse['country'],
                'city' => $warehouse['city'],
                'shipping_cost' => round($shippingCost, 2),
                'shipping_days_min' => $zone['shipping_days_min'],
                'shipping_days_max' => $zone['shipping_days_max'],
                'avg_shipping_days' => $avgShippingDays,
                'estimated_delivery_date' => date('Y-m-d', strtotime('+' . ceil($avgShippingDays) . ' days')),
                'score' => round($score, 2),
                'inventory_available' => true,
            ];
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        if (empty($scored)) {
            return $this->writeAuditForResult([
                'success' => false,
                'message' => '没有找到符合条件的仓库，请联系客服',
                'error_type' => 'NO_MATCHED_WAREHOUSE',
                'permission_scoped' => !empty($scopeWarehouseCode),
                'scope_warehouse_code' => $scopeWarehouseCode,
            ], $items, $shippingCountry, $shippingState);
        }

        $best = $scored[0];

        $result = [
            'success' => true,
            'selected_warehouse' => $best,
            'alternatives' => array_slice($scored, 1, 3),
            'total_weight' => round($totalWeight, 2),
            'shipping_country' => $shippingCountry,
            'shipping_state' => $shippingState,
            'permission_scoped' => !empty($scopeWarehouseCode),
            'scope_warehouse_code' => $scopeWarehouseCode,
            'permission_role' => $role,
        ];

        return $this->writeAuditForResult($result, $items, $shippingCountry, $shippingState);
    }

    /**
     * 检查所有仓库的库存情况，返回详细的库存信息
     * 权限边界：若指定了 scopeWarehouseCode，则只检查该仓库
     */
    private function checkAllWarehousesStock($skus, $skuQuantities, $scopeWarehouseCode = null) {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $sql = "SELECT wi.warehouse_id, wi.sku, wi.quantity, w.warehouse_code, w.warehouse_name
                FROM warehouse_inventories wi
                JOIN warehouses w ON wi.warehouse_id = w.id
                WHERE wi.sku IN ($placeholders) AND w.status = 1";

        $params = $skus;
        if (!empty($scopeWarehouseCode)) {
            $sql .= " AND w.warehouse_code = ?";
            $params[] = $scopeWarehouseCode;
        }

        $rows = $this->db->fetchAll($sql, $params);

        $details = [];
        $missingSkus = [];

        foreach ($skus as $sku) {
            $requiredQty = $skuQuantities[$sku];
            $totalStock = 0;
            $availableIn = [];

            foreach ($rows as $row) {
                if ($row['sku'] === $sku) {
                    $totalStock += (int)$row['quantity'];
                    if ((int)$row['quantity'] >= $requiredQty) {
                        $availableIn[] = $row['warehouse_name'] . '(' . $row['warehouse_code'] . '):' . $row['quantity'];
                    }
                }
            }

            $details[$sku] = [
                'required' => $requiredQty,
                'total_stock' => $totalStock,
                'sufficient' => $totalStock >= $requiredQty,
                'available_warehouses' => $availableIn,
            ];

            if ($totalStock < $requiredQty) {
                $scopeNote = !empty($scopeWarehouseCode) ? "（范围:{$scopeWarehouseCode}）" : '';
                $missingSkus[] = "{$sku}(需要{$requiredQty}，总库存{$totalStock}){$scopeNote}";
            }
        }

        $allAvailable = empty($missingSkus);
        $message = '';
        if (!$allAvailable) {
            $prefix = !empty($scopeWarehouseCode) ? "仓库 [{$scopeWarehouseCode}] " : '';
            $message = $prefix . '以下商品库存不足：' . implode('；', $missingSkus);
        }

        return [
            'all_available' => $allAvailable,
            'message' => $message,
            'details' => $details,
            'missing_skus' => $missingSkus,
        ];
    }

    /**
     * 查询拥有所有商品库存的仓库
     * 权限边界：若指定了 scopeWarehouseCode，则只在该仓库内查询
     */
    private function findWarehousesWithAllStock($skus, $skuQuantities, $scopeWarehouseCode = null) {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $sql = "SELECT wi.warehouse_id, wi.sku, wi.quantity, w.warehouse_code, w.warehouse_name, w.country, w.city, w.priority
                FROM warehouse_inventories wi
                JOIN warehouses w ON wi.warehouse_id = w.id
                WHERE wi.sku IN ($placeholders) AND w.status = 1 AND wi.quantity > 0";

        $params = $skus;
        if (!empty($scopeWarehouseCode)) {
            $sql .= " AND w.warehouse_code = ?";
            $params[] = $scopeWarehouseCode;
        }

        $rows = $this->db->fetchAll($sql, $params);

        $warehouseStock = [];
        foreach ($rows as $row) {
            $wid = $row['warehouse_id'];
            if (!isset($warehouseStock[$wid])) {
                $warehouseStock[$wid] = [
                    'warehouse_code' => $row['warehouse_code'],
                    'warehouse_name' => $row['warehouse_name'],
                    'country' => $row['country'],
                    'city' => $row['city'],
                    'priority' => $row['priority'],
                    'stocks' => [],
                ];
            }
            $warehouseStock[$wid]['stocks'][$row['sku']] = (int)$row['quantity'];
        }

        $result = [];
        foreach ($warehouseStock as $wid => $info) {
            $hasAll = true;
            foreach ($skuQuantities as $sku => $qty) {
                if (!isset($info['stocks'][$sku]) || $info['stocks'][$sku] < $qty) {
                    $hasAll = false;
                    break;
                }
            }
            if ($hasAll) {
                $result[$wid] = $info;
            }
        }

        return $result;
    }

    private function getMatchingShippingZones($warehouseIds, $country, $state = null) {
        if (empty($warehouseIds)) {
            return [];
        }

        $ids = implode(',', array_map('intval', $warehouseIds));
        $params = [$country];

        $sql = "SELECT * FROM warehouse_shipping_zones
                WHERE warehouse_id IN ($ids) AND status = 1 AND country = ?";

        if ($state !== null && $state !== '') {
            $sql .= " AND (state = ? OR state IS NULL) ";
            $params[] = $state;
        } else {
            $sql .= " AND state IS NULL ";
        }

        $sql .= " ORDER BY CASE WHEN state IS NULL THEN 1 ELSE 0 END ASC";

        return $this->db->fetchAll($sql, $params);
    }

    private function calculateShippingCost($zone, $totalWeight) {
        return $zone['shipping_cost_base'] + ($zone['shipping_cost_per_kg'] * max($totalWeight, 0.1));
    }

    private function calculateTotalWeight($items) {
        $skus = array_column($items, 'sku');
        if (empty($skus)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $rows = $this->db->fetchAll("SELECT sku, weight FROM products WHERE sku IN ($placeholders)", $skus);

        $weightMap = [];
        foreach ($rows as $row) {
            $weightMap[$row['sku']] = (float)$row['weight'];
        }

        $total = 0;
        foreach ($items as $item) {
            $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            $w = isset($weightMap[$item['sku']]) ? $weightMap[$item['sku']] : 0;
            $total += $w * $qty;
        }

        return $total;
    }

    /**
     * 获取仓库列表
     * 权限边界：内部实现仓库级筛选，确保列表和明细筛选逻辑一致
     */
    public function listWarehouses($status = 1, $scopeWarehouseCode = null) {
        $where = [];
        $params = [];

        if ($status !== null) {
            $where[] = "w.status = ?";
            $params[] = $status;
        }
        if (!empty($scopeWarehouseCode)) {
            $where[] = "w.warehouse_code = ?";
            $params[] = $scopeWarehouseCode;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT w.*,
                (SELECT COUNT(*) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as sku_count,
                (SELECT SUM(wi.quantity) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as total_stock
                FROM warehouses w {$whereSql}
                ORDER BY w.priority DESC, w.id ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * 获取单个仓库详情（用于校验一致性）
     */
    public function getWarehouseByCode($warehouseCode) {
        if (empty($warehouseCode)) {
            return null;
        }
        $sql = "SELECT w.*,
                (SELECT COUNT(*) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as sku_count,
                (SELECT SUM(wi.quantity) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as total_stock
                FROM warehouses w
                WHERE w.warehouse_code = ? LIMIT 1";
        return $this->db->fetchOne($sql, [$warehouseCode]);
    }

    /**
     * 获取仓库库存
     * 权限边界：内部先验证仓库是否符合权限范围，防止通过 ID 越权访问
     */
    public function getWarehouseInventory($warehouseId, $sku = null, $scopeWarehouseCode = null, &$permissionCheck = []) {
        $permissionCheck = [
            'passed' => true,
            'warehouse_found' => false,
            'in_scope' => true,
            'scope_warehouse_code' => $scopeWarehouseCode,
            'actual_warehouse_code' => null,
            'message' => '',
        ];

        if (empty($warehouseId)) {
            $permissionCheck['passed'] = false;
            $permissionCheck['message'] = '缺少仓库ID参数';
            return [];
        }

        $warehouse = $this->db->fetchOne(
            "SELECT id, warehouse_code FROM warehouses WHERE id = ? LIMIT 1",
            [$warehouseId]
        );

        $permissionCheck['warehouse_found'] = !empty($warehouse);
        if (!$warehouse) {
            $permissionCheck['passed'] = false;
            $permissionCheck['message'] = '仓库不存在';
            return [];
        }

        $permissionCheck['actual_warehouse_code'] = $warehouse['warehouse_code'];

        if (!empty($scopeWarehouseCode) && $warehouse['warehouse_code'] !== $scopeWarehouseCode) {
            $permissionCheck['passed'] = false;
            $permissionCheck['in_scope'] = false;
            $permissionCheck['message'] = "无权访问仓库 [{$warehouse['warehouse_code']}]，当前仅可访问 [{$scopeWarehouseCode}]";
            return [];
        }

        $sql = "SELECT wi.*, p.name as product_name, p.weight, p.price, p.image_url
                FROM warehouse_inventories wi
                JOIN products p ON wi.product_id = p.id
                WHERE wi.warehouse_id = ?";
        $params = [$warehouseId];
        if ($sku) {
            $sql .= " AND wi.sku = ?";
            $params[] = $sku;
        }
        $sql .= " ORDER BY wi.id ASC";

        $permissionCheck['passed'] = true;
        $permissionCheck['message'] = '权限校验通过';
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * 获取单个仓库的全部信息（含库存，用于列表-明细一致性校验）
     */
    public function getWarehouseDetail($warehouseId, $scopeWarehouseCode = null, &$permissionCheck = []) {
        $permissionCheck = [
            'passed' => true,
            'warehouse_found' => false,
            'in_scope' => true,
            'scope_warehouse_code' => $scopeWarehouseCode,
            'actual_warehouse_code' => null,
            'message' => '',
        ];

        if (empty($warehouseId)) {
            $permissionCheck['passed'] = false;
            $permissionCheck['message'] = '缺少仓库ID参数';
            return null;
        }

        $warehouse = $this->db->fetchOne(
            "SELECT w.*,
                    (SELECT COUNT(*) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as sku_count,
                    (SELECT SUM(wi.quantity) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as total_stock
             FROM warehouses w WHERE w.id = ? LIMIT 1",
            [$warehouseId]
        );

        $permissionCheck['warehouse_found'] = !empty($warehouse);
        if (!$warehouse) {
            $permissionCheck['passed'] = false;
            $permissionCheck['message'] = '仓库不存在';
            return null;
        }

        $permissionCheck['actual_warehouse_code'] = $warehouse['warehouse_code'];

        if (!empty($scopeWarehouseCode) && $warehouse['warehouse_code'] !== $scopeWarehouseCode) {
            $permissionCheck['passed'] = false;
            $permissionCheck['in_scope'] = false;
            $permissionCheck['message'] = "无权访问仓库 [{$warehouse['warehouse_code']}]，当前仅可访问 [{$scopeWarehouseCode}]";
            return null;
        }

        $permissionCheck['passed'] = true;
        $permissionCheck['message'] = '权限校验通过';
        return $warehouse;
    }
}
