import request from './request'

export function calculateRoute(data) {
  return request({
    url: '/warehouse/route',
    method: 'post',
    data
  })
}

export function getWarehouseList(params) {
  return request({
    url: '/warehouses',
    method: 'get',
    params
  })
}

export function getWarehouseInventory(warehouseId, params) {
  return request({
    url: '/warehouse/' + warehouseId + '/inventory',
    method: 'get',
    params
  })
}
