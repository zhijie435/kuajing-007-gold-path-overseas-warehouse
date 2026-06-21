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

## 快速启动

### 1. 初始化数据库
```bash
mysql -u root -p < backend/sql/database.sql
```

### 2. 配置数据库连接
编辑 `backend/config/config.php`，修改数据库用户名密码。

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
