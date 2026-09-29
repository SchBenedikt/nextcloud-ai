# EVA AI API routes

EVA exposes authenticated OCS routes under `/ocs/v2.php/apps/eva_ai/api/`.
The Vue client uses `@nextcloud/axios`, which supplies Nextcloud's request token
and OCS headers. Admin routes live under `api/admin/` and require administrator
privileges.

## Compatibility aliases

Older releases exposed camelCase paths. They remain available for existing
clients. New integrations should use the consistent paths below:

| Operation | Method and path |
| --- | --- |
| Start mail indexing | `POST /api/mail_index` |
| Start Talk indexing | `POST /api/talk_index` |
| Stop indexing | `POST /api/index/stop` |
| Reset index | `POST /api/index/reset` |
| Delete a chat folder | `DELETE /api/folders` with `{ "name": "…" }` |

The legacy `/api/mailIndex`, `/api/talkIndex`, `/api/indexStop`,
`/api/indexReset`, and `POST /api/folders/delete` paths remain supported.
