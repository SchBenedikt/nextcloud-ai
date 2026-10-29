<?php
declare(strict_types=1);
namespace OCA\EvaAi\Command;

use OCA\EvaAi\Dto\{GenerationAdminRequest, GenerationBuildRequest};
use OCA\EvaAi\Vector\{GenerationBuildService, GenerationBuildWorker, VectorStoreException};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

final class VectorBuild extends Command {
    public function __construct(private GenerationBuildService $builds,private GenerationBuildWorker $worker) {parent::__construct();}
    protected function configure(): void {
        $this->setName('eva_ai:vector:build')->setDescription('Inspect/control an owned background build, or run one eligible bounded global tick; never activates search')
            ->addArgument('action',InputArgument::REQUIRED,'status, start, pause, resume, cancel or run')
            ->addArgument('user',InputArgument::OPTIONAL,'Generation owner, required except for run')
            ->addArgument('generation',InputArgument::OPTIONAL,'Generation ID, required except for run')
            ->addOption('confirm-document-transfer',null,InputOption::VALUE_NONE,'Explicit consent to read indexed sources and send text to the fixed embedding provider')
            ->addOption('confirm-text-transfer',null,InputOption::VALUE_NONE,'Separate explicit consent to copy chunk text into the configured Qdrant index');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        try {
            $action=$input->getArgument('action');
            if ($action==='run') $data=$this->worker->run();
            else {
                $user=$input->getArgument('user');$id=$input->getArgument('generation');
                GenerationAdminRequest::fromArray(['userId'=>$user,'id'=>$id]);
                if ($id===null) throw new \InvalidArgumentException();
                if ($action==='status') $data=$this->builds->status($user,$id);
                else {
                    $dto=GenerationBuildRequest::fromArray($action,['confirm_document_transfer'=>(bool)$input->getOption('confirm-document-transfer'),
                        'confirm_text_transfer'=>(bool)$input->getOption('confirm-text-transfer')]);
                    $data=$this->builds->control($user,$id,$action,$dto->confirmed,$dto->confirmedText);
                }
            }
            $output->writeln(json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),OutputInterface::OUTPUT_RAW);
            return !empty($data['error'])?1:0;
        } catch(\Throwable $error) {
            $output->writeln('Build operation failed: '.($error instanceof VectorStoreException?$error->reason:'invalid_request_or_storage_unavailable'),OutputInterface::OUTPUT_RAW);
            return 1;
        }
    }
}
