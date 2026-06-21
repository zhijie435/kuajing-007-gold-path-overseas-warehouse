# 海外仓一件代发下单系统

基于 Vue 3 + PHP 开发的海外仓一件代发下单链路管理系统，实现智能仓库路由和履约全流程回传。

## 功能特性

### 1. 智能仓库路由
- 多维度选择最优仓库：库存充足 → 配送区域匹配 → 运费最低 → 时效最快 → 优先级最高
- 支持按国家/州精准匹配配送区域
- 返回推荐仓库 + 备选仓库列表
- 实时计算运费、配送时效、预计送达日期

### 2. 订单管理
- 创建订单：自动路由 + 库存锁定 + WMS推送一体化
- 订单查询：多条件筛选、分页
- 订单详情：商品明细、收件信息、履约轨迹
- 订单取消：自动返还库存

### 3. 履约回传
- 支持 7 类回调事件：接单、拣货、打包、发货、签收、异常、取消
- Token 安全校验
- 完整回调日志，可追溯
- 自动更新订单状态和物流信息

### 4. 数据管理
- 仓库管理（查看仓库信息、库存分布）
- 商品管理（SKU、价格、库存分布）
- 履约追踪时间线

## 项目结构

```
003-内容审核标注平台/
├── backend/                          # PHP 后端
│   ├── api/                          # API 入口
│   │   ├── warehouse.php             # 仓库/路由接口
│   │   ├── orders.php                # 订单接口
│   │   ├── fulfillment.php           # 履约回调接口
│   │   └── products.php              # 商品接口
│   ├── config/
│   │   └── config.php                # 系统配置
│   ├── core/                         # 核心服务类
│   │   ├── Database.php              # 数据库封装
│   │   ├── Response.php              # 响应输出
│   │   ├── Request.php               # 请求处理
│   │   ├── OrderNoGenerator.php      # 订单号生成
│   │   ├── WarehouseRouter.php       # ★ 仓库路由核心算法
│   │   ├── OrderService.php          # ★ 订单服务（创建/查询/取消）
│   │   └── FulfillmentCallbackService.php  # ★ 履约回调处理
│   ├── sql/
│   │   └── database.sql              # 数据库初始化脚本
│   ├── index.php                     # 统一入口
│   └── .htaccess                     # URL 重写规则
│
└── frontend/                         # Vue 3 前端
    ├── src/
    │   ├── views/                    # 页面视图
    │   │   ├── Dashboard.vue         # 首页仪表盘
    │   │   ├── CreateOrder.vue       # 创建订单（含路由预览）
    │   │   ├── OrderList.vue         # 订单列表
    │   │   ├── OrderDetail.vue       # 订单详情+履约追踪
    │   │   ├── WarehouseList.vue     # 仓库列表
    │   │   └── ProductList.vue       # 商品列表
    │   ├── api/                      # API 封装
    │   ├── router/                   # 路由
    │   ├── App.vue
    │   └── main.js
    ├── index.html
    ├── vite.config.js
    └── package.json
```

## 核心业务流程

```
客户下单
   ↓
[创建订单] 校验商品 → 【仓库路由计算】 → 锁定库存
   ↓                                    ↓
[推送WMS] 生成仓库单号            路由算法：
   ↓                                    1. 筛选库存充足的仓库
[仓库接单] ←──── WMS回调               2. 匹配配送区域
   ↓                                    3. 综合评分（运费+时效+优先级）
[拣货开始] ←──── WMS回调               4. 返回最优+备选
   ↓
[打包开始] ←──── WMS回调
   ↓
[已发货] ←────── WMS回调（运单号、物流商）→ 扣减锁定库存
   ↓
[已签收] ←────── WMS回调 → 订单完成
```

## API 接口清单

### 仓库路由
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/warehouse/route` | 计算最优仓库路由 |
| GET | `/api/warehouses` | 获取仓库列表 |
| GET | `/api/warehouse/{id}/inventory` | 获取仓库库存 |

### 订单管理
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/orders` | 创建订单 |
| GET | `/api/orders` | 查询订单列表 |
| GET | `/api/orders/{order_no}` | 获取订单详情 |
| POST | `/api/orders/{order_no}/cancel` | 取消订单 |

### 履约回调
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/fulfillment/callback` | 仓库回调入口 |
| GET | `/api/fulfillment/callback/logs` | 查询回调日志 |

**回调 Header**: `X-Callback-Token: wh_callback_token_2024`

**回调类型 (callback_type)**:
- `ORDER_ACCEPT` - 仓库接单
- `PICKING_START` - 开始拣货
- `PACKING_START` - 开始打包
- `ORDER_SHIP` - 已发货 (需传 tracking_no, shipping_carrier)
- `ORDER_DELIVER` - 已签收 (需传 deliver_time)
- `ORDER_EXCEPTION` - 异常 (需传 exception_type, exception_message)

### 商品
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/products` | 商品列表 |
| GET | `/api/products/{sku}` | 商品详情（含各仓库存） |

## 数据库表

- `warehouses` - 仓库信息
- `warehouse_shipping_zones` - 仓库配送区域（时效/运费配置）
- `products` - 商品信息
- `warehouse_inventories` - 仓库商品库存（可用/锁定）
- `orders` - 订单主表
- `order_items` - 订单商品明细
- `fulfillment_tracks` - 履约追踪时间线
- `warehouse_callback_logs` - 回调日志

## 环境变量配置

### 1. 仓库路由环境变量

| 变量名 | 说明 | 默认值 | 示例 |
|--------|------|--------|------|
| `WAREHOUSE_ROUTING_STRATEGY` | 路由策略：`nearest`(最优综合评分)/`lowest_cost`(最低运费)/`fastest`(最快时效) | `nearest` | `nearest` |
| `WAREHOUSE_DEFAULT_SHIPPING_DAYS` | 默认配送天数（无配送区域配置时使用） | `5` | `5` |
| `WAREHOUSE_SCORE_COST_WEIGHT` | 运费评分权重（数值越大越优先考虑低运费） | `10` | `10` |
| `WAREHOUSE_SCORE_DAYS_WEIGHT` | 时效评分权重（数值越大越优先考虑快时效） | `5` | `5` |
| `WAREHOUSE_SCORE_PRIORITY_WEIGHT` | 仓库优先级评分权重 | `0.5` | `0.5` |

**配置位置**: `backend/config/config.php`

```php
'warehouse' => [
    'default_shipping_days' => getenv('WAREHOUSE_DEFAULT_SHIPPING_DAYS') ?: 5,
    'routing_strategy' => getenv('WAREHOUSE_ROUTING_STRATEGY') ?: 'nearest',
    'score_weights' => [
        'cost' => getenv('WAREHOUSE_SCORE_COST_WEIGHT') ?: 10,
        'days' => getenv('WAREHOUSE_SCORE_DAYS_WEIGHT') ?: 5,
        'priority' => getenv('WAREHOUSE_SCORE_PRIORITY_WEIGHT') ?: 0.5,
    ],
],
```

### 2. 履约回传环境变量

| 变量名 | 说明 | 默认值 | 示例 |
|--------|------|--------|------|
| `CALLBACK_TOKEN` | 履约回调 Token（WMS 回调时通过 Header `X-Callback-Token` 传递） | `wh_callback_token_2024` | `wh_callback_token_2024` |
| `CALLBACK_IP_WHITELIST` | 回调 IP 白名单（逗号分隔，留空表示不限制） | `` | `192.168.1.0/24,10.0.0.1` |
| `CALLBACK_RETRY_ENABLED` | 是否启用回调重试 | `false` | `true` |
| `CALLBACK_RETRY_MAX_TIMES` | 最大重试次数 | `3` | `3` |
| `CALLBACK_RETRY_INTERVAL` | 重试间隔（秒） | `60` | `60` |

**配置位置**: `backend/config/config.php`

```php
'callback' => [
    'token' => getenv('CALLBACK_TOKEN') ?: 'wh_callback_token_2024',
    'ip_whitelist' => getenv('CALLBACK_IP_WHITELIST') ?: '',
    'retry' => [
        'enabled' => getenv('CALLBACK_RETRY_ENABLED') ?: false,
        'max_times' => getenv('CALLBACK_RETRY_MAX_TIMES') ?: 3,
        'interval' => getenv('CALLBACK_RETRY_INTERVAL') ?: 60,
    ],
],
```

### 3. 完整配置参考

编辑 `backend/config/config.php`，完整配置如下：

```php
<?php
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: 3306,
        'database' => getenv('DB_DATABASE') ?: 'overseas_warehouse',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Overseas Warehouse Fulfillment System',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Shanghai',
        'debug' => getenv('APP_DEBUG') ?: true,
    ],
    'warehouse' => [
        'default_shipping_days' => getenv('WAREHOUSE_DEFAULT_SHIPPING_DAYS') ?: 5,
        'routing_strategy' => getenv('WAREHOUSE_ROUTING_STRATEGY') ?: 'nearest',
        'score_weights' => [
            'cost' => getenv('WAREHOUSE_SCORE_COST_WEIGHT') ?: 10,
            'days' => getenv('WAREHOUSE_SCORE_DAYS_WEIGHT') ?: 5,
            'priority' => getenv('WAREHOUSE_SCORE_PRIORITY_WEIGHT') ?: 0.5,
        ],
    ],
    'callback' => [
        'token' => getenv('CALLBACK_TOKEN') ?: 'wh_callback_token_2024',
        'ip_whitelist' => getenv('CALLBACK_IP_WHITELIST') ?: '',
        'retry' => [
            'enabled' => getenv('CALLBACK_RETRY_ENABLED') ?: false,
            'max_times' => getenv('CALLBACK_RETRY_MAX_TIMES') ?: 3,
            'interval' => getenv('CALLBACK_RETRY_INTERVAL') ?: 60,
        ],
    ],
    'security' => [
        'require_api_auth' => getenv('REQUIRE_API_AUTH') ?: false,
        'permission' => [
            'enable' => getenv('PERMISSION_ENABLE') ?: true,
        ],
        'audit' => [
            'enable' => getenv('AUDIT_ENABLE') ?: true,
            'log_request_body' => getenv('AUDIT_LOG_REQUEST') ?: true,
            'log_response_body' => getenv('AUDIT_LOG_RESPONSE') ?: true,
            'log_old_new_data' => getenv('AUDIT_LOG_OLD_NEW') ?: true,
        ],
        'ip' => [
            'trust_x_forwarded_for' => getenv('TRUST_X_FORWARDED_FOR') ?: true,
            'x_forwarded_for_index' => getenv('X_FORWARDED_FOR_INDEX') ?: 0,
        ],
    ],
];
```

## 快速启动

### 1. 初始化数据库
```bash
mysql -u root -p < backend/sql/database.sql
```

### 2. 配置环境变量（可选）
```bash
# 复制环境变量模板并修改
cp backend/config/config.php backend/config/config.local.php

# 设置关键环境变量
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_DATABASE=overseas_warehouse
export DB_USERNAME=root
export DB_PASSWORD=your_password
export CALLBACK_TOKEN=your_production_token
export WAREHOUSE_ROUTING_STRATEGY=nearest
```

### 3. 启动 PHP 后端
```bash
cd backend
php -S localhost:8000
```

### 4. 启动 Vue 前端
```bash
cd frontend
npm install
npm run dev
```

前端访问: http://localhost:5173

## 验收命令

### 1. 全量单元测试
```bash
# 运行所有单元测试
cd backend
php tests/run.php
```

**预期输出**:
```
========================================
  海外仓一件代发系统 - 单元测试
========================================

✓ 通过 WarehouseRouterTest (17/17)
✓ 通过 FulfillmentCallbackServiceTest (18/18)
✓ 通过 OrderServiceTest (XX/XX)
✓ 通过 StatusClosedLoopTest (XX/XX)

========================================
  测试总结
========================================
  总测试数: XX
  通过: XX
  失败: 0
  通过率: 100.00%
========================================
```

### 2. 仓库路由专项测试
```bash
# 单独运行仓库路由测试
cd backend
php -r "
require_once 'tests/bootstrap.php';
require_once 'tests/WarehouseRouterTest.php';
\$test = new WarehouseRouterTest();
\$test->setUp();
\$result = \$test->runAll();
echo '仓库路由测试结果: ' . (\$result['failed'] === 0 ? 'PASS' : 'FAIL') . PHP_EOL;
echo '通过: ' . \$result['passed'] . '/' . (\$result['passed'] + \$result['failed']) . PHP_EOL;
"
```

**验证点**:
- ✓ 基本路由计算成功（返回最优仓库）
- ✓ 多商品路由计算
- ✓ 库存不足时返回错误
- ✓ 配送区域不匹配时返回错误
- ✓ 返回备选仓库列表
- ✓ 运费计算正确
- ✓ 预计送达日期格式正确（YYYY-MM-DD）
- ✓ 权限范围控制（按仓库编码过滤）

### 3. 履约回传专项测试
```bash
# 单独运行履约回调测试
cd backend
php -r "
require_once 'tests/bootstrap.php';
require_once 'tests/FulfillmentCallbackServiceTest.php';
\$test = new FulfillmentCallbackServiceTest();
\$test->setUp();
\$result = \$test->runAll();
echo '履约回传测试结果: ' . (\$result['failed'] === 0 ? 'PASS' : 'FAIL') . PHP_EOL;
echo '通过: ' . \$result['passed'] . '/' . (\$result['passed'] + \$result['failed']) . PHP_EOL;
"
```

**验证点**:
- ✓ Token 校验正确
- ✓ 7 类回调事件处理正常（接单/拣货/打包/发货/签收/异常/取消）
- ✓ 重复回调幂等性
- ✓ 必填字段校验
- ✓ 状态流转正确
- ✓ 生成履约追踪记录
- ✓ 回调日志记录完整
- ✓ 发货时扣减锁定库存

### 4. 全流程闭环测试
```bash
# 运行端到端全流程测试
cd backend
php -r "
require_once 'tests/bootstrap.php';
require_once 'tests/StatusClosedLoopTest.php';
\$test = new StatusClosedLoopTest();
\$test->setUp();
\$result = \$test->runAll();
echo '全流程闭环测试结果: ' . (\$result['failed'] === 0 ? 'PASS' : 'FAIL') . PHP_EOL;
echo '通过: ' . \$result['passed'] . '/' . (\$result['passed'] + \$result['failed']) . PHP_EOL;
"
```

**验证点**:
- ✓ 订单创建 → 路由计算 → 库存锁定
- ✓ 仓库接单回调 → 状态更新为"仓库已接单"
- ✓ 拣货开始回调 → 履约状态更新为"拣货中"
- ✓ 打包开始回调 → 履约状态更新为"打包中"
- ✓ 发货回调 → 订单状态更新为"已发货"，扣减锁定库存
- ✓ 签收回调 → 订单状态更新为"已签收"
- ✓ 异常回调 → 履约状态更新为"异常"
- ✓ 发货前取消 → 库存返还，状态更新为"已取消"
- ✓ 发货后取消 → 拒绝取消
- ✓ 重复回调幂等性验证

### 5. API 接口冒烟测试

#### 仓库路由接口测试
```bash
# 测试仓库路由接口
curl -X POST http://localhost:8000/api/warehouse/route \
  -H "Content-Type: application/json" \
  -d '{
    "items": [
        {"sku": "SKU001", "quantity": 2},
        {"sku": "SKU002", "quantity": 1}
    ],
    "shipping_country": "US",
    "shipping_state": "CA"
  }'
```

**预期响应**:
```json
{
  "success": true,
  "selected_warehouse": {
    "warehouse_code": "USCA",
    "warehouse_name": "美国加州仓",
    "shipping_cost": 12.99,
    "estimated_delivery_date": "2024-06-25"
  },
  "alternatives": [...]
}
```

#### 履约回调接口测试
```bash
# 测试订单创建
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "items": [{"sku": "SKU001", "quantity": 1}],
    "customer_name": "张三",
    "customer_phone": "13800138000",
    "shipping_country": "US",
    "shipping_address": "123 Test Street",
    "shipping_city": "Los Angeles",
    "shipping_state": "CA",
    "shipping_zip": "90001"
  }'

# 记录返回的 order_no，然后测试履约回调
curl -X POST http://localhost:8000/api/fulfillment/callback \
  -H "Content-Type: application/json" \
  -H "X-Callback-Token: wh_callback_token_2024" \
  -d '{
    "callback_type": "ORDER_ACCEPT",
    "order_no": "YOUR_ORDER_NO",
    "warehouse_order_no": "WMS001",
    "warehouse_code": "USCA",
    "operate_time": "2024-06-22 10:00:00"
  }'
```

### 6. 部署验收检查清单

```bash
# 1. 检查数据库连接
php -r "
require_once 'backend/config/config.php';
\$config = require 'backend/config/config.php';
try {
    \$pdo = new PDO(
        'mysql:host=' . \$config['db']['host'] . ';port=' . \$config['db']['port'] . ';dbname=' . \$config['db']['database'] . ';charset=' . \$config['db']['charset'],
        \$config['db']['username'],
        \$config['db']['password']
    );
    echo '✓ 数据库连接正常' . PHP_EOL;
} catch (PDOException \$e) {
    echo '✗ 数据库连接失败: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

# 2. 检查关键数据表
mysql -u root -p overseas_warehouse -e "
SELECT 'warehouses' as table_name, COUNT(*) as count FROM warehouses
UNION ALL
SELECT 'products', COUNT(*) FROM products
UNION ALL
SELECT 'warehouse_inventories', COUNT(*) FROM warehouse_inventories
UNION ALL
SELECT 'warehouse_shipping_zones', COUNT(*) FROM warehouse_shipping_zones;
"

# 3. 检查环境变量配置
echo "=== 环境变量检查 ==="
echo "DB_HOST: ${DB_HOST:-127.0.0.1}"
echo "DB_DATABASE: ${DB_DATABASE:-overseas_warehouse}"
echo "CALLBACK_TOKEN: ${CALLBACK_TOKEN:-wh_callback_token_2024}"
echo "WAREHOUSE_ROUTING_STRATEGY: ${WAREHOUSE_ROUTING_STRATEGY:-nearest}"
echo "APP_DEBUG: ${APP_DEBUG:-true}"

# 4. 检查 PHP 扩展
php -m | grep -E "pdo_mysql|curl|json"
```

### 7. 一键验收脚本

```bash
# 保存为 backend/acceptance.sh
#!/bin/bash

echo "========================================"
echo "  海外仓一件代发系统 - 部署验收"
echo "========================================"

PASS=0
FAIL=0

# 测试1: 数据库连接
echo -n "[1/5] 检查数据库连接... "
php -r "
\$config = require 'config/config.php';
try {
    \$pdo = new PDO('mysql:host='.\$config['db']['host'].';dbname='.\$config['db']['database'], \$config['db']['username'], \$config['db']['password']);
    echo '✓ PASS';
    exit(0);
} catch (Exception \$e) {
    echo '✗ FAIL: '.\$e->getMessage();
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试2: 单元测试
echo -n "[2/5] 运行单元测试... "
php tests/run.php > /tmp/test_result.txt 2>&1
if [ $? -eq 0 ]; then
    echo "✓ PASS"
    PASS=$((PASS+1))
else
    echo "✗ FAIL"
    cat /tmp/test_result.txt
    FAIL=$((FAIL+1))
fi

# 测试3: 仓库路由功能
echo -n "[3/5] 测试仓库路由... "
php -r "
require_once 'core/WarehouseRouter.php';
\$router = new WarehouseRouter();
\$result = \$router->route([['sku'=>'SKU001','quantity'=>1]], 'US');
echo \$result['success'] ? '✓ PASS' : '✗ FAIL: '.\$result['message'];
exit(\$result['success'] ? 0 : 1);
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试4: 履约回调Token校验
echo -n "[4/5] 测试履约回调Token... "
php -r "
require_once 'core/FulfillmentCallbackService.php';
\$service = new FulfillmentCallbackService();
\$config = require 'config/config.php';
echo \$service->validateToken(\$config['callback']['token']) ? '✓ PASS' : '✗ FAIL';
exit(\$service->validateToken(\$config['callback']['token']) ? 0 : 1);
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试5: 数据表初始化
echo -n "[5/5] 检查测试数据... "
php -r "
\$config = require 'config/config.php';
\$pdo = new PDO('mysql:host='.\$config['db']['host'].';dbname='.\$config['db']['database'], \$config['db']['username'], \$config['db']['password']);
\$count = \$pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();
echo \$count >= 4 ? '✓ PASS ('.$count.' warehouses)' : '✗ FAIL';
exit(\$count >= 4 ? 0 : 1);
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

echo "========================================"
echo "  验收结果: $PASS 通过, $FAIL 失败"
echo "========================================"
exit $FAIL
```

**执行验收**:
```bash
chmod +x backend/acceptance.sh
cd backend && ./acceptance.sh
```

## 订单状态流转

| 状态值 | 状态名 | 触发条件 |
|--------|--------|----------|
| 0 | 待处理 | - |
| 1 | 已路由 | 路由计算完成 |
| 2 | 已推送仓库 | 创建订单并推送到 WMS |
| 3 | 仓库已接单 | WMS 回调 ORDER_ACCEPT |
| 4 | 已出库 | WMS 回调 PACKING 完成 |
| 5 | 已发货 | WMS 回调 ORDER_SHIP |
| 6 | 已签收 | WMS 回调 ORDER_DELIVER |
| 9 | 已取消 | 主动取消 |

## 履约状态

| 状态值 | 状态名 |
|--------|--------|
| 0 | 未开始 |
| 1 | 拣货中 |
| 2 | 打包中 |
| 3 | 已发货 |
| 4 | 已签收 |
| 9 | 异常 |
