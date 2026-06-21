import request from './request'

export function createOrder(data) {
  const silent = data._silent === true
  const requestData = { ...data }
  delete requestData._silent

  return request({
    url: '/orders',
    method: 'post',
    data: requestData,
    silentError: silent
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
