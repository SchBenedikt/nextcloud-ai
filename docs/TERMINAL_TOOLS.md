# EVA terminal tools

EVA has two local-command tools. Both are disabled by default and both require
an explicit confirmation before execution:

- `run_safe_command` runs one fixed, read-only diagnostic (`date`, `uptime`,
  PHP/Node version, disk or memory status, or EVA's Git status).
- `run_terminal_command` runs one command from the user's configured
  executable allowlist.
- `run_terminal_sequence` runs up to five `run_terminal_command`-style
  commands in order and stops at the first failure or timeout.

## Enable the feature

Open **Settings → EVA → Tools and safety** and enable **Terminal commands**.
The default allowlist contains common diagnostics such as `date`, `uptime`,
`php`, `node`, `git`, `ls`, `df`, `du`, `free` and `uname`. Add executable
names or exact absolute paths to **Allowed terminal executables**, separated by
commas. An empty value does not grant access.

The **Allow custom terminal executables** switch permits an executable that is
not in that list. Use it only when necessary. The command is still parsed into
an argument vector and is still confirmation-gated; it is never passed to a
shell.

## Command format and limits

Commands may contain an executable and ordinary arguments, for example:

```text
git -C /var/www/html/nextcloud/apps/eva_ai status --short
php -v
```

The following are deliberately rejected: `;`, `&&`, `||`, pipes, redirects,
backticks, `$()` substitutions, newlines and shell scripts. Each argument is
bounded, output is capped, and the timeout is between 1 and 30 seconds. A
sequence accepts 1–5 command strings and applies the timeout independently to
each command. It never continues after a failed command.

## Confirmation and background runs

EVA shows the exact tool name and redacted arguments in the confirmation card.
The command only runs after the user confirms it. Scheduled/background runs
must have the separate **background actions** permission; a model-generated
`confirmed` flag is never treated as authorization.

The **Agent runs** page records each command, phase, bounded result, exit code,
timeout state and duration. API keys, passwords, tokens and credential-shaped
fields are redacted before a trace is persisted. The live chat shows the same
information in a collapsible tool-result panel.

## Safe workflow examples

To inspect an installation, use a sequence such as:

```json
{
  "commands": [
    "php -v",
    "df -h",
    "git -C /var/www/html/nextcloud/apps/eva_ai status --short"
  ],
  "timeout_seconds": 10
}
```

For a custom executable, add its exact path to the allowlist, review the
confirmation dialog, and keep the command read-only whenever possible. EVA
does not infer that a command is safe merely because its name looks harmless;
the administrator's setting and the explicit confirmation are both required.

## Troubleshooting

- **Terminal commands are disabled:** enable the setting for the current user;
  the app does not silently enable it during an upgrade.
- **Executable is not allowed:** add the bare executable name or exact
  absolute path to the allowlist, or explicitly enable custom-executable mode.
- **Shell syntax rejected:** split a workflow into separate sequence entries;
  do not use pipes or redirects.
- **Timeout:** reduce the scope or run one command at a time. A timeout kills
  the child process and returns its partial bounded output.
- **No trace appears:** refresh **Agent runs**. Background progress is stored
  per user and does not expose the full conversation history.
