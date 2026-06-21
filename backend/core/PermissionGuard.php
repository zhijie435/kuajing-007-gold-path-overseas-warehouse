<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Request.php';

class PermissionGuard {
    private $db;
    private $currentRole;
    private $currentWarehouseCode;
    private $currentUserId;

    private $rolePermissions = [
        'admin' => [
            'warehouse:route',
            'warehouse:list',
            'warehouse:inventory',
            'fulfillment:callback',
            'fulfillment:callback_logs',
        ],
        'warehouse_operator' => [
            'warehouse:route',
            'warehouse:inventory',
            'fulfillment:callback',
        ],
        'viewer' => [
            'warehouse:list',
            'warehouse:inventory',
            'fulfillment:callback_logs',
        ],
    ];

    private $permissionMap = [
        'warehouse_route' => 'warehouse:route',
        'warehouse_list' => 'warehouse:list',
        'warehouse_inventory' => 'warehouse:inventory',
        'fulfillment_callback' => 'fulfillment:callback',
        'fulfillment_callback_logs' => 'fulfillment:callback_logs',
    ];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->initCurrentIdentity();
    }

    private function initCurrentIdentity() {
        $this->currentRole = Request::getHeader('X-Role') ?: ($_SERVER['HTTP_X_ROLE'] ?? 'viewer');
        $this->currentWarehouseCode = Request::getHeader('X-Warehouse-Code') ?: ($_SERVER['HTTP_X_WAREHOUSE_CODE'] ?? null);
        $this->currentUserId = Request::getHeader('X-User-Id') ?: ($_SERVER['HTTP_X_USER_ID'] ?? 'anonymous');

        $validRoles = array_keys($this->rolePermissions);
        if (!in_array($this->currentRole, $validRoles, true)) {
            $this->currentRole = 'viewer';
        }
    }

    public function check($permissionKey) {
        $result = [
            'allowed' => false,
            'reason' => 'unknown',
            'role' => $this->currentRole,
            'message' => '无权限访问',
        ];

        $permission = $this->permissionMap[$permissionKey] ?? $permissionKey;

        if ($this->currentRole === 'admin') {
            $result['allowed'] = true;
            $result['reason'] = 'admin_role';
            $result['message'] = '管理员权限验证通过';
            return $result;
        }

        $permissions = $this->rolePermissions[$this->currentRole] ?? [];

        if (in_array($permission, $permissions, true)) {
            $result['allowed'] = true;
            $result['reason'] = 'role_permission_granted';
            $result['message'] = '权限验证通过';
            return $result;
        }

        $result['reason'] = 'permission_not_found';
        $result['message'] = "角色 [{$this->currentRole}] 无权限 [{$permission}]";
        return $result;
    }

    public function validateCallbackWarehouse($warehouseCode) {
        $result = [
            'allowed' => false,
            'reason' => 'unknown',
            'message' => '仓库权限验证失败',
        ];

        if (empty($warehouseCode)) {
            $result['reason'] = 'empty_warehouse_code';
            $result['message'] = '缺少仓库编码';
            return $result;
        }

        if ($this->currentRole === 'admin') {
            $result['allowed'] = true;
            $result['reason'] = 'admin_bypass';
            $result['message'] = '管理员权限跳过仓库验证';
            return $result;
        }

        if ($this->currentRole === 'warehouse_operator') {
            if (empty($this->currentWarehouseCode)) {
                $result['reason'] = 'no_warehouse_assigned';
                $result['message'] = '仓库操作员未分配仓库';
                return $result;
            }

            if ($this->currentWarehouseCode === $warehouseCode) {
                $result['allowed'] = true;
                $result['reason'] = 'warehouse_matched';
                $result['message'] = '仓库权限验证通过';
                return $result;
            }

            $result['reason'] = 'warehouse_mismatch';
            $result['message'] = "无权操作仓库 [{$warehouseCode}]，当前仅可操作 [{$this->currentWarehouseCode}]";
            return $result;
        }

        $result['reason'] = 'role_not_allowed';
        $result['message'] = "角色 [{$this->currentRole}] 无权执行回调操作";
        return $result;
    }

    public function validateWarehouseAccess($warehouseId) {
        $result = [
            'allowed' => false,
            'reason' => 'unknown',
            'message' => '仓库访问权限验证失败',
        ];

        if (empty($warehouseId)) {
            $result['reason'] = 'empty_warehouse_id';
            $result['message'] = '缺少仓库ID';
            return $result;
        }

        if ($this->currentRole === 'admin' || $this->currentRole === 'viewer') {
            $result['allowed'] = true;
            $result['reason'] = 'role_bypass';
            $result['message'] = '角色权限跳过仓库验证';
            return $result;
        }

        if ($this->currentRole === 'warehouse_operator') {
            if (empty($this->currentWarehouseCode)) {
                $result['reason'] = 'no_warehouse_assigned';
                $result['message'] = '仓库操作员未分配仓库';
                return $result;
            }

            $warehouse = $this->db->fetchOne(
                "SELECT id, warehouse_code FROM warehouses WHERE id = ? LIMIT 1",
                [$warehouseId]
            );

            if (!$warehouse) {
                $result['reason'] = 'warehouse_not_found';
                $result['message'] = '仓库不存在';
                return $result;
            }

            if ($warehouse['warehouse_code'] === $this->currentWarehouseCode) {
                $result['allowed'] = true;
                $result['reason'] = 'warehouse_matched';
                $result['message'] = '仓库访问权限验证通过';
                return $result;
            }

            $result['reason'] = 'warehouse_mismatch';
            $result['message'] = "无权访问仓库 [{$warehouse['warehouse_code']}]，当前仅可访问 [{$this->currentWarehouseCode}]";
            return $result;
        }

        $result['reason'] = 'role_not_allowed';
        $result['message'] = "角色 [{$this->currentRole}] 无权访问仓库信息";
        return $result;
    }

    public function filterWarehouseList($warehouses) {
        if ($this->currentRole === 'admin' || $this->currentRole === 'viewer') {
            return $warehouses;
        }

        if ($this->currentRole === 'warehouse_operator' && !empty($this->currentWarehouseCode)) {
            return array_values(array_filter($warehouses, function ($w) {
                return $w['warehouse_code'] === $this->currentWarehouseCode;
            }));
        }

        return [];
    }

    public function getCurrentRole() {
        return $this->currentRole;
    }

    public function getCurrentWarehouseCode() {
        return $this->currentWarehouseCode;
    }

    public function getCurrentUserId() {
        return $this->currentUserId;
    }
}
