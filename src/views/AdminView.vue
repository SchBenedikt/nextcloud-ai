<template>
	<div class="admin-view">
		<header class="page-header">
			<div class="header-copy">
				<p class="eyebrow">EVA AI</p>
				<h2 class="settings-title">{{ $t('Admin dashboard') }}</h2>
				<p class="page-intro">{{ $t('Overview of the RAG index per user with re-index, reset and enrollment management.') }}</p>
			</div>
			<div class="header-actions">
				<NcButton type="secondary" :loading="loading" @click="load">
					{{ $t('Refresh') }}
				</NcButton>
			</div>
		</header>

		<div v-if="loadError" class="callout callout-error" role="alert">
			<strong>{{ $t('Overview could not be loaded.') }}</strong>
			<span>{{ loadError }}</span>
			<NcButton type="tertiary-no-background" @click="load">{{ $t('Try again') }}</NcButton>
		</div>

		<div v-if="message.text" class="callout" :class="'callout-' + message.type" :role="message.type === 'error' ? 'alert' : 'status'">
			<strong>{{ message.type === 'error' ? $t('Something went wrong') : $t('Done') }}</strong>
			<span>{{ message.text }}</span>
		</div>

		<div class="admin-summary-grid" :aria-label="$t('Instance status')">
			<div class="summary-card" :class="data?.ollama?.online ? 'is-ok' : 'is-muted'">
				<span class="status-dot" aria-hidden="true"></span>
				<div>
					<span class="summary-label">{{ $t('Ollama') }}</span>
					<strong>{{ data?.ollama?.online ? $t('Connected') : $t('Not connected') }}</strong>
				</div>
			</div>
			<div class="summary-card">
				<div>
					<span class="summary-label">{{ $t('Concurrent index passes') }}</span>
					<strong>{{ data?.scheduler?.running ?? 0 }} / {{ data?.scheduler?.limit ?? 0 }}</strong>
					<small>{{ $t('{count} waiting', { count: data?.scheduler?.queued ?? 0 }) }}</small>
				</div>
			</div>
			<div class="summary-card">
				<div>
					<span class="summary-label">{{ $t('Users with an index') }}</span>
					<strong>{{ data?.users?.length ?? 0 }}</strong>
				</div>
			</div>
		</div>

		<main class="admin-body">
			<div v-if="!data && loading" class="admin-loading">{{ $t('Loading…') }}</div>
			<div v-else-if="!data?.users?.length" class="admin-empty">{{ $t('No users have an index yet.') }}</div>
			<div v-else class="admin-table-wrap">
				<table class="admin-table">
					<thead>
						<tr>
							<th>{{ $t('User') }}</th>
							<th class="num">{{ $t('Docs') }}</th>
							<th class="num">{{ $t('Chunks') }}</th>
							<th>{{ $t('Last indexed') }}</th>
							<th>{{ $t('Enrollment') }}</th>
							<th>{{ $t('State') }}</th>
							<th>{{ $t('Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="user in data.users" :key="user.userId" :class="{ 'row-error': !!user.error }">
							<td>
								<strong class="user-name">{{ user.displayName }}</strong>
								<small class="user-id">{{ user.userId }}</small>
								<div v-if="user.error" class="user-error" :title="user.error">{{ user.error }}</div>
							</td>
							<td class="num">{{ user.documents }}</td>
							<td class="num">{{ user.chunks }}</td>
							<td>{{ user.lastIndexedAt ? formatTime(user.lastIndexedAt) : '—' }}</td>
							<td>
								<NcCheckboxRadioSwitch :checked="user.enrolled" :disabled="busyFor(user.userId)"
									@update:checked="toggleEnrollment(user)">
									{{ user.enrolled ? $t('Enrolled') : $t('Off') }}
								</NcCheckboxRadioSwitch>
							</td>
							<td>
								<span v-if="user.indexing" class="state-pill state-running">{{ $t('Indexing') }}</span>
								<span v-else-if="user.mode && user.mode !== 'idle'" class="state-pill">{{ user.mode }}</span>
								<span v-else class="state-pill state-idle">{{ $t('Idle') }}</span>
							</td>
							<td class="admin-actions">
								<NcButton type="tertiary" :loading="busyFor(user.userId) === 'reindex'" :disabled="!!busyFor(user.userId)" @click="reindex(user)">
									{{ $t('Re-index') }}
								</NcButton>
								<NcButton type="tertiary" :loading="busyFor(user.userId) === 'reset'" :disabled="!!busyFor(user.userId) || user.indexing" @click="resetUser(user)">
									{{ $t('Reset') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</main>
	</div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { api as requestApi, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

// The api() helper signature is (method, path, data).
const apiGet = (path) => requestApi('GET', path)
const apiPost = (path, data) => requestApi('POST', path, data)

export default {
	name: 'AdminView',
	setup() {
		const data = ref(null)
		const loading = ref(false)
		const loadError = ref('')
		const message = ref({ type: '', text: '' })
		const busy = ref({}) // userId -> 'reindex' | 'reset' | 'enroll'

		const busyFor = (userId) => busy.value[userId] || ''

		async function load() {
			loading.value = true
			loadError.value = ''
			try {
				const res = await apiGet('admin/overview')
				if (res && typeof res === 'object') {
					data.value = res
				} else {
					throw new Error('Unexpected response')
				}
			} catch (e) {
				loadError.value = errMsg(e)
			} finally {
				loading.value = false
			}
		}

		function flash(type, text) {
			message.value = { type, text }
			window.setTimeout(() => { message.value = { type: '', text: '' } }, 6000)
		}

		async function reindex(user) {
			busy.value[user.userId] = 'reindex'
			try {
				const res = await apiPost(`admin/users/${encodeURIComponent(user.userId)}/reindex`, {})
				if (res && res.queued) {
					flash('success', t('Indexing queued for {name}.', { name: user.displayName }))
				} else if (res && res.error) {
					flash('error', res.error)
				}
				await load()
			} catch (e) {
				flash('error', errMsg(e))
			} finally {
				delete busy.value[user.userId]
			}
		}

		async function resetUser(user) {
			if (!window.confirm(t('Delete the complete index of {name}? This cannot be undone.', { name: user.displayName }))) {
				return
			}
			busy.value[user.userId] = 'reset'
			try {
				await apiPost(`admin/users/${encodeURIComponent(user.userId)}/reset`, {})
				flash('success', t('Index of {name} was reset.', { name: user.displayName }))
				await load()
			} catch (e) {
				flash('error', errMsg(e))
			} finally {
				delete busy.value[user.userId]
			}
		}

		async function toggleEnrollment(user) {
			busy.value[user.userId] = 'enroll'
			try {
				await apiPost(`admin/users/${encodeURIComponent(user.userId)}/enrollment`, { enabled: !user.enrolled })
				await load()
			} catch (e) {
				flash('error', errMsg(e))
			} finally {
				delete busy.value[user.userId]
			}
		}

		function formatTime(ts) {
			try {
				return new Date(ts * 1000).toLocaleString()
			} catch (e) {
				return String(ts)
			}
		}

		onMounted(load)

		return { data, loading, loadError, message, busyFor, load, reindex, resetUser, toggleEnrollment, formatTime }
	},
}
</script>

<style scoped>
.admin-view {
	max-width: 1080px;
	padding: 0 8px 48px;
}
.page-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 20px;
	flex-wrap: wrap;
}
.eyebrow {
	font-size: 11px;
	letter-spacing: .12em;
	text-transform: uppercase;
	opacity: .6;
	margin: 0 0 4px;
}
.settings-title {
	margin: 0;
	font-size: 20px;
}
.page-intro {
	margin: 6px 0 0;
	opacity: .75;
	max-width: 560px;
}
.admin-summary-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	margin-bottom: 24px;
}
.summary-card {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 14px 16px;
	background: var(--color-main-background);
}
.summary-card .status-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-top: 5px;
	background: var(--color-text-maxcontrast);
}
.summary-card.is-ok .status-dot { background: var(--color-success, #2ecc71); }
.summary-card.is-muted .status-dot { background: var(--color-error, #e9322d); }
.summary-label {
	display: block;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .06em;
	opacity: .6;
	margin-bottom: 2px;
}
.summary-card strong { display: block; font-size: 15px; }
.summary-card small { opacity: .7; }
.admin-table-wrap {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
}
.admin-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
	min-width: 760px;
}
.admin-table th,
.admin-table td {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}
.admin-table thead th {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .06em;
	opacity: .65;
	background: var(--color-background-dark);
}
.admin-table th.num,
.admin-table td.num {
	text-align: right;
	font-variant-numeric: tabular-nums;
}
.admin-table tr:last-child td { border-bottom: 0; }
.admin-table tr.row-error td { background: color-mix(in srgb, var(--color-error, #e9322d) 6%, transparent); }
.user-name { display: block; }
.user-id { opacity: .55; }
.user-error {
	margin-top: 4px;
	font-size: 12px;
	color: var(--color-error, #e9322d);
	max-width: 320px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.state-pill {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 12px;
	background: var(--color-background-dark);
}
.state-pill.state-running { color: var(--color-primary-text, #fff); background: var(--color-primary); }
.state-pill.state-idle { opacity: .7; }
.admin-actions {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
.admin-loading,
.admin-empty {
	padding: 40px 16px;
	text-align: center;
	opacity: .7;
}
</style>