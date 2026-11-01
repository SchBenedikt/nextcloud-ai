import { createApp } from 'vue'
import ChatView from '../../src/views/ChatView.vue'
import { translate as t } from '../../src/lib/i18n'

const app = createApp(ChatView)
app.config.globalProperties.$t = t
app.mount('#chat-root')
