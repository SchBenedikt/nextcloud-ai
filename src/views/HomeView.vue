<template>
	<div class="home-view">
		<header class="home-hero">
			<div class="hero-copy">
				<p class="eyebrow">EVA AI</p>
				<h1>{{ greeting }}</h1>
				<p class="hero-sub">{{ $t('Your personal assistant for Nextcloud — ask your files, manage chats and stay on top of your knowledge base.') }}</p>
			</div>
			<div class="hero-actions">
				<NcButton type="secondary" @click="$emit('new-chat')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMessagePlus" :size="18" aria-hidden="true" />
					</template>
					{{ $t('Start a new conversation') }}
				</NcButton>
				<NcButton type="secondary" @click="$emit('navigate', 'docs')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="18" aria-hidden="true" />
					</template>
					{{ $t('Documents') }}
				</NcButton>
			</div>
		</header>

		<div v-if="error" class="home-callout home-callout--error" role="alert">{{ error }}</div>

		<section class="stat-grid" :aria-label="$t('Overview')">
			<div class="stat-card" v-for="card in statCards" :key="card.label">
				<span class="stat-icon">
					<NcIconSvgWrapper :path="card.icon" :size="20" aria-hidden="true" />
				</span>
				<div class="stat-body">
					<span class="stat-label">{{ card.label }}</span>
					<strong class="stat-value">{{ card.value }}</strong>
					<small class="stat-hint">{{ card.hint }}</small>
				</div>
			</div>
		</section>

		<section class="prompt-showcase" :aria-label="$t('Try EVA')">
			<div class="prompt-showcase__intro">
				<span class="prompt-showcase__spark">✦</span>
				<div><strong>{{ $t('Make your next step effortless') }}</strong><span>{{ $t('Start with a focused request — EVA can search, understand and act across your Nextcloud.') }}</span></div>
			</div>
			<div class="prompt-cards">
				<button v-for="prompt in quickPrompts" :key="prompt.text" type="button" class="prompt-card" @click="$emit('new-chat', prompt.text)">
					<NcIconSvgWrapper :path="prompt.icon" :size="19" aria-hidden="true" />
					<span>{{ prompt.text }}</span><span class="prompt-arrow" aria-hidden="true">→</span>
				</button>
			</div>
		</section>

		<section class="home-grid">
			<div class="home-panel">
				<div class="panel-head">
					<h2>
						<NcIconSvgWrapper :path="mdiMessageProcessingOutline" :size="18" aria-hidden="true" />
						{{ $t('Recent chats') }}
					</h2>
					<button v-if="recent.length" class="panel-link" type="button" @click="$emit('navigate', 'chat')">{{ $t('Show all') }}</button>
				</div>
				<ul v-if="recent.length" class="recent-list">
					<li v-for="c in recent" :key="c.id">
						<button type="button" class="recent-row" @click="$emit('open-chat', c.id)">
							<span class="recent-icon"><NcIconSvgWrapper :path="mdiChatProcessing" :size="16" aria-hidden="true" /></span>
							<span class="recent-main">
								<span class="recent-title">{{ c.title || $t('New chat') }}</span>
								<span class="recent-meta">
									{{ c.count }} {{ c.count === 1 ? $t('message') : $t('messages') }}
									<span v-if="c.folder" class="recent-folder"><NcIconSvgWrapper :path="mdiFolderOutline" :size="12" aria-hidden="true" />{{ c.folder }}</span>
									<span v-if="c.scopePath" class="recent-folder"><NcIconSvgWrapper :path="mdiFolderSearchOutline" :size="12" aria-hidden="true" />{{ c.scopePath }}</span>
								</span>
							</span>
							<time class="recent-time" :datetime="isoDate(c.updated)">{{ fmtDate(c.updated) }}</time>
						</button>
					</li>
				</ul>
				<p v-else class="panel-empty">{{ $t('No chats yet — start a new one.') }}</p>
			</div>

			<div class="home-panel">
				<div class="panel-head">
					<h2>
						<NcIconSvgWrapper :path="mdiTune" :size="18" aria-hidden="true" />
						{{ $t('System') }}
					</h2>
					<button class="panel-link" type="button" @click="$emit('navigate', 'settings')">{{ $t('Settings') }}</button>
				</div>
				<ul class="status-list">
                    <li v-if="status.chatProvider === 'groq'">
                        <span class="status-label">Groq</span>
                        <span class="status-value">{{ status.groq?.keyConfigured ? $t('API key saved') : $t('API key missing') }}</span>
                    </li>
					<li>
						<span class="status-dot" :class="online ? 'status-dot--ok' : 'status-dot--bad'"></span>
						<span class="status-label">{{ $t('Ollama connection') }}</span>
						<span class="status-value">{{ online ? $t('Online') : $t('Offline') }}</span>
					</li>
					<li v-if="status.ollamaError">
						<span class="status-label">{{ $t('Connection error') }}</span>
						<span class="status-value status-value--warn">{{ status.ollamaError }}</span>
					</li>
					<li>
						<span class="status-label">{{ $t('Chat model') }}</span>
						<span class="status-value status-value--mono">{{ (status.chatProvider === 'groq' ? status.groq?.model : status.chatModel) || '—' }}</span>
					</li>
					<li>
						<span class="status-label">{{ $t('Embedding model') }}</span>
						<span class="status-value status-value--mono">{{ status.embeddingModel || '—' }}</span>
					</li>
					<li>
						<span class="status-label">{{ $t('Indexing') }}</span>
						<span class="status-value" :class="{ 'status-value--ok': !status.indexing }">
							{{ status.indexing ? $t('Running') : $t('Idle') }}
						</span>
					</li>
					<li v-if="status.lastError">
						<span class="status-label">{{ $t('Last index error') }}</span>
						<span class="status-value status-value--warn">{{ status.lastError }}</span>
					</li>
				</ul>
			</div>
		</section>
	</div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiMessagePlus, mdiFileDocumentOutline, mdiMessageProcessingOutline, mdiChatProcessing, mdiFolderOutline, mdiFolderSearchOutline, mdiTune, mdiFileDocumentMultipleOutline, mdiTextBoxOutline, mdiDatabaseOutline, mdiMagnify, mdiCalendarClockOutline, mdiLightbulbOnOutline } from '@mdi/js'

export default {
	name: 'HomeView',
	components: { NcIconSvgWrapper },
	emits: ['new-chat', 'navigate', 'open-chat'],
	setup(_, { emit }) {
		const busy = ref(false)
		const error = ref('')
		const docs = ref({ count: 0, chunks: 0, size: 0 })
		const chatSummary = ref({ total: 0, active: 0, archived: 0, recent: [] })
		const folders = ref(0)
		const status = ref({})
		const aiGreeting = ref('')

		const online = computed(() => status.value.ollamaOnline === true)
		const recent = computed(() => chatSummary.value.recent || [])
		const activeChats = computed(() => chatSummary.value.active || 0)

		// Static time-of-day fallback, replaced by the AI-generated greeting
		// as soon as /api/greeting responds (offline -> stays static).
		const staticGreeting = computed(() => {
			const h = new Date().getHours()
			if (h < 5) return t('Good night')
			if (h < 12) return t('Good morning')
			if (h < 18) return t('Good afternoon')
			return t('Good evening')
		})
		const greeting = computed(() => aiGreeting.value || staticGreeting.value)

		const statCards = computed(() => [
			{
				label: t('Documents'),
				value: docs.value.count.toLocaleString(),
				hint: t('Indexed files in your knowledge base'),
				icon: mdiFileDocumentMultipleOutline,
			},
			{
				label: t('Text chunks'),
				value: docs.value.chunks.toLocaleString(),
				hint: t('Searchable sections'),
				icon: mdiTextBoxOutline,
			},
			{
				label: t('Indexed size'),
				value: fmtSize(docs.value.size),
				hint: t('Content currently available to EVA'),
				icon: mdiDatabaseOutline,
			},
			{
				label: t('Chats'),
				value: String(activeChats.value),
				hint: folders.value > 0
					? t('{count} active · {folders} folders', { count: activeChats.value, folders: folders.value })
					: t('{count} active conversations', { count: activeChats.value }),
				icon: mdiMessageProcessingOutline,
			},
		])
		const quickPrompts = computed(() => [
			{ text: t('Find the most relevant files for me'), icon: mdiMagnify },
			{ text: t('What is on my calendar this week?'), icon: mdiCalendarClockOutline },
			{ text: t('Help me turn my notes into a clear plan'), icon: mdiLightbulbOnOutline },
		])

		const load = async () => {
			busy.value = true
			try {
				const data = await api('GET', '/stats')
				docs.value = data && data.documents ? data.documents : docs.value
				chatSummary.value = data && data.chats ? data.chats : chatSummary.value
				folders.value = data ? Number(data.folders || 0) : 0
				status.value = data && data.status ? data.status : {}
				error.value = ''
			} catch (e) {
				error.value = t('Dashboard unavailable: {error}', { error: errMsg(e) })
			} finally {
				busy.value = false
			}
			// AI-generated greeting (non-blocking; the static one stays until
			// the response arrives or Ollama is offline).
			api('GET', '/greeting')
				.then((data) => {
					const text = data && data.greeting ? String(data.greeting).trim() : ''
					if (text) aiGreeting.value = text
				})
				.catch(() => { /* keep the static greeting */ })
		}

		function fmtSize(b) {
			if (b == null) return '—'
			const n = Number(b)
			if (n < 1024) return n + ' B'
			if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB'
			if (n < 1024 * 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB'
			return (n / (1024 * 1024 * 1024)).toFixed(2) + ' GB'
		}

		function fmtDate(ts) {
			if (!ts) return '—'
			const d = new Date(Number(ts) * 1000)
			if (isNaN(d)) return '—'
			const now = new Date()
			const sameDay = d.toDateString() === now.toDateString()
			if (sameDay) {
				return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
			}
			return d.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric' })
		}

		function isoDate(ts) {
			if (!ts) return ''
			const d = new Date(Number(ts) * 1000)
			return isNaN(d) ? '' : d.toISOString()
		}

		onMounted(load)

		return {
			busy, error, docs, chatSummary, folders, status, online, recent, activeChats,
			greeting, statCards, quickPrompts, fmtSize, fmtDate, isoDate,
			mdiMessagePlus, mdiFileDocumentOutline, mdiMessageProcessingOutline, mdiChatProcessing, mdiFolderOutline, mdiFolderSearchOutline, mdiTune,
		}
	},
}
</script>

<style scoped>
.home-view {
	width: 100%;
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 0 clamp(16px, 3vw, 44px);
	overflow-y: auto;
	background: var(--color-main-background, #fff);
}

.home-view :deep(.home-hero),
.home-view :deep(.stat-grid),
.home-view :deep(.home-grid) {
	width: min(100%, var(--eva-content-width, 1180px));
	margin: 0 auto;
	box-sizing: border-box;
}

.eyebrow {
	margin: 0 0 4px;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.12em;
	text-transform: uppercase;
	color: var(--color-primary, #00679c);
}

.home-hero {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	padding-top: 28px;
}

.hero-copy h1 {
	margin: 0;
	font-size: clamp(24px, 3.4vw, 34px);
	font-weight: 700;
	letter-spacing: -0.02em;
	color: var(--color-main-text, #222);
}

.hero-sub {
	margin: 8px 0 0;
	max-width: 560px;
	font-size: 14px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast, #666);
}

.hero-actions {
	display: flex;
	gap: 8px;
	margin-top: 14px;
}

.prompt-showcase { position: relative; display: grid; grid-template-columns: minmax(220px, .8fr) 2fr; align-items: center; gap: 18px; width: min(100%, var(--eva-content-width, 1180px)); margin: 0 auto; padding: 17px 19px; box-sizing: border-box; border: 1px solid color-mix(in srgb, var(--color-primary-element, #0082c9) 26%, var(--color-border, #e6e6e6)); border-radius: 16px; background: linear-gradient(115deg, color-mix(in srgb, var(--color-primary-element, #0082c9) 11%, var(--color-main-background, #fff)), var(--color-main-background, #fff) 62%); overflow: hidden; }
.prompt-showcase::after { content: ''; position: absolute; width: 170px; height: 170px; right: -72px; top: -95px; border-radius: 50%; background: color-mix(in srgb, var(--color-primary-element, #0082c9) 12%, transparent); pointer-events: none; }
.prompt-showcase__intro { display: flex; align-items: flex-start; gap: 11px; position: relative; z-index: 1; }
.prompt-showcase__intro strong, .prompt-showcase__intro span { display: block; }
.prompt-showcase__intro strong { font-size: 14px; color: var(--color-main-text, #222); }
.prompt-showcase__intro div > span { margin-top: 4px; color: var(--color-text-maxcontrast, #666); font-size: 12px; line-height: 1.45; }
.prompt-showcase__spark { color: var(--color-primary-element, #0082c9); font-size: 23px; line-height: 1; }
.prompt-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; position: relative; z-index: 1; }
.prompt-card { display: flex; align-items: center; gap: 9px; min-height: 55px; padding: 10px 11px; border: 1px solid var(--color-border, #e6e6e6); border-radius: 11px; background: color-mix(in srgb, var(--color-main-background, #fff) 86%, transparent); color: var(--color-main-text, #222); text-align: left; font: inherit; font-size: 12px; line-height: 1.3; cursor: pointer; transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease; }
.prompt-card:hover, .prompt-card:focus-visible { transform: translateY(-2px); border-color: var(--color-primary-element, #0082c9); box-shadow: 0 5px 14px color-mix(in srgb, var(--color-primary-element, #0082c9) 16%, transparent); outline: none; }
.prompt-card svg { flex: none; color: var(--color-primary-element, #0082c9); }
.prompt-arrow { margin-left: auto; color: var(--color-text-maxcontrast, #888); font-size: 17px; }

.stat-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
}

.stat-card {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #e6e6e6);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background, #fff);
}

.stat-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	flex: none;
	border-radius: var(--border-radius-large, 12px);
	/* Neutral theme styling instead of the old per-card pastel tints. */
	background: var(--color-background-hover, #f2f2f2);
	color: var(--color-main-text, #222);
}

.stat-body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.stat-label {
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast, #666);
}

.stat-value {
	font-size: 24px;
	font-weight: 700;
	letter-spacing: -0.02em;
	color: var(--color-main-text, #222);
}

.stat-hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}

.home-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 12px;
	padding-bottom: 28px;
}

.home-panel {
	border: 1px solid var(--color-border, #e6e6e6);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background, #fff);
	padding: 16px 18px 14px;
	overflow: hidden;
}

.panel-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
}

.panel-head h2 {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 15px;
	font-weight: 650;
	color: var(--color-main-text, #222);
}

.panel-link {
	border: 0;
	background: transparent;
	color: var(--color-primary, #00679c);
	cursor: pointer;
	font-size: 13px;
	font-weight: 600;
	padding: 4px 6px;
	border-radius: var(--border-radius, 6px);
}

.panel-link:hover {
	background: var(--color-background-hover, #f2f2f2);
}

.recent-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.recent-row {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	border: 0;
	border-radius: var(--border-radius-large, 10px);
	background: transparent;
	color: var(--color-main-text, #222);
	cursor: pointer;
	padding: 8px 10px;
	text-align: left;
	box-sizing: border-box;
}

.recent-row:hover {
	background: var(--color-background-hover, #f2f2f2);
}

.recent-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	flex: none;
	border-radius: 50%;
	background: var(--color-primary-light, #e8f0f7);
	color: var(--color-primary-text, #00679c);
}

.recent-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.recent-title {
	font-size: 14px;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.recent-meta {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}

.recent-folder {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	color: var(--color-primary-text, #00679c);
}

.recent-time {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
	flex: none;
}

.panel-empty {
	margin: 12px 0 8px;
	color: var(--color-text-maxcontrast, #888);
	font-size: 13px;
}

.status-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.status-list li {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 9px 4px;
	border-bottom: 1px solid var(--color-border-subtle, #f0f0f0);
}

.status-list li:last-child {
	border-bottom: 0;
}

.status-dot {
	width: 9px;
	height: 9px;
	flex: none;
	border-radius: 50%;
}

.status-dot--ok {
	background: var(--color-success, #2fb344);
}

.status-dot--bad {
	background: var(--color-error, #e9322d);
}

.status-label {
	flex: 1;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.status-value {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text, #222);
	max-width: 55%;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.status-value--mono {
	font-family: var(--font-face-mono, monospace);
	font-size: 12px;
	font-weight: 500;
}

.status-value--warn {
	color: var(--color-warning, #a66a00);
}

.status-value--ok {
	color: var(--color-success, #2fb344);
}

.home-callout {
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 10px);
	font-size: 13px;
}

.home-callout--error {
	background: var(--color-error-light, #fbecec);
	color: var(--color-error, #c00);
}

@media (max-width: 760px) {
	.prompt-showcase { grid-template-columns: 1fr; }
	.prompt-cards { grid-template-columns: 1fr; }
}
</style>
