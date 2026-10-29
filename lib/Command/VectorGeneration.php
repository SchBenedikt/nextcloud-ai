<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Vector\VectorGeneration as Generation;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class VectorGeneration extends Command {
    public function __construct(private VectorGenerationRepository $generations, private IUserManager $users, private AppConfig $config) { parent::__construct(); }

    protected function configure(): void {
        $this->setName('eva_ai:vector:generation')
            ->setDescription('Inspect or prepare immutable vector-generation metadata; never activates external retrieval')
            ->addArgument('action', InputArgument::REQUIRED, 'create, list, fail or owner-status')
            ->addArgument('user', InputArgument::REQUIRED, 'Existing generation owner');
        foreach (['backend', 'provider', 'profile', 'model', 'endpoint', 'dimension', 'chunk-size', 'chunk-overlap', 'id', 'after'] as $name) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, 'Explicit generation ' . $name);
        }
        $this->addOption('include-text-payload', null, InputOption::VALUE_NONE, 'Explicitly allow this Qdrant generation to store chunk text for native BM25 search');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $previous = $this->config->userId();
        try {
            $user = $input->getArgument('user');
            if ($input->getArgument('action') === 'owner-status') {
                $output->writeln(json_encode(['owner_state' => $this->generations->ownerStatus($user)], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
                return 0;
            }
            if ($user === '' || !$this->users->userExists($user)) throw new \InvalidArgumentException('Select an existing Nextcloud user.');
            $this->config->setUserId($user);
            $action = $input->getArgument('action');
            if ($action === 'list') {
                $rows = $this->generations->page($user, $input->getOption('after') ?? '');
                $data = ['retrieval_changed' => false, 'owner_state' => $this->generations->ownerStatus($user), 'generations' => $rows, 'next_after' => count($rows) === 50 ? $rows[49]['id'] : null];
            } elseif ($action === 'fail') {
                $id = $input->getOption('id');
                if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) throw new \InvalidArgumentException('Supply a generation ID.');
                $this->generations->failBuilding($user, $id);
                $data = ['id' => $id, 'status' => 'failed'];
            } elseif ($action === 'create') {
                $endpoint = $input->getOption('endpoint');
                $parts = is_string($endpoint) ? parse_url($endpoint) : false;
                if ($parts === false || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)) {
                    throw new \InvalidArgumentException('Supply an HTTP(S) embedding endpoint without credentials, query or fragment.');
                }
                $dimension = $this->integer($input->getOption('dimension'));
                $chunkSize = $this->integer($input->getOption('chunk-size') ?? $this->config->get('chunk_size'));
                $overlap = $this->integer($input->getOption('chunk-overlap') ?? $this->config->get('chunk_overlap'));
                $backend = $input->getOption('backend') ?? 'sql';
                if (!in_array($backend, ['sql', 'qdrant'], true)) throw new \InvalidArgumentException('Select sql or qdrant as the storage backend.');
                $includeTextPayload = (bool)$input->getOption('include-text-payload');
                if ($includeTextPayload && $backend !== 'qdrant') throw new \InvalidArgumentException('Text payloads are available only for an explicitly configured Qdrant generation.');
                $generation = new Generation(bin2hex(random_bytes(16)), $user, $backend, $input->getOption('provider') ?? 'ollama',
                    $input->getOption('profile'), $input->getOption('model') ?? '', hash('sha256', rtrim($endpoint, '/')),
                    $dimension, $chunkSize, $overlap, 1, $includeTextPayload);
                $this->generations->create($generation);
                $data = $generation->toRow() + ['status' => 'building', 'retrieval_changed' => false];
            } else throw new \InvalidArgumentException('Use create, list, fail or owner-status. Activation requires a verified backfill and is not available here.');
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return 0;
        } catch (\Throwable $e) {
            $reason = $e instanceof \InvalidArgumentException || $e instanceof \OCA\EvaAi\Vector\VectorStoreException
                ? $e->getMessage() : 'Storage metadata could not be updated. Check the database and app migrations.';
            $output->writeln('Generation operation failed: ' . $reason, OutputInterface::OUTPUT_RAW);
            return 1;
        } finally { $this->config->setUserId($previous); }
    }

    private function integer(mixed $value): int {
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 8) throw new \InvalidArgumentException('Supply nonnegative integer dimension and chunk settings.');
        return (int)$value;
    }
}
