<?php
declare(strict_types=1);
namespace OCA\EvaAi\Command;

use OCA\EvaAi\Vector\{GenerationEmbeddingCheck, VectorStoreException};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

final class VectorEmbedding extends Command {
    public function __construct(private GenerationEmbeddingCheck $check) { parent::__construct(); }
    protected function configure(): void {
        $this->setName('eva_ai:vector:embedding')->setDescription('Check an owned building generation with public probe text; no document backfill or cutover')
            ->addArgument('user',InputArgument::REQUIRED,'Generation owner')
            ->addArgument('generation',InputArgument::REQUIRED,'Building generation ID');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        try {
            $id=$input->getArgument('generation'); $user=$input->getArgument('user');
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D',$id)!==1 || !is_string($user) || $user==='') throw new \InvalidArgumentException();
            $output->writeln(json_encode($this->check->check($user,$id),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),OutputInterface::OUTPUT_RAW);
            return 0;
        } catch (\Throwable $e) {
            $reason=$e instanceof VectorStoreException ? $e->reason : 'embedding_check_failed';
            $output->writeln('Embedding check failed: '.$reason,OutputInterface::OUTPUT_RAW);
            return 1;
        }
    }
}
