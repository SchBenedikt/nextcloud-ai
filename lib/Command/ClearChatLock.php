<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Service\ChatStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Admin recovery path for a stuck chat lock (Issue #193).
 *
 * A request that dies while holding the per-user chat lock leaves it behind, and
 * Nextcloud only expires it at the locking provider's own timeout - 3600 s with
 * the database provider, which is a constructor setting and cannot be lowered
 * per call. Reads degrade to a lock-free read, so the symptom is a user whose
 * *writes* keep failing with "chat storage is busy".
 *
 * Releasing the lock is safe only when nothing is actually writing, and the
 * command cannot know that, so it reports what it found and refuses to act
 * without --force. The app logs the same decision point as
 * "eva_ai: chat lock contention" (with the wait in milliseconds and whether the
 * lock is still held) so the log says whether this command is needed at all.
 */
class ClearChatLock extends Command {
    public function __construct(
        private ChatStore $chatStore
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva_ai:clear-chat-lock')
            ->setDescription('Release a stuck EVA chat lock for one user (recovery after a request died holding it)')
            ->addArgument('user', InputArgument::REQUIRED, 'User whose chat lock should be released')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Release the lock even though a running request cannot be ruled out');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        if ($user === '') {
            $output->writeln('<error>A user id is required.</error>');
            return 1;
        }
        $force = (bool)$input->getOption('force');

        if (!$force) {
            $output->writeln('Check first that nothing is writing for "' . $user . '":');
            $output->writeln('  - look for "eva_ai: chat lock contention" in nextcloud.log');
            $output->writeln('  - re-run this command to see whether the lock is still held');
            $output->writeln('Re-run with --force once you are sure no request is running.');
            // Still report the current state, which is the useful part.
            $report = $this->chatStore->clearLock($user);
            $output->writeln('Lock path: ' . $report['path']);
            $output->writeln($report['was_locked']
                ? '<comment>Lock is currently held.</comment>'
                : 'Lock is not held; nothing to release.');
            return 0;
        }

        $report = $this->chatStore->clearLock($user);
        $output->writeln('Lock path: ' . $report['path']);
        if (!$report['was_locked']) {
            $output->writeln('Lock was not held; nothing to release.');
            return 0;
        }
        if (!$report['released']) {
            $output->writeln('<error>The lock could not be released. See nextcloud.log for the provider error.</error>');
            return 1;
        }
        $output->writeln('<info>Lock released.</info> The next chat write for "' . $user . '" should succeed.');
        return 0;
    }
}
