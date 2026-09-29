/** Browser speech recognition adapter for dictating chat input. */
export function createVoiceInput(options = {}) {
	const Recognition = options.Recognition || globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition
	const supported = typeof Recognition === 'function'
	let recognition = null
	let recording = false
	let cancelled = false
	let originalText = ''

	const onState = typeof options.onState === 'function' ? options.onState : () => {}
	const onInterim = typeof options.onInterim === 'function' ? options.onInterim : () => {}
	const onFinal = typeof options.onFinal === 'function' ? options.onFinal : () => {}
	const onError = typeof options.onError === 'function' ? options.onError : () => {}

	function start(currentText = '') {
		if (!supported || recording) return false
		try {
			recognition = new Recognition()
			recognition.lang = options.lang || globalThis.document?.documentElement?.lang || globalThis.navigator?.language || 'en-US'
			recognition.interimResults = true
			recognition.continuous = false
			originalText = String(currentText)
			cancelled = false
			recording = true
			recognition.onstart = () => onState('recording')
			recognition.onresult = event => {
				let interim = ''
				let finalText = ''
				for (let i = event.resultIndex || 0; i < event.results.length; i++) {
					const result = event.results[i]
					const text = result?.[0]?.transcript || ''
					if (result.isFinal) finalText += text
					else interim += text
				}
				if (finalText.trim()) onFinal(finalText.trim())
				onInterim(interim.trim())
			}
			recognition.onerror = event => {
				if (event?.error === 'aborted' && cancelled) return
				onError(event?.error || 'unknown')
			}
			recognition.onend = () => {
				if (cancelled) return
				recording = false
				recognition = null
				onInterim('')
				onState('idle')
			}
			recognition.start()
			return true
		} catch (error) {
			recording = false
			recognition = null
			onError(error?.name === 'NotAllowedError' ? 'not-allowed' : 'unavailable')
			onState('idle')
			return false
		}
	}

	function stop() {
		if (!recording || !recognition) return false
		recognition.stop()
		return true
	}

	function cancel() {
		if (!recording || !recognition) return false
		const activeRecognition = recognition
		cancelled = true
		onFinal('', { cancel: true, restore: originalText })
		recording = false
		recognition = null
		onInterim('')
		onState('idle')
		activeRecognition.abort()
		return true
	}

	return { supported, start, stop, cancel, get recording() { return recording } }
}
