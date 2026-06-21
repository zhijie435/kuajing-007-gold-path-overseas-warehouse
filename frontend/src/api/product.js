import request from './request'

export function getProductList(params) {
  return request({
    url: '/products',
    method: 'get',
    params
  })
}

export function getProductDetail(sku) {
  return request({
    url: '/products/' + sku,
    method: 'get'
  })
}

export function getCallbackLogs(params) {
  return request({
    url: '/fulfillment/callback/logs',
    method: 'get',
    params
  })
}
