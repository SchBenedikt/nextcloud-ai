<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\Indexer;
use OCP\IConfig;
use OCP\ICacheFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Index extends Command {
    public function __construct(
        private Indexer $indexer,
        private DocumentMapper $documentMapper,
        private IConfig $config,
        private ?ICacheFactory $cacheFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva_ai:index')
            ->setDescription('Index a user\'s files for RAG Chat')
            ->addArgument('user', InputArgument::OPTIONAL, 'User (default: configured index_user)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        if ($user === null || $user === '') {
            $user = $this->config->getAppValue('eva_ai', 'index_user', '');
        }
        if ($user === '') {
            $output->writeln('<error>No user given and index_user not configured.</error>');
            return 1;
        }
        if ($this->cacheFactory !== null) {
            try {
                $cache = $this->cacheFactory->createLocking('eva_ai_occ');
                if (!$cache->add('index_cooldown', time(), 60)) {
                    $output->writeln('<error>Indexing was started recently. Please wait at least 60 seconds.</error>');
                    return 2;
                }
            } catch (\Throwable) {
                // A missing cache backend must not make a maintenance command
                // unusable; Indexer still owns its concurrency lock.
            }
        }
        $output->writeln('Starting indexing for "' . $user . '" …');
        $result = $this->indexer->run($user);
        $output->writeln('processed: ' . $result['processed'] . ' · skipped: ' . $result['skipped'] . ' · total seen: ' . $result['total_seen']);
        if ($result['error'] !== null) {
            $output->writeln('<error>Error: ' . $result['error'] . '</error>');
            return 1;
        }
        $output->writeln('Indexed documents: ' . $this->documentMapper->countForUser($user));
        return 0;
    }
}
