<template>
	<NcContent class="eva-ai-app" :app-name="'eva_ai'">
		<NcAppNavigation :title="'Eva · v' + buildVersion" @close-navigation="mobileOpen = false">
			<template #list>
				<div class="chat-toolbar" :class="{ 'is-search-open': searchOpen }">
					<button class="new-chat-btn" :class="{ 'is-compact': searchOpen }" :disabled="busy" aria-label="New chat" :title="searchOpen ? 'New chat' : null" @click="newChat">
						<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiMessagePlus" fill="currentColor" /></svg>
						<span v-if="!searchOpen">New chat</span>
					</button>
					<button v-if="!searchOpen" ref="searchToggle" class="chat-search-toggle" type="button" aria-label="Search chats" title="Search chats" :aria-expanded="searchOpen" aria-controls="chat-search-field" @click="openSearch">
						<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiMagnify" fill="currentColor" /></svg>
					</button>
					<div v-else class="chat-search-expanded" role="search" aria-label="Search chats">
						<svg class="chat-search-icon" width="17" height="17" viewBox="0 0 24 24" aria-hidden="true"><path :d="mdiMagnify" fill="currentColor" /></svg>
						<input id="chat-search-field" ref="searchInput" v-model="chatFilter" type="search" placeholder="Search chats" aria-label="Search chats" @keydown.esc="closeSearch">
						<button class="chat-search-close" type="button" aria-label="Close chat search" title="Close search" @click="closeSearch">×</button>
					</div>
				</div>
				<div class="chat-list-heading"><span>Chats</span><small>{{ chats.length }}</small></div>
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
import { ref, computed, onMounted, nextTick } from 'vue'
import ChatView from './views/ChatView.vue'
import DocumentsView from './views/DocumentsView.vue'
import SettingsView from './views/SettingsView.vue'
import FileContextChatView from './views/FileContextChatView.vue'
import { mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline, mdiMagnify } from '@mdi/js'

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
		const searchOpen = ref(false)
		const searchInput = ref(null)
		const searchToggle = ref(null)
		const openSearch = () => {
			searchOpen.value = true
			nextTick(() => searchInput.value?.focus())
		}
		const closeSearch = () => {
			searchOpen.value = false
			chatFilter.value = ''
			nextTick(() => searchToggle.value?.focus())
		}

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
			chats, currentChat, busy, chatFilter, filteredChats, searchOpen, searchInput, searchToggle,
			fileContextIds,
			newChat, selectChat, renameChat, deleteChat, loadChats, navigate, openSearch, closeSearch,
			mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline, mdiMagnify,
		}
	},
}
</script>

<style scoped>
.eva-ai-app {
	width: 100%;
	--eva-content-width: 1180px;
}

.chat-toolbar { display: flex; align-items: center; gap: 6px; padding: 8px 12px 4px; }
.new-chat-btn, .chat-search-toggle { display: flex; align-items: center; justify-content: center; height: 40px; box-sizing: border-box; border: 1px solid transparent; border-radius: var(--border-radius-element, 8px); font-family: inherit; cursor: pointer; transition: flex-basis var(--animation-quick, .2s), width var(--animation-quick, .2s), background-color var(--animation-quick, .2s), border-color var(--animation-quick, .2s); }
.new-chat-btn { flex: 1 1 auto; min-width: 0; gap: 7px; padding: 0 12px; background: var(--color-primary-element, #00679c); color: var(--color-primary-element-text, #fff); font-size: 13px; font-weight: 600; }
.new-chat-btn.is-compact { flex: 0 0 40px; width: 40px; padding: 0; }
.new-chat-btn:hover:not(:disabled) { background: var(--color-primary-element-hover, #005b89); }
.new-chat-btn:disabled { opacity: .6; cursor: default; }
.chat-search-toggle { flex: 0 0 40px; width: 40px; padding: 0; background: var(--color-background-hover, #f1f1f1); color: var(--color-main-text, #222); }
.chat-search-toggle:hover { background: var(--color-background-dark, #ddd); }
.new-chat-btn:focus-visible, .chat-search-toggle:focus-visible, .chat-search-close:focus-visible { outline: 2px solid var(--color-main-text, #222); outline-offset: 2px; }
.chat-search-expanded { display: flex; align-items: center; flex: 1 1 auto; min-width: 0; height: 40px; box-sizing: border-box; gap: 6px; padding: 0 6px 0 10px; border: 1px solid var(--color-border, #ccc); border-radius: var(--border-radius-element, 8px); background: var(--color-main-background, #fff); color: var(--color-text-maxcontrast, #666); }
.chat-search-expanded:focus-within { border-color: var(--color-primary-element, #00679c); box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary-element, #00679c) 18%, transparent); }
.chat-search-expanded input { flex: 1 1 auto; min-width: 0; height: 100%; padding: 0; border: 0; outline: 0; background: transparent; color: var(--color-main-text, #222); font: inherit; font-size: 13px; }
.chat-search-icon { flex: 0 0 auto; }
.chat-search-close { display: grid; place-items: center; flex: 0 0 28px; width: 28px; height: 28px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: var(--color-text-maxcontrast, #666); cursor: pointer; font-size: 18px; line-height: 1; }
.chat-search-close:hover { background: var(--color-background-hover, #eee); color: var(--color-main-text, #222); }

.chat-list-heading { display: flex; align-items: center; justify-content: space-between; margin: 8px 12px 4px; color: var(--color-text-maxcontrast, #666); font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; }
.chat-list-heading small { min-width: 20px; padding: 2px 5px; border-radius: 999px; background: var(--color-background-hover, #eee); font-size: 10px; letter-spacing: 0; text-align: center; }
.chat-item-actions { display: flex; align-items: center; gap: 2px; margin-right: 4px; }
.chat-action { display: grid; place-items: center; width: 27px; height: 27px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: var(--color-text-maxcontrast, #666); cursor: pointer; opacity: .75; }
.chat-action:hover, .chat-action:focus-visible { background: var(--color-background-hover, #eee); color: var(--color-main-text, #222); opacity: 1; outline: 2px solid var(--color-primary-element, #00679c); outline-offset: 1px; }
.chat-action-delete:hover, .chat-action-delete:focus-visible { background: color-mix(in srgb, var(--color-error, #e9322d) 12%, transparent); color: var(--color-error, #e9322d); }

.chat-list {
	flex: 1 1 auto;
	min-height: 0;
	max-height: none;
	overflow-y: auto;
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

@media (max-width: 360px) {
	.chat-toolbar { padding-inline: 8px; }
	.chat-search-expanded { padding-inline-start: 8px; gap: 4px; }
}
</style>