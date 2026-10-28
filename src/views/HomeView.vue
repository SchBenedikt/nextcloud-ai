<template>
	<div class="home-view">
		<main class="home-workspace">
			<header class="home-intro">
				<NcIconSvgWrapper :path="mdiCreationOutline" :size="40" aria-hidden="true" />
				<h1>{{ $t('How can I help?') }}</h1>
				<p>{{ $t('Ask a question, find a file or work through an idea.') }}</p>
			</header>
			<form class="home-composer" @submit.prevent="startChat">
				<NcTextArea v-model="prompt" :label="$t('Message EVA')" :placeholder="$t('What would you like to work on?')" :disabled="busy" resize="vertical" />
				<div class="home-composer__actions">
					<span>{{ $t('Answers can contain mistakes. Check the sources.') }}</span>
					<NcButton type="submit" variant="primary" :loading="busy" :disabled="busy || !prompt.trim()">
						<template #icon><NcIconSvgWrapper :path="mdiArrowUp" :size="20" /></template>
						{{ $t('Send') }}
					</NcButton>
				</div>
			</form>
			<div class="home-suggestions" :aria-label="$t('Example requests')">
				<NcButton v-for="example in examples" :key="example" variant="secondary" :disabled="busy" @click="$emit('new-chat', example)">{{ example }}</NcButton>
			</div>
			<NcNoteCard v-if="error" type="error">{{ error }} <NcButton variant="tertiary" :disabled="loading" @click="$emit('retry')">{{ $t('Try again') }}</NcButton></NcNoteCard>
			<section class="home-recent" :aria-label="$t('Recent chats')">
				<h2>{{ $t('Continue a conversation') }}</h2>
				<p v-if="loading" role="status">{{ $t('Loading chats…') }}</p>
				<ul v-else-if="recent.length">
					<li v-for="chat in recent" :key="chat.id">
						<button type="button" class="home-recent__chat" @click="$emit('open-chat', chat.id)">
							<NcIconSvgWrapper :path="mdiChatOutline" :size="20" aria-hidden="true" />
							<span class="home-recent__title">{{ chat.title || $t('New chat') }}</span>
							<time :datetime="isoDate(chat.updated)">{{ formatDate(chat.updated) }}</time>
						</button>
					</li>
				</ul>
				<p v-else-if="!error">{{ $t('Your conversations will appear here.') }}</p>
			</section>
			<div class="home-links">
				<NcButton variant="tertiary" @click="$emit('navigate', 'docs')">{{ $t('Manage documents') }}</NcButton>
				<NcButton variant="tertiary" @click="$emit('navigate', 'settings')">{{ $t('Settings') }}</NcButton>
			</div>
		</main>
	</div>
</template>

<script>
import { computed, ref } from 'vue'
import { NcButton, NcIconSvgWrapper, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { mdiArrowUp, mdiChatOutline, mdiCreationOutline } from '@mdi/js'
import { translate as t } from '../lib/i18n'

export default {
	name: 'HomeView',
	components: { NcButton, NcIconSvgWrapper, NcNoteCard, NcTextArea },
	props: {
		chats: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},
	emits: ['new-chat', 'navigate', 'open-chat', 'retry'],
	setup(props, { emit }) {
		const prompt = ref('')
		const recent = computed(() => props.chats.filter(chat => !chat.archived)
			.slice().sort((a, b) => b.updated - a.updated).slice(0, 5))
		const examples = [t('Find files I changed this week'), t('Help me structure an idea')]
		const startChat = () => {
			if (!props.busy && prompt.value.trim()) emit('new-chat', prompt.value.trim())
		}
		const isoDate = timestamp => {
			const date = new Date(Number(timestamp) * 1000)
			return Number.isFinite(date.getTime()) ? date.toISOString() : undefined
		}
		const formatDate = timestamp => isoDate(timestamp) ? new Date(Number(timestamp) * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : ''
		return { prompt, recent, examples, startChat, formatDate, isoDate, mdiArrowUp, mdiChatOutline, mdiCreationOutline }
	},
}
</script>

<style scoped>
.home-view { height: 100%; overflow-y: auto; background: var(--color-main-background); }
.home-workspace { width: min(100% - 40px, 760px); margin: clamp(32px, 8vh, 96px) auto 32px; }
.home-intro { text-align: center; margin-bottom: 28px; }
.home-intro > :first-child { color: var(--color-primary-element); margin: 0 auto 16px; }
.home-intro h1 { font-size: clamp(26px, 4vw, 36px); font-weight: 600; line-height: 1.3; margin: 0 0 12px; }
.home-intro p, .home-recent > p { color: var(--color-text-maxcontrast); }
.home-composer { padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.home-composer:focus-within { border-color: var(--color-primary-element); }
.home-composer__actions { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 12px; }
.home-composer__actions > span { font-size: 12px; color: var(--color-text-maxcontrast); }
.home-suggestions { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin: 16px 0 36px; }
.home-recent h2 { font-size: 16px; font-weight: 600; margin: 0 0 12px; }
.home-recent ul { list-style: none; padding: 0; margin: 0; }
.home-recent__chat { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 52px; padding: 10px 12px; border: 0; border-radius: var(--border-radius); background: transparent; color: var(--color-main-text); text-align: start; cursor: pointer; font: inherit; }
.home-recent__chat:hover { background: var(--color-background-hover); }
.home-recent__chat:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
.home-recent__title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.home-recent__chat > :first-child { flex: 0 0 auto; }
.home-recent__chat time { font-size: 12px; color: var(--color-text-maxcontrast); white-space: nowrap; }
.home-links { display: flex; justify-content: center; gap: 8px; margin-top: 28px; }
@media (max-width: 480px) { .home-composer__actions { align-items: flex-end; } .home-composer__actions > span { max-width: 180px; } }
</style>
