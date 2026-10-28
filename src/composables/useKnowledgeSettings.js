import { ref } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

export function useKnowledgeSettings({ setMessage }) {
	const knowledgeContent = ref('')
	const knowledgeOriginal = ref('')
	const savingKnowledge = ref(false)
	const knowledgeSaved = ref(false)
	const knowledgeLoading = ref(false)
	const knowledgeLoadError = ref(false)
	const knowledgeReady = ref(false)

	async function loadKnowledge() {
		knowledgeLoading.value = true
		knowledgeLoadError.value = false
		try {
			const data = await api('GET', 'knowledge')
			if (!data || typeof data.content !== 'string') throw new Error('Invalid knowledge response')
			knowledgeContent.value = data.content
			knowledgeOriginal.value = knowledgeContent.value
			knowledgeReady.value = true
			return true
		} catch (e) {
			knowledgeLoadError.value = true
			return false
		} finally {
			knowledgeLoading.value = false
		}
	}

	async function saveKnowledgeContent() {
		if (savingKnowledge.value) return
		savingKnowledge.value = true
		try {
			await api('PUT', 'knowledge', { content: knowledgeContent.value })
			knowledgeOriginal.value = knowledgeContent.value
			knowledgeSaved.value = true
			setMessage('success', t('Your personal knowledge was saved.'))
			window.setTimeout(() => { knowledgeSaved.value = false }, 3000)
		} catch (error) {
			setMessage('error', t('Could not save knowledge: {error}', { error: errMsg(error) }))
		} finally {
			savingKnowledge.value = false
		}
	}

	return { knowledgeContent, knowledgeOriginal, savingKnowledge, knowledgeSaved, knowledgeLoading, knowledgeLoadError, knowledgeReady, loadKnowledge, saveKnowledgeContent }
}
