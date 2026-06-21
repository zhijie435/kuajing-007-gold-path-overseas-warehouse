<?php
require_once __DIR__ . '/../core/Database.php';

class WarehouseRouter {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * 路由决策：根据商品、收货地址选择最优仓库
     * 算法优先级：1.库存充足 2.配送区域匹配 3.运费最低 4.时效最快 5.优先级最高
     */
    public function route($items, $shippingCountry, $shippingState = null) {
        if (empty($items) || empty($shippingCountry)) {
            return [
                'success' => false,
                'message' => '商品信息和收货国家不能为空',
            ];
        }

        $skus = array_column($items, 'sku');
        $skuQuantities = [];
        foreach ($items as $item) {
            $skuQuantities[$item['sku']] = isset($skuQuantities[$item['sku']])
                ? $skuQuantities[$item['sku']] + ($item['quantity'] ?? 1)
                : ($item['quantity'] ?? 1);
        }

        $candidateWarehouses = $this->findWarehousesWithAllStock($skus, $skuQuantities);

        if (empty($candidateWarehouses)) {
            return [
                'success' => false,
                'message' => '没有仓库同时拥有所有商品的充足库存',
            ];
        }

        $warehouseIds = array_keys($candidateWarehouses);

        $shippingZones = $this->getMatchingShippingZones($warehouseIds, $shippingCountry, $shippingState);

        if (empty($shippingZones)) {
            return [
                'success' => false,
                'message' => '候选仓库均不支持配送到该地区',
            ];
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
            return [
                'success' => false,
                'message' => '没有找到符合条件的仓库',
            ];
        }

        $best = $scored[0];

        return [
            'success' => true,
            'selected_warehouse' => $best,
            'alternatives' => array_slice($scored, 1, 3),
            'total_weight' => round($totalWeight, 2),
            'shipping_country' => $shippingCountry,
            'shipping_state' => $shippingState,
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
