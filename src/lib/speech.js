/** Browser speech synthesis adapter used by the chat answer controls. */
export function createSpeechService(synthesis = globalThis.speechSynthesis, Utterance = globalThis.SpeechSynthesisUtterance) {
	let active = null
	const supported = Boolean(synthesis
		&& ['speak', 'cancel', 'pause', 'resume'].every(method => typeof synthesis[method] === 'function')
		&& typeof Utterance === 'function')

	function getVoices() {
		return supported && typeof synthesis.getVoices === 'function' ? synthesis.getVoices() : []
	}

	function resetActive() {
		const previous = active
		active = null
		previous?.onState?.('idle')
	}

	function stop() {
		if (!supported) return
		resetActive()
		synthesis.cancel()
	}

	function speak(text, options = {}) {
		if (!supported || !String(text || '').trim()) return false
		stop()
		const utterance = new Utterance(String(text))
		const rate = Number(options.rate)
		utterance.rate = Number.isFinite(rate) ? Math.max(0.5, Math.min(2, rate)) : 1
		if (typeof options.lang === 'string' && options.lang) utterance.lang = options.lang
		if (typeof options.voice === 'string' && options.voice) {
			utterance.voice = getVoices().find(voice => voice.voiceURI === options.voice || voice.name === options.voice) || null
		}
		const onState = typeof options.onState === 'function' ? options.onState : () => {}
		active = { utterance, onState }
		utterance.onstart = () => { if (active?.utterance === utterance) onState('speaking') }
		utterance.onend = () => { if (active?.utterance === utterance) resetActive() }
		utterance.onerror = () => { if (active?.utterance === utterance) resetActive() }
		synthesis.speak(utterance)
		return true
	}

	function pause() {
		if (supported && active) synthesis.pause()
	}

	function resume() {
		if (supported && active) synthesis.resume()
	}

	return { supported, getVoices, speak, pause, resume, stop }
}
