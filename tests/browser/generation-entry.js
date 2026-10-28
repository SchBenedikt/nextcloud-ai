import { createApp } from 'vue'
import GenerationEmbeddingSettings from '../../src/components/settings/GenerationEmbeddingSettings.vue'
import { translate as t } from '../../src/lib/i18n'
const app = createApp(GenerationEmbeddingSettings, { users: [{ userId: 'alice', displayName: 'Alice' }, { userId: 'bob', displayName: 'Bob' }] })
app.config.globalProperties.$t = t
app.mount('#generation-root')
