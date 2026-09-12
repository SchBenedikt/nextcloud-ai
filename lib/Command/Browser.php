<?php

declare(strict_types=1);

namespace OCA\EvaAi\Command;

use OCA\EvaAi\Service\BrowserRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports whether this server can really read pages in a browser, and proves it.
 *
 * Browser rendering is the one part of the web search that depends on things
 * outside the app: a Node.js runtime, the Playwright package and a downloaded
 * Chromium build. When any of those is missing, the search keeps working and
 * simply reads less, which is the worst kind of failure to diagnose from the
 * outside - the setting is on, the answers are just thinner.
 *
 * So this command answers the question directly: it prints what was found and
 * where, and, given URLs, renders them for real through the same component the
 * search uses. The URLs are rendered under this command's own policy - http(s)
 * only - because an administrator on the server shell is trusted; the web
 * search itself applies its stricter public-host policy on top.
 */
class Browser extends Command {
    public function __construct(
        private BrowserRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('eva_ai:browser')
            ->setDescription('Check whether EVA can read pages in a real browser, and render the given URLs')
            ->addArgument(
                'urls',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'http(s) URLs to load in the headless browser, e.g. a page whose text only appears after JavaScript has run',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $output->writeln('<info>Browser rendering</info>');
        $output->writeln('  Status:          ' . ($this->renderer->isAvailable()
            ? 'ready'
            : $this->renderer->unavailableReason()));

        $node = $this->renderer->nodeBinary();
        $output->writeln('  Node.js:         ' . ($node ?? 'not found'));
        $script = $this->renderer->scriptPath();
        $output->writeln('  Renderer script: ' . $script . (is_file($script) ? ' (found)' : ' (MISSING)'));
        $output->writeln('  Browsers path:   ' . $this->renderer->browsersPath());
        $output->writeln('  Chromium:        ' . ($this->renderer->browserExecutable() ?? 'not installed'));
        $output->writeln('  Page timeout:    ' . ($this->renderer->timeoutMs() / 1000) . ' s');

        /** @var list<string> $urls */
        $urls = (array)$input->getArgument('urls');
        if ($urls === []) {
            $output->writeln('');
            $output->writeln('Pass one or more http(s) URLs to render them for real, for example:');
            $output->writeln('  occ eva_ai:browser https://example.org/');
            // A missing piece is a failure, not a note: a caller scripting this
            // (a deployment check, say) has to be able to tell the two apart.
            return $this->renderer->isAvailable() ? 0 : 1;
        }

        if (!$this->renderer->isAvailable()) {
            $output->writeln('<error>Rendering is not available, so the URLs cannot be loaded.</error>');
            return 1;
        }

        $allowed = [];
        foreach ($urls as $url) {
            if (preg_match('~^https?://~i', trim($url)) !== 1) {
                $output->writeln('<error>Not an http(s) URL, skipping: ' . $url . '</error>');
                continue;
            }
            $allowed[] = trim($url);
        }
        if ($allowed === []) {
            return 1;
        }

        $output->writeln('');
        // The renderer reports pages under the caller's own indexes, which for a
        // plain argument list is simply its position.
        $pages = $this->renderer->renderMany($allowed, static fn(string $url): bool => true, false);
        foreach ($allowed as $index => $url) {
            $page = $pages[$index] ?? null;
            if ($page === null) {
                $output->writeln('<error>FAILED  ' . $url . '</error>');
                continue;
            }
            $text = trim((string)$page['text']);
            $output->writeln('<info>OK      ' . $url . '</info>');
            $output->writeln('  landed on: ' . ((string)$page['finalUrl'] !== '' ? (string)$page['finalUrl'] : '(unknown)'));
            $output->writeln('  title:     ' . ((string)$page['title'] !== '' ? (string)$page['title'] : '(none)'));
            $output->writeln('  text:      ' . mb_strlen($text) . ' chars, ' . mb_strlen((string)$page['html']) . ' chars of DOM');
            if ($text !== '') {
                $output->writeln('  reads:     ' . mb_substr(preg_replace('~\s+~u', ' ', $text) ?? '', 0, 200) . ' …');
            }
        }

        // Both URLs failed is a failure; one of two is a partial result the
        // caller can still judge from the output above.
        return $pages === [] ? 1 : 0;
    }
}
