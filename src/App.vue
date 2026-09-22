<template>
	<NcContent v-if="adminMode" class="eva-ai-admin" :app-name="'eva_ai'">
		<NcAppContent>
			<AdminView />
		</NcAppContent>
	</NcContent>
	<NcContent v-else class="eva-ai-app" :app-name="'eva_ai'">
		<NcAppNavigation :title="$t('Eva · v') + buildVersion" @close-navigation="mobileOpen = false">
			<template #search>
				<NcAppNavigationSearch v-model="chatFilter" :label="$t('Search chats')" :placeholder="$t('Search chats')" />
				<div class="new-chat-container">
					<NcButton
						class="new-chat-button"
						variant="primary"
						size="normal"
						:wide="true"
						:disabled="busy"
						:aria-label="$t('Start a new chat')"
					@click="() => newChat()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiMessagePlus" :size="16" aria-hidden="true" />
						</template>
						{{ $t('New chat') }}
					</NcButton>
				</div>
			</template>
			<template #list>
				<NcAppNavigationItem
					class="start-nav-item"
					:name="$t('Home')"
					:active="view === 'home'"
					@click="navigate('home')">
					<template #icon>
						<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiViewDashboardOutline" fill="currentColor" /></svg>
					</template>
				</NcAppNavigationItem>
				<li class="chat-list-heading">
					<span>{{ $t('Chats') }}</span>
					<NcCounterBubble :count="activeChats.length" />
				</li>
				<template v-for="item in navItems" :key="item.key">
					<li
						v-if="item.type === 'heading'"
						class="chat-list-heading"
						:class="{ 'chat-list-heading--archived': item.archived, 'chat-list-heading--folder': item.folder }"
						:title="item.folder ? (item.collapsed ? $t('Expand folder') : $t('Collapse folder')) : undefined"
						@click="item.folder ? toggleFolder(item.folderName) : (item.archived && (showArchived = !showArchived))">
						<svg v-if="item.icon" width="14" height="14" viewBox="0 0 24 24" class="chat-list-heading-icon"><path :d="item.icon" fill="currentColor" /></svg>
						<span>{{ item.label }}</span>
						<NcCounterBubble v-if="item.count !== undefined" :count="item.count" />
						<svg v-if="item.archived || item.folder" width="16" height="16" viewBox="0 0 24 24" :class="{ rotated: item.archived && showArchived, 'chevron-collapsed': item.folder && item.collapsed }"><path :d="mdiChevronDown" fill="currentColor" /></svg>
					</li>
					<NcAppNavigationItem
						v-else
						v-show="!item.archivedChat || showArchived"
						:class="{ 'chat-item--nested': item.nested }"
						:name="itemName(item.chat)"
						:active="view === 'chat' && item.chat.id === currentChat"
						:force-menu="true"
						:title="itemTip(item.chat)"
						@click="selectChat(item.chat.id)">
						<template #icon>
							<svg v-if="item.chat.archived" width="16" height="16" viewBox="0 0 24 24"><path :d="mdiArchiveOutline" fill="currentColor" /></svg>
							<svg v-else width="16" height="16" viewBox="0 0 24 24"><path :d="mdiChatProcessing" fill="currentColor" /></svg>
						</template>
						<template #actions>
							<NcActionButton v-if="!item.chat.archived" :aria-label="item.chat.pinned ? $t('Unpin chat') : $t('Pin chat')" :close-after-click="true" @click.stop="updateChatMeta(item.chat.id, { pinned: !item.chat.pinned })">
								<template #icon><NcIconSvgWrapper :path="item.chat.pinned ? mdiPinOffOutline : mdiPinOutline" :size="16" aria-hidden="true" /></template>
								{{ item.chat.pinned ? $t('Unpin chat') : $t('Pin chat') }}
							</NcActionButton>
							<NcActionButton v-if="!item.chat.archived && item.chat.folder" :aria-label="$t('Remove from folder')" :close-after-click="true" @click.stop="updateChatMeta(item.chat.id, { folder: '' })">
								<template #icon><NcIconSvgWrapper :path="mdiFolderRemoveOutline" :size="16" aria-hidden="true" /></template>
								{{ $t('Remove from folder') }}
							</NcActionButton>
							<NcActionButton v-if="!item.chat.archived" :aria-label="item.chat.folder ? $t('Move to folder') : $t('Add to folder')" :close-after-click="true" @click.stop="pickFolder(item.chat)">
								<template #icon><NcIconSvgWrapper :path="mdiFolderPlusOutline" :size="16" aria-hidden="true" /></template>
								{{ item.chat.folder ? $t('Move to folder') : $t('Add to folder') }}
							</NcActionButton>
							<NcActionButton v-if="!item.chat.archived" :aria-label="$t('Chat with folder')" :close-after-click="true" @click.stop="pickScope(item.chat)">
								<template #icon><NcIconSvgWrapper :path="mdiFolderSearchOutline" :size="16" aria-hidden="true" /></template>
								{{ item.chat.scopePath ? $t('Change folder scope') : $t('Chat with folder') }}
							</NcActionButton>
							<NcActionButton v-if="!item.chat.archived && item.chat.scopePath" :aria-label="$t('Remove folder scope')" :close-after-click="true" @click.stop="updateChatMeta(item.chat.id, { scopePath: '' })">
								<template #icon><NcIconSvgWrapper :path="mdiFolderOffOutline" :size="16" aria-hidden="true" /></template>
								{{ $t('Remove folder scope') }}
							</NcActionButton>
							<NcActionButton v-if="!item.chat.archived" :aria-label="$t('Archive chat')" :close-after-click="true" @click.stop="updateChatMeta(item.chat.id, { archived: true })">
								<template #icon><NcIconSvgWrapper :path="mdiArchiveOutline" :size="16" aria-hidden="true" /></template>
								{{ $t('Archive chat') }}
							</NcActionButton>
							<NcActionButton v-if="item.chat.archived" :aria-label="$t('Unarchive chat')" :close-after-click="true" @click.stop="updateChatMeta(item.chat.id, { archived: false })">
								<template #icon><NcIconSvgWrapper :path="mdiArchiveArrowUpOutline" :size="16" aria-hidden="true" /></template>
								{{ $t('Unarchive chat') }}
							</NcActionButton>
							<NcActionSeparator />
							<NcActionButton :aria-label="$t('Rename chat')" :close-after-click="true" @click.stop="renameChat(item.chat.id)">
								<template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="16" aria-hidden="true" /></template>
								{{ $t('Rename chat') }}
							</NcActionButton>
							<NcActionButton :aria-label="$t('Delete chat')" :close-after-click="true" @click.stop="deleteChat(item.chat.id)">
								<template #icon><NcIconSvgWrapper :path="mdiTrashCanOutline" :size="16" aria-hidden="true" /></template>
								{{ $t('Delete chat') }}
							</NcActionButton>
						</template>
					</NcAppNavigationItem>
				</template>
				<li v-if="apiError" class="chat-list-error" role="alert">{{ apiError }}</li>
				<li v-if="chatsLoading" class="chat-list-empty" role="status">{{ $t('Loading chats…') }}</li>
				<li v-else-if="!apiError && !chats.length" class="chat-list-empty">{{ $t('No chats yet — start a new one.') }}</li>
				<li v-else-if="!apiError && chatFilter.trim() && !listChats.length" class="chat-list-empty">{{ $t('No chats match your search.') }}</li>
			</template>
			<template #footer>
				<ul class="nav-footer">
					<NcAppNavigationItem
						:name="$t('Documents')"
						:active="view === 'docs'"
						@click="navigate('docs')">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiFileDocumentOutline" fill="currentColor" /></svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="$t('Metrics')"
						:active="view === 'metrics'"
						@click="navigate('metrics')">
						<template #icon><svg width="16" height="16" viewBox="0 0 24 24"><path d="M5 19V5h2v14H5zm6 0V9h2v10h-2zm6 0V3h2v16h-2z" fill="currentColor" /></svg></template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="$t('Agent runs')"
						:active="view === 'runs'"
						@click="navigate('runs')">
						<template #icon><svg width="16" height="16" viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10h-2a8 8 0 1 1-8-8V2zm1 0v9h9v2h-11V2h2z" fill="currentColor" /></svg></template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="$t('Settings')"
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
			<HomeView v-if="view === 'home'" @new-chat="() => newChat()" @navigate="navigate" @open-chat="selectChat" />
			<ChatView v-else-if="view === 'chat'" :chat-id="currentChat" :initial-prompt="pendingPrompt" :auto-send="!!pendingPrompt" @chat-updated="loadChats" @prompt-consumed="pendingPrompt = ''" />
			<FileContextChatView v-else-if="view === 'fileContext'" :file-ids="fileContextIds" />
			<DocumentsView v-else-if="view === 'docs'" />
			<MetricsView v-else-if="view === 'metrics'" />
			<AgentRunsView v-else-if="view === 'runs'" />
			<SettingsView v-else />
		</NcAppContent>
		<NcModal v-if="folderPickerOpen" size="small" :name="pickerMode === 'scope' ? $t('Chat with folder') : $t('Move to folder')" @close="folderPickerOpen = false">
			<div class="folder-picker">
				<p v-if="pickerMode === 'scope'" class="folder-picker-hint">{{ $t('Only documents from this folder are used as context:') }}</p>
				<p v-else class="folder-picker-hint">{{ $t('Choose a folder for this chat:') }}</p>
				<ul class="folder-picker-list">
					<li v-if="foldersLoading" class="folder-picker-state" role="status">{{ $t('Loading folders…') }}</li>
					<li v-if="foldersLoadError" class="folder-picker-state folder-picker-state--error" role="alert"><span>{{ $t('Folders could not be loaded: {error}', { error: foldersLoadError }) }}</span><NcButton variant="tertiary" :disabled="foldersLoading" @click="loadFolders">{{ $t('Retry') }}</NcButton></li>
					<li v-if="!foldersLoading && !foldersLoadError && !folders.length" class="folder-picker-state">{{ $t('No folders have been created yet.') }}</li>
					<li v-if="pickerMode === 'scope' ? folderChat && folderChat.scopePath : folderChat && folderChat.folder">
						<button type="button" class="folder-picker-row" @click="assignTarget('')">
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiFolderRemoveOutline" fill="currentColor" /></svg>
							<span>{{ pickerMode === 'scope' ? $t('No folder scope') : $t('No folder') }}</span>
						</button>
					</li>
					<li v-for="f in folders" :key="f.name">
						<button
							type="button"
							class="folder-picker-row"
							:class="{ 'folder-picker-row--active': pickerMode === 'scope' ? folderChat && folderChat.scopePath === f.name : folderChat && folderChat.folder === f.name }"
							@click="assignTarget(f.name)">
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiFolderOutline" fill="currentColor" /></svg>
							<span>{{ f.name }}</span>
						</button>
					</li>
				</ul>
				<form class="folder-picker-create" @submit.prevent="createAndAssign">
					<input v-model="newFolderName" class="folder-picker-input" type="text" :placeholder="$t('New folder name')" />
					<button type="submit" class="folder-picker-submit" :disabled="!newFolderName.trim()">{{ pickerMode === 'scope' ? $t('Scope to folder') : $t('Create folder') }}</button>
				</form>
			</div>
		</NcModal>
		<NcModal v-if="chatDialog" size="small" :name="chatDialog.mode === 'rename' ? $t('Rename chat') : $t('Delete chat')" @close="closeChatDialog">
			<form class="chat-dialog" @submit.prevent="confirmChatDialog">
				<p v-if="chatDialog.mode === 'delete'" class="chat-dialog__copy">{{ $t('Delete “{title}”? This permanently removes the conversation and its messages.', { title: chatDialog.title }) }}</p>
				<NcTextField v-else v-model="chatDialog.draft" :label="$t('Chat title')" :label-outside="true" :disabled="chatDialog.busy" maxlength="120" autofocus />
				<p v-if="chatDialog.error" class="chat-dialog__error" role="alert">{{ chatDialog.error }}</p>
				<div class="chat-dialog__actions">
					<NcButton type="button" variant="tertiary" :disabled="chatDialog.busy" @click="closeChatDialog">{{ $t('Cancel') }}</NcButton>
					<NcButton v-if="chatDialog.mode === 'rename'" type="submit" variant="primary" :loading="chatDialog.busy" :disabled="chatDialog.busy || !chatDialog.draft.trim()">{{ $t('Save title') }}</NcButton>
					<NcButton v-else type="submit" variant="error" :loading="chatDialog.busy" :disabled="chatDialog.busy">{{ $t('Delete chat') }}</NcButton>
				</div>
			</form>
		</NcModal>
	</NcContent>
</template>

<script>
import { ref, computed, watch, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue'
import HomeView from './views/HomeView.vue'
import ChatView from './views/ChatView.vue'
// Keep the initial chat bundle small. These views are opened on demand and
// loaded as independent chunks, which is especially important on slower
// production Nextcloud instances.
const DocumentsView = defineAsyncComponent(() => import('./views/DocumentsView.vue'))
const MetricsView = defineAsyncComponent(() => import('./views/MetricsView.vue'))
const SettingsView = defineAsyncComponent(() => import('./views/SettingsView.vue'))
const AgentRunsView = defineAsyncComponent(() => import('./views/AgentRunsView.vue'))
const FileContextChatView = defineAsyncComponent(() => import('./views/FileContextChatView.vue'))
const AdminView = defineAsyncComponent(() => import('./views/AdminView.vue'))
import { mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline, mdiChevronDown, mdiViewDashboardOutline, mdiPinOutline, mdiPinOffOutline, mdiFolderOutline, mdiFolderPlusOutline, mdiFolderRemoveOutline, mdiFolderSearchOutline, mdiFolderOffOutline, mdiArchiveOutline, mdiArchiveArrowUpOutline } from '@mdi/js'
import { NcCounterBubble, NcTextField } from '@nextcloud/vue'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { api as requestApi, errMsg } from './lib/api'
import { translate as t } from './lib/i18n'

export default {
	name: 'EvaAiApp',
	components: { HomeView, ChatView, DocumentsView, MetricsView, SettingsView, AgentRunsView, FileContextChatView, AdminView, NcCounterBubble, NcTextField, NcAppNavigationSearch, NcActionButton, NcActionSeparator, NcIconSvgWrapper },		setup() {
			// Admin settings form (Issue #82): the template mounts the same app
			// with data-admin="1" and renders the admin dashboard instead.
			const rootEl = document.getElementById('eva_ai-root')
			const isAdminMode = !!(rootEl && rootEl.dataset && rootEl.dataset.admin === '1')

		const parseFileIds = (value) => [...new Set(String(value || '').split(',').map((part) => Number.parseInt(part, 10)).filter((id) => Number.isSafeInteger(id) && id > 0))]
		const resolveRoute = (location) => {
			const url = new URL(location.href)
			const explicitView = url.searchParams.get('view')
			const validViews = ['home', 'docs', 'settings', 'metrics', 'runs', 'fileContext']
			if (validViews.includes(explicitView)) {
				return { view: explicitView, chatId: null, fileIds: explicitView === 'fileContext' ? parseFileIds(url.searchParams.get('fileIds')) : [] }
			}
			const path = url.pathname.replace(/\/+$/, '')
			const pathView = path.endsWith('/settings') ? 'settings'
				: path.endsWith('/documents') ? 'docs'
					: path.endsWith('/metrics') ? 'metrics'
						: path.endsWith('/runs') ? 'runs' : 'home'
			const chatId = url.searchParams.get('chat')
			return chatId ? { view: 'chat', chatId, fileIds: [] } : { view: pathView, chatId: null, fileIds: [] }
		}
		// Deep links from the dashboard widget (?chat=new | ?chat=<id>) use the
		// same route parser as browser back/forward navigation.
		const initialRoute = resolveRoute(window.location)
		const initialChatParam = new URLSearchParams(window.location.search).get('chat')
		const view = ref(initialRoute.view)
		const fileContextIds = ref(initialRoute.fileIds)
		const mobileOpen = ref(false)
		const buildVersion = appVersion

		const chats = ref([])
		const folders = ref([])
		const currentChat = ref(null)
		const busy = ref(false)
		const chatsLoading = ref(true)
		// First message typed on the dashboard hero: passed to the chat view
		// and sent automatically as soon as the conversation is open.
		const pendingPrompt = ref('')
		const chatFilter = ref('')
		const apiError = ref('')
		const chatDialog = ref(null)
		const showArchived = ref(false)
		// Per-folder collapse state, persisted across reloads.
		const collapsedFolders = ref(loadCollapsedFolders())
		const toggleFolder = (name) => {
			if (collapsedFolders.value[name]) {
				delete collapsedFolders.value[name]
			} else {
				collapsedFolders.value[name] = true
			}
			try {
				window.localStorage.setItem('eva_ai_collapsed_folders', JSON.stringify(collapsedFolders.value))
			} catch (error) {
				// Private mode or quota — collapsing still works for this session.
			}
		}
		// Folder picker modal (Issue #87). 'organize' assigns the chat's
		// folder; 'scope' binds the chat's RAG retrieval to a folder (#88).
		const folderPickerOpen = ref(false)
		const pickerMode = ref('organize')
		const folderChat = ref(null)
		const newFolderName = ref('')
		const foldersLoading = ref(false)
		const foldersLoadError = ref('')
		// Sidebar sections (Issue #87): pinned on top, folder groups, then the
		// remaining chats, archived chats collapsed at the bottom.
		const pinnedChats = computed(() => chats.value.filter((c) => c.pinned && !c.archived))
		const folderGroups = computed(() => {
			const groups = new Map()
			for (const c of chats.value) {
				if (c.archived || c.pinned || !c.folder) continue
				if (!groups.has(c.folder)) groups.set(c.folder, [])
				groups.get(c.folder).push(c)
			}
			return [...groups.entries()]
				.sort((a, b) => a[0].localeCompare(b[0]))
				.map(([name, group]) => ({ name, chats: group }))
		})
		const plainChats = computed(() => chats.value.filter((c) => !c.archived && !c.pinned && !c.folder))
		// Search results (null while not searching). The server matches chat
		// titles AND message text (Issue #152), so results may carry a snippet
		// + matchCount from the first content hit.
		const searchResults = ref(null)
		// One flat, ordered list of headings + chat items for the sidebar.
		const navItems = computed(() => {
			const query = chatFilter.value.trim()
			if (query) {
				const base = searchResults.value || chats.value.filter((chat) => String(chat.title || '').toLowerCase().includes(query.toLowerCase()))
				return base.filter((c) => !c.archived).map((c) => ({ type: 'chat', key: 'chat-' + c.id, chat: c }))
			}
			const items = []
			if (pinnedChats.value.length) {
				items.push({ type: 'heading', key: 'h-pinned', label: t('Pinned'), icon: mdiPinOutline })
				for (const c of pinnedChats.value) items.push({ type: 'chat', key: 'chat-' + c.id, chat: c })
			}
			for (const group of folderGroups.value) {
				const collapsed = !!collapsedFolders.value[group.name]
				// A folder without a stored name (and one saved under the old
				// hardcoded German default) is labelled in the reader's language
				// instead of showing a German word in an English UI (issue #190).
				const label = folderLabel(group.name)
				items.push({ type: 'heading', key: 'h-folder-' + group.name, label, icon: mdiFolderOutline, folder: true, folderName: group.name, count: group.chats.length, collapsed })
				if (collapsed) continue
				// Chats inside a folder are visually nested under their heading.
				for (const c of group.chats) items.push({ type: 'chat', key: 'chat-' + c.id, chat: c, nested: true })
			}
			for (const c of plainChats.value) items.push({ type: 'chat', key: 'chat-' + c.id, chat: c })
			if (archivedChats.value.length) {
				items.push({ type: 'heading', key: 'h-archived', label: t('Archived') + ' (' + archivedChats.value.length + ')', icon: mdiArchiveOutline, archived: true })
				for (const c of archivedChats.value) items.push({ type: 'chat', key: 'chat-' + c.id, chat: c, archivedChat: true })
			}
			return items
		})
		const activeChats = computed(() => chats.value.filter((c) => !c.archived))
		const archivedChats = computed(() => chats.value.filter((c) => c.archived))
		const listChats = computed(() => {
			const query = chatFilter.value.trim().toLowerCase()
			if (!query) return []
			if (searchResults.value) return searchResults.value.filter((c) => !c.archived)
			return chats.value.filter((chat) => !chat.archived && String(chat.title || '').toLowerCase().includes(query))
		})
		let searchTimer = null
		const searchMessages = async () => {
			const query = chatFilter.value.trim()
			searchResults.value = null
			if (!query) return
			searchTimer = null
			try {
				const list = await requestApi('GET', '/chats', { search: query })
				if (chatFilter.value.trim() === query && Array.isArray(list)) searchResults.value = list
			} catch (error) {
				apiError.value = t('Chat search unavailable: {error}', { error: errMsg(error) })
			}
		}
		watch(chatFilter, () => {
			if (searchTimer !== null) window.clearTimeout(searchTimer)
			searchTimer = window.setTimeout(searchMessages, 220)
		})
		// For content hits show the matched excerpt instead of an unrelated
		// auto-generated title; the real title stays visible on hover.
		// Untitled chats get the translated placeholder instead of the legacy
		// hardcoded German default the server used to store.
		const displayTitle = (chat) => chat.title || t('New chat')
		// A folder name is user data, so it is never stored in a translated form;
		// an empty one (or one saved under the old German default) gets a label in
		// the reader's language.
		const folderLabel = (name) => {
			const clean = String(name || '').trim()
			return (clean === '' || clean === 'Unbenannt') ? t('Untitled folder') : clean
		}
		const itemName = (chat) => {
			const query = chatFilter.value.trim().toLowerCase()
			const titleHit = query && String(chat.title || '').toLowerCase().includes(query)
			return !titleHit && chat.snippet ? chat.snippet : displayTitle(chat)
		}
		const itemTip = (chat) => {
			const title = displayTitle(chat)
			const parts = [t('{title} · {count} messages', { title, count: chat.count })]
			if (chat.folder) parts.push(t('Folder: {folder}', { folder: chat.folder }))
			if (chat.scopePath) parts.push(t('Folder scope: {path}', { path: chat.scopePath }))
			if (chat.snippet) parts.push(chat.snippet)
			if (chat.matchCount) parts.push(t('{count} message matches', { count: chat.matchCount }))
			return parts.join(' — ')
		}

		// Hoisted function declaration: used by the collapsedFolders ref above.
		function loadCollapsedFolders() {
			try {
				const raw = window.localStorage.getItem('eva_ai_collapsed_folders')
				const parsed = raw ? JSON.parse(raw) : {}
				return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
			} catch (error) {
				return {}
			}
		}

		const appRootPath = () => {
			const current = window.location.pathname.replace(/\/+$/, '')
			return current.replace(/\/(settings|documents|metrics|runs|app|standalone)$/, '') || current
		}
		const navigate = (nextView) => {
			view.value = nextView
			const url = new URL(window.location.href)
			url.searchParams.delete('view')
			url.searchParams.delete('fileIds')
			if (nextView === 'chat' && currentChat.value) {
				// Keep the open conversation in the URL so refresh and
				// dashboard links land on the same chat.
				url.searchParams.set('chat', currentChat.value)
			} else {
				url.searchParams.delete('chat')
			}
			// 'home' and 'chat' share the app root path; only docs/settings
			// get their own suffix.
			url.pathname = nextView === 'chat' || nextView === 'home'
				? appRootPath()
				: appRootPath() + '/' + (nextView === 'docs' ? 'documents' : nextView)
			window.history.pushState({}, '', url.toString())
		}

		const loadChats = () => {
			chatsLoading.value = true
			apiError.value = ''
			return requestApi('GET', '/chats').then((list) => {
				apiError.value = ''
				if (!Array.isArray(list)) throw new Error(t('The chat list response was invalid.'))
				chats.value = list
				if (!currentChat.value || !list.some((chat) => chat.id === currentChat.value)) {
					// Prefer a chat the user can actually see (archived chats
					// are collapsed by default).
					const first = list.find((chat) => !chat.archived) || list[0]
					currentChat.value = first ? first.id : null
				}
			}).catch((error) => {
				apiError.value = t('Chat list unavailable: {error}', { error: errMsg(error) })
				return []
			}).finally(() => {
				chatsLoading.value = false
			})
		}

		const loadFolders = () => {
			foldersLoading.value = true
			foldersLoadError.value = ''
			return requestApi('GET', '/folders').then((list) => {
				if (!Array.isArray(list)) throw new Error(t('The folder list response was invalid.'))
				folders.value = list
			}).catch((error) => {
				foldersLoadError.value = errMsg(error)
			}).finally(() => {
				foldersLoading.value = false
			})
		}

		// Apply pinned/folder/archived metadata and refresh both lists.
		const updateChatMeta = async (id, meta) => {
			try {
				await requestApi('POST', '/chats/' + encodeURIComponent(id) + '/meta', meta)
				await loadChats()
				await loadFolders()
			} catch (error) {
				apiError.value = t('The chat could not be updated: {error}', { error: errMsg(error) })
			}
		}

		// Folder assignment (Issue #87): pick an existing folder, clear the
		// assignment, or type a new name — unknown names create the folder.
		// In 'scope' mode the same picker binds the chat's RAG scope instead
		// ("Chat with this folder", Issue #88).
		const assignTarget = async (name) => {
			const id = folderChat.value && folderChat.value.id
			if (!id) return
			const mode = pickerMode.value
			folderPickerOpen.value = false
			newFolderName.value = ''
			folderChat.value = null
			await updateChatMeta(id, mode === 'scope' ? { scopePath: name } : { folder: name })
		}
		const createAndAssign = () => {
			const name = newFolderName.value.trim()
			if (!name) return
			assignTarget(name)
		}
		const pickFolder = (chat) => {
			pickerMode.value = 'organize'
			folderChat.value = chat
			newFolderName.value = ''
			folderPickerOpen.value = true
			loadFolders()
		}
		const pickScope = (chat) => {
			pickerMode.value = 'scope'
			folderChat.value = chat
			newFolderName.value = ''
			folderPickerOpen.value = true
			loadFolders()
		}

		const newChat = async (prompt = '') => {
			if (busy.value) return
			// Vue passes the click event to handlers without an explicit
			// argument. Never serialize that PointerEvent as a chat prompt.
			if (typeof prompt !== 'string') prompt = ''
			busy.value = true
			try {
				const c = await requestApi('POST', '/chats', {})
				if (!c || !c.id) throw new Error(t('The server returned no chat ID.'))
				pendingPrompt.value = String(prompt || '').trim()
				await loadChats()
				currentChat.value = c.id
				navigate('chat')
				apiError.value = ''
			} catch (error) {
				apiError.value = t('A new chat could not be created: {error}', { error: errMsg(error) })
			} finally {
				busy.value = false
			}
		}

		const selectChat = (id) => {
			// A dashboard prompt belongs only to the chat that was just created.
			// Clear it before switching to an existing conversation.
			pendingPrompt.value = ''
			currentChat.value = id
			// Clear an active message search so the normal chat list returns.
			if (chatFilter.value.trim()) {
				chatFilter.value = ''
				searchResults.value = null
			}
			navigate('chat')
		}


		const renameChat = (id) => {
			const c = chats.value.find((x) => x.id === id)
			if (!c) return
			chatDialog.value = { mode: 'rename', id, title: displayTitle(c), draft: c.title || '', busy: false, error: '' }
		}

		const deleteChat = (id) => {
			const c = chats.value.find((x) => x.id === id)
			if (!c) return
			chatDialog.value = { mode: 'delete', id, title: displayTitle(c), draft: '', busy: false, error: '' }
		}

		const closeChatDialog = () => {
			if (!chatDialog.value?.busy) chatDialog.value = null
		}

		const confirmChatDialog = async () => {
			const dialog = chatDialog.value
			if (!dialog || dialog.busy) return
			const title = String(dialog.draft || '').trim()
			if (dialog.mode === 'rename' && !title) {
				dialog.error = t('Enter a title for this chat.')
				return
			}
			dialog.busy = true
			dialog.error = ''
			try {
				if (dialog.mode === 'rename') {
					await requestApi('POST', '/chats/' + encodeURIComponent(dialog.id) + '/title', { title })
					chatDialog.value = null
					await loadChats()
				} else {
					const wasCurrentChat = currentChat.value === dialog.id
					await requestApi('DELETE', '/chats/' + encodeURIComponent(dialog.id))
					if (wasCurrentChat) currentChat.value = null
					chatDialog.value = null
					await loadChats()
					if (wasCurrentChat) navigate('home')
				}
			} catch (error) {
				dialog.error = dialog.mode === 'rename'
					? t('The chat could not be renamed: {error}', { error: errMsg(error) })
					: t('The chat could not be deleted: {error}', { error: errMsg(error) })
			} finally {
				if (chatDialog.value === dialog) dialog.busy = false
			}
		}

		const onPopState = () => {
			const route = resolveRoute(window.location)
			if (route.view === 'chat') {
				if (route.chatId && route.chatId !== 'new' && chats.value.some((chat) => chat.id === route.chatId)) {
					currentChat.value = route.chatId
					view.value = 'chat'
				} else {
					currentChat.value = null
					view.value = 'home'
				}
			} else {
				view.value = route.view
			}
			fileContextIds.value = route.fileIds
		}
		const onChatsCleared = () => {
			currentChat.value = null
			loadChats()
		}
		const onFileContext = (event) => {
			const ids = event && event.detail && Array.isArray(event.detail.fileIds) ? parseFileIds(event.detail.fileIds) : []
			if (ids.length === 0) return
			fileContextIds.value = ids
			view.value = 'fileContext'
			// URL anpassen, damit der User die Seite bookmarken/teilen kann.
			const url = new URL(window.location.href)
			url.searchParams.set('view', 'fileContext')
			url.searchParams.set('fileIds', ids.join(','))
			url.searchParams.delete('chat')
			window.history.pushState({}, '', url.toString())
		}

		onMounted(() => {
			loadFolders()
			loadChats().then(() => {
				// Dashboard deep links: ?chat=new starts a conversation,
				// ?chat=<id> opens an existing one.
				if (initialChatParam === 'new') {
					newChat()
				} else if (initialChatParam && chats.value.some((c) => c.id === initialChatParam)) {
					currentChat.value = initialChatParam
				}
			})
			if (typeof window !== 'undefined' && window.addEventListener) {
				window.addEventListener('popstate', onPopState)
				window.addEventListener('eva-ai:chats-cleared', onChatsCleared)
				window.addEventListener('eva-ai:file-context', onFileContext)
			}
		})

		onBeforeUnmount(() => {
			if (searchTimer !== null) window.clearTimeout(searchTimer)
			window.removeEventListener('popstate', onPopState)
			window.removeEventListener('eva-ai:chats-cleared', onChatsCleared)
			window.removeEventListener('eva-ai:file-context', onFileContext)
		})

		return {
			view, adminMode: isAdminMode, mobileOpen, buildVersion,
			chats, folders, currentChat, busy, chatsLoading, chatFilter, apiError, chatDialog, showArchived,
			pinnedChats, folderGroups, plainChats, navItems, listChats, activeChats, archivedChats,
			folderPickerOpen, folderChat, newFolderName, foldersLoading, foldersLoadError, collapsedFolders, toggleFolder,
			fileContextIds, itemName, itemTip,
			newChat, selectChat, renameChat, deleteChat, closeChatDialog, confirmChatDialog, loadChats, navigate, updateChatMeta, pickFolder, pickScope, assignTarget, createAndAssign, pendingPrompt,
			pickerMode,
			mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline, mdiChevronDown, mdiViewDashboardOutline,
			mdiPinOutline, mdiPinOffOutline, mdiFolderOutline, mdiFolderPlusOutline, mdiFolderRemoveOutline, mdiFolderSearchOutline, mdiFolderOffOutline, mdiArchiveOutline, mdiArchiveArrowUpOutline,
		}
	},
}
</script>

<style scoped>
.eva-ai-app {
	width: 100%;
	--eva-content-width: clamp(1180px, 78vw, 1680px);
}

.new-chat-container {
	background: transparent;
	box-sizing: border-box;
	display: block;
	margin-top: calc(-1 * var(--default-grid-baseline, 4px));
	padding: 0 var(--app-navigation-padding, 8px) var(--default-grid-baseline, 4px);
	width: 100%;
}

.new-chat-button,
.new-chat-container :deep(.new-chat-button) {
	box-sizing: border-box;
	display: block;
	margin: 0;
	max-width: 100%;
	width: 100%;
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
}	.chat-list-heading-icon {
		flex: none;
	}

	/* Chats grouped inside a folder are indented under the folder heading. */
	.chat-item--nested {
		padding-left: 14px;
	}	.chat-list-heading--folder {
		cursor: pointer;
		text-transform: none;
		user-select: none;
	}

	.chat-list-heading--archived {
		cursor: pointer;
		user-select: none;
	}

	.chat-list-heading--archived svg,
	.chat-list-heading--folder svg {
		transition: transform 0.15s ease;
	}

	.chat-list-heading--archived svg.rotated {
		transform: rotate(180deg);
	}

	.chat-list-heading--folder svg.chevron-collapsed {
		transform: rotate(-90deg);
	}

.chat-list-error {
	color: var(--color-error, #c00);
	font-size: 12px;
	padding: 8px 14px;
	word-break: break-word;
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

<style>
/* The folder picker renders inside NcModal, which teleports its content to
   <body>, so these styles must not be scoped (Issue #87). */
.folder-picker {
	padding: 4px 20px 20px;
}

.folder-picker-hint {
	color: var(--color-text-maxcontrast, #666);
	font-size: 13px;
	margin: 4px 0 12px;
}

.folder-picker-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.folder-picker-row {
	align-items: center;
	background: transparent;
	border: 0;
	border-radius: var(--border-radius-large, 10px);
	color: var(--color-main-text, #222);
	cursor: pointer;
	display: flex;
	font-size: 14px;
	gap: 10px;
	padding: 10px 12px;
	text-align: left;
	width: 100%;
}

.folder-picker-row:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.folder-picker-row--active {
	background: var(--color-primary-light, #e8f0f7);
	color: var(--color-primary-text, #00679c);
	font-weight: 600;
}

.folder-picker-create {
	display: flex;
	gap: 8px;
	margin-top: 14px;
}

.folder-picker-input {
	flex: 1;
	min-width: 0;
}

.chat-dialog {
	display: grid;
	gap: 16px;
	padding: 4px 20px 20px;
}

.chat-dialog__copy {
	color: var(--color-main-text, #222);
	line-height: 1.5;
	margin: 0;
	overflow-wrap: anywhere;
}

.chat-dialog__error {
	color: var(--color-error, #c00);
	font-size: 13px;
	margin: 0;
}

.chat-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.folder-picker-submit {
	background: var(--color-primary, #00679c);
	border: 0;
	border-radius: var(--border-radius-pill, 22px);
	color: var(--color-primary-text, #fff);
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
	padding: 8px 16px;
	white-space: nowrap;
}

.folder-picker-submit:disabled {
	cursor: default;
	opacity: 0.5;
}
</style>
