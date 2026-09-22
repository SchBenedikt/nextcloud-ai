/** Decode streaming chat events across arbitrary UTF-8/network boundaries. */
export async function readNdjson(body, onEvent) {
	const reader = body.getReader()
	const decoder = new TextDecoder()
	let buffer = ''
	const emit = (line) => {
		if (!line.trim()) return
		let event
		try {
			event = JSON.parse(line)
		} catch (error) {
			// A malformed line should not discard the valid events that follow it.
			// Keep the warning local to the stream and continue decoding.
			console.warn('Ignoring malformed chat stream event', error)
			return
		}
		if (!event || typeof event !== 'object' || Array.isArray(event)) {
			console.warn('Ignoring invalid chat stream event')
			return
		}
		onEvent(event)
	}
	try {
		while (true) {
			const { done, value } = await reader.read()
			buffer += done ? decoder.decode() : decoder.decode(value, { stream: true })
			let newline
			while ((newline = buffer.indexOf('\n')) !== -1) {
				emit(buffer.slice(0, newline))
				buffer = buffer.slice(newline + 1)
			}
			if (done) {
				emit(buffer)
				return
			}
		}
	} catch (error) {
		try { await reader.cancel() } catch (_) { /* preserve original failure */ }
		throw error
	} finally {
		reader.releaseLock()
	}
}
