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
            ->addArgument('action', InputArgument::REQUIRED, 'create, list or fail')
            ->addArgument('user', InputArgument::REQUIRED, 'Existing generation owner');
        foreach (['provider', 'profile', 'model', 'endpoint', 'dimension', 'chunk-size', 'chunk-overlap', 'id', 'after'] as $name) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, 'Explicit generation ' . $name);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $previous = $this->config->userId();
        try {
            $user = $input->getArgument('user');
            if ($user === '' || !$this->users->userExists($user)) throw new \InvalidArgumentException('Select an existing Nextcloud user.');
            $this->config->setUserId($user);
            $action = $input->getArgument('action');
            if ($action === 'list') {
                $rows = $this->generations->page($user, $input->getOption('after') ?? '');
                $data = ['retrieval_changed' => false, 'generations' => $rows, 'next_after' => count($rows) === 50 ? $rows[49]['id'] : null];
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
                $generation = new Generation(bin2hex(random_bytes(16)), $user, 'qdrant', $input->getOption('provider') ?? 'ollama',
                    $input->getOption('profile'), $input->getOption('model') ?? '', hash('sha256', rtrim($endpoint, '/')),
                    $dimension, $chunkSize, $overlap);
                $this->generations->create($generation);
                $data = $generation->toRow() + ['status' => 'building', 'retrieval_changed' => false];
            } else throw new \InvalidArgumentException('Use create, list or fail. Activation requires a verified backfill and is not available here.');
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('Generation operation failed: ' . $e->getMessage(), OutputInterface::OUTPUT_RAW);
            return 1;
        } finally { $this->config->setUserId($previous); }
    }

    private function integer(mixed $value): int {
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 8) throw new \InvalidArgumentException('Supply nonnegative integer dimension and chunk settings.');
        return (int)$value;
    }
}
