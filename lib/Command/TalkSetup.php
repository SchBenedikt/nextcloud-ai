<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Service\TalkBotRegistrar;
use OCA\Talk\Model\Bot;
use OCA\Talk\Model\BotServer;
use OCA\Talk\Model\BotServerMapper;
use OCP\App\IAppManager;
use OCP\DB\Exception as DbException;
use OCP\Security\ISecureRandom;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registriert den Eva-AI-Bot in Nextcloud Talk (talk_bots_server).
 * Danach muss der Bot in der gewünschten Konversation über die
 * Talk-Admin-Oberfläche (oder per OCS-API) noch aktiviert werden.
 */
class TalkSetup extends Command {
	public const BOT_URL = TalkBotRegistrar::BOT_URL;

    public function __construct(
        private IAppManager $appManager,
        private ISecureRandom $random,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva-ai:talk:setup')
            ->setDescription('Register or update the Eva-AI bot in Nextcloud Talk (talk_bots_server).')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the Eva-AI Talk bot instead of registering it.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name for the bot.', 'Eva')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Optional description shown to admins.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if (!$this->appManager->isEnabledForAnyone('spreed')) {
            $output->writeln('<error>Nextcloud Talk (spreed) is not installed or not enabled.</error>');
            return 1;
        }
        // Talk is optional; resolve the mapper lazily so that occ can load this
        // command even when Talk is not installed or enabled.
        $botServerMapper = \OCP\Server::get(BotServerMapper::class);
        if ($input->getOption('remove')) {
            return $this->remove($output, $botServerMapper);
        }
        $name = (string)$input->getOption('name');
        $description = (string)$input->getOption('description');
        try {
            $existing = $botServerMapper->findByUrl(self::BOT_URL);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            $existing = null;
        } catch (DbException) {
            $existing = null;
        }
        $secret = $this->random->generate(64);
        // FEATURE_EVENT makes Talk dispatch BotInvokeEvent locally to listeners
        // (our TalkBotListener); FEATURE_RESPONSE is also enabled so the bot can
        // also call back into /ocs/.../bot/{token}/message if needed.
        $features = Bot::FEATURE_RESPONSE | Bot::FEATURE_EVENT;
        if ($existing instanceof BotServer) {
            $existing->setName($name);
            $existing->setSecret($secret);
            $existing->setState(Bot::STATE_ENABLED);
            $existing->setFeatures($features);
            if ($description !== '') {
                $existing->setDescription($description);
            }
            $existing->setErrorCount(0);
            $existing->setLastErrorMessage('');
            try {
                $bot = $botServerMapper->update($existing);
            } catch (DbException $e) {
                $output->writeln('<error>Could not update bot: ' . $e->getMessage() . '</error>');
                return 1;
            }
            $output->writeln('<info>Updated existing Eva-AI Talk bot.</info>');
        } else {
            $bot = new BotServer();
            $bot->setName($name);
            $bot->setUrl(self::BOT_URL);
            $bot->setUrlHash(sha1(self::BOT_URL));
            $bot->setSecret($secret);
            $bot->setState(Bot::STATE_ENABLED);
            $bot->setFeatures($features);
            if ($description !== '') {
                $bot->setDescription($description);
            }
            try {
                $bot = $botServerMapper->insert($bot);
            } catch (DbException $e) {
                $output->writeln('<error>Could not insert bot: ' . $e->getMessage() . '</error>');
                return 1;
            }
            $output->writeln('<info>Registered new Eva-AI Talk bot.</info>');
        }
        $output->writeln('Bot ID         : ' . $bot->getId());
        $output->writeln('Name           : ' . $bot->getName());
        $output->writeln('URL            : ' . $bot->getUrl());
        $output->writeln('Features       : ' . Bot::featureFlagsToLabels($bot->getFeatures()));
        $output->writeln('Secret (64 B)  : ' . $bot->getSecret());
        $output->writeln('<comment>Activate the bot for a conversation via Talk admin UI or POST /ocs/.../bot/{token}/{botId}.</comment>');
        return 0;
    }

    private function remove(OutputInterface $output, BotServerMapper $botServerMapper): int {
        try {
            $existing = $botServerMapper->findByUrl(self::BOT_URL);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            $output->writeln('No Eva-AI Talk bot registered.');
            return 0;
        }
        try {
            $botServerMapper->delete($existing);
            $output->writeln('<info>Removed Eva-AI Talk bot.</info>');
        } catch (DbException $e) {
            $output->writeln('<error>Could not remove bot: ' . $e->getMessage() . '</error>');
            return 1;
        }
        return 0;
    }
}
