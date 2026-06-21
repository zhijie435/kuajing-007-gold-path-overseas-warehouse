<template>
  <el-container class="layout-container">
    <el-header class="layout-header">
      <div class="header-left">
        <el-icon :size="28" color="#409EFF"><Box /></el-icon>
        <span class="title">海外仓一件代发管理系统</span>
      </div>
      <el-menu
        :default-active="activeMenu"
        mode="horizontal"
        router
        class="layout-menu"
        background-color="transparent"
        text-color="#333"
        active-text-color="#409EFF"
      >
        <el-menu-item index="/">首页</el-menu-item>
        <el-menu-item index="/order/create">创建订单</el-menu-item>
        <el-menu-item index="/orders">订单列表</el-menu-item>
        <el-menu-item index="/warehouses">仓库管理</el-menu-item>
        <el-menu-item index="/products">商品列表</el-menu-item>
      </el-menu>
    </el-header>
    <el-main class="layout-main">
      <router-view />
    </el-main>
    <el-footer class="layout-footer">
      <span>Overseas Warehouse Fulfillment System © 2024</span>
    </el-footer>
  </el-container>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Box } from '@element-plus/icons-vue'

const route = useRoute()
const activeMenu = computed(() => route.path)
</script>

<style scoped>
.layout-container {
  height: 100%;
}

.layout-header {
  display: flex;
  align-items: center;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
  padding: 0 24px;
  height: 60px;
}

.header-left {
  display: flex;
  align-items: center;
  margin-right: 40px;
}

.title {
  font-size: 18px;
  font-weight: 600;
  margin-left: 10px;
  color: #1f2937;
}

.layout-menu {
  flex: 1;
  border-bottom: none;
}

.layout-main {
  padding: 20px;
  background-color: #f0f2f5;
  overflow-y: auto;
}

.layout-footer {
  text-align: center;
  padding: 16px;
  background: #fff;
  border-top: 1px solid #e4e7ed;
  color: #909399;
  font-size: 12px;
}
</style>
