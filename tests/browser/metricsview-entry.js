import { createApp } from 'vue'
import MetricsView from '../../src/views/MetricsView.vue'

const app = createApp(MetricsView)
app.config.globalProperties.$t = (text, placeholders = {}) => text.replace(/\{(\w+)\}/g, (_match, key) => placeholders[key] ?? '')
app.mount('#metrics-root')
