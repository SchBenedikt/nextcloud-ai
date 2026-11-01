<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Service\ChatStorageMigration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Copy one user's encrypted AppData chats to SQL without activating a cutover. */
final class MigrateChatStorage extends Command {
	public function __construct(private ChatStorageMigration $migration) { parent::__construct(); }

	protected function configure(): void {
		$this->setName('eva_ai:migrate-chat-storage')
			->setDescription('Copy one user chat history to encrypted SQL and verify its source snapshot')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user ID to migrate')
			->addOption('activate-sql', null, InputOption::VALUE_NONE, 'Enable SQL reads after successful import and verification')
			->addOption('deactivate-sql', null, InputOption::VALUE_NONE, 'Restore AppData from SQL, then disable SQL reads');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getArgument('user');
		try {
			if ((bool)$input->getOption('activate-sql') && (bool)$input->getOption('deactivate-sql')) {
				$output->writeln('<error>Choose either --activate-sql or --deactivate-sql.</error>');
				return 1;
			}
			if ((bool)$input->getOption('deactivate-sql')) {
				$count = $this->migration->disableSqlReads($user);
				$output->writeln('SQL reads disabled after restoring ' . $count . ' chats to AppData.');
				return 0;
			}
			$result = $this->migration->migrate($user);
			$output->writeln('Status: ' . $result['status']);
			$output->writeln('Chats copied: ' . $result['chats']);
			if ($result['status'] === 'source-changed-retry') {
				$output->writeln('<comment>The AppData source changed during import. Rerun this command to resume from a fresh snapshot.</comment>');
				return 1;
			}
			if ($result['status'] === 'in-progress') {
				$output->writeln('<comment>The encrypted copy is checkpointed. Rerun this command to continue from the last completed chat.</comment>');
				return 0;
			}
			if ($result['status'] === 'verification-failed') {
				$output->writeln('<error>SQL verification did not match the encrypted AppData snapshot. Source data was preserved.</error>');
				return 1;
			}
			if ($result['status'] === 'account-removed') {
				$output->writeln('<comment>The account was removed while the copy was running. No chat rows were re-created.</comment>');
				return 1;
			}
			if ((bool)$input->getOption('activate-sql') && in_array($result['status'], ['complete', 'already-current'], true)) {
				$this->migration->enableSqlReads($user, $result['status'] === 'complete');
				$output->writeln('SQL reads enabled for this user.');
			} elseif ((bool)$input->getOption('activate-sql') && $result['status'] === 'already-enabled') {
				$output->writeln('SQL reads are already enabled for this user.');
			}
			return 0;
		} catch (\Throwable $e) {
			$output->writeln('<error>Chat storage migration failed: ' . $e->getMessage() . '</error>');
			return 1;
		}
	}
}
