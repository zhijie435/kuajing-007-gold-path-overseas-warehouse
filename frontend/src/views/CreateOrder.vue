<template>
  <div class="create-order">
    <el-card>
      <template #header>
        <span style="font-weight:600">创建代发订单</span>
      </template>

      <el-form :model="form" :rules="rules" ref="formRef" label-width="120px">
        <el-divider content-position="left">商品信息</el-divider>

        <el-form-item label="选择商品">
          <el-select
            v-model="selectedSku"
            filterable
            placeholder="搜索并选择商品"
            style="width:300px"
            @change="addProduct"
          >
            <el-option
              v-for="p in products"
              :key="p.sku"
              :label="`${p.sku} - ${p.name} ($${p.price})`"
              :value="p.sku"
            />
          </el-select>
        </el-form-item>

        <el-table :data="form.items" border style="width:100%">
          <el-table-column label="SKU" prop="sku" width="120" />
          <el-table-column label="商品名称" prop="name" />
          <el-table-column label="单价" width="100">
            <template #default="{ row }">${{ row.unit_price }}</template>
          </el-table-column>
          <el-table-column label="重量(kg)" width="100">
            <template #default="{ row }">{{ row.weight }}</template>
          </el-table-column>
          <el-table-column label="数量" width="180">
            <template #default="{ row, $index }">
              <el-input-number v-model="row.quantity" :min="1" :max="999" size="small" @change="calcRoute" />
            </template>
          </el-table-column>
          <el-table-column label="小计" width="120">
            <template #default="{ row }">${{ (row.unit_price * row.quantity).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="80">
            <template #default="{ $index }">
              <el-button type="danger" link @click="removeProduct($index)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-divider content-position="left">收货信息</el-divider>

        <el-row :gutter="20">
          <el-col :span="12">
            <el-form-item label="收件人姓名" prop="customer_name">
              <el-input v-model="form.customer_name" placeholder="请输入收件人姓名" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="联系电话" prop="customer_phone">
              <el-input v-model="form.customer_phone" placeholder="请输入联系电话" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="20">
          <el-col :span="12">
            <el-form-item label="电子邮箱">
              <el-input v-model="form.customer_email" placeholder="可选" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="外部订单号">
              <el-input v-model="form.external_order_no" placeholder="平台订单号（可选）" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="20">
          <el-col :span="8">
            <el-form-item label="国家" prop="shipping_country">
              <el-select v-model="form.shipping_country" placeholder="请选择国家" @change="calcRoute">
                <el-option label="美国 (US)" value="US" />
                <el-option label="英国 (GB)" value="GB" />
                <el-option label="德国 (DE)" value="DE" />
                <el-option label="法国 (FR)" value="FR" />
                <el-option label="加拿大 (CA)" value="CA" />
                <el-option label="澳大利亚 (AU)" value="AU" />
                <el-option label="新西兰 (NZ)" value="NZ" />
                <el-option label="荷兰 (NL)" value="NL" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="州/省" prop="shipping_state">
              <el-input v-model="form.shipping_state" placeholder="如：California / NY" @blur="calcRoute" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="城市">
              <el-input v-model="form.shipping_city" placeholder="请输入城市" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="详细地址" prop="shipping_address">
          <el-input
            v-model="form.shipping_address"
            type="textarea"
            :rows="2"
            placeholder="街道、门牌号等详细信息"
          />
        </el-form-item>

        <el-row :gutter="20">
          <el-col :span="12">
            <el-form-item label="邮编">
              <el-input v-model="form.shipping_zip" placeholder="ZIP / Postal Code" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="备注">
              <el-input v-model="form.remark" placeholder="可选" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-divider content-position="left">仓库路由预览</el-divider>

        <el-form-item label="">
          <el-button type="primary" @click="calcRoute" :loading="routing">
            <el-icon><Position /></el-icon>
            计算最优仓库
          </el-button>
        </el-form-item>

        <div v-if="routeResult" style="background:#f5f7fa;padding:20px;border-radius:8px">
          <template v-if="routeResult.success">
            <el-alert type="success" :closable="false" show-icon style="margin-bottom:16px">
              <template #title>
                已匹配最优仓库：<strong>{{ routeResult.selected_warehouse.warehouse_name }}</strong>
                ({{ routeResult.selected_warehouse.warehouse_code }})
              </template>
            </el-alert>
            <el-descriptions :column="3" border>
              <el-descriptions-item label="仓库位置">
                {{ routeResult.selected_warehouse.country }} - {{ routeResult.selected_warehouse.city }}
              </el-descriptions-item>
              <el-descriptions-item label="运费">
                <span style="color:#F56C6C;font-weight:600">${{ routeResult.selected_warehouse.shipping_cost }}</span>
              </el-descriptions-item>
              <el-descriptions-item label="配送时效">
                {{ routeResult.selected_warehouse.shipping_days_min }} - {{ routeResult.selected_warehouse.shipping_days_max }} 天
              </el-descriptions-item>
              <el-descriptions-item label="预计送达">
                {{ routeResult.selected_warehouse.estimated_delivery_date }}
              </el-descriptions-item>
              <el-descriptions-item label="总重量">{{ routeResult.total_weight }} kg</el-descriptions-item>
              <el-descriptions-item label="匹配评分">{{ routeResult.selected_warehouse.score }}</el-descriptions-item>
            </el-descriptions>

            <el-divider v-if="routeResult.alternatives && routeResult.alternatives.length">备选仓库</el-divider>
            <el-table v-if="routeResult.alternatives && routeResult.alternatives.length" :data="routeResult.alternatives" size="small">
              <el-table-column prop="warehouse_name" label="仓库名称" />
              <el-table-column label="运费">
                <template #default="{ row }">${{ row.shipping_cost }}</template>
              </el-table-column>
              <el-table-column label="时效">
                <template #default="{ row }">{{ row.shipping_days_min }}-{{ row.shipping_days_max }}天</template>
              </el-table-column>
              <el-table-column prop="score" label="评分" />
            </el-table>
          </template>
          <el-alert v-else type="error" :closable="false" show-icon>
            {{ routeResult.message }}
          </el-alert>
        </div>

        <div v-if="submitError" style="margin-bottom:20px">
          <el-alert type="error" :closable="false" show-icon>
            <template #title>
              <div style="font-weight:600">订单提交失败</div>
            </template>
            <div style="margin-top:8px">
              <p style="margin:0 0 8px 0;color:#F56C6C">{{ submitError.message }}</p>
              <p v-if="submitError.rollback" style="margin:0 0 8px 0;color:#67C23A">
                ✓ 系统已自动回滚，库存未扣减，订单未创建
              </p>
              <p v-if="submitError.retryable" style="margin:0;color:#909399">
                您可以点击下方「重试提交」按钮再次尝试，无需重新填写表单
              </p>
              <div v-if="submitError.traceId" style="margin-top:8px;font-size:12px;color:#C0C4CC">
                Trace ID: {{ submitError.traceId }}
              </div>
            </div>
          </el-alert>
        </div>

        <el-form-item style="margin-top:30px">
          <el-button
            v-if="submitError && submitError.retryable"
            type="warning"
            size="large"
            :loading="submitting"
            @click="retrySubmit"
          >
            <el-icon><Refresh /></el-icon>
            重试提交
          </el-button>
          <el-button type="primary" size="large" :loading="submitting" @click="submitOrder">
            <el-icon><Check /></el-icon>
            {{ submitError ? '重新提交' : '确认下单' }}
          </el-button>
          <el-button size="large" @click="resetForm">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Position, Check, Refresh } from '@element-plus/icons-vue'
import { getProductList } from '@/api/product'
import { calculateRoute } from '@/api/warehouse'
import { createOrder } from '@/api/order'
import { orderStore } from '@/store/order'

const router = useRouter()
const formRef = ref()
const products = ref([])
const selectedSku = ref('')
const routing = ref(false)
const submitting = ref(false)
const routeResult = ref(null)
const submitError = ref(null)

const form = reactive({
  items: [],
  customer_name: '',
  customer_phone: '',
  customer_email: '',
  external_order_no: '',
  shipping_country: '',
  shipping_state: '',
  shipping_city: '',
  shipping_address: '',
  shipping_zip: '',
  remark: ''
})

const validateItems = (rule, value, callback) => {
  if (form.items.length === 0) {
    callback(new Error('请至少添加一个商品'))
  } else {
    const invalidQty = form.items.some(i => !i.quantity || i.quantity <= 0)
    if (invalidQty) {
      callback(new Error('商品数量必须大于0'))
    } else {
      callback()
    }
  }
}

const validateEmail = (rule, value, callback) => {
  if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
    callback(new Error('请输入正确的邮箱格式'))
  } else {
    callback()
  }
}

const validatePhone = (rule, value, callback) => {
  if (!value) {
    callback(new Error('请输入联系电话'))
  } else if (!/^[\d\s\-+()]{6,20}$/.test(value)) {
    callback(new Error('请输入正确的联系电话格式'))
  } else {
    callback()
  }
}

const rules = {
  customer_name: [
    { required: true, message: '请输入收件人姓名', trigger: 'blur' },
    { min: 2, max: 50, message: '姓名长度在 2 到 50 个字符', trigger: 'blur' }
  ],
  customer_phone: [{ validator: validatePhone, trigger: 'blur' }],
  customer_email: [{ validator: validateEmail, trigger: 'blur' }],
  shipping_country: [{ required: true, message: '请选择国家', trigger: 'change' }],
  shipping_state: [{ required: false, message: '请输入州/省', trigger: 'blur' }],
  shipping_address: [
    { required: true, message: '请输入详细地址', trigger: 'blur' },
    { min: 5, message: '地址长度不能少于 5 个字符', trigger: 'blur' }
  ],
  items: [{ validator: validateItems, trigger: 'change' }]
}

const loadProducts = async () => {
  try {
    const res = await getProductList({ page: 1, page_size: 100 })
    products.value = res.list || []
  } catch (e) {}
}

const addProduct = (sku) => {
  const p = products.value.find(x => x.sku === sku)
  if (!p) return
  const exist = form.items.find(x => x.sku === sku)
  if (exist) {
    exist.quantity += 1
  } else {
    form.items.push({
      sku: p.sku,
      name: p.name,
      unit_price: p.price,
      weight: p.weight,
      quantity: 1
    })
  }
  selectedSku.value = ''
  calcRoute()
}

const removeProduct = (idx) => {
  form.items.splice(idx, 1)
  calcRoute()
}

const calcRoute = async () => {
  if (form.items.length === 0 || !form.shipping_country) {
    routeResult.value = null
    return
  }
  routing.value = true
  try {
    const items = form.items.map(x => ({ sku: x.sku, quantity: x.quantity }))
    routeResult.value = await calculateRoute({
      items,
      shipping_country: form.shipping_country,
      shipping_state: form.shipping_state
    })
  } catch (e) {
    routeResult.value = { success: false, message: e.message || '路由计算失败' }
  } finally {
    routing.value = false
  }
}

const validateBeforeSubmit = () => {
  if (form.items.length === 0) {
    ElMessage.error('请至少添加一个商品')
    return false
  }

  const invalidItems = form.items.filter(i => !i.sku || !i.quantity || i.quantity <= 0)
  if (invalidItems.length > 0) {
    ElMessage.error('商品信息不完整，请检查 SKU 和数量')
    return false
  }

  const skuSet = new Set()
  const duplicateSkus = []
  form.items.forEach(i => {
    if (skuSet.has(i.sku)) {
      duplicateSkus.push(i.sku)
    }
    skuSet.add(i.sku)
  })
  if (duplicateSkus.length > 0) {
    ElMessage.warning(`商品 ${duplicateSkus.join(', ')} 已存在，数量已合并`)
  }

  if (!form.shipping_country) {
    ElMessage.error('请选择收货国家')
    return false
  }

  if (!routeResult.value) {
    ElMessage.warning('请先点击「计算最优仓库」进行路由预览')
    return false
  }

  if (!routeResult.value.success) {
    ElMessage.error('仓库路由失败：' + (routeResult.value.message || '无法匹配到合适的仓库，请检查商品和收货地址'))
    return false
  }

  if (!routeResult.value.selected_warehouse) {
    ElMessage.error('未获取到有效仓库信息，请重新计算路由')
    return false
  }

  return true
}

const submitOrder = async () => {
  if (!formRef.value) return

  submitError.value = null

  try {
    await formRef.value.validate()
  } catch (e) {
    ElMessage.error('请检查表单填写是否完整')
    return
  }

  if (!validateBeforeSubmit()) {
    return
  }

  submitting.value = true
  let submitSuccess = false

  try {
    const payload = {
      items: form.items.map(x => ({ sku: x.sku, quantity: x.quantity })),
      customer_name: form.customer_name.trim(),
      customer_phone: form.customer_phone.trim(),
      customer_email: form.customer_email ? form.customer_email.trim() : null,
      external_order_no: form.external_order_no ? form.external_order_no.trim() : null,
      shipping_country: form.shipping_country,
      shipping_state: form.shipping_state ? form.shipping_state.trim() : null,
      shipping_city: form.shipping_city ? form.shipping_city.trim() : null,
      shipping_address: form.shipping_address.trim(),
      shipping_zip: form.shipping_zip ? form.shipping_zip.trim() : null,
      remark: form.remark ? form.remark.trim() : null
    }

    ElMessage.info({
      message: '正在提交订单，请稍候...',
      duration: 0,
      showClose: false
    })

    const res = await createOrder({
      ...payload,
      _silent: true
    })

    const orderNo = res?.order_no
    if (orderNo) {
      submitSuccess = true
      ElMessage.closeAll()
      ElMessage.success({
        message: '订单创建成功！订单号：' + orderNo,
        duration: 2000,
        showClose: true
      })
      orderStore.triggerRefresh()

      setTimeout(() => {
        router.push('/orders/' + orderNo)
      }, 1000)
    } else {
      throw new Error('订单创建失败，未获取到订单号')
    }
  } catch (e) {
    console.error('创建订单失败:', e)
    ElMessage.closeAll()
    if (!submitSuccess) {
      const errorData = e.data || {}
      submitError.value = {
        message: e.message || '订单提交失败，请重试',
        rollback: errorData.rollback === true,
        retryable: errorData.retryable !== false,
        errorType: errorData.error_type,
        traceId: errorData.trace_id,
        details: errorData.details
      }
    }
  } finally {
    if (!submitSuccess) {
      submitting.value = false
    }
  }
}

const resetForm = () => {
  if (formRef.value) formRef.value.resetFields()
  form.items = []
  routeResult.value = null
  submitError.value = null
}

const retrySubmit = async () => {
  if (submitting.value) return
  await submitOrder()
}

onMounted(loadProducts)
</script>

<style scoped>
.create-order {
  max-width: 1200px;
  margin: 0 auto;
}
</style>
