<?php
class MockDatabase {
    private static $instance = null;
    private $tables = [];
    private $autoIncrement = [];
    private $transactionDepth = 0;
    private $transactionData = [];

    private function __construct() {
        $this->initTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance() {
        self::$instance = null;
    }

    private function initTables() {
        $this->tables = [
            'warehouses' => [],
            'warehouse_shipping_zones' => [],
            'products' => [],
            'warehouse_inventories' => [],
            'orders' => [],
            'order_items' => [],
            'fulfillment_tracks' => [],
            'warehouse_callback_logs' => [],
            'fulfillment_callback_audit_logs' => [],
            'warehouse_route_audit_logs' => [],
            'api_permissions' => [],
            'audit_logs' => [],
        ];
        $this->autoIncrement = array_fill_keys(array_keys($this->tables), 1);
    }

    public function getConnection() {
        return null;
    }

    public function query($sql, $params = []) {
        return new MockStatement($this, $sql, $params);
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function insert($table, $data) {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
            $this->autoIncrement[$table] = 1;
        }
        $id = $this->autoIncrement[$table]++;
        $row = array_merge(['id' => $id], $data);
        $this->tables[$table][] = $row;
        return $id;
    }

    public function update($table, $data, $where, $whereParams = []) {
        if (!isset($this->tables[$table])) {
            return 0;
        }
        $count = 0;
        foreach ($this->tables[$table] as &$row) {
            if ($this->matchWhere($row, $where, $whereParams, $table)) {
                foreach ($data as $key => $value) {
                    $row[$key] = $value;
                }
                $count++;
            }
        }
        return $count;
    }

    private function matchWhere($row, $where, $whereParams, $table) {
        if (empty($where)) {
            return true;
        }
        if (is_string($where)) {
            if (preg_match('/^(\w+)\s*=\s*\?$/', $where, $matches)) {
                $field = $matches[1];
                return isset($row[$field]) && isset($whereParams[0]) && $row[$field] == $whereParams[0];
            }
            if (preg_match('/^(\w+)\s*=\s*:(\w+)$/', $where, $matches)) {
                $field = $matches[1];
                $paramKey = ':' . $matches[2];
                return isset($row[$field]) && isset($whereParams[$paramKey]) && $row[$field] == $whereParams[$paramKey];
            }
        }
        return true;
    }

    public function beginTransaction() {
        $this->transactionDepth++;
        if ($this->transactionDepth === 1) {
            $this->transactionData = $this->deepCopy($this->tables);
        }
        return true;
    }

    public function commit() {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
        }
        if ($this->transactionDepth === 0) {
            $this->transactionData = [];
        }
        return true;
    }

    public function rollBack() {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
        }
        if ($this->transactionDepth === 0 && !empty($this->transactionData)) {
            $this->tables = $this->transactionData;
            $this->transactionData = [];
        }
        return true;
    }

    private function deepCopy($array) {
        return array_map(function($item) {
            return is_array($item) ? $this->deepCopy($item) : $item;
        }, $array);
    }

    public function getTableData($table) {
        return $this->tables[$table] ?? [];
    }

    public function seedWarehouses($data) {
        foreach ($data as $row) {
            $this->insert('warehouses', $row);
        }
    }

    public function seedShippingZones($data) {
        foreach ($data as $row) {
            $this->insert('warehouse_shipping_zones', $row);
        }
    }

    public function seedProducts($data) {
        foreach ($data as $row) {
            $this->insert('products', $row);
        }
    }

    public function seedInventories($data) {
        foreach ($data as $row) {
            $this->insert('warehouse_inventories', $row);
        }
    }

    public function seedOrder($data) {
        return $this->insert('orders', $data);
    }
}

class MockStatement {
    private $db;
    private $sql;
    private $params;
    private $result = [];
    private $rowCount = 0;

    public function __construct($db, $sql, $params) {
        $this->db = $db;
        $this->sql = $sql;
        $this->params = $params;
        $this->execute();
    }

    private function execute() {
        $sql = trim($this->sql);
        
        if (preg_match('/^UPDATE\s+`?(\w+)`?\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $sql, $matches)) {
            $table = $matches[1];
            $setPart = $matches[2];
            $wherePart = $matches[3];
            $this->handleUpdate($table, $setPart, $wherePart);
        }
        elseif (preg_match('/^SELECT\s+(.+?)\s+FROM\s+/is', $sql)) {
            $this->handleSelect($sql);
        }
        else {
            $this->result = [];
            $this->rowCount = 0;
        }
    }

    private function handleUpdate($table, $setPart, $wherePart) {
        $tableData = $this->db->getTableData($table);
        if (empty($tableData)) {
            $this->rowCount = 0;
            return;
        }

        $setFields = $this->parseSetClause($setPart);
        $count = 0;

        foreach ($tableData as &$row) {
            if ($this->rowMatchesWhere($row, $wherePart)) {
                foreach ($setFields as $key => $value) {
                    $row[$key] = $value;
                }
                $count++;
            }
        }

        $this->db->update($table, $setFields, $wherePart, $this->params);
        $this->rowCount = $count;
    }

    private function parseSetClause($setPart) {
        $fields = [];
        $setPart = preg_replace('/`/', '', $setPart);
        
        if (preg_match_all('/(\w+)\s*=\s*:set_(\w+)/i', $setPart, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field = $match[1];
                $paramKey = ':set_' . $match[2];
                $fields[$field] = $this->params[$paramKey] ?? null;
            }
        }
        
        return $fields;
    }

    private function handleSelect($sql) {
        $sql = preg_replace('/`/', '', $sql);
        $tables = $this->extractTables($sql);
        $whereConditions = $this->extractWhereConditions($sql);
        $joins = $this->extractJoins($sql);
        
        $mainTable = $tables[0]['name'] ?? '';
        $tableData = $this->db->getTableData($mainTable);
        
        if (!empty($joins)) {
            $tableData = $this->applyJoins($tableData, $joins, $mainTable);
        }
        
        $filtered = [];
        foreach ($tableData as $row) {
            if ($this->rowMatches($row, $whereConditions)) {
                $filtered[] = $row;
            }
        }
        
        $this->applyOrderBy($sql, $filtered);
        $this->applyLimit($sql, $filtered);
        
        $this->result = $filtered;
        $this->rowCount = count($filtered);
    }

    private function extractTables($sql) {
        $tables = [];
        if (preg_match('/FROM\s+(\w+)(?:\s+(\w+))?/i', $sql, $matches)) {
            $tables[] = [
                'name' => $matches[1],
                'alias' => $matches[2] ?? $matches[1]
            ];
        }
        return $tables;
    }

    private function extractJoins($sql) {
        $joins = [];
        if (preg_match_all('/JOIN\s+(\w+)(?:\s+(\w+))?\s+ON\s+(.+?)(?:\s+WHERE|\s+ORDER|\s+GROUP|\s+LIMIT|$)/is', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $joins[] = [
                    'table' => $match[1],
                    'alias' => $match[2] ?? $match[1],
                    'condition' => trim($match[3])
                ];
            }
        }
        return $joins;
    }

    private function applyJoins($data, $joins, $mainTable) {
        foreach ($joins as $join) {
            $joinTable = $join['table'];
            $joinData = $this->db->getTableData($joinTable);
            
            if (empty($joinData)) {
                foreach ($data as &$row) {
                    foreach ($joinData[0] ?? [] as $key => $value) {
                        if (!isset($row[$key])) {
                            $row[$key] = null;
                        }
                    }
                }
                continue;
            }
            
            $condition = $join['condition'];
            $newData = [];
            
            foreach ($data as $leftRow) {
                $matched = false;
                foreach ($joinData as $rightRow) {
                    if ($this->matchJoinCondition($leftRow, $rightRow, $condition)) {
                        $newData[] = array_merge($leftRow, $rightRow);
                        $matched = true;
                    }
                }
                if (!$matched) {
                    $rightRowTemplate = [];
                    foreach ($joinData[0] as $key => $value) {
                        $rightRowTemplate[$key] = null;
                    }
                    $newData[] = array_merge($leftRow, $rightRowTemplate);
                }
            }
            
            $data = $newData;
        }
        return $data;
    }

    private function matchJoinCondition($leftRow, $rightRow, $condition) {
        if (preg_match('/(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)/i', $condition, $matches)) {
            $leftField = $matches[2];
            $rightField = $matches[4];
            return ($leftRow[$leftField] ?? null) == ($rightRow[$rightField] ?? null);
        }
        return true;
    }

    private function extractWhereConditions($sql) {
        $conditions = [];
        if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+GROUP\s+BY|\s+LIMIT|$)/is', $sql, $matches)) {
            $whereStr = $matches[1];
            $parts = preg_split('/\s+AND\s+/i', $whereStr);
            foreach ($parts as $part) {
                $conditions[] = trim($part);
            }
        }
        return $conditions;
    }

    private function rowMatches($row, $conditions) {
        foreach ($conditions as $condition) {
            if ($condition === '1=1') {
                continue;
            }
            
            if (preg_match('/^(\w+)\s*=\s*\?$/', $condition, $matches)) {
                $field = $matches[1];
                $value = array_shift($this->params);
                if (($row[$field] ?? null) != $value) {
                    array_unshift($this->params, $value);
                    return false;
                }
            }
            elseif (preg_match('/^(\w+)\s*=\s*:(\w+)$/', $condition, $matches)) {
                $field = $matches[1];
                $paramKey = ':' . $matches[2];
                $value = $this->params[$paramKey] ?? null;
                if (($row[$field] ?? null) != $value) {
                    return false;
                }
            }
            elseif (preg_match('/^(\w+)\s+IN\s*\((.+)\)$/i', $condition, $matches)) {
                $field = $matches[1];
                $inStr = $matches[2];
                $placeholders = explode(',', $inStr);
                $values = [];
                foreach ($placeholders as $ph) {
                    $values[] = array_shift($this->params);
                }
                if (!in_array($row[$field] ?? null, $values)) {
                    foreach (array_reverse($values) as $v) {
                        array_unshift($this->params, $v);
                    }
                    return false;
                }
            }
            elseif (preg_match('/^(\w+)\s+LIKE\s+\?$/i', $condition, $matches)) {
                $field = $matches[1];
                $pattern = array_shift($this->params);
                $regex = str_replace(['%', '_'], ['.*', '.'], $pattern);
                if (!preg_match('/^' . $regex . '$/i', $row[$field] ?? '')) {
                    array_unshift($this->params, $pattern);
                    return false;
                }
            }
            elseif (preg_match('/^(\w+)\s*>=\s*\?$/', $condition, $matches)) {
                $field = $matches[1];
                $value = array_shift($this->params);
                if (($row[$field] ?? 0) < $value) {
                    array_unshift($this->params, $value);
                    return false;
                }
            }
            elseif (preg_match('/^(\w+)\s*<=\s*\?$/', $condition, $matches)) {
                $field = $matches[1];
                $value = array_shift($this->params);
                if (($row[$field] ?? 0) > $value) {
                    array_unshift($this->params, $value);
                    return false;
                }
            }
        }
        return true;
    }

    private function rowMatchesWhere($row, $wherePart) {
        if (empty($wherePart)) {
            return true;
        }
        $conditions = preg_split('/\s+AND\s+/i', $wherePart);
        foreach ($conditions as $condition) {
            $condition = trim($condition);
            if ($condition === '1=1') {
                continue;
            }
            if (preg_match('/^(\w+)\s*=\s*\?$/', $condition, $matches)) {
                $field = $matches[1];
                $value = $this->params[0] ?? null;
                if (($row[$field] ?? null) != $value) {
                    return false;
                }
            }
        }
        return true;
    }

    private function applyOrderBy($sql, &$data) {
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|$)/is', $sql, $matches)) {
            $orderStr = trim($matches[1]);
            $parts = explode(',', $orderStr);
            usort($data, function($a, $b) use ($parts) {
                foreach ($parts as $part) {
                    $part = trim($part);
                    $desc = false;
                    if (stripos($part, ' DESC') !== false) {
                        $desc = true;
                        $part = trim(str_ireplace(' DESC', '', $part));
                    } else {
                        $part = trim(str_ireplace(' ASC', '', $part));
                    }
                    $valA = $a[$part] ?? null;
                    $valB = $b[$part] ?? null;
                    $cmp = $valA <=> $valB;
                    if ($cmp !== 0) {
                        return $desc ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
        }
    }

    private function applyLimit($sql, &$data) {
        if (preg_match('/LIMIT\s+(\d+)(?:\s*,\s*(\d+))?/i', $sql, $matches)) {
            if (isset($matches[2])) {
                $offset = (int)$matches[1];
                $limit = (int)$matches[2];
            } else {
                $offset = 0;
                $limit = (int)$matches[1];
            }
            $data = array_slice($data, $offset, $limit);
        }
    }

    public function fetchAll() {
        return $this->result;
    }

    public function fetch() {
        return $this->result[0] ?? false;
    }

    public function rowCount() {
        return $this->rowCount;
    }
}
