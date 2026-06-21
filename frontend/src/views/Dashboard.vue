<template>
  <div class="dashboard">
    <el-row :gutter="20">
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-content">
            <div class="stat-icon" style="background:#409EFF">
              <el-icon :size="32"><Document /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.totalOrders }}</div>
              <div class="stat-label">订单总数</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-content">
            <div class="stat-icon" style="background:#67C23A">
              <el-icon :size="32"><Van /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.shippedOrders }}</div>
              <div class="stat-label">已发货</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-content">
            <div class="stat-icon" style="background:#E6A23C">
              <el-icon :size="32"><Loading /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.processingOrders }}</div>
              <div class="stat-label">处理中</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-content">
            <div class="stat-icon" style="background:#F56C6C">
              <el-icon :size="32"><Warning /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.exceptionOrders }}</div>
              <div class="stat-label">异常订单</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="20" style="margin-top:20px">
      <el-col :span="12">
        <el-card>
          <template #header>
            <div class="card-header">
              <span>订单履约流程</span>
            </div>
          </template>
          <el-steps direction="vertical" :active="3" finish-status="success">
            <el-step title="创建订单" description="客户下单，系统生成订单" />
            <el-step title="仓库路由" description="智能匹配最优海外仓" />
            <el-step title="推送WMS" description="订单推送到仓库管理系统" />
            <el-step title="拣货打包" description="仓库拣货、复核、打包" />
            <el-step title="发货出库" description="包裹交予物流商，获取运单号" />
            <el-step title="末端配送" description="本地物流配送到客户手中" />
            <el-step title="签收完成" description="客户签收，订单完成" />
          </el-steps>
        </el-card>
      </el-col>

      <el-col :span="12">
        <el-card>
          <template #header>
            <div class="card-header">
              <span>快捷操作</span>
            </div>
          </template>
          <div class="quick-actions">
            <el-button type="primary" size="large" @click="goCreateOrder">
              <el-icon><Plus /></el-icon>
              创建新订单
            </el-button>
            <el-button size="large" @click="goOrders">
              <el-icon><List /></el-icon>
              查看订单列表
            </el-button>
            <el-button size="large" @click="goProducts">
              <el-icon><Goods /></el-icon>
              商品管理
            </el-button>
            <el-button size="large" @click="goWarehouses">
              <el-icon><OfficeBuilding /></el-icon>
              仓库管理
            </el-button>
          </div>
          <el-divider />
          <div>
            <h4 style="margin-bottom:10px">海外仓分布</h4>
            <el-tag style="margin:4px" type="primary" v-for="w in warehouses" :key="w.id">
              {{ w.warehouse_name }} ({{ w.warehouse_code }})
            </el-tag>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row style="margin-top:20px">
      <el-col :span="24">
        <el-card>
          <template #header>
            <div class="card-header">
              <span>最近订单</span>
              <el-button type="primary" link @click="goOrders">查看全部</el-button>
            </div>
          </template>
          <el-table :data="recentOrders" style="width:100%">
            <el-table-column prop="order_no" label="订单号" width="220" />
            <el-table-column prop="customer_name" label="收件人" width="120" />
            <el-table-column prop="shipping_country" label="国家" width="80" />
            <el-table-column label="订单状态" width="120">
              <template #default="{ row }">
                <el-tag :type="getStatusType(row.order_status)">
                  {{ getStatusText(row.order_status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="total_amount" label="金额" width="100">
              <template #default="{ row }">${{ row.total_amount }}</template>
            </el-table-column>
            <el-table-column prop="created_at" label="创建时间" width="180" />
            <el-table-column label="操作" width="100">
              <template #default="{ row }">
                <el-button type="primary" link @click="viewOrder(row.order_no)">详情</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Document, Van, Loading, Warning, Plus, List, Goods, OfficeBuilding } from '@element-plus/icons-vue'
import { getOrderList } from '@/api/order'
import { getWarehouseList } from '@/api/warehouse'

const router = useRouter()

const stats = ref({
  totalOrders: 0,
  shippedOrders: 0,
  processingOrders: 0,
  exceptionOrders: 0
})

const warehouses = ref([])
const recentOrders = ref([])

const orderStatusMap = {
  0: '待处理', 1: '已路由', 2: '已推送仓库', 3: '仓库已接单',
  4: '已出库', 5: '已发货', 6: '已签收', 9: '已取消'
}

const getStatusText = (s) => orderStatusMap[s] || '未知'
const getStatusType = (s) => {
  if (s === 6) return 'success'
  if (s === 5 || s === 4 || s === 3 || s === 2 || s === 1) return 'primary'
  if (s === 9) return 'info'
  return 'warning'
}

const loadData = async () => {
  try {
    const [res1, res2] = await Promise.all([
      getOrderList({ page: 1, page_size: 5 }),
      getWarehouseList()
    ])
    recentOrders.value = res1.list || []
    stats.value.totalOrders = res1.total || 0
    stats.value.shippedOrders = (res1.list || []).filter(o => o.order_status >= 5).length
    stats.value.processingOrders = (res1.list || []).filter(o => o.order_status >= 1 && o.order_status < 5).length
    stats.value.exceptionOrders = (res1.list || []).filter(o => o.fulfillment_status === 9).length
    warehouses.value = res2.list || []
  } catch (e) {}
}

const goCreateOrder = () => router.push('/order/create')
const goOrders = () => router.push('/orders')
const goProducts = () => router.push('/products')
const goWarehouses = () => router.push('/warehouses')
const viewOrder = (no) => router.push('/orders/' + no)

onMounted(loadData)
</script>

<style scoped>
.dashboard {
  min-height: 100%;
}
.stat-card {
  border-radius: 8px;
}
.stat-content {
  display: flex;
  align-items: center;
}
.stat-icon {
  width: 64px;
  height: 64px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  margin-right: 16px;
}
.stat-value {
  font-size: 28px;
  font-weight: 600;
  color: #1f2937;
  line-height: 1;
}
.stat-label {
  font-size: 14px;
  color: #909399;
  margin-top: 6px;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
}
.quick-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.quick-actions .el-button {
  margin: 0;
}
</style>
