# EVA AI API routes

EVA exposes OCS routes under `/ocs/v2.php/apps/eva_ai/api/`. Most routes require
a signed-in Nextcloud user; the `/api/v1/` integration routes authenticate
with an EVA bearer key and are explicitly scoped to that key's owner.
The Vue client uses `@nextcloud/axios`, which supplies Nextcloud's request token
and OCS headers. Admin routes live under `api/admin/` and require administrator
privileges.

## Error responses

Non-streaming API and admin errors use one OCS data shape:

```json
{
  "error": {
    "code": "conflict",
    "message": "This chat changed in another tab."
  }
}
```

`code` is a stable machine-readable identifier and `message` is suitable for
display to the user. HTTP status codes remain authoritative. NDJSON streaming
endpoints report failures as `{ "type": "error", "message": "…" }` records
inside the stream.

## Compatibility aliases

Older releases exposed camelCase paths. They remain available for existing
clients. New integrations should use the consistent paths below:

| Operation | Method and path |
| --- | --- |
| Start mail indexing | `POST /api/mail_index` |
| Start Talk indexing | `POST /api/talk_index` |
| Stop indexing | `POST /api/index/stop` |

## Programmatic API keys

Users manage their own credentials with the authenticated routes below. The
secret is returned only once by `POST /api/keys`; storage contains only its
SHA-256 hash. `admin` keys can only be created by a Nextcloud instance admin.

| Operation | Method and path |
| --- | --- |
| List keys and usage | `GET /api/keys` |
| Create a key | `POST /api/keys` |
| Revoke a key | `DELETE /api/keys/{id}` |
| Send a programmatic chat request | `POST /api/v1/chat` |
| List chats | `GET /api/v1/chats` |
| Read one chat | `GET /api/v1/chats/{id}` |
| List indexed documents | `GET /api/v1/documents` |
| Read indexed document chunks | `GET /api/v1/documents/{id}/chunks` |

Create with JSON fields `name`, `scope` (`read`, `write`, or `admin`), optional
`expiresAt` (Unix timestamp), and optional `ipWhitelist` (exact IP addresses).
Read keys can list chats and indexed documents and read their content in bounded
pages. They are limited to 100 requests per minute; write keys (30/min) can also send a chat request, and
admin keys (10/min) inherit both capabilities. The external chat route never
runs EVA tools or mutating actions. The API key owner must remain an enabled
Nextcloud user.

```sh
curl -X POST 'https://nextcloud.example.com/ocs/v2.php/apps/eva_ai/api/v1/chat' \
  -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer eva_sk_REPLACE_WITH_YOUR_SECRET' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Summarize my indexed documents"}'
```

Keep the returned secret private. The key list includes its name, masked
prefix, scope, expiry, last-use time, and call count, but never the secret.

## Prompt templates

Signed-in users can manage their own prompt templates through the chat API.
Templates support `{variable}` placeholders, optional per-template persona
instructions, and a usage counter. JSON export and import let users share
templates by passing the exported file to another user.

| Operation | Method and path |
| --- | --- |
| List or export this user's templates | `GET /api/templates` or `GET /api/templates/export` |
| Create or update a template | `POST /api/templates` |
| Import templates from `{ "templates": [...] }` | `POST /api/templates/import` |
| Record use and fetch a template | `POST /api/templates/{id}/use` |
| Delete a template | `DELETE /api/templates/{id}` |

Template data is stored in the user's private EVA app data. Import creates new
IDs and accepts at most 100 templates per request; each prompt is limited to
8,000 characters.

| Reset index | `POST /api/index/reset` |
| Delete a chat folder | `DELETE /api/folders` with `{ "name": "…" }` |

The legacy `/api/mailIndex`, `/api/talkIndex`, `/api/indexStop`,
`/api/indexReset`, and `POST /api/folders/delete` paths remain supported.
