<template>
	<div ref="homeRoot" class="home-view">
		<NcNoteCard v-if="error" type="error">{{ error }} <NcButton variant="tertiary" :disabled="loading" @click="$emit('retry')">{{ $t('Try again') }}</NcButton></NcNoteCard>
		<main class="home-main">
			<h1 class="home-greeting">{{ $t('How can I help?') }}</h1>
			<form class="home-compose" @submit.prevent="startChat">
				<NcTextArea v-model="prompt" :label="$t('Message EVA')" :placeholder="$t('Ask a question or describe a task')" :disabled="busy" :resize="'none'" />
				<div class="compose-actions"><NcButton type="submit" variant="primary" :loading="busy" :disabled="busy || !prompt.trim()"><template #icon><NcIconSvgWrapper :path="mdiSendOutline" :size="20" /></template>{{ $t('Send') }}</NcButton></div>
			</form>
		</main>
		<section class="recent-chats" :aria-label="$t('Recent chats')">
			<h2>{{ $t('Recent chats') }}</h2>
			<NcDashboardWidget :items="recent" :loading="loading">
				<template #default="{ item }"><NcDashboardWidgetItem :id="item.id" :main-text="item.title || $t('New chat')" :sub-text="chatDetails(item)" :target-url="chatUrl(item.id)" @click="openChat($event, item.id)"><template #avatar><NcIconSvgWrapper class="chat-icon" :path="mdiChatOutline" :size="24" /></template></NcDashboardWidgetItem></template>
				<template #empty-content><NcEmptyContent :name="$t('No conversations yet')"><template #icon><NcIconSvgWrapper :path="mdiChatOutline" :size="40" /></template></NcEmptyContent></template>
			</NcDashboardWidget>
		</section>
	</div>
</template>

<script>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { mdiChatOutline, mdiSendOutline } from '@mdi/js'

export default {
	name: 'HomeView',
	components: { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper, NcNoteCard, NcTextArea },
	props: { chats: { type: Array, default: () => [] }, loading: Boolean, busy: Boolean, error: { type: String, default: '' } },
	emits: ['new-chat', 'open-chat', 'retry'],
	setup(props, { emit }) {
		const homeRoot = ref(null)
		const prompt = ref('')
		const recentCount = ref(5)
		const recent = computed(() => props.chats.filter(chat => !chat.archived).slice().sort((a, b) => b.updated - a.updated).slice(0, recentCount.value))
		let homeResizeObserver = null
		const updateRecentCount = () => {
			const height = homeRoot.value?.clientHeight || window.innerHeight || 0
			recentCount.value = height < 500 ? 1 : height < 640 ? 2 : height < 800 ? 3 : 5
		}
		onMounted(() => {
			updateRecentCount()
			if (typeof ResizeObserver !== 'undefined' && homeRoot.value) {
				homeResizeObserver = new ResizeObserver(updateRecentCount)
				homeResizeObserver.observe(homeRoot.value)
			}
			window.addEventListener('resize', updateRecentCount)
		})
		onBeforeUnmount(() => {
			homeResizeObserver?.disconnect()
			window.removeEventListener('resize', updateRecentCount)
		})
		const startChat = () => { if (!props.busy && prompt.value.trim()) emit('new-chat', prompt.value.trim()) }
		const openChat = (event, id) => {
			if (!event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && event.button === 0) {
				event.preventDefault()
				emit('open-chat', id)
			}
		}
		const chatUrl = id => generateUrl('/apps/eva_ai/') + '?chat=' + encodeURIComponent(id)
		const chatDetails = chat => {
			const date = new Date(Number(chat.updated) * 1000)
			return Number.isFinite(date.getTime()) ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : ''
		}
		return { homeRoot, prompt, recent, startChat, openChat, chatUrl, chatDetails, mdiChatOutline, mdiSendOutline }
	},
}
</script>

<style scoped>
.home-view { --home-column-width: 640px; width: min(100%, 1040px); height: 100%; min-height: 0; margin: 0 auto; padding: clamp(12px, 3vh, 28px) clamp(16px, 3vw, 32px) max(8px, env(safe-area-inset-bottom)); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(8px, 2vh, 20px); overflow: hidden; }
.home-main { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; justify-content: center; align-items: center; transform: translateY(clamp(0px, 6vh, 48px)); }
.home-greeting { width: min(100%, var(--home-column-width)); margin: 0 0 16px; color: var(--color-main-text); text-align: center; font-size: clamp(19px, 2.4vh, 24px); font-weight: 600; line-height: 1.25; }
.home-compose { width: min(100%, var(--home-column-width)); flex: 0 0 auto; margin: 0 auto; }
.home-compose :deep(textarea) { min-height: 96px; max-height: 20vh; }
.compose-actions { display: flex; justify-content: flex-end; margin-top: 8px; }
.recent-chats { width: min(100%, var(--home-column-width)); flex: 0 0 auto; align-self: center; min-height: 0; overflow: hidden; }
h2 { font-size: 16px; font-weight: 600; margin: 0 0 12px; }
.chat-icon { flex: none; margin-inline-end: 16px; color: var(--color-text-maxcontrast); }
@media (max-width: 600px) { .home-view { padding-inline: 16px; } .home-main { transform: translateY(clamp(0px, 3vh, 20px)); } .home-greeting { margin-bottom: 12px; } .home-compose :deep(textarea) { min-height: 88px; } }
</style>
