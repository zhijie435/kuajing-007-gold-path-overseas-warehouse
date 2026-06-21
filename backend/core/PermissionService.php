<?php
require_once __DIR__ . '/../core/Database.php';

class PermissionService {
    private $db;
    private $config;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    public static function getClientIp() {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip ?: '0.0.0.0';
    }

    public static function generateAuditNo($prefix = 'AUD') {
        return $prefix . date('YmdHis') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function authenticateApiClient($clientKey, $apiSecret) {
        $result = [
            'success' => false,
            'client' => null,
            'error_code' => null,
            'error_message' => null,
            'checks' => [],
        ];

        if (empty($clientKey) || empty($apiSecret)) {
            $result['error_code'] = 'MISSING_CREDENTIALS';
            $result['error_message'] = '缺少客户端标识或密钥';
            $result['checks']['credentials'] = false;
            return $result;
        }

        $client = $this->db->fetchOne(
            "SELECT * FROM api_clients WHERE client_key = ? LIMIT 1",
            [$clientKey]
        );

        $result['checks']['client_exists'] = !empty($client);

        if (!$client) {
            $result['error_code'] = 'CLIENT_NOT_FOUND';
            $result['error_message'] = '客户端不存在';
            return $result;
        }

        $result['checks']['status'] = (int)$client['status'] === 1;
        if ((int)$client['status'] !== 1) {
            $result['error_code'] = 'CLIENT_DISABLED';
            $result['error_message'] = '客户端已被禁用';
            return $result;
        }

        $result['checks']['secret'] = hash_equals($client['api_secret'], $apiSecret);
        if (!hash_equals($client['api_secret'], $apiSecret)) {
            $result['error_code'] = 'INVALID_SECRET';
            $result['error_message'] = 'API密钥不正确';
            return $result;
        }

        if (!empty($client['expires_at'])) {
            $result['checks']['expires'] = strtotime($client['expires_at']) > time();
            if (strtotime($client['expires_at']) <= time()) {
                $result['error_code'] = 'CLIENT_EXPIRED';
                $result['error_message'] = '客户端凭证已过期';
                return $result;
            }
        } else {
            $result['checks']['expires'] = true;
        }

        $clientIp = self::getClientIp();
        $result['checks']['ip_whitelist'] = $this->checkIpWhitelist($client['allowed_ips'] ?? '', $clientIp);
        if (!$result['checks']['ip_whitelist']) {
            $result['error_code'] = 'IP_NOT_ALLOWED';
            $result['error_message'] = "IP [{$clientIp}] 不在白名单中";
            return $result;
        }

        $client['permissions_array'] = !empty($client['permissions'])
            ? json_decode($client['permissions'], true) ?: []
            : [];

        $result['success'] = true;
        $result['client'] = $client;
        return $result;
    }

    public function checkPermission($client, $permission) {
        if (empty($client) || empty($client['permissions_array'])) {
            return false;
        }
        $permissions = $client['permissions_array'];
        if (in_array('*', $permissions, true)) {
            return true;
        }
        if (in_array($permission, $permissions, true)) {
            return true;
        }
        $parts = explode(':', $permission);
        if (count($parts) >= 2 && in_array($parts[0] . ':*', $permissions, true)) {
            return true;
        }
        return false;
    }

    public function checkIpWhitelist($whitelistStr, $ip) {
        if (empty($whitelistStr)) {
            return true;
        }
        $ips = array_filter(array_map('trim', explode(',', $whitelistStr)));
        if (empty($ips)) {
            return true;
        }
        foreach ($ips as $allowedIp) {
            if ($this->ipMatches($allowedIp, $ip)) {
                return true;
            }
        }
        return false;
    }

    private function ipMatches($pattern, $ip) {
        if ($pattern === $ip) {
            return true;
        }
        if (strpos($pattern, '/') !== false) {
            return $this->ipInCidr($ip, $pattern);
        }
        if (strpos($pattern, '*') !== false) {
            $patternRegex = '/^' . str_replace(['.', '*'], ['\\.', '\\d+'], $pattern) . '$/';
            return (bool)preg_match($patternRegex, $ip);
        }
        return false;
    }

    private function ipInCidr($ip, $cidr) {
        list($subnet, $maskBits) = explode('/', $cidr, 2);
        $maskBits = (int)$maskBits;
        if (strpos($ip, ':') !== false && strpos($subnet, ':') !== false) {
            return $this->ipv6InCidr($ip, $subnet, $maskBits);
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($maskBits === 0) {
            return true;
        }
        $mask = -1 << (32 - $maskBits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function ipv6InCidr($ip, $subnet, $maskBits) {
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        $hex = str_split(bin2hex($subnetBin), 2);
        $maskHex = '';
        $remainingBits = $maskBits;
        foreach ($hex as $byte) {
            if ($remainingBits >= 8) {
                $maskHex .= 'ff';
                $remainingBits -= 8;
            } elseif ($remainingBits > 0) {
                $mask = 0xff << (8 - $remainingBits);
                $maskHex .= sprintf('%02x', $mask & 0xff);
                $remainingBits = 0;
            } else {
                $maskHex .= '00';
            }
        }
        $maskBin = hex2bin($maskHex);
        return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
    }

    public function authenticateByHeaders() {
        $clientKey = Request::getHeader('X-Client-Key') ?: ($_SERVER['HTTP_X_CLIENT_KEY'] ?? '');
        $apiSecret = Request::getHeader('X-API-Secret') ?: ($_SERVER['HTTP_X_API_SECRET'] ?? '');
        if (empty($clientKey) || empty($apiSecret)) {
            $authHeader = Request::getHeader('Authorization') ?: ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (stripos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7);
                $parts = explode(':', $token, 2);
                if (count($parts) === 2) {
                    $clientKey = $parts[0];
                    $apiSecret = $parts[1];
                }
            } elseif (stripos($authHeader, 'Basic ') === 0) {
                $decoded = base64_decode(substr($authHeader, 6), true);
                if ($decoded !== false && strpos($decoded, ':') !== false) {
                    list($clientKey, $apiSecret) = explode(':', $decoded, 2);
                }
            }
        }
        if (empty($clientKey) && isset($_GET['client_key'])) {
            $clientKey = $_GET['client_key'];
            $apiSecret = $_GET['api_secret'] ?? '';
        }
        return $this->authenticateApiClient($clientKey, $apiSecret);
    }

    public function verifyFulfillmentCallbackToken($warehouseCode, $token, &$details = []) {
        $result = [
            'success' => false,
            'token_verified' => false,
            'warehouse_found' => false,
            'warehouse_status_ok' => false,
            'ip_verified' => true,
            'error_message' => '',
        ];

        $warehouse = $this->db->fetchOne(
            "SELECT * FROM warehouses WHERE warehouse_code = ? LIMIT 1",
            [$warehouseCode]
        );

        $result['warehouse_found'] = !empty($warehouse);
        $details['warehouse_found'] = $result['warehouse_found'];

        if (!$warehouse) {
            $result['error_message'] = "仓库编码 [{$warehouseCode}] 不存在";
            return $result;
        }

        $result['warehouse_status_ok'] = (int)$warehouse['status'] === 1;
        $details['warehouse_status'] = (int)$warehouse['status'];
        if (!$result['warehouse_status_ok']) {
            $result['error_message'] = "仓库 [{$warehouseCode}] 已停用";
            return $result;
        }

        if (!empty($warehouse['callback_secret'])) {
            $result['token_verified'] = hash_equals($warehouse['callback_secret'], $token);
            $details['token_source'] = 'warehouse_specific';
        } else {
            $result['token_verified'] = hash_equals($this->config['callback']['token'], $token);
            $details['token_source'] = 'global';
        }
        $details['token_verified'] = $result['token_verified'];

        if (!$result['token_verified']) {
            $result['error_message'] = '回调Token验证失败';
            return $result;
        }

        $clientIp = self::getClientIp();
        $result['ip_verified'] = $this->checkIpWhitelist($warehouse['callback_allowed_ips'] ?? '', $clientIp);
        $details['callback_allowed_ips'] = $warehouse['callback_allowed_ips'] ?? '';
        $details['client_ip'] = $clientIp;
        $details['ip_verified'] = $result['ip_verified'];

        if (!$result['ip_verified']) {
            $result['error_message'] = "IP [{$clientIp}] 不在仓库回调白名单中";
            return $result;
        }

        $result['success'] = true;
        return $result;
    }

    public function verifyWarehouseOrderMatch($warehouseCode, $orderNo, &$details = []) {
        $result = [
            'success' => false,
            'order_found' => false,
            'warehouse_matched' => false,
            'order_warehouse_code' => null,
            'error_message' => '',
        ];

        $order = $this->db->fetchOne(
            "SELECT id, order_no, warehouse_code, warehouse_id, order_status FROM orders WHERE order_no = ? LIMIT 1",
            [$orderNo]
        );

        $result['order_found'] = !empty($order);
        $details['order_found'] = $result['order_found'];

        if (!$order) {
            $result['error_message'] = "订单 [{$orderNo}] 不存在";
            return $result;
        }

        $result['order_warehouse_code'] = $order['warehouse_code'];
        $details['order_warehouse_code'] = $order['warehouse_code'];
        $details['order_status'] = $order['order_status'];
        $details['callback_warehouse_code'] = $warehouseCode;

        if (empty($order['warehouse_code'])) {
            $result['warehouse_matched'] = true;
            $result['error_message'] = '订单尚未分配仓库，允许绑定';
            $details['match_note'] = '订单未分配仓库，跳过一致性校验';
        } else {
            $result['warehouse_matched'] = $order['warehouse_code'] === $warehouseCode;
            if (!$result['warehouse_matched']) {
                $result['error_message'] = "仓库不匹配：订单 [{$orderNo}] 属于仓库 [{$order['warehouse_code']}]，当前回调仓库为 [{$warehouseCode}]";
                $details['match_note'] = '仓库编码不一致，权限边界拦截';
                return $result;
            }
            $details['match_note'] = '仓库编码一致，校验通过';
        }

        $result['success'] = true;
        return $result;
    }
}
