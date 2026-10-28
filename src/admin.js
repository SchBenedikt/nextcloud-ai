import { createApp, h } from 'vue'
import { setRequestToken } from '@nextcloud/auth'
import { NcContent, NcAppContent, NcButton, NcCheckboxRadioSwitch, NcModal } from '@nextcloud/vue'
import { translate, translatePlural, loadTranslations } from '@nextcloud/l10n'
import AdminView from './views/AdminView.vue'

// Dedicated entry: administration must not initialize the personal chat shell.
const app = createApp({
	render: () => h(NcContent, { appName: 'eva_ai' }, {
		default: () => h(NcAppContent, {}, { default: () => h(AdminView) }),
	}),
})
app.config.globalProperties.$t = (text, placeholders) => translate('eva_ai', text, placeholders)
app.config.globalProperties.$n = (singular, plural, count, placeholders) => translatePlural('eva_ai', singular, plural, count, placeholders)
app.component('NcButton', NcButton)
app.component('NcCheckboxRadioSwitch', NcCheckboxRadioSwitch)
app.component('NcModal', NcModal)
const token = document.querySelector('meta[name="requesttoken"]')?.content
if (token) setRequestToken(token)
loadTranslations('eva_ai')
	.catch(error => console.warn('[eva-ai] admin translations could not be loaded', error))
	.finally(() => app.mount('#eva_ai-admin-app'))
