import { onBeforeUnmount, onMounted, ref } from 'vue'

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

/** Centralizes browser URL state and navigation for the main EVA shell. */
export function useNavigation({ chats, currentChat, view, fileContextIds, onChatsCleared, onFileContext }) {
	const initialRoute = resolveRoute(window.location)
	const initialChatParam = new URLSearchParams(window.location.search).get('chat')
	view.value = initialRoute.view
	fileContextIds.value = initialRoute.fileIds

	const appRootPath = () => {
		const current = window.location.pathname.replace(/\/+$/, '')
		return current.replace(/\/(settings|documents|metrics|runs|app|standalone)$/, '') || current
	}
	const navigate = (nextView) => {
		view.value = nextView
		const url = new URL(window.location.href)
		url.searchParams.delete('view')
		url.searchParams.delete('fileIds')
		if (nextView === 'chat' && currentChat.value) url.searchParams.set('chat', currentChat.value)
		else url.searchParams.delete('chat')
		url.pathname = nextView === 'chat' || nextView === 'home' ? appRootPath() : appRootPath() + '/' + (nextView === 'docs' ? 'documents' : nextView)
		window.history.pushState({}, '', url.toString())
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
		} else view.value = route.view
		fileContextIds.value = route.fileIds
	}
	const handleFileContext = (event) => {
		const ids = event && event.detail && Array.isArray(event.detail.fileIds) ? parseFileIds(event.detail.fileIds) : []
		if (ids.length === 0) return
		fileContextIds.value = ids
		view.value = 'fileContext'
		const url = new URL(window.location.href)
		url.searchParams.set('view', 'fileContext')
		url.searchParams.set('fileIds', ids.join(','))
		url.searchParams.delete('chat')
		window.history.pushState({}, '', url.toString())
	}
	onMounted(() => {
		window.addEventListener('popstate', onPopState)
		if (onChatsCleared) window.addEventListener('eva-ai:chats-cleared', onChatsCleared)
		window.addEventListener('eva-ai:file-context', onFileContext || handleFileContext)
	})
	onBeforeUnmount(() => {
		window.removeEventListener('popstate', onPopState)
		if (onChatsCleared) window.removeEventListener('eva-ai:chats-cleared', onChatsCleared)
		window.removeEventListener('eva-ai:file-context', onFileContext || handleFileContext)
	})
	return { initialChatParam, navigate, resolveRoute }
}
