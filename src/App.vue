<template>
	<NcContent class="eva-ai-app" :app-name="'eva-ai'">
		<NcAppNavigation :title="'EVA · v' + buildVersion" @close-navigation="mobileOpen = false">
			<template #list>
				<button class="new-chat-btn" :disabled="busy" @click="newChat">
					<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiMessagePlus" fill="currentColor" /></svg>
					<span>New chat</span>
				</button>
				<div class="chat-list">
					<NcAppNavigationItem
						v-for="c in chats"
						:key="c.id"
						:name="c.title"
						:active="view === 'chat' && c.id === currentChat"
						:title="c.title + ' · ' + c.count + ' messages'"
						@click="selectChat(c.id)">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiChatProcessing" fill="currentColor" /></svg>
						</template>
						<template #actions>
							<NcActionButton :name="'Rename chat'" @click="renameChat(c.id)">
								<template #icon>
									<svg width="18" height="18" viewBox="0 0 24 24"><path :d="mdiPencilOutline" fill="currentColor" /></svg>
								</template>
							</NcActionButton>
							<NcActionButton :name="'Delete chat'" @click="deleteChat(c.id)">
								<template #icon>
									<svg width="18" height="18" viewBox="0 0 24 24"><path :d="mdiTrashCanOutline" fill="currentColor" /></svg>
								</template>
							</NcActionButton>
						</template>
					</NcAppNavigationItem>
					<div v-if="!chats.length" class="chat-list-empty">No chats yet — start a new one.</div>
				</div>
			</template>
			<template #footer>
				<ul class="nav-footer">
					<NcAppNavigationItem
						:name="'Documents'"
						:active="view === 'docs'"
						@click="view = 'docs'">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiFileDocumentOutline" fill="currentColor" /></svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="'Settings'"
						:active="view === 'settings'"
						@click="view = 'settings'">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiTune" fill="currentColor" /></svg>
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<ChatView v-if="view === 'chat'" :chat-id="currentChat" :initial-prompt="pendingPrompt" @chat-updated="loadChats" />
			<FileContextChatView v-else-if="view === 'fileContext'" :file-ids="fileContextIds" />
			<DocumentsView v-else-if="view === 'docs'" />
			<SettingsView v-else />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { ref, onMounted } from 'vue'
import ChatView from './views/ChatView.vue'
import DocumentsView from './views/DocumentsView.vue'
import SettingsView from './views/SettingsView.vue'
import FileContextChatView from './views/FileContextChatView.vue'
import { mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline } from '@mdi/js'

export default {
	name: 'EvaAiApp',
	components: { ChatView, DocumentsView, SettingsView, FileContextChatView },
	setup() {
		const params = new URLSearchParams(window.location.search)
		const initialFileIdsParam = params.get('fileIds')
		const initialFileIds = initialFileIdsParam
			? initialFileIdsParam.split(',').map((x) => parseInt(x, 10)).filter((x) => Number.isFinite(x) && x > 0)
			: []
		const initial = params.get('view') === 'fileContext'
			? 'fileContext'
			: params.get('view') === 'docs'
				? 'docs'
				: params.get('view') === 'settings'
					? 'settings'
					: 'chat'
		const view = ref(initial)
		const fileContextIds = ref(initialFileIds)
		const mobileOpen = ref(false)
		const buildVersion = appVersion

		const chats = ref([])
		const currentChat = ref(null)
		const busy = ref(false)
		const pendingPrompt = ref('')

		const apiBase = () => {
			const el = document.head.querySelector('meta[name="eva-ai-api"]')
			return el ? el.getAttribute('content') : ''
		}
		const token = () => {
			const el = document.head.querySelector('meta[name="requesttoken"]')
			return el ? el.getAttribute('content') : ''
		}

		const api = (method, path, body) => {
			const opts = {
				method,
				credentials: 'same-origin',
				headers: {
					'OCS-APIRequest': 'true',
					'Accept': 'application/json',
					'requesttoken': token(),
				},
			}
			if (body) {
				opts.headers['Content-Type'] = 'application/json'
				opts.body = JSON.stringify(body)
			}
			return fetch(apiBase() + path, opts)
				.then((r) => r.json())
				.then((json) => (json && json.ocs && typeof json.ocs.data !== 'undefined' ? json.ocs.data : json))
				.catch(() => null)
		}

		const loadChats = () => {
			api('GET', '/chats').then((list) => {
				if (!Array.isArray(list)) return
				chats.value = list
				if (!currentChat.value && list.length) {
					currentChat.value = list[0].id
				}
			})
		}

		const newChat = async () => {
			if (busy.value) return
			busy.value = true
			const c = await api('POST', '/chats', {})
			busy.value = false
			if (c && c.id) {
				await loadChats()
				currentChat.value = c.id
				view.value = 'chat'
			}
		}

		const selectChat = (id) => {
			currentChat.value = id
			view.value = 'chat'
		}

		const askAbout = (prompt) => {
			pendingPrompt.value = prompt
			view.value = 'chat'
		}

		const renameChat = async (id) => {
			const c = chats.value.find((x) => x.id === id)
			const name = window.prompt('New chat title:', c ? c.title : '')
			if (name === null || !name.trim()) return
			await api('POST', '/chats/' + encodeURIComponent(id) + '/title', { title: name.trim() })
			loadChats()
			}

		const deleteChat = async (id) => {
			if (!window.confirm('Delete this chat?')) return
			await api('DELETE', '/chats/' + encodeURIComponent(id))
			if (currentChat.value === id) currentChat.value = null
			loadChats()
		}

		onMounted(() => {
			loadChats()
			if (typeof window !== 'undefined' && window.addEventListener) {
				window.addEventListener('eva-ai:ask-about', (e) => {
					if (e && e.detail && e.detail.prompt) askAbout(e.detail.prompt)
				})
				window.addEventListener('eva-ai:file-context', (e) => {
					const ids = e && e.detail && Array.isArray(e.detail.fileIds)
						? e.detail.fileIds.map((x) => parseInt(x, 10)).filter((x) => Number.isFinite(x) && x > 0)
						: []
					if (ids.length === 0) return
					fileContextIds.value = ids
					view.value = 'fileContext'
					// URL anpassen, damit der User die Seite bookmarken/teilen kann.
					const url = new URL(window.location.href)
					url.searchParams.set('view', 'fileContext')
					url.searchParams.set('fileIds', ids.join(','))
					window.history.replaceState({}, '', url.toString())
				})
			}
		})

		return {
			view, mobileOpen, buildVersion,
			chats, currentChat, busy, pendingPrompt,
			fileContextIds,
			newChat, selectChat, renameChat, deleteChat, loadChats, askAbout,
			mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus,
		}
	},
}
</script>

<style scoped>
.eva-ai-app {
	width: 100%;
}

.newchat-wrap {
	padding: 10px 12px 6px;
}

.new-chat-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 9px 12px;
	width: 100%;
	box-sizing: border-box;
	border: none;
	border-radius: 10px;
	background: var(--color-primary-element, #00679c);
	color: var(--color-primary-element-text, #fff);
	font-size: 13px;
	font-weight: 600;
	font-family: inherit;
	cursor: pointer;
	transition: filter .1s;
}

.new-chat-btn:hover:not(:disabled) { filter: brightness(1.08); }
.new-chat-btn:disabled { opacity: .6; cursor: default; }

.chat-list {
	overflow-y: auto;
	max-height: 45vh;
	padding: 4px 0;
}

.chat-list-empty {
	color: var(--color-text-maxcontrast, #666);
	font-size: 12px;
	padding: 8px 14px;
}

.nav-footer {
	list-style: none;
	margin: 0;
	padding: var(--app-navigation-padding, 8px);
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
}
</style>