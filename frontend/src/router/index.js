import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    component: () => import('@/views/Dashboard.vue')
  },
  {
    path: '/order/create',
    component: () => import('@/views/CreateOrder.vue')
  },
  {
    path: '/orders',
    component: () => import('@/views/OrderList.vue')
  },
  {
    path: '/orders/:orderNo',
    component: () => import('@/views/OrderDetail.vue')
  },
  {
    path: '/warehouses',
    component: () => import('@/views/WarehouseList.vue')
  },
  {
    path: '/products',
    component: () => import('@/views/ProductList.vue')
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
