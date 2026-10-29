<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Service\ChatStorageMigration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Copy one user's encrypted AppData chats to SQL without activating a cutover. */
final class MigrateChatStorage extends Command {
	public function __construct(private ChatStorageMigration $migration) { parent::__construct(); }

	protected function configure(): void {
		$this->setName('eva_ai:migrate-chat-storage')
			->setDescription('Copy one user chat history to encrypted SQL and verify its source snapshot')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user ID to migrate');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getArgument('user');
		try {
			$result = $this->migration->migrate($user);
			$output->writeln('Status: ' . $result['status']);
			$output->writeln('Chats copied: ' . $result['chats']);
			if ($result['status'] === 'source-changed-retry') {
				$output->writeln('<comment>The AppData source changed during import. Rerun this command to resume from a fresh snapshot.</comment>');
				return 1;
			}
			if ($result['status'] === 'verification-failed') {
				$output->writeln('<error>SQL verification did not match the encrypted AppData snapshot. Source data was preserved.</error>');
				return 1;
			}
			return 0;
		} catch (\Throwable $e) {
			$output->writeln('<error>Chat storage migration failed: ' . $e->getMessage() . '</error>');
			return 1;
		}
	}
}
