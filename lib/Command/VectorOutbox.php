<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Db\VectorOutboxRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Queue inspection never causes an HTTP request or executes an event. */
final class VectorOutbox extends Command {
    public function __construct(private VectorOutboxRepository $outbox) { parent::__construct(); }
    protected function configure(): void {
        $this->setName('eva_ai:vector:outbox')->setDescription('Inspect synchronization intent or explicitly retry a dead letter')
            ->addArgument('action', InputArgument::REQUIRED, 'status, list or retry')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'State to list', 'dead')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'ID cursor', '0')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Dead-letter ID to retry');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $action = $input->getArgument('action');
            if ($action === 'status') $data = ['states' => $this->outbox->stats(), 'now' => time()];
            elseif ($action === 'list') {
                $rows = $this->outbox->page($input->getOption('state'), $this->id($input->getOption('after')));
                $data = ['events' => $rows, 'next_after' => count($rows) === 100 ? $rows[99]['id'] : null];
            } elseif ($action === 'retry') {
                $id = $this->id($input->getOption('id'));
                $this->outbox->retryDead($id, time()); $data = ['id' => $id, 'state' => 'pending'];
            } else throw new \InvalidArgumentException('Use status, list or retry.');
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
