<?php

declare(strict_types=1);

namespace OCA\RagChat\Command;

use OCA\RagChat\Service\Indexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Reset extends Command {
    public function __construct(
        private Indexer $indexer
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('ragchat:reset')
            ->setDescription('Delete the complete RAG index (documents + chunks). Without argument: ALL users.')
            ->addArgument('user', InputArgument::OPTIONAL, 'Only delete the index of this user (default: all users)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        if ($user === null || $user === '') {
            $user = null; // ohne Argument: kompletter Index aller Nutzer
        }
        if ($user !== null) {
            $output->writeln('Resetting index for user "' . $user . '" …');
        } else {
            $output->writeln('Resetting index for ALL users …');
        }
        $result = $this->indexer->reset($user);
        $output->writeln('Deleted: ' . $result['documents'] . ' documents, ' . $result['chunks'] . ' chunks.');
        return 0;
    }
}
