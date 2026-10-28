<template>
	<div class="home-view">
		<NcNoteCard v-if="error" type="error">{{ error }} <NcButton variant="tertiary" :disabled="loading" @click="$emit('retry')">{{ $t('Try again') }}</NcButton></NcNoteCard>
		<form class="home-compose" @submit.prevent="startChat">
			<NcTextArea v-model="prompt" :label="$t('Message EVA')" :placeholder="$t('Ask a question or describe a task')" :disabled="busy" resize="vertical" />
			<div class="compose-actions"><NcButton type="submit" variant="primary" :loading="busy" :disabled="busy || !prompt.trim()"><template #icon><NcIconSvgWrapper :path="mdiSendOutline" :size="20" /></template>{{ $t('Send') }}</NcButton></div>
		</form>
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
import { computed, ref } from 'vue'
import { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { mdiChatOutline, mdiSendOutline } from '@mdi/js'

export default {
	name: 'HomeView',
	components: { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper, NcNoteCard, NcTextArea },
	props: { chats: { type: Array, default: () => [] }, loading: Boolean, busy: Boolean, error: { type: String, default: '' } },
	emits: ['new-chat', 'open-chat', 'retry'],
	setup(props, { emit }) {
		const prompt = ref('')
		const recent = computed(() => props.chats.filter(chat => !chat.archived).slice().sort((a, b) => b.updated - a.updated).slice(0, 5))
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
		return { prompt, recent, startChat, openChat, chatUrl, chatDetails, mdiChatOutline, mdiSendOutline }
	},
}
</script>

<style scoped>
.home-view { width: min(100%, 860px); margin: 0 auto; padding: clamp(48px, 8vh, 80px) 28px 48px; }
.home-compose { margin-bottom: 48px; }
.home-compose :deep(textarea) { min-height: 160px; }
.compose-actions { display: flex; justify-content: flex-end; margin-top: 12px; }
h2 { font-size: 16px; font-weight: 600; margin: 0 0 16px; }
.chat-icon { flex: none; margin-inline-end: 16px; color: var(--color-text-maxcontrast); }
@media (max-width: 600px) { .home-view { padding: 56px 16px 32px; } .home-compose { margin-bottom: 32px; } }
</style>
