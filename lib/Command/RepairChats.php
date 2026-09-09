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
 * Admin recovery path for a corrupt chats.json (Issue #184). A corrupt store
 * is preserved (never silently overwritten); this command reports the damage
 * and - after explicit --yes - backs the damaged file up and reconstructs a
 * minimal valid store that keeps every parseable chat.
 */
class RepairChats extends Command {
    public function __construct(
        private ChatStore $chatStore
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva_ai:repair-chats')
            ->setDescription('Inspect and repair a corrupt EVA chat store for one user')
            ->addArgument('user', InputArgument::REQUIRED, 'User whose chat store should be inspected/repaired')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply the repair (backs up the corrupt file first)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        $apply = (bool)$input->getOption('yes');
        if ($user === '') {
            $output->writeln('<error>A user id is required.</error>');
            return 1;
        }
        try {
            $report = $this->chatStore->repairStore($user, $apply);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not inspect the chat store for "' . $user . '": ' . $e->getMessage() . '</error>');
            return 1;
        }
        $output->writeln('User: ' . $user);
        $output->writeln('Status: ' . $report['status']);
        $output->writeln($report['message']);
        if ($report['backup'] !== null) {
            $output->writeln('Backup written as: ' . $report['backup']);
        }
        return $report['status'] === 'ok' || $report['status'] === 'repaired' ? 0 : 1;
    }
}