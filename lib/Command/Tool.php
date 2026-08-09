<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Service\ActionExecutor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Tool extends Command {
    public function __construct(
        private ActionExecutor $executor
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva-ai:tool')
            ->setDescription('Run an EVA tool for a user (test)')
            ->addArgument('user', InputArgument::REQUIRED)
            ->addArgument('tool', InputArgument::REQUIRED)
            ->addArgument('args', InputArgument::OPTIONAL, 'JSON arguments', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        $tool = (string)$input->getArgument('tool');
        $args = json_decode((string)$input->getArgument('args'), true);
        if (!is_array($args)) {
            $args = [];
        }
        if ($tool === 'notify-test') {
            try {
                $mgr = \OCP\Server::get(\OCP\Notification\IManager::class);
                $n = $mgr->createNotification();
                $n->setApp('eva-ai')->setUser($user)->setObject('chat', 'answer')
                    ->setSubject('answer_ready', ['text' => mb_strimwidth((string)($args['text'] ?? 'Hallo'), 0, 400, '…')])
                    ->setLink('https://localhost/nextcloud/apps/eva-ai/')
                    ->setDateTime(new \DateTime());
                $mgr->notify($n);
                $output->writeln('OK notified');
                return 0;
            } catch (\Throwable $e) {
                $output->writeln('FAIL: ' . $e->getMessage());
                return 1;
            }
        }
        $result = $this->executor->run($user, $tool, $args);
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return ($result['ok'] ?? false) ? 0 : 1;
    }
}
