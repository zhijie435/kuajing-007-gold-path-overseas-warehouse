import request from './request'

export function createOrder(data) {
  return request({
    url: '/orders',
    method: 'post',
    data
  })
}

export function getOrderList(params) {
  return request({
    url: '/orders',
    method: 'get',
    params
  })
}

export function getOrderDetail(orderNo) {
  return request({
    url: '/orders/' + orderNo,
    method: 'get'
  })
}

export function cancelOrder(orderNo, reason) {
  return request({
    url: '/orders/' + orderNo + '/cancel',
    method: 'post',
    data: { reason }
  })
}
