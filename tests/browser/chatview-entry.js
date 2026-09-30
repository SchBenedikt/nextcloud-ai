import { mountChat } from '../../src/lib/vanilla'

const root = document.getElementById('chat-root')
if (root) mountChat(root)
