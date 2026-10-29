# AI providers

Chat providers and the Ollama embedding service are separate. Changing the chat
provider does not migrate or rebuild the document index.

## Configure a profile

1. Open **Settings → Models → Add AI provider**.
2. Choose a template. It fills the API base URL, not a model or pricing tier.
3. Enter a display name and exact chat model ID.
4. Enter an API key, or explicitly disable authentication for a server that
   accepts unauthenticated requests.
5. Choose **Save & use provider**, then send a short chat to verify inference.

Editing an existing inactive profile saves its configuration without changing
the active chat provider. This lets you configure a dedicated embedding profile
without redirecting chat. Its API key is stored against that exact profile ID,
even while another provider remains active. A newly added profile is still
selected for chat when it is saved; you can switch chat back independently afterward.

Store up to 20 compatible profiles, including multiple profiles for one service.
Each has an independent encrypted key. Ollama and Groq are built-in providers;
their IDs cannot be reused by custom profiles.

An OpenAI-compatible profile may also define an exact `embedding_model` and
`embedding_dimension`. In its **Document embeddings** section, opt it into
automatic file indexing to send indexed file text to that endpoint. This choice
is separate from the active chat provider; changing a chat model does not change
the embedding destination.

## Templates and protocol

Compatible profiles use `POST {base URL}/chat/completions` with bearer
authentication unless `auth` is explicitly `none`. Templates cover these
services; consult their documentation for current model IDs and account access:

| Template | Documentation |
| --- | --- |
| OpenAI | [Chat Completions](https://platform.openai.com/docs/api-reference/chat) |
| Google Gemini | [OpenAI compatibility](https://ai.google.dev/gemini-api/docs/openai) |
| Mistral | [Migration and compatibility](https://docs.mistral.ai/resources/migration-guides) |
| DeepSeek | [API setup](https://api-docs.deepseek.com/) |
| OpenRouter | [API overview](https://openrouter.ai/docs/api/reference/overview) |
| Together AI | [OpenAI compatibility](https://docs.together.ai/docs/openai-api-compatibility) |
| xAI | [API reference](https://api.x.ai/docs/) |
| LM Studio | [OpenAI compatibility](https://lmstudio.ai/docs/developer/openai-compat) |
| vLLM | [Compatible server](https://docs.vllm.ai/en/latest/serving/openai_compatible_server.html) |

A custom template supports other servers with the same contract. Ollama uses
its native API; Groq has its existing dedicated adapter. This is protocol
compatibility, not a claim that every model supports every tool or modality.
Anthropic's native Messages API and Azure's deployment-specific authentication
are not separate native adapters here. A compatible gateway may expose those
models; review that gateway's own data flow.

## Local servers

LM Studio and vLLM templates use localhost examples and default to no key.
Change these settings to match your deployment. Localhost means the Nextcloud
server, or its container, rather than your laptop. With authentication disabled,
EVA omits Authorization instead of sending a placeholder secret. Existing
profiles without `auth` keep API-key authentication.

## Images and audio

Optional `image_model`, `audio_model`, `tts_model` and `tts_voice` fields select
the existing image-generation, transcription and speech endpoints. Configure
them only when the provider implements those contracts. Chat compatibility
alone does not establish image or audio compatibility.

## Data and keys

The active provider receives prompts, relevant conversation context, retrieved
excerpts and tool results needed for the request. Keys are encrypted in
Nextcloud user configuration, separate from profile JSON. A blank key field
keeps a stored key; the status response exposes only `providerKeys` presence flags.

Base URLs must be plain HTTP(S) URLs without embedded credentials, query strings
or fragments. Review [Privacy](PRIVACY.md) and [Security](SECURITY.md) before
changing providers.

## Endpoint redirects and network access

OpenAI-compatible model discovery, chat completions, image generation,
transcription/translation and speech requests do not follow HTTP redirects.
A 3xx response is handled as a provider error. Configure the final API base URL
in the profile rather than relying on an HTTP-to-HTTPS or host redirect. This
also prevents a 307/308 from forwarding a request body to another destination.
Returned image downloads already reject redirects and continue to do so.

Requests continue through Nextcloud's HTTP client, preserving its certificate,
proxy and remote-host validation. EVA does not set `allow_local_address` to bypass
those checks. On the inspected Nextcloud 35 installation, system-wide
`allow_local_remote_servers` can permit local destinations; this is broader than
an EVA-specific endpoint allowlist. Redirect rejection alone is not a complete
SSRF policy or DNS-rebinding defense. The dedicated admin-owned endpoint policy,
metadata/link-local restrictions and adversarial validation remain pending in the
[backend migration ledger](BACKEND-ROADMAP.md).

## EVA-owned destination policy

Custom OpenAI-compatible requests now also pass `ProviderEndpointPolicy` at the
actual HTTP transport boundary. The policy applies to model checks, chat, images,
all audio operations and provider-returned image downloads. It resolves and
classifies all addresses, rejects ambiguous CNAME responses and excessive DNS
work, and replaces the cURL DNS mapping immediately before transfer with the
validated addresses. Credentials in URLs, fragments, encoded/noncanonical hosts,
link-local, metadata, multicast and reserved targets are rejected. Public
connections require HTTPS. IPv4-mapped IPv6 addresses receive IPv4 classification.

Private/loopback endpoints require an exact administrator-owned origin, including
scheme and effective port, in the app configuration `provider_private_origins`:

```sh
php occ config:app:set eva_ai provider_private_origins --value='["http://127.0.0.1:1234","https://models.internal:443"]'
```

The setting is read directly from instance IConfig, never personal settings.
Omitting the port in the allowlist does not grant access. HTTP also requires an
approved origin. Link-local and metadata destinations remain blocked even when
listed. Nextcloud's own local-address policy still applies, so an approval here
alone does not override a stricter core policy. Existing private custom providers
need this explicit approval when upgrading. Built-in Ollama is unchanged by this
custom-provider policy, preserving the app's default installation behavior.

Transport uses Nextcloud's HTTP client and certificate checks with a guarded
cURL handler. Explicitly configured proxies are rejected: proxy-side DNS cannot
be proven to use the locally checked destination. Implicit environment proxies
are disabled for these requests. Deployments that require a proxy need a reviewed
proxy-side egress policy and a future compatible transport mode; do not disable
this guard to make a proxy work. DNS names defined only in `/etc/hosts` are not
resolved by this policy; use a literal approved address or a DNS record. No
connection or transfer to a hosted provider is performed during app installation.
