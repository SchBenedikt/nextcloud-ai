const MAX_PROMPTS = 500

export function createPromptHistory(storage, key) {
	const read = () => {
		try {
			const value = JSON.parse(storage.getItem(key) || '[]')
			return Array.isArray(value) ? value.filter((item) => item && typeof item.text === 'string') : []
		} catch (_) { return [] }
	}
	const write = (items) => {
		try { storage.setItem(key, JSON.stringify(items.slice(0, MAX_PROMPTS))); return true } catch (_) { return false }
	}
	return {
		list(query = '') {
			const needle = String(query).trim().toLocaleLowerCase()
			return read().filter((item) => !needle || item.text.toLocaleLowerCase().includes(needle) || String(item.category || '').toLocaleLowerCase().includes(needle))
				.sort((a, b) => Number(Boolean(b.favorite)) - Number(Boolean(a.favorite)) || Number(b.lastUsed || 0) - Number(a.lastUsed || 0))
		},
		record(text) {
			const clean = String(text || '').trim()
			if (!clean) return
			const items = read()
			const existing = items.find((item) => item.text === clean)
			const now = Date.now()
			if (existing) {
				existing.usageCount = Number(existing.usageCount || 0) + 1
				existing.lastUsed = now
			} else {
				items.unshift({ id: `${now}-${Math.random().toString(36).slice(2, 8)}`, text: clean, favorite: false, category: '', usageCount: 1, lastUsed: now, created: now })
			}
			write(items.sort((a, b) => Number(b.lastUsed || 0) - Number(a.lastUsed || 0)))
		},
		update(id, changes) {
			const items = read()
			const item = items.find((entry) => entry.id === id)
			if (!item) return false
			if (Object.prototype.hasOwnProperty.call(changes, 'favorite')) item.favorite = Boolean(changes.favorite)
			if (Object.prototype.hasOwnProperty.call(changes, 'category')) item.category = String(changes.category || '').trim().slice(0, 48)
			return write(items)
		},
		remove(id) { return write(read().filter((item) => item.id !== id)) },
		export() { return JSON.stringify(read(), null, 2) },
	}
}
