<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Db\VectorOutboxRepository;
use OCA\EvaAi\Vector\VectorErasureWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Inspection is offline; explicit run consumes enabled generation erasure only. */
final class VectorOutbox extends Command {
    public function __construct(private VectorOutboxRepository $outbox, private VectorErasureWorker $worker) { parent::__construct(); }
    protected function configure(): void {
        $this->setName('eva_ai:vector:outbox')->setDescription('Inspect synchronization intent or explicitly retry a dead letter')
            ->addArgument('action', InputArgument::REQUIRED, 'status, list, retry or run')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum erasure events to claim (1–20)', '5')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'State to list', 'dead')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'ID cursor', '0')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Dead-letter ID to retry');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $action = $input->getArgument('action');
            if ($action === 'run') {
                $limit = $this->id($input->getOption('limit'));
                if ($limit < 1 || $limit > 20) throw new \InvalidArgumentException('Limit must be between 1 and 20.');
                $data = $this->worker->run($limit);
                $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
                return $data['failed'] > 0 ? 1 : 0;
            }
            if ($action === 'status') $data = ['states' => $this->outbox->stats(), 'now' => time()];
            elseif ($action === 'list') {
                $rows = $this->outbox->page($input->getOption('state'), $this->id($input->getOption('after')));
                $data = ['events' => $rows, 'next_after' => count($rows) === 100 ? $rows[99]['id'] : null];
            } elseif ($action === 'retry') {
                $id = $this->id($input->getOption('id'));
                $this->outbox->retryDead($id, time()); $data = ['id' => $id, 'state' => 'pending'];
            } else throw new \InvalidArgumentException('Use status, list, retry or run.');
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return 0;
        } catch (\Throwable $e) {
            $message = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Vector queue operation failed.';
            $output->writeln($message, OutputInterface::OUTPUT_RAW); return 1;
        }
    }
    private function id(mixed $value): int {
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 18) throw new \InvalidArgumentException('Supply a nonnegative integer ID.');
        return (int)$value;
    }
}
