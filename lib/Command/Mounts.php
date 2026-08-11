<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OC\Files\Filesystem;
use OCP\Files\Mount\IMountManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Mounts extends Command {
    public function __construct(
        private IUserManager $userManager
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva_ai:mounts')
            ->setDescription('List all file mounts (home, external storages, groupfolders, shares) visible for a user - debug helper to see what the app can access')
            ->addArgument('user', InputArgument::REQUIRED, 'User id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = (string)$input->getArgument('user');
        if (!$this->userManager->userExists($userId)) {
            $output->writeln('<error>User "' . $userId . '" does not exist.</error>');
            return 1;
        }

        Filesystem::initMountPoints($userId);
        $mountManager = \OCP\Server::get(IMountManager::class);
        $prefix = '/' . $userId . '/';
        $mounts = array_values(array_filter(
            $mountManager->getAll(),
            static fn($mount) => str_starts_with($mount->getMountPoint(), $prefix)
        ));

        $output->writeln('Mounts for "' . $userId . '":');
        $count = 0;
        foreach ($mounts as $mount) {
            $mountPoint = $mount->getMountPoint();
            $storage = $mount->getStorage();
            $storageId = $storage ? (string)$storage->getId() : '?';
            $storageType = $storage ? get_class($storage) : '?';
            $output->writeln(sprintf('  - %-70s | %s | %s', $mountPoint, $storageId, $storageType));
            $count++;
        }
        $output->writeln('Total: ' . $count . ' mounts.');
        return 0;
    }
}