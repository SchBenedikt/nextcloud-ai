<template>
	<NcContent class="eva-ai-app" :app-name="'eva_ai'">
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
						@click="newChat">
						<template #icon>
							<NcIconSvgWrapper :path="mdiMessagePlus" :size="16" aria-hidden="true" />
						</template>
						{{ $t('New chat') }}
					</NcButton>
				</div>
			</template>
			<template #list>
				<li class="chat-list-heading">
					<span>{{ $t('Chats') }}</span>
					<NcCounterBubble :count="chats.length" />
				</li>
				<NcAppNavigationItem
					v-for="c in filteredChats"
					:key="c.id"
					:name="(c.pinned ? '★ ' : '') + c.title"
                    :subtitle="c.snippet || ''"
					:active="view === 'chat' && c.id === currentChat"
					:force-menu="true"
					:title="$t('{title} · {count} messages', { title: c.title, count: c.count })"
					@click="selectChat(c.id)">
					<template #icon>
						<svg width="16" height="16" viewBox="0 0 24 24"><path :d="mdiChatProcessing" fill="currentColor" /></svg>
					</template>
					<template #actions>
                        <NcActionButton @click.stop="updateChat(c.id, { pinned: !c.pinned })">{{ c.pinned ? $t('Unpin') : $t('Pin') }}</NcActionButton>
                        <NcActionButton @click.stop="updateChat(c.id, { archived: !c.archived })">{{ c.archived ? $t('Unarchive') : $t('Archive') }}</NcActionButton>
                        <NcActionButton @click.stop="configureChat(c)">{{ $t('Chat instructions') }}</NcActionButton>                        <NcActionButton :aria-label="$t('Rename chat')" @click.stop="renameChat(c.id)">
							<template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="16" aria-hidden="true" /></template>
							{{ $t('Rename chat') }}
						</NcActionButton>
						<NcActionButton :aria-label="$t('Delete chat')" @click.stop="deleteChat(c.id)">
							<template #icon><NcIconSvgWrapper :path="mdiTrashCanOutline" :size="16" aria-hidden="true" /></template>
							{{ $t('Delete chat') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
				<li v-if="hasMoreChats"><NcButton @click="loadChats(true)">{{ $t('Load more') }}</NcButton></li>
                <li v-if="apiError" class="chat-list-error" role="alert">{{ apiError }}</li>
				<li v-if="!chats.length" class="chat-list-empty">{{ $t('No chats yet — start a new one.') }}</li>
				<li v-else-if="!filteredChats.length" class="chat-list-empty">{{ $t('No chats match your search.') }}</li>
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
			<ChatView v-if="view === 'chat'" :chat-id="currentChat" @chat-updated="loadChats" />
			<FileContextChatView v-else-if="view === 'fileContext'" :file-ids="fileContextIds" />
			<DocumentsView v-else-if="view === 'docs'" />
			<SettingsView v-else />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { ref, computed, onMounted, watch, onUnmounted } from 'vue'
import ChatView from './views/ChatView.vue'
import DocumentsView from './views/DocumentsView.vue'
import SettingsView from './views/SettingsView.vue'
import FileContextChatView from './views/FileContextChatView.vue'
import { mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline } from '@mdi/js'
import { NcCounterBubble } from '@nextcloud/vue'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { api as requestApi, errMsg } from './lib/api'
import { translate as t } from './lib/i18n'

export default {
	name: 'EvaAiApp',
	components: { ChatView, DocumentsView, SettingsView, FileContextChatView, NcCounterBubble, NcAppNavigationSearch, NcIconSvgWrapper },
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
		const apiError = ref('')
        const filteredChats = computed(() => chats.value)
        const showArchived = ref(false)
        const hasMoreChats = ref(false)
        let searchTimer = null
        let searchVersion = 0


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

        const loadChats = async (append = false) => {
            const version = ++searchVersion
            try {
                const list = await requestApi('GET', '/chats', { search: chatFilter.value, offset: append ? chats.value.length : 0,
                    limit: 100, archived: showArchived.value })
                if (version !== searchVersion) return
                if (!Array.isArray(list)) throw new Error(t('The chat list response was invalid.'))
                chats.value = append ? chats.value.concat(list) : list
                hasMoreChats.value = list.length === 100
                apiError.value = ''
            } catch (error) { apiError.value = errMsg(error) }
        }
        watch([chatFilter, showArchived], () => {
            clearTimeout(searchTimer)
            searchTimer = setTimeout(() => loadChats(), 250)
        })
        onUnmounted(() => { clearTimeout(searchTimer); searchVersion++ })
        const updateChat = async (id, changes) => {
            try { await requestApi('PUT', '/chats/' + encodeURIComponent(id), changes); await loadChats() }
            catch (e) { apiError.value = errMsg(e) }
        }
        const configureChat = async (chat) => {
            const instructions = window.prompt(t('Chat instructions'), chat.instructions || '')
            if (instructions !== null) await updateChat(chat.id, { instructions })
        }


		const newChat = async () => {
			if (busy.value) return
			busy.value = true
			try {
				const c = await requestApi('POST', '/chats', {})
				if (!c || !c.id) throw new Error(t('The server returned no chat ID.'))
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
			currentChat.value = id
			navigate('chat')
		}


		const renameChat = async (id) => {
			const c = chats.value.find((x) => x.id === id)
			const name = window.prompt(t('New chat title:'), c ? c.title : '')
			if (name === null || !name.trim()) return
			try {
				await requestApi('POST', '/chats/' + encodeURIComponent(id) + '/title', { title: name.trim() })
				await loadChats()
			} catch (error) {
				apiError.value = t('The chat could not be renamed: {error}', { error: errMsg(error) })
			}
		}

		const deleteChat = async (id) => {
			if (!window.confirm(t('Delete this chat?'))) return
			try {
				await requestApi('DELETE', '/chats/' + encodeURIComponent(id))
				if (currentChat.value === id) currentChat.value = null
				await loadChats()
			} catch (error) {
				apiError.value = t('The chat could not be deleted: {error}', { error: errMsg(error) })
			}
		}

		onMounted(() => {
			loadChats()
			if (typeof window !== 'undefined' && window.addEventListener) {
				window.addEventListener('popstate', () => {
					const current = window.location.pathname.replace(/\/+$/, '')
					view.value = current.endsWith('/settings') ? 'settings' : current.endsWith('/documents') ? 'docs' : 'chat'
				})
                window.addEventListener('eva-ai:select-chat', (e) => { if (e.detail?.id) selectChat(e.detail.id) })
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
			chats, currentChat, busy, chatFilter, filteredChats, apiError,
			fileContextIds,
			newChat, selectChat, renameChat, deleteChat, loadChats, navigate,
			showArchived, hasMoreChats, updateChat, configureChat,
			mdiChatProcessing, mdiFileDocumentOutline, mdiTune, mdiTrashCanOutline, mdiMessagePlus, mdiPencilOutline,
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