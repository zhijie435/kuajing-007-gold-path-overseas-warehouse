<template>
  <div v-loading="loading">
    <div style="display:flex;gap:12px;margin-bottom:16px;align-items:center">
      <el-button @click="$router.back()">
        <el-icon><ArrowLeft /></el-icon> 返回
      </el-button>
      <el-button type="primary" @click="loadDetail()" :loading="loading">
        <el-icon><Refresh /></el-icon> 刷新
      </el-button>
    </div>

    <el-row :gutter="20" v-if="detail">
      <el-col :span="16">
        <el-card>
          <template #header>
            <div class="card-header">
              <div>
                <span style="font-weight:600;font-size:16px">订单详情</span>
                <el-tag style="margin-left:12px" :type="getStatusType(detail.order_status)">
                  {{ orderStatusText(detail.order_status) }}
                </el-tag>
                <el-tag
                  v-if="detail.fulfillment_status === 9"
                  style="margin-left:6px"
                  type="danger"
                >履约异常</el-tag>
              </div>
              <el-button
                v-if="detail.order_status < 4 && detail.order_status !== 9"
                type="danger"
                @click="handleCancel"
              >取消订单</el-button>
            </div>
          </template>

          <el-descriptions :column="2" border>
            <el-descriptions-item label="订单号">{{ detail.order_no }}</el-descriptions-item>
            <el-descriptions-item label="外部订单号">{{ detail.external_order_no || '-' }}</el-descriptions-item>
            <el-descriptions-item label="分配仓库">
              {{ detail.warehouse_name || '-' }}
              <span v-if="detail.warehouse_code">({{ detail.warehouse_code }})</span>
            </el-descriptions-item>
            <el-descriptions-item label="仓库单号">{{ detail.warehouse_order_no || '-' }}</el-descriptions-item>
            <el-descriptions-item label="物流商">{{ detail.shipping_carrier || '-' }}</el-descriptions-item>
            <el-descriptions-item label="运单号">
              <span v-if="detail.tracking_no" style="font-weight:600;color:#409EFF">{{ detail.tracking_no }}</span>
              <span v-else>-</span>
            </el-descriptions-item>
            <el-descriptions-item label="预计送达">{{ detail.estimated_delivery_date || '-' }}</el-descriptions-item>
            <el-descriptions-item label="实际送达">{{ detail.actual_delivery_date || '-' }}</el-descriptions-item>
            <el-descriptions-item label="创建时间">{{ detail.created_at }}</el-descriptions-item>
            <el-descriptions-item label="备注">{{ detail.remark || '-' }}</el-descriptions-item>
          </el-descriptions>

          <el-divider content-position="left">收件人信息</el-divider>
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="姓名">{{ detail.customer_name }}</el-descriptions-item>
            <el-descriptions-item label="电话">{{ detail.customer_phone }}</el-descriptions-item>
            <el-descriptions-item label="邮箱">{{ detail.customer_email || '-' }}</el-descriptions-item>
            <el-descriptions-item label="邮编">{{ detail.shipping_zip || '-' }}</el-descriptions-item>
            <el-descriptions-item label="地址" :span="2">
              {{ detail.shipping_country }},
              {{ detail.shipping_state ? detail.shipping_state + ', ' : '' }}
              {{ detail.shipping_city ? detail.shipping_city + ', ' : '' }}
              {{ detail.shipping_address }}
            </el-descriptions-item>
          </el-descriptions>

          <el-divider content-position="left">商品明细</el-divider>
          <el-table :data="detail.items" border>
            <el-table-column prop="sku" label="SKU" width="120" />
            <el-table-column prop="product_name" label="商品名称" />
            <el-table-column label="单价" width="100">
              <template #default="{ row }">${{ row.unit_price }}</template>
            </el-table-column>
            <el-table-column prop="quantity" label="数量" width="80" />
            <el-table-column label="重量" width="100">
              <template #default="{ row }">{{ row.weight }}kg</template>
            </el-table-column>
            <el-table-column label="小计" width="120" align="right">
              <template #default="{ row }">${{ row.subtotal }}</template>
            </el-table-column>
          </el-table>

          <div style="margin-top:20px;text-align:right">
            <div>商品总金额: <span style="font-size:16px">${{ detail.total_amount }}</span></div>
            <div style="margin-top:6px">运费: <span style="color:#F56C6C">${{ detail.shipping_cost }}</span></div>
            <el-divider style="margin:12px 0" />
            <div>
              应付总额:
              <span style="font-size:22px;color:#F56C6C;font-weight:600">
                ${{ (Number(detail.total_amount) + Number(detail.shipping_cost)).toFixed(2) }}
              </span>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="8">
        <el-card>
          <template #header>
            <span style="font-weight:600">履约追踪</span>
          </template>
          <el-timeline>
            <el-timeline-item
              v-for="t in sortedTracks"
              :key="t.id"
              :timestamp="t.created_at"
              :type="getTrackType(t)"
              :icon="getTrackIcon(t)"
              :hollow="t.track_status !== 'success'"
            >
              <div style="font-weight:600">{{ getTrackTitle(t) }}</div>
              <div style="color:#606266;font-size:13px;margin-top:4px">{{ t.description }}</div>
              <div style="color:#909399;font-size:12px;margin-top:4px">
                操作方: {{ t.operator }}
              </div>
            </el-timeline-item>
          </el-timeline>
        </el-card>

        <el-card style="margin-top:20px">
          <template #header>
            <span style="font-weight:600">模拟履约回调（测试用）</span>
          </template>
          <el-form label-position="top">
            <el-form-item label="回调类型">
              <el-select v-model="mockCallbackType" style="width:100%">
                <el-option label="仓库接单 - ORDER_ACCEPT" value="ORDER_ACCEPT" />
                <el-option label="开始拣货 - PICKING_START" value="PICKING_START" />
                <el-option label="开始打包 - PACKING_START" value="PACKING_START" />
                <el-option label="已发货 - ORDER_SHIP" value="ORDER_SHIP" />
                <el-option label="已签收 - ORDER_DELIVER" value="ORDER_DELIVER" />
                <el-option label="异常 - ORDER_EXCEPTION" value="ORDER_EXCEPTION" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="warning" @click="mockCallback" :loading="mockLoading">
                <el-icon><Promotion /></el-icon>
                触发回调
              </el-button>
              <span style="color:#909399;font-size:12px;margin-left:8px">
                模拟仓库WMS系统回传履约状态
              </span>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  ArrowLeft, Promotion, Sort, Box, Van, CircleCheck, WarningFilled,
  Loading as LoadingIcon, Refresh
} from '@element-plus/icons-vue'
import { getOrderDetail, cancelOrder } from '@/api/order'
import request from '@/api/request'
import { orderStore } from '@/store/order'

const route = useRoute()
const router = useRouter()

const detail = ref(null)
const loading = ref(false)
const mockCallbackType = ref('ORDER_ACCEPT')
const mockLoading = ref(false)

const orderStatusMap = {
  0: '待处理', 1: '已路由', 2: '已推送仓库', 3: '仓库已接单',
  4: '已出库', 5: '已发货', 6: '已签收', 9: '已取消'
}
const orderStatusText = (s) => orderStatusMap[s] || '未知'
const getStatusType = (s) => {
  if (s === 6) return 'success'
  if (s === 9) return 'info'
  if (s >= 1) return 'primary'
  return 'warning'
}

const sortedTracks = computed(() => {
  if (!detail.value || !detail.value.tracks) return []
  return [...detail.value.tracks].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
})

const getTrackType = (t) => {
  if (t.track_type === 'EXCEPTION' || t.track_status === 'error') return 'danger'
  if (t.track_type === 'DELIVERED') return 'success'
  if (t.track_type === 'SHIPPED') return 'primary'
  return ''
}

const getTrackIcon = (t) => {
  if (t.track_type === 'DELIVERED') return CircleCheck
  if (t.track_type === 'SHIPPED') return Van
  if (t.track_type === 'EXCEPTION') return WarningFilled
  if (t.track_type === 'ROUTE_ASSIGNED') return Sort
  return Box
}

const getTrackTitle = (t) => {
  const map = {
    'ROUTE_ASSIGNED': '仓库路由分配',
    'WMS_PUSHED': '推送仓库WMS',
    'WMS_ACCEPTED': '仓库接单',
    'PICKING': '拣货作业',
    'PACKING': '打包作业',
    'SHIPPED': '已发货',
    'DELIVERED': '已签收',
    'EXCEPTION': '异常',
    'CANCELLED': '订单取消'
  }
  return map[t.track_type] || t.track_type
}

const loadDetail = async () => {
  loading.value = true
  try {
    detail.value = await getOrderDetail(route.params.orderNo)
  } catch (e) {} finally {
    loading.value = false
  }
}

const handleCancel = async () => {
  try {
    await ElMessageBox.confirm('确定要取消该订单吗？', '提示', { type: 'warning' })
    await cancelOrder(route.params.orderNo, '用户取消')
    ElMessage.success('已取消')
    orderStore.triggerRefresh()
    loadDetail()
  } catch (e) {}
}

const mockCallback = async () => {
  if (!detail.value) return
  mockLoading.value = true
  try {
    const baseData = {
      callback_type: mockCallbackType.value,
      order_no: detail.value.order_no,
      warehouse_code: detail.value.warehouse_code || 'USCA',
      warehouse_order_no: detail.value.warehouse_order_no || ('WMS' + Date.now()),
      operate_time: new Date().toISOString().replace('T', ' ').substring(0, 19)
    }
    if (mockCallbackType.value === 'ORDER_SHIP') {
      baseData.tracking_no = 'TRK' + Date.now()
      baseData.shipping_carrier = ['USPS', 'UPS', 'FedEx', 'DHL'][Math.floor(Math.random() * 4)]
    }
    if (mockCallbackType.value === 'ORDER_DELIVER') {
      baseData.deliver_time = new Date().toISOString().split('T')[0]
      baseData.signed_by = 'John Doe'
    }
    if (mockCallbackType.value === 'ORDER_EXCEPTION') {
      baseData.exception_type = 'INVENTORY_SHORTAGE'
      baseData.exception_message = '商品库存不足，无法出库'
    }
    if (mockCallbackType.value === 'PICKING_START' || mockCallbackType.value === 'PACKING_START') {
      baseData.operator = 'WMS Worker ' + Math.floor(Math.random() * 100)
    }
    await request({
      url: '/fulfillment/callback',
      method: 'post',
      headers: { 'X-Callback-Token': 'wh_callback_token_2024' },
      data: baseData
    })
    ElMessage.success('回调触发成功')
    orderStore.triggerRefresh()
    loadDetail()
  } catch (e) {} finally {
    mockLoading.value = false
  }
}

watch(
  () => route.params.orderNo,
  (newOrderNo, oldOrderNo) => {
    if (newOrderNo && newOrderNo !== oldOrderNo) {
      loadDetail()
    }
  }
)

onMounted(loadDetail)
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>
