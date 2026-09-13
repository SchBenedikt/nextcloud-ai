# EVA tool plugins

For the built-in confirmed terminal tools and their safety model, see
[`TERMINAL_TOOLS.md`](TERMINAL_TOOLS.md).

Other Nextcloud apps can add tools to EVA without changing the EVA source.
Register an event listener for `OCA\EvaAi\Event\ToolPluginRegisterEvent` and
call `$event->registry->register(new YourPlugin())`.

Plugins must implement `OCA\EvaAi\Service\ToolPluginInterface` and expose
names beginning with `plugin_`. A definition looks like:

```php
[
    'name' => 'plugin_example_status',
    'description' => 'Read the example service status.',
    'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
    'risk' => \OCA\EvaAi\Service\ToolPolicy::RISK_READONLY,
    'surfaces' => [\OCA\EvaAi\Service\ToolPolicy::SURFACE_WEB],
    'requiresConfirmation' => false,
]
```

`execute()` receives the authenticated Nextcloud user ID, the tool name and
centrally validated JSON arguments (required fields, declared types, and
declared string limits are checked before the plugin runs). Mutating and destructive plugins must set the
corresponding risk and require confirmation. EVA never exposes a plugin on a
surface that was not declared, and a failing optional plugin cannot remove
built-in tools.

For terminal or system integrations, keep commands in the plugin's own strict
allowlist and return structured results. Do not execute arbitrary shell text;
EVA's built-in `run_safe_command` remains confirmation-gated for the same
reason.

## What to choose: connector or plugin?

Use a **connector** when an administrator or user only needs to connect an
existing HTTP service from Settings. The service does not need a Nextcloud app,
and EVA discovers its OpenAPI/Swagger document, same-origin documentation
links, hypermedia links and (for single-page apps) literal `/api`, `/rest` or
`/ocs` routes from JavaScript bundles. A connector stores its secrets in the
encrypted credential store and never exposes them to the model. Every request
is host-pinned, bounded and confirmation-gated. Configure one from **Settings →
External connectors**, or use the `configure_external_connector` tool.

Use a **plugin** when a Nextcloud app owns the integration and can provide a
stable, user-aware operation (for example “find this person in Immich” rather
than making the model assemble several raw HTTP requests). A plugin can use
Nextcloud service contracts, check the current user's permissions and return a
small structured result. It is loaded at runtime through the event dispatcher;
EVA's built-in tools remain available if a plugin is broken or absent.

For generic connectors, discovered request-body schemas are also used as a
local guard: fields marked `required` must be present before EVA sends a
POST, PUT or PATCH request. Optional and additional fields remain allowed, so
the guard improves error messages without narrowing an existing API.

## Complete plugin example

The following is a minimal read-only plugin from another Nextcloud app. The
class may live in that app's `lib/Service/EvaPlugin.php`:

```php
namespace OCA\Example\Service;

use OCA\EvaAi\Event\ToolPluginRegisterEvent;
use OCA\EvaAi\Service\ToolPluginInterface;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\EventDispatcher\IEventDispatcher;

final class EvaPlugin implements ToolPluginInterface {
    public function __construct(private readonly ExampleService $service) {}

    public function getToolDefinitions(): array {
        return [[
            'name' => 'plugin_example_lookup',
            'description' => 'Look up one record the current user is allowed to see.',
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 120,
                        'description' => 'Name or identifier to search for.',
                    ],
                ],
                'required' => ['query'],
            ],
            'risk' => ToolPolicy::RISK_READONLY,
            'surfaces' => [ToolPolicy::SURFACE_WEB, ToolPolicy::SURFACE_TALK],
            'requiresConfirmation' => false,
        ]];
    }

    public function execute(string $userId, string $toolName, array $arguments): array {
        if ($toolName !== 'plugin_example_lookup') {
            return ['ok' => false, 'error' => 'Unknown plugin operation.'];
        }
        $query = trim((string)($arguments['query'] ?? ''));
        if ($query === '' || mb_strlen($query) > 120) {
            return ['ok' => false, 'error' => 'A short query is required.'];
        }

        // Always pass the authenticated EVA user through to the app service.
        // The plugin must enforce ownership/ACLs; EVA does not grant access.
        $record = $this->service->findForUser($userId, $query);
        return ['ok' => true, 'result' => [
            'query' => $query,
            'record' => $record, // omit secrets and large binary payloads
        ]];
    }
}

// In the app's bootstrap/register method:
$context->registerEventListener(
    ToolPluginRegisterEvent::class,
    static function (ToolPluginRegisterEvent $event) use ($container): void {
        $event->registry->register($container->get(EvaPlugin::class));
    },
);
```

The registration callback must be cheap and side-effect free. Do not perform a
network request while registering. Do not register a name without the
`plugin_` prefix, shadow a built-in tool, or return a definition without a
JSON-schema-like `parameters` object. EVA rejects all three cases.

## Confirmation and execution surfaces

`surfaces` is an allow-list, not a hint:

| Surface | Meaning |
|---|---|
| `web` | Interactive EVA chat. Read-only tools may run immediately; mutating tools are confirmation-gated. |
| `talk` | Nextcloud Talk. Keep responses short and enforce the Talk user's ACLs. |
| `rag` | Read-only retrieval pipeline. Use only bounded, side-effect-free operations. |
| `taskprocessing` | Assistant proposal/read phase. Mutations are not executed on this surface. |
| `taskprocessing_confirmed` | Background/Assistant execution after Nextcloud has supplied an authenticated app token and the required confirmation. |

Set `requiresConfirmation` to `true` for any write, delete, share, message,
system command or operation whose result can have side effects. EVA still
checks the risk and surface centrally before invoking `execute()`. A plugin
must not implement its own “confirmation bypass” flag, trust an argument such
as `confirmed: true`, or treat a model-generated string as authorization.

For destructive operations use `ToolPolicy::RISK_DESTRUCTIVE`, return a clear
structured result, and make the operation idempotent where possible. A failed
plugin must return `['ok' => false, 'error' => '…']` and must not throw secrets
or stack traces into the chat.

## Data, secrets and limits

- Never put API keys, passwords, cookies or bearer tokens in a tool schema,
  argument, description, log entry or result.
- Store credentials through the Nextcloud credential/configuration service of
  the owning app. EVA's connector credential store is for connectors, not a
  replacement for an app's ACL system.
- Bound query length, result count, response size and network timeouts. Return
  summaries and stable identifiers instead of entire media files.
- Escape or normalize user-provided paths and URLs; refuse `..`, shell syntax,
  cross-host redirects and arbitrary outbound hosts.
- For images/documents return an authenticated, time-limited or same-origin
  URL plus metadata only when the user is authorized to view it.

## Testing a plugin

At minimum, add tests for:

1. the definition name, schema, risk and declared surfaces;
2. a successful call for the current user;
3. an unauthorized user and an unknown identifier;
4. malformed/oversized arguments;
5. confirmation-required writes (the operation must not run before the
   confirmation path);
6. a timeout or upstream error (no exception escapes and no secret is
   returned).

Install the app alongside EVA in a development Nextcloud, open **Settings →
EVA extensions**, and confirm that the namespaced tool appears without exposing
credentials. The `Agent runs` view shows the tool name, phase and redacted,
structured arguments for background executions.
