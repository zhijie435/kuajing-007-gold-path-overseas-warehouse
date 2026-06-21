<template>
  <div>
    <el-card>
      <template #header>
        <div class="card-header">
          <span style="font-weight:600">订单列表</span>
          <div>
            <el-button type="primary" @click="$router.push('/order/create')">
              <el-icon><Plus /></el-icon>
              新建订单
            </el-button>
          </div>
        </div>
      </template>

      <el-form :inline="true" :model="query" style="margin-bottom:16px">
        <el-form-item label="订单号">
          <el-input v-model="query.order_no" placeholder="输入订单号" clearable style="width:200px" />
        </el-form-item>
        <el-form-item label="外部订单号">
          <el-input v-model="query.external_order_no" placeholder="外部订单号" clearable style="width:180px" />
        </el-form-item>
        <el-form-item label="订单状态">
          <el-select v-model="query.order_status" placeholder="全部" clearable style="width:140px">
            <el-option
              v-for="(v, k) in (orderStatusMap || {})"
              :key="k"
              :label="v"
              :value="Number(k)"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="联系电话">
          <el-input v-model="query.customer_phone" placeholder="手机号" clearable style="width:150px" />
        </el-form-item>
        <el-form-item label="创建时间">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width:260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="loadList">
            <el-icon><Search /></el-icon>
            查询
          </el-button>
          <el-button @click="resetQuery">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table :data="list" border style="width:100%" v-loading="loading">
        <el-table-column prop="order_no" label="订单号" width="220">
          <template #default="{ row }">
            <el-button type="primary" link @click="viewDetail(row.order_no)">{{ row.order_no }}</el-button>
          </template>
        </el-table-column>
        <el-table-column prop="external_order_no" label="外部订单号" width="160" show-overflow-tooltip />
        <el-table-column label="仓库" width="160">
          <template #default="{ row }">
            <span v-if="row.warehouse_name">{{ row.warehouse_name }} ({{ row.warehouse_code }})</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="customer_name" label="收件人" width="100" />
        <el-table-column label="收件地" width="120">
          <template #default="{ row }">
            {{ row.shipping_country }}
            <span v-if="row.shipping_state">/{{ row.shipping_state }}</span>
          </template>
        </el-table-column>
        <el-table-column label="订单状态" width="110">
          <template #default="{ row }">
            <el-tag :type="getStatusType(row.order_status)">
              {{ orderStatusMap[row.order_status] || '未知' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="履约状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.fulfillment_status === 9 ? 'danger' : (row.fulfillment_status >= 3 ? 'success' : 'warning')" size="small">
              {{ fulfillmentStatusMap[row.fulfillment_status] || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="运单号" width="160">
          <template #default="{ row }">
            <span v-if="row.tracking_no">{{ row.tracking_no }}</span>
            <span v-else style="color:#c0c4cc">-</span>
          </template>
        </el-table-column>
        <el-table-column label="金额(USD)" width="100" align="right">
          <template #default="{ row }">${{ (Number(row.total_amount) + Number(row.shipping_cost)).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" />
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="viewDetail(row.order_no)">详情</el-button>
            <el-button
              v-if="row.order_status < 4 && row.order_status !== 9"
              type="danger"
              link
              size="small"
              @click="handleCancel(row)"
            >取消</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div style="margin-top:20px;text-align:right">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          v-model:current-page="query.page"
          v-model:page-size="query.page_size"
          @current-change="loadList"
          @size-change="loadList"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Search } from '@element-plus/icons-vue'
import { getOrderList, cancelOrder } from '@/api/order'

const router = useRouter()

const list = ref([])
const loading = ref(false)
const total = ref(0)
const orderStatusMap = ref({})
const fulfillmentStatusMap = ref({})
const dateRange = ref([])

const query = reactive({
  page: 1,
  page_size: 20,
  order_no: '',
  external_order_no: '',
  order_status: '',
  customer_phone: ''
})

const getStatusType = (s) => {
  if (s === 6) return 'success'
  if (s === 9) return 'info'
  if (s >= 1) return 'primary'
  return 'warning'
}

const loadList = async () => {
  loading.value = true
  try {
    const params = { ...query }
    if (dateRange.value && dateRange.value.length === 2) {
      params.start_date = dateRange.value[0]
      params.end_date = dateRange.value[1]
    }
    const res = await getOrderList(params)
    list.value = res.list || []
    total.value = res.total || 0
    orderStatusMap.value = res.order_status_map || {}
    fulfillmentStatusMap.value = res.fulfillment_status_map || {}
  } catch (e) {} finally {
    loading.value = false
  }
}

const resetQuery = () => {
  query.page = 1
  query.order_no = ''
  query.external_order_no = ''
  query.order_status = ''
  query.customer_phone = ''
  dateRange.value = []
  loadList()
}

const viewDetail = (no) => router.push('/orders/' + no)

const handleCancel = async (row) => {
  try {
    await ElMessageBox.confirm(
      `确定要取消订单 ${row.order_no} 吗？取消后库存将返还。`,
      '取消订单',
      { type: 'warning' }
    )
    await cancelOrder(row.order_no, '用户主动取消')
    ElMessage.success('订单已取消')
    loadList()
  } catch (e) {}
}

onMounted(loadList)
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>
