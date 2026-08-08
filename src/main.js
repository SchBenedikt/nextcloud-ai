import { createApp } from 'vue'
import { setRequestToken } from '@nextcloud/auth'
import App from './App.vue'
import {
	NcContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppContent,
	NcButton,
	NcTextField,
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

const tokenEl = document.querySelector('meta[name="requesttoken"]')
if (tokenEl && tokenEl.content) {
	setRequestToken(tokenEl.content)
}

function showErrorBox(msg) {
	const el = document.getElementById('ragchat-error')
	if (!el) return
	el.style.display = 'block'
	el.textContent = 'RagChat error:\n' + msg
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
	console.error('[ragchat] vue error', err, info)
	showErrorBox('[Vue] ' + (err && err.message ? err.message : err) + (info ? ' (' + info + ')' : ''))
}
app.component('NcContent', NcContent)
app.component('NcAppNavigation', NcAppNavigation)
app.component('NcAppNavigationItem', NcAppNavigationItem)
app.component('NcAppContent', NcAppContent)
app.component('NcButton', NcButton)
app.component('NcTextField', NcTextField)
app.component('NcLoadingIcon', NcLoadingIcon)
app.component('NcEmptyContent', NcEmptyContent)
app.component('NcNoteCard', NcNoteCard)
app.component('NcProgressBar', NcProgressBar)
app.component('NcModal', NcModal)
app.component('NcInputField', NcInputField)
app.component('NcRichText', NcRichText)
app.component('NcAppNavigationSpacer', NcAppNavigationSpacer)
app.component('NcActionButton', NcActionButton)

app.mount('#ragchat-root')