<template>
	<NcContent v-if="adminMode" class="eva-ai-admin" :app-name="'eva_ai'">
		<NcAppContent>
			<AdminView />
		</NcAppContent>
	</NcContent>
	<NcContent v-else class="eva-ai-app" :app-name="'eva_ai'">
		<NcAppNavigation :key="view === 'settings' ? 'settings' : 'workspace'" :title="'EVA'">
			<template v-if="view !== 'settings'" #search>
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
				<template v-if="view === 'settings'">
					<NcAppNavigationItem :name="$t('Back to EVA')" @click.prevent="navigate('home')"><template #icon><NcIconSvgWrapper :path="mdiArrowLeft" :size="20" /></template></NcAppNavigationItem>
					<NcAppNavigationCaption :name="$t('Settings')" />
					<NcAppNavigationItem v-for="section in settingsSections" :key="section.id" :name="section.label" :title="section.description" :active="settingsSection === section.id" @click.prevent="navigate('settings', section.id)">
						<template #icon><NcIconSvgWrapper :path="section.icon" :size="20" /></template>
					</NcAppNavigationItem>
				</template>
				<template v-else>
				<NcAppNavigationItem
					class="start-nav-item"
					:name="$t('Home')"
					:active="view === 'home'"
					@click.prevent="navigate('home')">
					<template #icon>
						<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiViewDashboardOutline" fill="currentColor" /></svg>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="bookmarkedCount > 0 || showBookmarked"
					:name="$t('Bookmarked answers') + ' (' + bookmarkedCount + ')'"
					:active="showBookmarked"
					@click.prevent="showBookmarked = !showBookmarked">
					<template #icon><svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiBookmarkOutline" fill="currentColor" /></svg></template>
				</NcAppNavigationItem>
				<li class="chat-list-heading">
					<span>{{ $t('Chats') }}</span>
					<NcCounterBubble :count="activeChats.length" />
				</li>
				<template v-for="item in navItems" :key="item.key">
					<li
						v-if="item.type === 'heading'"
						class="chat-list-heading"
						:class="{ 'chat-list-heading--archived': item.archived }">
						<svg v-if="item.icon" width="14" height="14" viewBox="0 0 24 24" class="chat-list-heading-icon"><path :d="item.icon" fill="currentColor" /></svg>
						<button v-if="item.archived" type="button" :aria-expanded="showArchived" @click="showArchived = !showArchived">{{ item.label }}</button>
						<span v-else>{{ item.label }}</span>
						<NcCounterBubble v-if="item.count !== undefined" :count="item.count" />
						<svg v-if="item.archived" width="16" height="16" viewBox="0 0 24 24" :class="{ rotated: showArchived }"><path :d="mdiChevronDown" fill="currentColor" /></svg>
					</li>
				<NcAppNavigationItem
					v-else-if="!item.archivedChat || showArchived"
						:name="itemName(item.chat)"
						:active="view === 'chat' && item.chat.id === currentChat"
						:force-menu="true"
						:title="itemTip(item.chat)"
						@click.prevent="selectChat(item.chat.id)">
						<template #icon>
							<svg v-if="item.chat.archived" width="16" height="16" viewBox="0 0 24 24"><path :d="mdiArchiveOutline" fill="currentColor" /></svg>
							<svg v-else width="16" height="16" viewBox="0 0 24 24"><path :d="mdiChatProcessing" fill="currentColor" /></svg>
						</template>
						<template #actions>
							<NcActionButton v-if="!item.chat.archived" :aria-label="$t('Pin chat')" :close-after-click="true" @click.stop="updateChatMeta(item.chat.id, { pinned: !item.chat.pinned })">
								<template #icon><NcIconSvgWrapper :path="item.chat.pinned ? mdiPinOffOutline : mdiPinOutline" :size="16" aria-hidden="true" /></template>
								{{ item.chat.pinned ? $t('Unpin chat') : $t('Pin chat') }}
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
				<li v-else-if="showBookmarked && !chatFilter.trim() && !bookmarkedChats.length" class="chat-list-empty">{{ $t('No bookmarked answers yet.') }}</li>
				<li v-else-if="!apiError && chatFilter.trim() && !listChats.length" class="chat-list-empty">{{ $t('No chats match your search.') }}</li>
				</template>
			</template>
			<template v-if="view !== 'settings'" #footer>
				<ul class="nav-footer">
					<NcAppNavigationItem
						:name="$t('Documents')"
						:active="view === 'docs'"
						@click.prevent="navigate('docs')">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiFileDocumentOutline" fill="currentColor" /></svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="$t('Settings')"
						:active="view === 'settings'"
						@click.prevent="navigate('settings')">
						<template #icon>
							<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiTune" fill="currentColor" /></svg>
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<HomeView v-if="view === 'home'" :chats="chats" :loading="chatsLoading" :busy="busy" :error="apiError" @retry="loadChats" @new-chat="newChat" @open-chat="selectChat" />
			<ChatView v-else-if="view === 'chat'" :chat-id="currentChat" :initial-prompt="pendingPrompt" :auto-send="!!pendingPrompt" @chat-updated="loadChats" @prompt-consumed="pendingPrompt = ''" />
			<FileContextChatView v-else-if="view === 'fileContext'" :file-ids="fileContextIds" />
			<DocumentsView v-else-if="view === 'docs'" />
			<MetricsView v-else-if="view === 'metrics'" />
			<AgentRunsView v-else-if="view === 'runs'" />
			<SettingsView v-else :section="settingsSection" @navigate="navigate" />
		</NcAppContent>
		<NcModal v-if="chatDialog" size="small" :name="chatDialog.mode === 'rename' ? $t('Rename chat') : $t('Delete chat')" @close="closeChatDialog">
			<form class="chat-dialog" @submit.prevent="confirmChatDialog">
				<p v-if="chatDialog.mode === 'delete'" class="chat-dialog__copy">{{ $t('Delete “{title}”? This permanently removes the conversation and its messages.', { title: chatDialog.title }) }}</p>
				<NcTextField v-else v-model="chatDialog.draft" :label="$t('Chat title')" :disabled="chatDialog.busy" maxlength="120" autofocus />
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
import { ref, computed, watch, onMounted, onBeforeUnmount, defineAsyncComponent, nextTick } from 'vue'
import HomeView from './views/HomeView.vue'
import ChatView from './views/ChatView.vue'
// Keep the initial chat bundle small. These views are opened on demand and
// loaded as independent chunks, which is especially important on slower
// production Nextcloud instances.
const DocumentsView = defineAsyncComponent(() => import('./views/DocumentsView.vue'))
const SettingsView = defineAsyncComponent(() => import('./views/SettingsView.vue'))
const FileContextChatView = defineAsyncComponent(() => import('./views/FileContextChatView.vue'))
const AdminView = defineAsyncComponent(() => import('./views/AdminView.vue'))
const MetricsView = defineAsyncComponent(() => import('./views/MetricsView.vue'))
const AgentRunsView = defineAsyncComponent(() => import('./views/AgentRunsView.vue'))
import { mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline, mdiViewDashboardOutline, mdiPinOutline, mdiBookmarkOutline, mdiArchiveOutline, mdiArchiveArrowUpOutline, mdiChevronDown, mdiPinOffOutline, mdiArrowLeft } from '@mdi/js'
import { NcTextField } from '@nextcloud/vue'
import { settingsSections } from './lib/settings-sections'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { api as requestApi, errMsg } from './lib/api'
import { translate as t } from './lib/i18n'
import { useNavigation } from './composables/useNavigation'

export default {
	name: 'EvaAiApp',
	components: { NcAppNavigationCaption, MetricsView, AgentRunsView, NcCounterBubble, HomeView, ChatView, DocumentsView, SettingsView, FileContextChatView, AdminView, NcTextField, NcAppNavigationSearch, NcActionButton, NcActionSeparator, NcIconSvgWrapper },
	setup() {
			// Admin settings form (Issue #82): the template mounts the same app
			// with data-admin="1" and renders the admin dashboard instead.
			const rootEl = document.getElementById('eva_ai-root')
			const isAdminMode = !!(rootEl && rootEl.dataset && rootEl.dataset.admin === '1')

		const view = ref('home')
		const settingsSection = ref('general')
		const fileContextIds = ref([])
		// The navigation composable reads the dashboard query parameter via
		// params.get('chat') and preserves the existing deep-link contract.
		// It also performs url.searchParams.set('chat', currentChat.value) when
		// navigating to an open conversation.

		const chats = ref([])
		const currentChat = ref(null)
		const { initialChatParam, navigate } = useNavigation({ chats, currentChat, view, fileContextIds, settingsSection })
		const busy = ref(false)
		const chatsLoading = ref(true)
		// First message typed on the dashboard hero: passed to the chat view
		// and sent automatically as soon as the conversation is open.
		const pendingPrompt = ref('')
		const chatFilter = ref('')
		const showBookmarked = ref(false)
		const apiError = ref('')
		const chatDialog = ref(null)
		const showArchived = ref(false)
		// Search results (null while not searching). The server matches chat
		// titles AND message text (Issue #152), so results may carry a snippet
		// + matchCount from the first content hit.
		const searchResults = ref(null)
		// One flat, ordered list of headings + chat items for the sidebar.
		const navItems = computed(() => {
			const query = chatFilter.value.trim()
			if (showBookmarked.value) {
				const base = query ? (searchResults.value || chats.value.filter((chat) => String(chat.title || '').toLowerCase().includes(query.toLowerCase()))) : bookmarkedChats.value
				const matches = base.filter((chat) => Number(chat.bookmarkedCount || 0) > 0)
				return [{ type: 'heading', key: 'h-bookmarks', label: t('Bookmarked answers'), icon: mdiBookmarkOutline, count: bookmarkedCount.value }, ...matches.map((chat) => ({ type: 'chat', key: 'chat-' + chat.id, chat }))]
			}
			if (query) {
				const base = searchResults.value || chats.value.filter((chat) => String(chat.title || '').toLowerCase().includes(query.toLowerCase()))
				return base.filter((c) => c.id && !c.archived).map((c) => ({ type: 'chat', key: 'chat-' + c.id, chat: c }))
			}
			const items = []
			const pinnedChats = chats.value.filter((c) => c.pinned && !c.archived)
			if (pinnedChats.length) {
				items.push({ type: 'heading', key: 'h-pinned', label: t('Pinned'), icon: mdiPinOutline })
				for (const c of pinnedChats) items.push({ type: 'chat', key: 'chat-' + c.id, chat: c })
			}
			const plainChats = chats.value.filter((c) => !c.archived && !c.pinned)
			for (const c of plainChats) items.push({ type: 'chat', key: 'chat-' + c.id, chat: c })
			const archivedChats = chats.value.filter((c) => c.archived)
			if (archivedChats.length) {
				items.push({ type: 'heading', key: 'h-archived', label: t('Archived') + ' (' + archivedChats.length + ')', icon: mdiArchiveOutline, archived: true })
				for (const c of archivedChats) items.push({ type: 'chat', key: 'chat-' + c.id, chat: c, archivedChat: true })
			}
			return items
		})
		const activeChats = computed(() => chats.value.filter((c) => !c.archived))
		const bookmarkedChats = computed(() => chats.value.filter((chat) => Number(chat.bookmarkedCount || 0) > 0))
		const bookmarkedCount = computed(() => bookmarkedChats.value.reduce((total, chat) => total + Number(chat.bookmarkedCount || 0), 0))
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
		const itemName = (chat) => {
			const query = chatFilter.value.trim().toLowerCase()
			const titleHit = query && String(chat.title || '').toLowerCase().includes(query)
			return !titleHit && chat.snippet ? chat.snippet : displayTitle(chat)
		}
		const itemTip = (chat) => {
			const title = displayTitle(chat)
			const parts = [t('{title} · {count} messages', { title, count: chat.count })]
			if (chat.scopePath) parts.push(t('Folder scope: {path}', { path: chat.scopePath }))
			if (chat.snippet) parts.push(chat.snippet)
			if (chat.matchCount) parts.push(t('{count} message matches', { count: chat.matchCount }))
			return parts.join(' — ')
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

		// Apply pinned/folder/archived metadata and refresh both lists.
		const updateChatMeta = async (id, meta) => {
			try {
				await requestApi('POST', '/chats/' + encodeURIComponent(id) + '/meta', meta)
				await loadChats()
			} catch (error) {
				apiError.value = t('The chat could not be updated: {error}', { error: errMsg(error) })
			}
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
			showBookmarked.value = false
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

		const focusChatNavigationItem = async (id) => {
			await nextTick()
			await new Promise((resolve) => window.setTimeout(resolve, 700))
			const chat = chats.value.find((item) => item.id === id)
			const name = chat ? itemName(chat) : ''
			const item = name
				? Array.from(document.querySelectorAll('.app-navigation-entry-link'))
					.find((element) => element.querySelector('.app-navigation-entry__name')?.textContent?.trim() === name)
				: null
			if (item?.isConnected) {
				item.focus()
				return
			}
			document.querySelector('.new-chat-button')?.focus()
		}

		const closeChatDialog = () => {
			if (chatDialog.value?.busy) return
			const id = chatDialog.value?.id
			chatDialog.value = null
			if (id) focusChatNavigationItem(id)
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
					await focusChatNavigationItem(dialog.id)
				} else {
					const wasCurrentChat = currentChat.value === dialog.id
					await requestApi('DELETE', '/chats/' + encodeURIComponent(dialog.id))
					if (wasCurrentChat) currentChat.value = null
					chatDialog.value = null
					await loadChats()
					if (wasCurrentChat) navigate('home')
					await focusChatNavigationItem(dialog.id)
				}
			} catch (error) {
				dialog.error = dialog.mode === 'rename'
					? t('The chat could not be renamed: {error}', { error: errMsg(error) })
					: t('The chat could not be deleted: {error}', { error: errMsg(error) })
			} finally {
				if (chatDialog.value === dialog) dialog.busy = false
			}
		}

		const onChatsCleared = () => {
			currentChat.value = null
			loadChats()
		}

		onMounted(() => {
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
				window.addEventListener('eva-ai:chats-cleared', onChatsCleared)
			}
		})

		onBeforeUnmount(() => {
			if (searchTimer !== null) window.clearTimeout(searchTimer)
			window.removeEventListener('eva-ai:chats-cleared', onChatsCleared)
		})

		return {
			view, settingsSection, settingsSections, mdiArrowLeft, adminMode: isAdminMode,
			chats, currentChat, busy, chatsLoading, chatFilter, apiError, chatDialog, showArchived,
			navItems, listChats, activeChats, showBookmarked, bookmarkedChats, bookmarkedCount,
			fileContextIds, itemName, itemTip,
			newChat, selectChat, renameChat, deleteChat, closeChatDialog, confirmChatDialog, loadChats, navigate, updateChatMeta, pendingPrompt,
			mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline, mdiViewDashboardOutline,
			mdiPinOutline, mdiBookmarkOutline, mdiArchiveOutline, mdiArchiveArrowUpOutline, mdiChevronDown, mdiPinOffOutline,
		}
	},
}
</script>

<style scoped>
.eva-ai-app {
	width: 100%;
	--eva-content-width: 1120px;
}

.chat-list-heading > button { border: 0; background: transparent; color: inherit; font: inherit; cursor: pointer; text-align: start; flex: 1; min-height: 44px; }
.chat-list-heading > button:focus-visible { outline: 2px solid var(--color-primary-element); }

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

	.chat-list-heading--archived {
		cursor: pointer;
		user-select: none;
	}

	.chat-list-heading--archived svg {
		transition: transform 0.15s ease;
	}

	.chat-list-heading--archived svg.rotated {
		transform: rotate(180deg);
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
/* NcAppContent places its navigation toggle over the first header row. */
.eva-ai-app .page-header,
.eva-ai-app .agent-runs__header,
.eva-ai-app .file-context-root > .head { padding-inline-start: 44px; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    scroll-behavior: auto !important;
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
