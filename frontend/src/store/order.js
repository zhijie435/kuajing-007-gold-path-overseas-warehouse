import { reactive } from 'vue'

export const orderStore = reactive({
  refreshVersion: 0,

  triggerRefresh() {
    this.refreshVersion++
  },

  reset() {
    this.refreshVersion = 0
  }
})
