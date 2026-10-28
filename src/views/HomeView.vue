<template>
	<div class="home-view">
		<header class="page-header">
			<div><h1>{{ $t('Overview') }}</h1><p>{{ $t('Your conversations and knowledge in Nextcloud.') }}</p></div>
			<NcButton variant="primary" :disabled="busy" @click="$emit('new-chat')"><template #icon><NcIconSvgWrapper :path="mdiMessagePlusOutline" :size="20" /></template>{{ $t('New chat') }}</NcButton>
		</header>
		<NcNoteCard v-if="error" type="error">{{ error }} <NcButton variant="tertiary" :disabled="loading" @click="$emit('retry')">{{ $t('Try again') }}</NcButton></NcNoteCard>
		<div class="overview-grid">
			<div class="overview-main">
				<section class="overview-compose" :aria-label="$t('Ask EVA')">
					<h2>{{ $t('Ask EVA') }}</h2>
					<form @submit.prevent="startChat">
						<NcTextArea v-model="prompt" :label="$t('Message EVA')" :placeholder="$t('Ask a question or describe a task')" :disabled="busy" resize="vertical" />
						<div class="compose-actions"><span>{{ $t('Answers can contain mistakes. Check the sources.') }}</span><NcButton type="submit" variant="primary" :loading="busy" :disabled="busy || !prompt.trim()"><template #icon><NcIconSvgWrapper :path="mdiSendOutline" :size="20" /></template>{{ $t('Send') }}</NcButton></div>
					</form>
				</section>
				<section class="overview-panel" :aria-label="$t('Recent chats')">
					<h2><NcIconSvgWrapper :path="mdiChatOutline" :size="20" />{{ $t('Recent chats') }}</h2>
					<NcDashboardWidget :items="recent" :loading="loading">
						<template #default="{ item }"><NcDashboardWidgetItem :id="item.id" :main-text="item.title || $t('New chat')" :sub-text="chatDetails(item)" :target-url="chatUrl(item.id)" @click="openChat($event, item.id)"><template #avatar><NcIconSvgWrapper class="overview-item-icon" :path="mdiChatOutline" :size="28" /></template></NcDashboardWidgetItem></template>
						<template #empty-content><NcEmptyContent :name="$t('No conversations yet')" :description="$t('Start a chat to keep your questions and answers together.')"><template #icon><NcIconSvgWrapper :path="mdiChatOutline" :size="40" /></template></NcEmptyContent></template>
					</NcDashboardWidget>
				</section>
			</div>
			<section class="overview-panel" :aria-label="$t('Your workspace')">
				<h2><NcIconSvgWrapper :path="mdiViewDashboardOutline" :size="20" />{{ $t('Your workspace') }}</h2>
				<NcDashboardWidget :items="workspaceItems">
					<template #default="{ item }"><NcDashboardWidgetItem :id="item.id" :main-text="item.name" :sub-text="item.description" :target-url="item.href" @click="openView($event, item.id)"><template #avatar><NcIconSvgWrapper class="overview-item-icon" :path="item.icon" :size="28" /></template></NcDashboardWidgetItem></template>
				</NcDashboardWidget>
			</section>
		</div>
	</div>
</template>

<script>
import { computed, ref } from 'vue'
import { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { mdiChatOutline, mdiMessagePlusOutline, mdiSendOutline, mdiViewDashboardOutline, mdiFileDocumentOutline, mdiRobotOutline, mdiChartBar, mdiProgressClock } from '@mdi/js'
import { translate as t } from '../lib/i18n'

export default {
	name: 'HomeView',
	components: { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper, NcNoteCard, NcTextArea },
	props: { chats: { type: Array, default: () => [] }, loading: Boolean, busy: Boolean, error: { type: String, default: '' } },
	emits: ['new-chat', 'navigate', 'open-chat', 'retry'],
	setup(props, { emit }) {
		const prompt = ref('')
		const recent = computed(() => props.chats.filter(chat => !chat.archived).slice().sort((a, b) => b.updated - a.updated).slice(0, 5))
		const workspaceItems = [
			{ id: 'docs', name: t('Documents'), description: t('Browse and manage indexed files'), icon: mdiFileDocumentOutline, href: generateUrl('/apps/eva_ai/documents') },
			{ id: 'settings', name: t('AI models'), description: t('Connect and configure your AI providers'), icon: mdiRobotOutline, href: generateUrl('/apps/eva_ai/settings') },
			{ id: 'metrics', name: t('Usage metrics'), description: t('Review requests, tokens and feedback'), icon: mdiChartBar, href: generateUrl('/apps/eva_ai/metrics') },
			{ id: 'runs', name: t('Background tasks'), description: t('Follow work that continues in the background'), icon: mdiProgressClock, href: generateUrl('/apps/eva_ai/runs') },
		]
		const startChat = () => { if (!props.busy && prompt.value.trim()) emit('new-chat', prompt.value.trim()) }
		const plainClick = event => !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && event.button === 0
		const openChat = (event, id) => { if (plainClick(event)) { event.preventDefault(); emit('open-chat', id) } }
		const openView = (event, view) => { if (plainClick(event)) { event.preventDefault(); emit('navigate', view) } }
		const chatUrl = id => generateUrl('/apps/eva_ai/') + '?chat=' + encodeURIComponent(id)
		const chatDetails = chat => {
			const date = new Date(Number(chat.updated) * 1000)
			return Number.isFinite(date.getTime()) ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : ''
		}
		return { prompt, recent, workspaceItems, startChat, openChat, openView, chatUrl, chatDetails, mdiChatOutline, mdiMessagePlusOutline, mdiSendOutline, mdiViewDashboardOutline }
	},
}
</script>

<style scoped>
.home-view { width: min(100%, 1200px); margin: 0 auto; padding: 24px 32px 40px; overflow-y: auto; }
.page-header { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 32px; }
h1 { margin: 0 0 4px; font-size: 24px; font-weight: 600; }
.page-header p { margin: 0; color: var(--color-text-maxcontrast); }
h2 { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 600; margin: 0 0 16px; }
.overview-grid { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(280px, 1fr); gap: 40px; align-items: start; }
.overview-main { min-width: 0; }
.overview-compose { margin-bottom: 36px; }
.compose-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; }
.compose-actions > span { color: var(--color-text-maxcontrast); font-size: 12px; }
.overview-item-icon { flex: none; margin-inline-end: 16px; color: var(--color-text-maxcontrast); }
@media (max-width: 900px) { .overview-grid { grid-template-columns: 1fr; gap: 32px; } }
@media (max-width: 600px) { .home-view { padding: 20px 16px; } .page-header { align-items: flex-start; gap: 12px; } .page-header > div { min-width: 0; } .compose-actions > span { max-width: 180px; } }
</style>
