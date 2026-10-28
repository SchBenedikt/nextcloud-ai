import { computed, ref } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

export function useBriefingSettings({ f, fill, setMessage }) {
	const proactiveEnabled = computed({
		get: () => f.value.proactive_enabled === '1',
		set: value => { f.value.proactive_enabled = value ? '1' : '0' },
	})
	const weekdays = [
		{ value: 1, label: t('Mon') }, { value: 2, label: t('Tue') }, { value: 3, label: t('Wed') },
		{ value: 4, label: t('Thu') }, { value: 5, label: t('Fri') }, { value: 6, label: t('Sat') }, { value: 7, label: t('Sun') },
	]
	const briefingDraft = ref({ prompt: '', time: '08:00', days: [1, 2, 3, 4, 5], allow_actions: false, type: 'custom', channels: ['notification'], talk_room: '' })
	const briefingFormError = ref('')
	const editingBriefingId = ref(null)
	const briefingHistory = ref([])
	const briefingTypeOptions = [
		{ value: 'morning', label: t('Morning briefing') },
		{ value: 'document_digest', label: t('Document digest') },
		{ value: 'custom', label: t('Custom report') },
	]
	const proactiveBriefings = computed(() => {
		try { const rows = JSON.parse(f.value.proactive_schedules || '[]'); return Array.isArray(rows) ? rows : [] } catch (_) { return [] }
	})
	const dayName = day => (weekdays.find(item => item.value === Number(day)) || {}).label || String(day)
	const briefingTypeName = type => briefingTypeOptions.find(item => item.value === type)?.label || t('Custom report')
	const channelName = channel => ({ notification: t('Nextcloud notification'), email: t('Email'), talk: t('Nextcloud Talk') }[channel] || String(channel))
	const formatDateTime = timestamp => new Date(Number(timestamp || 0) * 1000).toLocaleString()
	const failureSummary = run => Object.values(run?.failures || {}).join('; ')
	function writeBriefings(rows) { f.value.proactive_schedules = JSON.stringify(rows.slice(0, 20)) }
	async function persistBriefings(rows, messageKey) {
		writeBriefings(rows)
		setMessage('info', t('Saving briefing changes…'))
		if (await saveBriefingSchedule()) setMessage('success', t(messageKey))
	}
	async function saveBriefingSchedule() {
		// Save the schedule explicitly.  The general settings watcher may be
		// saving another field at the same time; relying on changedSettingKeys()
		// here made the Add button appear to do nothing when that save raced.
		const payload = {
			proactive_enabled: f.value.proactive_enabled,
			proactive_schedules: f.value.proactive_schedules,
		}
		try {
			const settings = await api('PUT', 'settings', payload)
			if (settings) fill(settings)
			return true
		} catch (error) {
			setMessage('error', t('The briefing could not be saved: {error}', { error: errMsg(error) }))
			return false
		}
	}
	async function saveBriefing() {
		const prompt = briefingDraft.value.prompt.trim()
		const rawTime = String(briefingDraft.value.time || '').trim()
		briefingFormError.value = ''
		if (!prompt) { briefingFormError.value = t('Enter a question or instruction for this briefing.'); return }
		// Native time inputs already constrain the value to HH:MM. Keep a
		// safe default for browsers that expose an empty value during the
		// first interaction instead of making Add appear to do nothing.
		const time = rawTime || '08:00'
		if (!briefingDraft.value.days.length) { briefingFormError.value = t('Choose at least one weekday.'); return }
		if (!briefingDraft.value.channels.length) { briefingFormError.value = t('Choose at least one delivery channel.'); return }
		if (briefingDraft.value.channels.includes('talk') && !briefingDraft.value.talk_room.trim()) { briefingFormError.value = t('Enter a Talk room name or token.'); return }
		if (!editingBriefingId.value && proactiveBriefings.value.length >= 20) { briefingFormError.value = t('You can create up to 20 briefings.'); return }
		const current = proactiveBriefings.value.find(item => item.id === editingBriefingId.value)
		const item = { ...(current || {}), id: editingBriefingId.value || `briefing-${Date.now()}`, prompt, time, days: [...new Set(briefingDraft.value.days)].sort(), enabled: current?.enabled !== false, allow_actions: briefingDraft.value.allow_actions === true, type: briefingDraft.value.type, channels: [...new Set(briefingDraft.value.channels)], talk_room: briefingDraft.value.talk_room.trim() }
		writeBriefings(editingBriefingId.value ? proactiveBriefings.value.map(entry => entry.id === editingBriefingId.value ? item : entry) : [...proactiveBriefings.value, item])
		setMessage('info', t('Saving briefing changes…'))
		if (await saveBriefingSchedule()) {
			editingBriefingId.value = null
			briefingDraft.value = { prompt: '', time: '08:00', days: [1, 2, 3, 4, 5], allow_actions: false, type: 'custom', channels: ['notification'], talk_room: '' }
			setMessage('success', t('Briefing saved.'))
		}
	}
	function editBriefing(item) {
		editingBriefingId.value = item.id
		briefingDraft.value = { prompt: item.prompt || '', time: item.time || '08:00', days: [...(item.days || [])], allow_actions: item.allow_actions === true, type: item.type || 'custom', channels: [...(item.channels || ['notification'])], talk_room: item.talk_room || '' }
		briefingFormError.value = ''
		document.querySelector('.briefing-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
	}
	function cancelEditBriefing() {
		editingBriefingId.value = null
		briefingDraft.value = { prompt: '', time: '08:00', days: [1, 2, 3, 4, 5], allow_actions: false, type: 'custom', channels: ['notification'], talk_room: '' }
		briefingFormError.value = ''
	}
	async function removeBriefing(id) { await persistBriefings(proactiveBriefings.value.filter(item => item.id !== id), 'Briefing changes saved.') }
	async function toggleBriefing(id) { await persistBriefings(proactiveBriefings.value.map(item => item.id === id ? { ...item, enabled: item.enabled === false } : item), 'Briefing changes saved.') }
	async function toggleBriefingActions(id) { await persistBriefings(proactiveBriefings.value.map(item => item.id === id ? { ...item, allow_actions: item.allow_actions !== true } : item), 'Briefing changes saved.') }
	async function loadBriefingHistory() {
		try { const result = await api('GET', 'briefings/history'); briefingHistory.value = Array.isArray(result?.items) ? result.items.reverse() : [] } catch (_) { briefingHistory.value = [] }
	}
	async function runBriefingNow(id) {
		try {
			await api('POST', `briefings/${encodeURIComponent(id)}/run`, {})
			setMessage('success', t('Briefing queued. It will appear in the history when delivery finishes.'))
			window.setTimeout(loadBriefingHistory, 5000)
		} catch (error) { setMessage('error', t('The briefing could not be started: {error}', { error: errMsg(error) })) }
	}
	return { proactiveEnabled, weekdays, briefingDraft, briefingFormError, editingBriefingId, briefingHistory, briefingTypeOptions, proactiveBriefings, dayName, briefingTypeName, channelName, formatDateTime, failureSummary, saveBriefing, editBriefing, cancelEditBriefing, removeBriefing, toggleBriefing, toggleBriefingActions, loadBriefingHistory, runBriefingNow }
}
