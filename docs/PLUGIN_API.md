# EVA tool plugins

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
validated JSON arguments. Mutating and destructive plugins must set the
corresponding risk and require confirmation. EVA never exposes a plugin on a
surface that was not declared, and a failing optional plugin cannot remove
built-in tools.

For terminal or system integrations, keep commands in the plugin's own strict
allowlist and return structured results. Do not execute arbitrary shell text;
EVA's built-in `run_safe_command` remains confirmation-gated for the same
reason.
