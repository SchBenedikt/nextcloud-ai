<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Service\RetrievalBenchmarkSnapshot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RetrievalBenchmark extends Command {
    public function __construct(private RetrievalBenchmarkSnapshot $snapshots) { parent::__construct(); }

    protected function configure(): void {
        $this->setName('eva_ai:retrieval:benchmark')
            ->setDescription('Explicitly export a private retrieval snapshot or record a verified SQL/Qdrant baseline')
            ->addArgument('action', InputArgument::REQUIRED, 'export, record, export-qdrant or record-qdrant')
            ->addArgument('user', InputArgument::REQUIRED, 'Existing user whose index is explicitly selected')
            ->addArgument('dataset', InputArgument::REQUIRED, 'Private dataset directory')
            ->addOption('queries', null, InputOption::VALUE_REQUIRED, 'Query seed JSON for export; sent to the configured embedding endpoint')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'New JSONL output file for record');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $action = $input->getArgument('action');
            if ($action === 'export' && is_string($input->getOption('queries'))) {
                $this->snapshots->export($input->getArgument('user'), $input->getArgument('dataset'), $input->getOption('queries'));
            } elseif ($action === 'record' && is_string($input->getOption('output'))) {
                $this->snapshots->record($input->getArgument('user'), $input->getArgument('dataset'), $input->getOption('output'));
            } elseif ($action === 'export-qdrant' && is_string($input->getOption('queries'))) {
                $this->snapshots->exportQdrant($input->getArgument('user'), $input->getArgument('dataset'), $input->getOption('queries'));
            } elseif ($action === 'record-qdrant' && is_string($input->getOption('output'))) {
                $this->snapshots->recordQdrant($input->getArgument('user'), $input->getArgument('dataset'), $input->getOption('output'));
            } else {
                $output->writeln('Use export/export-qdrant with --queries or record/record-qdrant with --output.');
                return 2;
            }
            $output->writeln('Benchmark operation completed. Outputs are private; no performance target is implied.');
            return 0;
        } catch (\Throwable $e) {
            // Console markup is deliberately not enabled for arbitrary errors.
            $output->writeln('Benchmark operation failed: ' . $e->getMessage(), OutputInterface::OUTPUT_RAW);
            return 1;
        }
    }
}
