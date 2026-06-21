import axios from 'axios'
import { ElMessage } from 'element-plus'

const service = axios.create({
  baseURL: '/api',
  timeout: 30000
})

service.interceptors.request.use(
  config => {
    if (config.silentError === undefined) {
      config.silentError = false
    }
    if (config.showSuccess === undefined) {
      config.showSuccess = false
    }
    return config
  },
  error => {
    return Promise.reject(error)
  }
)

service.interceptors.response.use(
  response => {
    const res = response.data
    const config = response.config

    if (res.code !== 0) {
      const errorMsg = res.message || '请求失败'

      if (!config.silentError) {
        ElMessage.error({
          message: errorMsg,
          duration: 3000,
          showClose: true
        })
      }

      const error = new Error(errorMsg)
      error.code = res.code
      error.data = res.data
      error.errorType = res.data?.error_type
      return Promise.reject(error)
    }

    if (config.showSuccess && res.message) {
      ElMessage.success({
        message: res.message,
        duration: 2000,
        showClose: true
      })
    }

    return {
      ...res.data,
      _response: {
        code: res.code,
        message: res.message
      }
    }
  },
  error => {
    const config = error.config || {}
    let errorMsg = error.message || '网络错误'

    if (error.response) {
      const status = error.response.status
      if (status === 401) {
        errorMsg = '登录已过期，请重新登录'
      } else if (status === 403) {
        errorMsg = '没有权限执行此操作'
      } else if (status === 404) {
        errorMsg = '请求的接口不存在'
      } else if (status >= 500) {
        errorMsg = '服务器异常，请稍后重试'
      } else if (error.response.data?.message) {
        errorMsg = error.response.data.message
      }
    } else if (error.code === 'ECONNABORTED') {
      errorMsg = '请求超时，请检查网络连接'
    } else if (error.message === 'Network Error') {
      errorMsg = '网络连接失败，请检查网络'
    }

    if (!config.silentError) {
      ElMessage.error({
        message: errorMsg,
        duration: 3000,
        showClose: true
      })
    }

    const newError = new Error(errorMsg)
    newError.originalError = error
    newError.response = error.response
    return Promise.reject(newError)
  }
)

export default service
