<template>
	<NcContent class="eva-ai-app" :app-name="'eva_ai'">
		<NcAppNavigation :title="'Eva · v' + buildVersion" @close-navigation="mobileOpen = false">
			<template #search>
				<NcAppNavigationSearch v-model="chatFilter" label="Search chats" placeholder="Search chats" />
			</template>
			<template #list>
				<li class="new-chat-container">
					<NcButton
						class="new-chat-button"
						variant="tertiary"
						size="normal"
						alignment="start"
						:wide="true"
						:disabled="busy"
						aria-label="Start a new chat"
						@click="newChat">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiMessagePlus" fill="currentColor" /></svg>
						</template>
						New chat
					</NcButton>
				</li>
				<li class="chat-list-heading">
					<span>Chats</span>
					<NcCounterBubble :count="chats.length" />
				</li>
				<NcAppNavigationItem
					v-for="c in filteredChats"
					:key="c.id"
					:name="c.title"
					:active="view === 'chat' && c.id === currentChat"
					:force-menu="true"
					:title="c.title + ' · ' + c.count + ' messages'"
					@click="selectChat(c.id)">
					<template #icon>
						<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiChatProcessing" fill="currentColor" /></svg>
					</template>
					<template #actions>
						<NcActionButton aria-label="Rename chat" :close-after-click="true" @click.stop="renameChat(c.id)">
							<template #icon><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiPencilOutline" fill="currentColor" /></svg></template>
							Rename chat
						</NcActionButton>
						<NcActionButton aria-label="Delete chat" :close-after-click="true" @click.stop="deleteChat(c.id)">
							<template #icon><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiTrashCanOutline" fill="currentColor" /></svg></template>
							Delete chat
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
				<li v-if="!chats.length" class="chat-list-empty">No chats yet — start a new one.</li>
				<li v-else-if="!filteredChats.length" class="chat-list-empty">No chats match your search.</li>
			</template>
			<template #footer>
				<ul class="nav-footer">
					<NcAppNavigationItem
						:name="'Documents'"
						:active="view === 'docs'"
						@click="navigate('docs')">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiFileDocumentOutline" fill="currentColor" /></svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="'Settings'"
						:active="view === 'settings'"
						@click="navigate('settings')">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiTune" fill="currentColor" /></svg>
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<ChatView v-if="view === 'chat'" :chat-id="currentChat" @chat-updated="loadChats" />
			<FileContextChatView v-else-if="view === 'fileContext'" :file-ids="fileContextIds" />
			<DocumentsView v-else-if="view === 'docs'" />
			<SettingsView v-else />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import ChatView from './views/ChatView.vue'
import DocumentsView from './views/DocumentsView.vue'
import SettingsView from './views/SettingsView.vue'
import FileContextChatView from './views/FileContextChatView.vue'
import { mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline } from '@mdi/js'
import { NcCounterBubble } from '@nextcloud/vue'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'

export default {
	name: 'EvaAiApp',
	components: { ChatView, DocumentsView, SettingsView, FileContextChatView, NcCounterBubble, NcAppNavigationSearch },
	setup() {
		const params = new URLSearchParams(window.location.search)
		const initialFileIdsParam = params.get('fileIds')
		const initialFileIds = initialFileIdsParam
			? initialFileIdsParam.split(',').map((x) => parseInt(x, 10)).filter((x) => Number.isFinite(x) && x > 0)
			: []
		const path = window.location.pathname.replace(/\/+$/, '')
		const pathView = path.endsWith('/settings')
			? 'settings'
			: path.endsWith('/documents')
				? 'docs'
				: 'chat'
		const initial = params.get('view') === 'fileContext'
			? 'fileContext'
			: params.get('view') === 'docs'
				? 'docs'
				: params.get('view') === 'settings'
					? 'settings'
					: pathView
		const view = ref(initial)
		const fileContextIds = ref(initialFileIds)
		const mobileOpen = ref(false)
		const buildVersion = appVersion

		const chats = ref([])
		const currentChat = ref(null)
		const busy = ref(false)
		const chatFilter = ref('')
		const filteredChats = computed(() => {
			const query = chatFilter.value.trim().toLowerCase()
			return query ? chats.value.filter((chat) => String(chat.title || '').toLowerCase().includes(query)) : chats.value
		})

		const appRootPath = () => {
			const current = window.location.pathname.replace(/\/+$/, '')
			return current.replace(/\/(settings|documents|app|standalone)$/, '') || current
		}
		const navigate = (nextView) => {
			view.value = nextView
			const url = new URL(window.location.href)
			url.searchParams.delete('view')
			url.searchParams.delete('fileIds')
			url.pathname = nextView === 'chat' ? appRootPath() : appRootPath() + '/' + (nextView === 'docs' ? 'documents' : nextView)
			window.history.pushState({}, '', url.toString())
		}

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
			return api('GET', '/chats').then((list) => {
				if (!Array.isArray(list)) return
				chats.value = list
				if (!currentChat.value || !list.some((chat) => chat.id === currentChat.value)) {
					currentChat.value = list.length ? list[0].id : null
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
				navigate('chat')
			}
		}

		const selectChat = (id) => {
			currentChat.value = id
			navigate('chat')
		}


		const renameChat = async (id) => {
			const c = chats.value.find((x) => x.id === id)
			const name = window.prompt('New chat title:', c ? c.title : '')
			if (name === null || !name.trim()) return
			await api('POST', '/chats/' + encodeURIComponent(id) + '/title', { title: name.trim() })
			await loadChats()
		}

		const deleteChat = async (id) => {
			if (!window.confirm('Delete this chat?')) return
			await api('DELETE', '/chats/' + encodeURIComponent(id))
			if (currentChat.value === id) currentChat.value = null
			await loadChats()
		}

		onMounted(() => {
			loadChats()
			if (typeof window !== 'undefined' && window.addEventListener) {
				window.addEventListener('popstate', () => {
					const current = window.location.pathname.replace(/\/+$/, '')
					view.value = current.endsWith('/settings') ? 'settings' : current.endsWith('/documents') ? 'docs' : 'chat'
				})
				window.addEventListener('eva-ai:chats-cleared', () => {
					currentChat.value = null
					loadChats()
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
			chats, currentChat, busy, chatFilter, filteredChats,
			fileContextIds,
			newChat, selectChat, renameChat, deleteChat, loadChats, navigate,
			mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline,
		}
	},
}
</script>

<style scoped>
.eva-ai-app {
	width: 100%;
	--eva-content-width: 1180px;
}

.new-chat-container {
	background: transparent;
	list-style: none;
	margin: 0;
	padding: var(--app-navigation-padding, 8px);
	position: sticky;
	top: 0;
	z-index: 2;
}

.chat-list-heading {
	align-items: center;
	color: var(--color-text-maxcontrast, #666);
	display: flex;
	font-size: 12px;
	font-weight: 600;
	gap: 6px;
	list-style: none;
	padding: 8px var(--app-navigation-padding, 8px) 4px;
	text-transform: uppercase;
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