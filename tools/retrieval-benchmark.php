#!/usr/bin/env php
<?php

declare(strict_types=1);

// The offline runner intentionally has no dependency on Nextcloud's autoloader.
// Load the shared path validator used by the evaluator explicitly.
require_once __DIR__ . '/../lib/Vector/SearchFilters.php';
require_once __DIR__ . '/../lib/Service/RetrievalBenchmark.php';

use OCA\EvaAi\Service\RetrievalBenchmark;

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['exact', 'evaluate'], true) || !isset($argv[2]) || ($mode === 'evaluate' && !isset($argv[3], $argv[4]))) {
        fwrite(STDERR, "Usage: php tools/retrieval-benchmark.php exact DATASET_DIR\n       php tools/retrieval-benchmark.php evaluate DATASET_DIR RUNS_JSONL BACKEND\n");
        exit(2);
    }
    $benchmark = new RetrievalBenchmark();
    $dataset = $benchmark->dataset($argv[2]);
    $runs = $mode === 'exact' ? $benchmark->exact($dataset) : $benchmark->readRuns($argv[3]);
    $report = $benchmark->evaluate($dataset, $runs, $mode === 'exact' ? 'exact_cosine' : $argv[4]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($report['isolation_passed'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Benchmark failed: ' . $e->getMessage() . "\n");
    exit(2);
}
