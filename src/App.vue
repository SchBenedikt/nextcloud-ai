<template>
	<NcContent class="eva-ai-app" :app-name="'eva_ai'">
		<NcAppNavigation :title="'Eva · v' + buildVersion" @close-navigation="mobileOpen = false">
			<template #list>
				<button class="new-chat-btn" :disabled="busy" @click="newChat">
					<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiMessagePlus" fill="currentColor" /></svg>
					<span>New chat</span>
				</button>
				<div class="chat-list-tools">
					<div class="chat-list-heading"><span>Chats</span><small>{{ chats.length }}</small></div>
					<div class="chat-search">
						<input v-model="chatFilter" type="search" placeholder="Search chats" aria-label="Search chats" @keyup.esc="chatFilter = ''">
						<button v-if="chatFilter" class="clear-chat-search" type="button" aria-label="Clear chat search" title="Clear search" @click="chatFilter = ''">×</button>
					</div>
				</div>
				<div class="chat-list">
					<NcAppNavigationItem
						v-for="c in filteredChats"
						:key="c.id"
						:name="c.title"
						:active="view === 'chat' && c.id === currentChat"
						:title="c.title + ' · ' + c.count + ' messages'"
						@click="selectChat(c.id)">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiChatProcessing" fill="currentColor" /></svg>
						</template>
						<template #actions>
							<div class="chat-item-actions" @click.stop>
								<button class="chat-action" type="button" aria-label="Rename chat" title="Rename chat" @click.stop="renameChat(c.id)">
									<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiPencilOutline" fill="currentColor" /></svg>
								</button>
								<button class="chat-action chat-action-delete" type="button" aria-label="Delete chat" title="Delete chat" @click.stop="deleteChat(c.id)">
									<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiTrashCanOutline" fill="currentColor" /></svg>
								</button>
							</div>
						</template>
					</NcAppNavigationItem>
					<div v-if="!chats.length" class="chat-list-empty">No chats yet — start a new one.</div>
					<div v-else-if="!filteredChats.length" class="chat-list-empty">No chats match your search.</div>
				</div>
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

export default {
	name: 'EvaAiApp',
	components: { ChatView, DocumentsView, SettingsView, FileContextChatView },
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

.chat-list-tools { padding: 12px 12px 6px; }
.chat-list-heading { display: flex; align-items: center; justify-content: space-between; margin: 0 2px 7px; color: var(--color-text-maxcontrast, #666); font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; }
.chat-list-heading small { min-width: 20px; padding: 2px 5px; border-radius: 999px; background: var(--color-background-hover, #eee); font-size: 10px; letter-spacing: 0; text-align: center; }
.chat-search { position: relative; }
.chat-search input { width: 100%; box-sizing: border-box; padding: 7px 28px 7px 9px; border: 1px solid var(--color-border, #ddd); border-radius: 7px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: inherit; font-size: 12px; }
.chat-search input:focus { border-color: var(--color-primary-element, #00679c); outline: 2px solid color-mix(in srgb, var(--color-primary-element, #00679c) 18%, transparent); outline-offset: 0; }
.clear-chat-search { position: absolute; top: 50%; right: 5px; width: 22px; height: 22px; transform: translateY(-50%); border: 0; border-radius: 5px; background: transparent; color: var(--color-text-maxcontrast, #666); cursor: pointer; font-size: 17px; line-height: 1; }
.clear-chat-search:hover { background: var(--color-background-hover, #eee); color: var(--color-main-text, #222); }
.chat-item-actions { display: flex; align-items: center; gap: 2px; margin-right: 4px; }
.chat-action { display: grid; place-items: center; width: 27px; height: 27px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: var(--color-text-maxcontrast, #666); cursor: pointer; opacity: .75; }
.chat-action:hover, .chat-action:focus-visible { background: var(--color-background-hover, #eee); color: var(--color-main-text, #222); opacity: 1; outline: 2px solid var(--color-primary-element, #00679c); outline-offset: 1px; }
.chat-action-delete:hover, .chat-action-delete:focus-visible { background: color-mix(in srgb, var(--color-error, #e9322d) 12%, transparent); color: var(--color-error, #e9322d); }

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