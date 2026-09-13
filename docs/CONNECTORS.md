# EVA external connectors

External connectors let a user connect an existing HTTP service from **Settings
→ External connectors** without installing a second Nextcloud app. They are
deliberately generic: EVA does not assume that a service is Immich, Vaultwarden
or TrueNAS. It learns the service's own API description and keeps the learned
route metadata scoped to that connector's host.

## Setup flow

1. Enter the service base URL. Use the URL that the Nextcloud server can reach,
   not necessarily the URL that works in the browser on a laptop.
2. Optionally enter a same-host OpenAPI/Swagger URL. Leaving it blank lets EVA
   probe standard locations such as `/openapi.json`, `/swagger.json`,
   `/.well-known/openapi.json`, `/api/openapi.json`, `/api/swagger.json` and
   links advertised by the service's landing page.
3. Select the authentication scheme and enter the secret. Secrets are encrypted
   per user and are never returned to the model or included in exports.
4. Click **Test connection**, then **Discover API**. The diagnostic distinguishes
   unreachable hosts, authentication failures and reachable services without
   exposing the response body.

OpenAPI security schemes are used to preselect `api_key`, `basic` or `bearer`
for a new connector. Existing credentials and an explicit choice are never
overwritten. Immich's schema-free fallback automatically selects the usual
`x-api-key` header when no credential has been stored yet.

## Authentication examples

### Immich

- Base URL: `https://immich.example.test`
- Authentication: **API key**
- API key header: `x-api-key`
- Typical read routes after discovery: `/api/people`, `/api/assets`,
  `/api/people/{id}/thumbnail`

Immich deployments often disable Swagger. EVA identifies an Immich landing
page, probes bounded read routes and learns common parameterized routes without
performing writes. A person search still requires a valid Immich API key and
the Immich user's permissions.

### Vaultwarden / Bitwarden-compatible services

Use the service's published API base URL and choose **Bearer token** when the
service exposes a bearer/OAuth2 scheme. If the service publishes an API key
under a custom header, choose **API key** and enter that exact header name.
Never paste a password, session cookie or master password into request
parameters. EVA only stores the selected connector secret in its encrypted
credential store.

### TrueNAS and appliances

Use the API mount point advertised by the appliance, for example a TrueNAS
`/api/v2.0` base URL, and choose **Bearer token** for an API key that the
appliance expects as a bearer value. Discovery reads the complete schema (up to
the bounded endpoint limit), including routes that appear late in a large
document such as VM and container operations.

## Learned requests

For each discovered operation EVA stores only bounded metadata: method, path,
operation ID, parameter names/locations, and request-body field types. Local
`$ref` schemas and bounded `allOf`/`oneOf`/`anyOf` fragments are resolved; remote
schema URLs are never fetched. Required request-body fields are checked before
POST/PUT/PATCH requests. Parameters marked `in: query` are sent in the URL,
path parameters are URL-encoded, and remaining fields form the JSON body.
Unknown optional fields remain allowed for compatibility with vendor-specific
extensions.

Every external action is confirmation-gated. Read-only batches are limited in
size and time; write and destructive methods are never silently upgraded to a
read operation. Redirects, cross-host URLs, shell syntax and credential-shaped
fields are rejected or redacted.

## When to build a plugin instead

Build a Nextcloud EVA plugin when the integration belongs to a Nextcloud app,
needs app-specific ACL checks, or should expose a meaningful operation rather
than raw HTTP. For example, an Immich plugin can expose
`plugin_immich_find_person` and call the integration's own service layer while
enforcing the current user's permissions. Register it through
`ToolPluginRegisterEvent` as described in [`PLUGIN_API.md`](PLUGIN_API.md).

Use a connector for a user-configured service; use a plugin for a maintained,
user-aware integration. Both paths retain EVA's normal surface restrictions,
bounded results, redaction and confirmation policy.

## Troubleshooting

- **Host unreachable:** test routing, DNS, VPN, firewall rules and the bind
  address from the Nextcloud server itself.
- **HTTP 401/403:** verify the selected scheme, secret and service ACL. Editing
  the connector with an empty secret keeps the encrypted secret; it does not
  delete it.
- **No schema found:** provide the service's same-host OpenAPI URL or inspect
  the learned route list. Services without a schema can still expose bounded
  runtime, hypermedia, JavaScript-route or vendor-fallback metadata.
- **Route not discovered:** run **Discover API** again after changing the
  service base URL or schema URL, then call only the learned method/path.

