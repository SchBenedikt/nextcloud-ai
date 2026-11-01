import { createApp } from 'vue'
import VectorStorageStatus from '../../src/components/settings/VectorStorageStatus.vue'
import { translate as t } from '../../src/lib/i18n'
const app = createApp(VectorStorageStatus)
app.config.globalProperties.$t = t
app.mount('#vector-status-root')
