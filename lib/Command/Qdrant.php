<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Vector\QdrantConnection;
use OCA\EvaAi\Vector\QdrantVectorStore;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Instance configuration and explicit empty-collection preparation only. */
final class Qdrant extends Command {
    public function __construct(private QdrantConnection $connection, private QdrantVectorStore $store, private VectorGenerationRepository $generations, private IUserManager $users) { parent::__construct(); }

    protected function configure(): void {
        $this->setName('eva_ai:vector:qdrant')
            ->setDescription('Configure optional Qdrant, inspect health or prepare an empty generation collection')
            ->addArgument('action', InputArgument::REQUIRED, 'configure, status, health, disable or prepare')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Server URL; remote targets require HTTPS')
            ->addOption('key-file', null, InputOption::VALUE_REQUIRED, 'Private local file containing the API key; never pass the key on the command line')
            ->addOption('no-key', null, InputOption::VALUE_NONE, 'Explicitly use no API-key authentication')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Explicitly enable transport; does not change retrieval')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Owner of the generation to prepare')
            ->addOption('generation', null, InputOption::VALUE_REQUIRED, 'Building generation ID to prepare');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $action = $input->getArgument('action');
            if ($action === 'configure') {
                $key = null;
                $path = $input->getOption('key-file');
                if ($path !== null) {
                    if (!is_string($path) || !is_file($path) || !is_readable($path) || filesize($path) > 4096) throw new \InvalidArgumentException('API key file is missing, unreadable or oversized.');
                    $bytes = file_get_contents($path);
                    if ($bytes === false) throw new \RuntimeException('Cannot read API key file.');
                    $key = trim($bytes);
                }
                $this->connection->configure($input->getOption('url') ?? '', $key, (bool)$input->getOption('no-key'), (bool)$input->getOption('enable'));
                $data = $this->connection->status();
            } elseif ($action === 'disable') {
                $this->connection->disable(); $data = $this->connection->status();
            } elseif ($action === 'status') {
                $data = $this->connection->status();
            } elseif ($action === 'health') {
                $data = $this->store->health();
                $output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
                return $data['available'] ? 0 : 1;
            } elseif ($action === 'prepare') {
                $user = $input->getOption('user'); $id = $input->getOption('generation');
                if (!is_string($user) || !$this->users->userExists($user) || !is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) throw new \InvalidArgumentException('Select an existing user and a valid generation ID.');
                $generation = $this->generations->find($user, $id);
                if ($generation === null || $this->generations->status($user, $id) !== 'building') throw new \RuntimeException('Only an owned building generation can be prepared.');
                $target = $this->connection->targetFingerprint();
                $this->generations->bindTarget($user, $id, $target);
                $guard = function () use ($user, $id, $target): void {
                    if (!$this->generations->isBuildingBound($user, $id, $target)) throw new \OCA\EvaAi\Vector\VectorStoreException('generation_not_building');
                };
                $this->store->forTarget($target, $guard)->prepare($generation);
                $data = ['prepared' => true, 'generation_id' => $id, 'retrieval_changed' => false];
            } else throw new \InvalidArgumentException('Use configure, status, health, disable or prepare.');
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return 0;
        } catch (\Throwable $e) {
            // Transport errors are sanitized; never print raw upstream request data.
            $message = $e instanceof \OCA\EvaAi\Vector\VectorStoreException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Qdrant administration failed.';
            $output->writeln($message, OutputInterface::OUTPUT_RAW);
            return 1;
        }
    }
}
