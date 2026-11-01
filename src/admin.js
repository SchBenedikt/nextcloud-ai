import { createApp } from 'vue'
import { setRequestToken } from '@nextcloud/auth'
import { translate, translatePlural, loadTranslations } from '@nextcloud/l10n'
import AdminView from './views/AdminView.vue'

// Mount the optional tools inside Nextcloud's native Admin settings.
const root = document.getElementById('eva_ai-admin-app')
if (!root) throw new Error('EVA admin controls mount point is missing')
const token = document.querySelector('meta[name="requesttoken"]')?.content
if (token) setRequestToken(token)
loadTranslations('eva_ai')
	.catch(error => console.warn('[eva-ai] admin translations could not be loaded', error))
	.finally(() => {
		const app = createApp(AdminView)
		app.config.globalProperties.$t = (text, placeholders) => translate('eva_ai', text, placeholders)
		app.config.globalProperties.$n = (singular, plural, count, placeholders) => translatePlural('eva_ai', singular, plural, count, placeholders)
		app.mount(root)
	})
