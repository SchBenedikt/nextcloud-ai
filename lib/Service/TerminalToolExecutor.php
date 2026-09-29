<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Executes the explicitly enabled local command tools.
 */
final class TerminalToolExecutor implements DomainToolExecutor {
    private const TOOLS = ['run_safe_command', 'run_terminal_command', 'run_terminal_sequence'];

    public function __construct(private AppConfig $config) {
    }

    public function tools(): array {
        return self::TOOLS;
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'run_safe_command' => $this->runSafeCommand($args),
            'run_terminal_command' => $this->runTerminalCommand($args),
            'run_terminal_sequence' => $this->runTerminalSequence($args),
            default => ['ok' => false, 'error' => 'Unsupported terminal tool: ' . $tool],
        };
    }

    private function runSafeCommand(array $args): array {
        if ($this->config->get('safe_commands_enabled') !== '1') return ['ok' => false, 'error' => 'Safe local commands are disabled in EVA settings.'];
        $name = trim((string)($args['command'] ?? ''));
        $commands = [
            'date' => ['date'], 'uptime' => ['uptime'], 'php_version' => ['php', '-v'],
            'node_version' => ['node', '--version'], 'disk_free' => ['df', '-h'],
            'memory_free' => ['free', '-h'], 'eva_git_status' => ['git', '-C', __DIR__ . '/../../', 'status', '--short'],
        ];
        if (!isset($commands[$name])) return ['ok' => false, 'error' => 'Command is not on the safe diagnostic allowlist.'];
        $pipes = [];
        $process = proc_open($commands[$name], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../../');
        if (!is_resource($process)) return ['ok' => false, 'error' => 'Could not start the diagnostic command.'];
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $deadline = microtime(true) + 5; $timedOut = false; $observedExitCode = null;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) { $observedExitCode = is_int($status['exitcode']) ? $status['exitcode'] : null; break; }
            if (microtime(true) >= $deadline) { $timedOut = true; proc_terminate($process, 9); break; }
            usleep(20000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]); $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        $exit = ($observedExitCode !== null && $closedExitCode < 0) ? $observedExitCode : $closedExitCode;
        return ['ok' => !$timedOut && $exit === 0, 'result' => ['command' => $name, 'output' => mb_substr(trim($stdout), 0, 10000), 'error_output' => mb_substr(trim($stderr), 0, 2000), 'exit_code' => $timedOut ? null : $exit, 'timed_out' => $timedOut]];
    }

    /**
     * Execute a user-requested command without invoking a shell. This is an
     * intentionally separate, opt-in tool: unlike runSafeCommand it accepts
     * arguments, but only for executable names configured in the user's
     * allowlist and only after the normal explicit-confirmation gate.
     */
    private function runTerminalCommand(array $args): array {
        if ($this->config->get('terminal_commands_enabled') !== '1') {
            return ['ok' => false, 'error' => 'Confirmed terminal commands are disabled in EVA settings.'];
        }
        $command = trim((string)($args['command'] ?? ''));
        if ($command === '' || mb_strlen($command) > 1000 || preg_match('/[\x00-\x1F\x7F;&|<>`$()\r\n]/', $command)) {
            return ['ok' => false, 'error' => 'Command is empty, too long, or contains shell syntax/control characters.'];
        }
        $stdin = (string)($args['stdin'] ?? '');
        if (mb_strlen($stdin) > 4000 || str_contains($stdin, "\0")) {
            return ['ok' => false, 'error' => 'stdin is limited to 4000 characters and cannot contain NUL bytes.'];
        }
        if (preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s]+/u', $command, $parts) === false || $parts[0] === []) {
            return ['ok' => false, 'error' => 'Command arguments could not be parsed safely.'];
        }
        $argv = [];
        foreach ($parts[0] as $part) {
            $first = $part[0] ?? '';
            $last = substr($part, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $part = substr($part, 1, -1);
                $part = str_replace(['\\"', '\\\\'], ['"', '\\'], $part);
            } elseif (str_contains($part, '"') || str_contains($part, "'")) {
                return ['ok' => false, 'error' => 'Quotes must wrap a complete argument.'];
            }
            if ($part === '' || mb_strlen($part) > 400) {
                return ['ok' => false, 'error' => 'Command arguments are outside the allowed bounds.'];
            }
            // Even with shell syntax disabled, an allowlisted program such as
            // cat, cp or git could receive a relative path escaping the app's
            // working directory. Reject traversal segments before the child
            // process is created; ordinary absolute paths and filenames remain
            // available to explicitly allowlisted diagnostic commands.
            if (preg_match('#(?:^|[\\/])\.\.(?:[\\/]|$)#', $part) === 1) {
                return ['ok' => false, 'error' => 'Command arguments may not contain path traversal segments.'];
            }
            $argv[] = $part;
        }
        $executable = (string)($argv[0] ?? '');
        $allowlist = array_values(array_filter(array_map('trim', explode(',', (string)$this->config->get('terminal_command_allowlist'))), static fn(string $item): bool => $item !== ''));
        $allowed = $this->config->get('terminal_command_any') === '1';
        if (!$allowed) {
            foreach ($allowlist as $entry) {
                // A configured absolute path is an exact capability grant. Do not
                // let `/tmp/date` inherit permission merely because `date` is on
                // the allowlist; for bare names, matching an absolute configured
                // entry remains convenient and still resolves through PATH.
                $allowed = str_contains($executable, '/')
                    ? $executable === $entry
                    : ($executable === $entry || basename($entry) === $executable);
                if ($allowed) {
                    break;
                }
            }
        }
        if (!$allowed) {
            return ['ok' => false, 'error' => 'The executable is not in the configured terminal allowlist.'];
        }
        $timeout = filter_var($args['timeout_seconds'] ?? 10, FILTER_VALIDATE_INT);
        $timeout = $timeout === false ? 10 : max(1, min(30, $timeout));
        $pipes = [];
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../../');
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Could not start the terminal command.'];
        }
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        // Always close stdin after the bounded payload. Programs that expect
        // more input receive EOF instead of hanging until the timeout.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        $observedExitCode = null;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $observedExitCode = is_int($status['exitcode']) ? $status['exitcode'] : null;
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(20000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        // PHP can return -1 from proc_close after proc_get_status has already
        // reaped a short-lived child. Prefer the observed exit code in that
        // case so successful custom commands are not reported as failures.
        $exit = ($observedExitCode !== null && $closedExitCode < 0) ? $observedExitCode : $closedExitCode;
        return [
            'ok' => !$timedOut && $exit === 0,
            'result' => [
                'command' => $command,
                'output' => mb_substr(trim($stdout), 0, 20000),
                'error_output' => mb_substr(trim($stderr), 0, 4000),
                'exit_code' => $timedOut ? null : $exit,
                'timed_out' => $timedOut,
            ],
        ];
    }

    /** Execute a short, explicitly confirmed diagnostic workflow without a shell. */
    private function runTerminalSequence(array $args): array {
        $commands = $args['commands'] ?? null;
        if (!is_array($commands) || $commands === [] || count($commands) > 5) {
            return ['ok' => false, 'error' => 'commands must contain between 1 and 5 entries'];
        }
        $inputs = $args['stdin'] ?? [];
        if (!is_array($inputs) || count($inputs) > count($commands)) {
            return ['ok' => false, 'error' => 'stdin must contain at most one string per command'];
        }
        foreach ($inputs as $input) {
            if (!is_string($input) || mb_strlen($input) > 4000 || str_contains($input, "\0")) {
                return ['ok' => false, 'error' => 'Each stdin value is limited to 4000 characters and cannot contain NUL bytes.'];
            }
        }
        $timeout = filter_var($args['timeout_seconds'] ?? 10, FILTER_VALIDATE_INT);
        $timeout = $timeout === false ? 10 : max(1, min(30, $timeout));
        $results = [];
        foreach ($commands as $index => $command) {
            if (!is_string($command) || trim($command) === '') {
                return ['ok' => false, 'error' => 'Every terminal sequence entry must be a non-empty command string'];
            }
            $result = $this->runTerminalCommand(['command' => $command, 'stdin' => (string)($inputs[$index] ?? ''), 'timeout_seconds' => $timeout]);
            $results[] = ['index' => (int)$index, 'command' => mb_substr($command, 0, 1000), 'ok' => !empty($result['ok']), 'result' => $result['result'] ?? null, 'error' => $result['error'] ?? null];
            if (empty($result['ok'])) {
                return ['ok' => false, 'error' => 'Terminal sequence stopped after command ' . ((int)$index + 1) . '.', 'result' => ['completed' => count($results) - 1, 'results' => $results]];
            }
        }
        return ['ok' => true, 'result' => ['completed' => count($results), 'results' => $results]];
    }
}
