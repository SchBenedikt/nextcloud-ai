<?php
declare(strict_types=1);
namespace OCA\EvaAi\Command;

use OCA\EvaAi\Dto\GenerationAdminRequest;
use OCA\EvaAi\Vector\{GenerationVerificationService,GenerationVerificationWorker,VectorStoreException};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

final class VectorVerify extends Command {
    public function __construct(private GenerationVerificationService $verification,private GenerationVerificationWorker $worker){parent::__construct();}
    protected function configure():void {
        $this->setName('eva_ai:vector:verify')->setDescription('Inspect or run read-only verification for a staged vector generation; never activates search')
            ->addArgument('action',InputArgument::REQUIRED,'status, start, pause, resume, cancel, retry or run')
            ->addArgument('user',InputArgument::OPTIONAL,'Generation owner, required except for run')
            ->addArgument('generation',InputArgument::OPTIONAL,'Generation ID, required except for run');
    }
    protected function execute(InputInterface$input,OutputInterface$output):int {
        try{
            $action=$input->getArgument('action');
            if($action==='run')$data=$this->worker->run();
            else{
                $user=$input->getArgument('user');$id=$input->getArgument('generation');
                GenerationAdminRequest::fromArray(['userId'=>$user,'id'=>$id]);
                if($id===null)throw new \InvalidArgumentException();
                if($action==='status')$data=$this->verification->status($user,$id);
                elseif(in_array($action,['start','pause','resume','cancel','retry'],true))$data=$this->verification->control($user,$id,$action);
                else throw new \InvalidArgumentException();
            }
            $output->writeln(json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),OutputInterface::OUTPUT_RAW);
            return !empty($data['error'])?1:0;
        }catch(\Throwable$error){
            $output->writeln('Verification operation failed: '.($error instanceof VectorStoreException?$error->reason:'invalid_request_or_storage_unavailable'),OutputInterface::OUTPUT_RAW);
            return 1;
        }
    }
}
