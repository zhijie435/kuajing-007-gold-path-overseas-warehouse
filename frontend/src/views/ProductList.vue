<template>
  <el-card>
    <template #header>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-weight:600">商品列表</span>
        <el-input
          v-model="keyword"
          placeholder="搜索SKU或商品名称"
          clearable
          style="width:260px"
          @clear="loadList"
          @keyup.enter="loadList"
        >
          <template #prefix><el-icon><Search /></el-icon></template>
        </el-input>
      </div>
    </template>

    <el-table :data="list" border v-loading="loading">
      <el-table-column label="商品" width="300">
        <template #default="{ row }">
          <div style="display:flex;align-items:center">
            <el-avatar :size="48" shape="square" style="background:#f0f2f5;margin-right:12px">
              <el-icon :size="24" color="#909399"><Goods /></el-icon>
            </el-avatar>
            <div>
              <div style="font-weight:600">{{ row.name }}</div>
              <div style="color:#909399;font-size:12px">{{ row.sku }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="单价" width="100" align="right">
        <template #default="{ row }">
          <span style="color:#F56C6C;font-weight:600">${{ row.price }}</span>
        </template>
      </el-table-column>
      <el-table-column label="重量" width="100" align="right">{{ row.weight }}kg</el-table-column>
      <el-table-column label="总库存" width="100" align="right">
        <template #default="{ row }">
          <el-tag :type="row.total_stock > 100 ? 'success' : (row.total_stock > 20 ? 'warning' : 'danger')">
            {{ row.total_stock }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="仓库分布">
        <template #default="{ row }">
          <el-tag
            v-for="inv in (row.inventories || [])"
            :key="inv.warehouse_id"
            size="small"
            style="margin:2px"
            type="info"
          >
            {{ inv.warehouse_code }}: {{ inv.quantity }}
          </el-tag>
        </template>
      </el-table-column>
    </el-table>

    <div style="margin-top:20px;text-align:right">
      <el-pagination
        background
        layout="total, prev, pager, next"
        :total="total"
        v-model:current-page="page"
        :page-size="pageSize"
        @current-change="loadList"
      />
    </div>
  </el-card>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Search, Goods } from '@element-plus/icons-vue'
import { getProductList } from '@/api/product'

const list = ref([])
const loading = ref(false)
const total = ref(0)
const keyword = ref('')
const page = ref(1)
const pageSize = ref(20)

const loadList = async () => {
  loading.value = true
  try {
    const res = await getProductList({
      page: page.value,
      page_size: pageSize.value,
      keyword: keyword.value
    })
    list.value = res.list || []
    total.value = res.total || 0
  } catch (e) {} finally {
    loading.value = false
  }
}

onMounted(loadList)
</script>
