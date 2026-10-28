# Get started with EVA

EVA runs inside Nextcloud. An administrator installs and enables `eva_ai`;
each user can then configure their own models and tools.

## Connect a chat model

Open **EVA → Settings → Models**. Choose **Configure** beside Ollama or Groq,
or **Add AI provider** for another service. Enter the exact model ID available
in your account. **Save & use provider** saves the profile and activates it.

Cloud profiles need an API key. For a local compatible server that has no
authentication, disable **Authenticate with an API key**. Localhost refers to
the Nextcloud server, not the computer running your browser.

Read [AI providers](PROVIDERS.md) before choosing a hosted service. A local
Ollama endpoint can still use a cloud model; its address alone does not prove
that inference is local.

## Send a message

Go to **Home**, type a question and press **Send**. EVA creates a conversation
and sends the question through the normal chat flow. Recent conversations
appear below the input and in the navigation.

Start with a question needing no integrations, such as “Help me turn this idea
into a checklist.” Check the answer before relying on it.

## Add documents

Chat and indexing have separate model requirements. Indexing uses an Ollama
embedding model even when another provider handles chat. In **Settings →
Documents**, choose your indexing scope and limits. Start with a small folder.
Open **Documents** and start indexing. Background processing needs working
Nextcloud cron.

Once indexing finishes, ask about a document and open the cited sources.
Use **Open with EVA** in Files to focus on a particular file.

## Enable the tools you need

Review **Settings → Tools & permissions** before enabling actions, Talk or web
search. These integrations have different permissions and data flows. Scheduled
briefings are configured under **Automation**.

For failures, follow [Troubleshooting](TROUBLESHOOTING.md).
