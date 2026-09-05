import { createApp } from 'vue'
import { setRequestToken } from '@nextcloud/auth'
import App from './App.vue'
import { loadTranslations, translate as nextcloudTranslate, translatePlural as nextcloudTranslatePlural } from '@nextcloud/l10n'
import {
	NcContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppContent,
	NcButton,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcEmptyContent,
	NcNoteCard,
	NcProgressBar,
	NcModal,
	NcInputField,
	NcRichText,
	NcAppNavigationSpacer,
	NcActionButton,
} from '@nextcloud/vue'

const app = createApp(App)
app.config.globalProperties.$t = (text, placeholders) => nextcloudTranslate('eva_ai', text, placeholders)
app.config.globalProperties.$n = (singular, plural, count, placeholders) => nextcloudTranslatePlural('eva_ai', singular, plural, count, placeholders)

const tokenEl = document.querySelector('meta[name="requesttoken"]')
if (tokenEl && tokenEl.content) {
	setRequestToken(tokenEl.content)
}

function showErrorBox(msg) {
	const el = document.getElementById('eva_ai-error')
	if (!el) return
	el.style.display = 'block'
	el.textContent = 'EvaAi error:\n' + msg
}
window.addEventListener('error', (e) => {
	showErrorBox('JS: ' + (e.message || '') + ' @ ' + (e.filename || '') + ':' + (e.lineno || ''))
})
window.addEventListener('unhandledrejection', (e) => {
	const r = e.reason
	let msg = (r && (r.message || r)) || ''
	if (r && r.stack) msg += '\n' + r.stack.split('\n').slice(0, 4).join('\n')
	showErrorBox('API: ' + msg)
})
app.config.errorHandler = (err, instance, info) => {
	console.error('[eva-ai] vue error', err, info)
	showErrorBox('[Vue] ' + (err && err.message ? err.message : err) + (info ? ' (' + info + ')' : ''))
}
app.component('NcContent', NcContent)
app.component('NcAppNavigation', NcAppNavigation)
app.component('NcAppNavigationItem', NcAppNavigationItem)
app.component('NcAppContent', NcAppContent)
app.component('NcButton', NcButton)
app.component('NcTextField', NcTextField)
app.component('NcCheckboxRadioSwitch', NcCheckboxRadioSwitch)
app.component('NcLoadingIcon', NcLoadingIcon)
app.component('NcEmptyContent', NcEmptyContent)
app.component('NcNoteCard', NcNoteCard)
app.component('NcProgressBar', NcProgressBar)
app.component('NcModal', NcModal)
app.component('NcInputField', NcInputField)
app.component('NcRichText', NcRichText)
app.component('NcAppNavigationSpacer', NcAppNavigationSpacer)
app.component('NcActionButton', NcActionButton)

loadTranslations('eva_ai')
	.catch((error) => console.warn('[eva-ai] translation bundle could not be loaded', error))
	.finally(() => app.mount('#eva_ai-root'))