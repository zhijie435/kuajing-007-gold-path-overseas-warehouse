<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/AuditService.php';
require_once __DIR__ . '/../core/PermissionService.php';

class WarehouseRouter {
    private $db;
    private $auditService;
    private $auditContext;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auditService = new AuditService();
        $this->auditContext = [
            'start_time' => microtime(true),
            'client_key' => null,
            'client_ip' => PermissionService::getClientIp(),
        ];
    }

    public function setAuditContext($context) {
        $this->auditContext = array_merge($this->auditContext, $context);
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

        $stockCheck = $this->checkAllWarehousesStock($skus, $skuQuantities);
        if (!$stockCheck['all_available']) {
            return $this->returnWithAudit([
                'success' => false,
                'message' => $stockCheck['message'],
                'error_type' => 'INSUFFICIENT_STOCK',
                'stock_details' => $stockCheck['details'],
                'missing_skus' => $stockCheck['missing_skus'],
            ], $items, $shippingCountry, $shippingState);
        }

        $candidateWarehouses = $this->findWarehousesWithAllStock($skus, $skuQuantities);

        if (empty($candidateWarehouses)) {
            return $this->returnWithAudit([
                'success' => false,
                'message' => '没有仓库同时拥有所有商品的充足库存，请尝试分拆订单或减少数量',
                'error_type' => 'NO_WAREHOUSE_WITH_ALL_STOCK',
                'stock_details' => $stockCheck['details'],
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
                'message' => '候选仓库（' . implode('、', $warehouseNames) . '）均不支持配送到 ' . $shippingCountry . ($shippingState ? '/' . $shippingState : ''),
                'error_type' => 'NO_SHIPPING_ZONE',
                'shipping_country' => $shippingCountry,
                'shipping_state' => $shippingState,
                'candidate_warehouses' => $warehouseNames,
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
        ];

        return $this->writeAuditForResult($result, $items, $shippingCountry, $shippingState);
    }

    /**
     * 检查所有仓库的库存情况，返回详细的库存信息
     */
    private function checkAllWarehousesStock($skus, $skuQuantities) {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $sql = "SELECT wi.warehouse_id, wi.sku, wi.quantity, w.warehouse_code, w.warehouse_name
                FROM warehouse_inventories wi
                JOIN warehouses w ON wi.warehouse_id = w.id
                WHERE wi.sku IN ($placeholders) AND w.status = 1";

        $rows = $this->db->fetchAll($sql, $skus);

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
                $missingSkus[] = "{$sku}(需要{$requiredQty}，总库存{$totalStock})";
            }
        }

        $allAvailable = empty($missingSkus);
        $message = '';
        if (!$allAvailable) {
            $message = '以下商品库存不足：' . implode('；', $missingSkus);
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
     */
    private function findWarehousesWithAllStock($skus, $skuQuantities) {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $sql = "SELECT wi.warehouse_id, wi.sku, wi.quantity, w.warehouse_code, w.warehouse_name, w.country, w.city, w.priority
                FROM warehouse_inventories wi
                JOIN warehouses w ON wi.warehouse_id = w.id
                WHERE wi.sku IN ($placeholders) AND w.status = 1 AND wi.quantity > 0";

        $rows = $this->db->fetchAll($sql, $skus);

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

    public function listWarehouses($status = 1) {
        $sql = "SELECT w.*,
                (SELECT COUNT(*) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as sku_count,
                (SELECT SUM(wi.quantity) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as total_stock
                FROM warehouses w";
        if ($status !== null) {
            $sql .= " WHERE w.status = ?";
            return $this->db->fetchAll($sql, [$status]);
        }
        return $this->db->fetchAll($sql);
    }

    public function getWarehouseInventory($warehouseId, $sku = null) {
        $sql = "SELECT wi.*, p.name as product_name, p.weight, p.price, p.image_url
                FROM warehouse_inventories wi
                JOIN products p ON wi.product_id = p.id
                WHERE wi.warehouse_id = ?";
        $params = [$warehouseId];
        if ($sku) {
            $sql .= " AND wi.sku = ?";
            $params[] = $sku;
        }
        return $this->db->fetchAll($sql, $params);
    }
}
