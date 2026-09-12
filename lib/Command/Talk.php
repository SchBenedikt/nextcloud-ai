<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\TalkTranscriptService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports and exercises the indexing of Nextcloud Talk chat histories.
 *
 * Talk indexing has more moving parts than file indexing - a room must be
 * discoverable, the user must be a member, the messages must be readable, and
 * the room must land in the index under its own path - and when one of them
 * fails the pass still reports success, because a room it could not read is
 * simply skipped. That is how a broken Talk pass stays invisible: the search
 * keeps answering, just without the conversation.
 *
 * This command therefore shows each step for one user: which rooms were found,
 * how many messages each one yields, what is stored for it, and - on request -
 * the passages the bot would actually recall for a question.
 */
class Talk extends Command {
    public function __construct(
        private TalkTranscriptService $transcripts,
        private Indexer $indexer,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private AppConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva_ai:talk')
            ->setDescription('Show and test the Nextcloud Talk indexing for one user')
            ->addArgument('user', InputArgument::REQUIRED, 'User whose Talk rooms should be reported')
            ->addOption('index', null, InputOption::VALUE_NONE, 'Run the Talk indexing pass now, regardless of the switch')
            ->addOption('room', null, InputOption::VALUE_REQUIRED, 'Talk room id for the recall test')
            ->addOption('ask', null, InputOption::VALUE_REQUIRED, 'Question to run against the room\'s indexed history');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        // Talk settings are personal: read them the way the indexer does, in the
        // user's own context, or the report would describe somebody else.
        $this->config->setUserId($user);

        $output->writeln('<info>Talk indexing</info>');
        if (!$this->transcripts->isAvailable()) {
            $output->writeln('  Talk:            not installed or not enabled - nothing can be indexed.');
            return 1;
        }
        $output->writeln('  Talk:            available');
        $output->writeln('  Index switch:    talk_index_enabled = ' . ($this->config->get('talk_index_enabled') === '1' ? 'on' : 'off (use --index, or the button in the settings)'));
        $output->writeln('  Rooms per pass:  ' . $this->config->getInt('talk_index_max_rooms', 20));
        $output->writeln('  Messages/room:   ' . $this->config->getInt('talk_index_max_messages', 200));
        $output->writeln('  Trigger word:    ' . $this->config->get('talk_bot_trigger'));

        $maxRooms = max(1, min(200, $this->config->getInt('talk_index_max_rooms', 20)));
        $rooms = $this->transcripts->roomsForUser($user, $maxRooms);

        // What is stored for this user, so a room can be compared with its index
        // entry rather than only with the live history.
        $stored = [];
        foreach ($this->documentMapper->findByUserAndPathPrefix($user, 'talk://') as $document) {
            $stored[(string)$document->getPath()] = $document;
        }

        $output->writeln('');
        $output->writeln('Rooms of ' . $user . ': ' . count($rooms) . ' (limit ' . $maxRooms . ')');
        if ($rooms === []) {
            $output->writeln('  (none - the user is not a member of any Talk room)');
        }
        $indexable = 0;
        foreach ($rooms as $room) {
            $roomId = (int)$room['id'];
            $path = 'talk://' . $roomId;
            $document = $stored[$path] ?? null;
            $transcript = $this->transcripts->transcript($user, $roomId);
            if ($transcript !== null) {
                $indexable++;
            }
            $output->writeln(sprintf(
                '  room %-4d %s',
                $roomId,
                (string)$room['name'],
            ));
            $output->writeln('    readable:      ' . ($transcript === null
                ? '<comment>no usable messages</comment>'
                : $transcript['messages'] . ' messages, ' . mb_strlen((string)$transcript['text']) . ' characters'));
            if ($document === null) {
                $output->writeln('    in the index:  <comment>no</comment>');
            } else {
                $output->writeln('    in the index:  yes, '
                    . mb_strlen((string)$document->getContentHash()) . '-char fingerprint, '
                    . $this->chunkMapper->countForDocument((int)$document->getId()) . ' chunks');
            }
        }
        $output->writeln('');
        $output->writeln('Rooms with usable content: ' . $indexable . ' of ' . count($rooms));

        if ($input->getOption('index')) {
            $output->writeln('');
            $output->writeln('Running the Talk pass …');
            try {
                $result = $this->indexer->run($user, null, 'talk');
            } catch (\Throwable $e) {
                $output->writeln('<error>The run could not start: ' . $e->getMessage() . '</error>');
                return 1;
            }
            $output->writeln('  processed: ' . $result['processed']
                . ' · skipped: ' . $result['skipped']
                . ' · failed: ' . $result['failed']
                . ' · total seen: ' . $result['total_seen']);
            if (($result['error'] ?? null) !== null) {
                $output->writeln('<error>  error: ' . (string)$result['error'] . '</error>');
                return 1;
            }
            $storedAfter = count($this->documentMapper->findByUserAndPathPrefix($user, 'talk://'));
            $output->writeln('  Talk rooms stored for this user: ' . $storedAfter);
        }

        $room = $input->getOption('room');
        $ask = $input->getOption('ask');
        if ($room !== null || $ask !== null) {
            if ($room === null || $ask === null) {
                $output->writeln('<error>--room and --ask belong together.</error>');
                return 1;
            }
            $roomId = (int)$room;
            $output->writeln('');
            $output->writeln('Recall test in room ' . $roomId . ': "' . $ask . '"');
            $passages = $this->transcripts->recall($user, $roomId, (string)$ask, 4);
            if ($passages === []) {
                // Two very different reasons land here, and the difference
                // matters: not being a member is a permission decision, an empty
                // result is an indexing or embedding problem.
                $output->writeln($this->transcripts->isMember($user, $roomId)
                    ? '  <comment>nothing recalled - the room may not be indexed yet, or the embeddings are unavailable</comment>'
                    : '  <comment>refused: ' . $user . ' is not a member of room ' . $roomId . '</comment>');
                return 1;
            }
            foreach ($passages as $index => $passage) {
                $output->writeln('  [' . ($index + 1) . '] ' . mb_substr(preg_replace('~\s+~u', ' ', $passage) ?? '', 0, 300) . ' …');
            }
        }

        return 0;
    }
}
