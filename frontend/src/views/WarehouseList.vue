<template>
  <el-card>
    <template #header>
      <span style="font-weight:600">仓库列表</span>
    </template>

    <el-row :gutter="20">
      <el-col :span="8" v-for="w in list" :key="w.id" style="margin-bottom:20px">
        <el-card shadow="hover" class="wh-card">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
              <div style="font-size:16px;font-weight:600">{{ w.warehouse_name }}</div>
              <div style="color:#909399;margin-top:4px">{{ w.warehouse_code }}</div>
            </div>
            <el-tag :type="w.status === 1 ? 'success' : 'info'">
              {{ w.status === 1 ? '运行中' : '已停用' }}
            </el-tag>
          </div>
          <el-divider style="margin:12px 0" />
          <el-descriptions :column="1" size="small" border>
            <el-descriptions-item label="位置">
              {{ w.country }} - {{ w.state || '' }} {{ w.city || '' }}
            </el-descriptions-item>
            <el-descriptions-item label="地址">{{ w.address || '-' }}</el-descriptions-item>
            <el-descriptions-item label="SKU数量">{{ w.sku_count || 0 }}</el-descriptions-item>
            <el-descriptions-item label="总库存">{{ w.total_stock || 0 }}</el-descriptions-item>
            <el-descriptions-item label="优先级">{{ w.priority }}</el-descriptions-item>
          </el-descriptions>
          <div style="margin-top:12px">
            <el-button type="primary" size="small" @click="viewInventory(w)">查看库存</el-button>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-dialog v-model="invVisible" :title="invTitle" width="800px">
      <el-table :data="inventory" border>
        <el-table-column prop="sku" label="SKU" width="140" />
        <el-table-column prop="product_name" label="商品名称" />
        <el-table-column prop="quantity" label="可用库存" width="100" align="right" />
        <el-table-column prop="reserved_quantity" label="锁定库存" width="100" align="right" />
        <el-table-column label="单价" width="100" align="right">
          <template #default="{ row }">${{ row.price }}</template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </el-card>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { getWarehouseList, getWarehouseInventory } from '@/api/warehouse'

const list = ref([])
const invVisible = ref(false)
const invTitle = ref('')
const inventory = ref([])

const loadList = async () => {
  try {
    const res = await getWarehouseList()
    list.value = res.list || []
  } catch (e) {}
}

const viewInventory = async (w) => {
  invTitle.value = w.warehouse_name + ' - 库存明细'
  invVisible.value = true
  try {
    const res = await getWarehouseInventory(w.id)
    inventory.value = res.list || []
  } catch (e) {
    inventory.value = []
  }
}

onMounted(loadList)
</script>

<style scoped>
.wh-card:hover {
  transform: translateY(-2px);
  transition: transform 0.2s;
}
</style>
