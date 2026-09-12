<?php
/**
 * Eva AI admin settings — native Nextcloud admin page.
 *
 * Server-rendered with only native Nextcloud elements and classes:
 * div.section, h2/h3, p.settings-hint, table.grid, native input/select/button.
 * The layout follows the Social app admin page: one section per topic, a
 * hint paragraph under each heading and a native grid table for status data.
 * Layout only uses CSS classes from css/admin-settings.css — no inline styles.
 */

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */

$admin = $_['adminSettings'];
$ollamaOnline = $_['ollamaOnline'];
$ollamaError = $_['ollamaError'] ?? '';
$ollamaUrl = $_['ollamaUrl'];
$users = $_['users'];
$userCount = $_['userCount'];
$totalDocuments = $_['totalDocuments'];
$totalChunks = $_['totalChunks'];
$scheduler = $_['scheduler'];
$apiBase = $_['apiBase'];
$providers = $_['webSearchProviders'] ?? [];

$weatherEnabled = ($admin['weather_tool_enabled'] ?? '1') === '1';
$webSearchUrl = $admin['web_search_url'] ?? '';
$webSearchApiKeyConfigured = !empty($_['webSearchKeyConfigured']);
$webSearchProviders = $providers;
$webSearchMaxResults = $admin['web_search_max_results'] ?? '8';
$webSearchTimeout = $admin['web_search_timeout'] ?? '10';
$webSearchSafeSearch = ($admin['web_search_safe_search'] ?? '1') === '1';
$webSearchFetchContent = ($admin['web_search_fetch_content'] ?? '1') === '1';
$webSearchContentChars = $admin['web_search_content_chars'] ?? '2000';
$webSearchCandidates = $admin['web_search_candidates'] ?? '12';
$webSearchImages = ($admin['web_search_images'] ?? '1') === '1';
$webSearchBrowser = ($admin['web_search_browser'] ?? '0') === '1';
$webSearchBrowserNode = $admin['web_search_browser_node'] ?? '';
$webSearchBrowserBrowsersPath = $admin['web_search_browser_browsers_path'] ?? '';
$webSearchBrowserTimeout = $admin['web_search_browser_timeout'] ?? '20';
// '' means a browser is usable; anything else is the reason it is not, so the
// switch never looks like it is doing something when it cannot.
$webSearchBrowserStatus = (string)($_['webSearchBrowserStatus'] ?? '');
// Where the browser was searched for, and the command that installs it. Both
// come from the server rather than from a hardcoded default in this template,
// because the path depends on the account the web server runs as.
$webSearchBrowserDetectedPath = (string)($_['webSearchBrowserBrowsersPath'] ?? '');
$webSearchBrowserInstallCommand = (string)($_['webSearchBrowserInstallCommand'] ?? '');
$indexMaxConcurrent = $admin['index_max_concurrent'] ?? '2';
$indexJobMaxSeconds = $admin['index_job_max_seconds'] ?? '50';
$indexJobInterval = $admin['index_job_interval_minutes'] ?? '5';
$lastIndexFailed = (int)($admin['last_index_failed'] ?? 0);

$schedulerRunning = (int)($scheduler['running'] ?? 0);
$schedulerQueued = (int)($scheduler['queued'] ?? 0);
$schedulerLimit = (int)($scheduler['limit'] ?? 2);

/** Providers that work without an API key supply their own description. */
$providerKeyRequired = [
	'duckduckgo' => false,
	'bing' => false,
	'searxng' => false,
	'brave' => true,
	'tavily' => true,
];
$providerLabels = [
	'duckduckgo' => $l->t('DuckDuckGo — free, no key, works out of the box'),
	'bing' => $l->t('Bing (RSS results) — free, no key, an alternative web index'),
	'searxng' => $l->t('SearxNG — self-hosted, no third party involved'),
	'brave' => $l->t('Brave Search API — hosted, requires an API key'),
	'tavily' => $l->t('Tavily — hosted, tuned for AI grounding, requires an API key'),
];
?>

<div id="eva-ai-admin" class="section" data-api-base="<?php p($apiBase); ?>">

	<h2><?php p($l->t('Eva AI')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Eva AI answers questions from the files and mail of this instance. Configure the language models, the background indexer and the optional external tools here.')); ?>
	</p>

	<p class="eva-chip-row">
		<span class="eva-chip <?php p($ollamaOnline ? 'eva-chip--ok' : 'eva-chip--error'); ?>">
			<?php p($ollamaOnline ? $l->t('Language model connected') : $l->t('Language model unavailable')); ?>
		</span>
		<span class="eva-chip <?php p($schedulerRunning > 0 ? 'eva-chip--busy' : 'eva-chip--idle'); ?>">
			<?php p($schedulerRunning > 0
				? $l->n('%n index pass running', '%n index passes running', $schedulerRunning)
				: $l->t('Indexer idle')); ?>
		</span>
		<span class="eva-chip eva-chip--idle">
			<?php p($l->n('%n indexed account', '%n indexed accounts', (int)$userCount)); ?>
		</span>
	</p>

	<h3><?php p($l->t('Instance status')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Live information about the services Eva AI depends on. Nothing here changes a setting.')); ?>
	</p>

	<table class="grid">
		<thead>
			<tr>
				<th scope="col"><?php p($l->t('Service')); ?></th>
				<th scope="col"><?php p($l->t('Status')); ?></th>
				<th scope="col"><?php p($l->t('Detail')); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php p($l->t('Language model server')); ?></strong></td>
				<td>
					<span class="eva-status <?php p($ollamaOnline ? 'eva-status--ok' : 'eva-status--error'); ?>">
						<?php p($ollamaOnline ? $l->t('Connected') : $l->t('Not connected')); ?>
					</span>
				</td>
				<td>
					<span class="eva-mono"><?php p($ollamaUrl); ?></span>
					<?php if (!$ollamaOnline && $ollamaError !== ''): ?>
						<span class="eva-error eva-block"><?php p($ollamaError); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong><?php p($l->t('Web search')); ?></strong></td>
				<td>
					<span class="eva-status <?php p(!empty($_['webSearchConfigured']) ? 'eva-status--ok' : 'eva-status--idle'); ?>">
						<?php p(!empty($_['webSearchConfigured']) ? $l->t('Ready') : $l->t('Not configured')); ?>
					</span>
				</td>
				<td>
					<?php if ($webSearchApiKeyConfigured): ?>
						<?php p($l->t('An API key is stored for the hosted providers.')); ?>
					<?php else: ?>
						<?php p($l->t('No API key stored. DuckDuckGo and SearxNG work without one.')); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong><?php p($l->t('Knowledge base')); ?></strong></td>
				<td><?php p($l->t('%s documents', [(string)$totalDocuments])); ?></td>
				<td>
					<?php p($l->t('%s text chunks', [(string)$totalChunks])); ?>
					<?php if ($lastIndexFailed > 0): ?>
						<span class="eva-block eva-muted">
							<?php p($l->t('%s files were skipped and are retried on the next run', [(string)$lastIndexFailed])); ?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong><?php p($l->t('Background indexing')); ?></strong></td>
				<td><?php p($schedulerRunning . ' / ' . $schedulerLimit); ?></td>
				<td>
					<?php p($l->t('passes running')); ?>
					<?php if ($schedulerQueued > 0): ?>
						— <?php p($l->n('%n waiting in the queue', '%n waiting in the queue', $schedulerQueued)); ?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<h3><?php p($l->t('Indexing performance')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('The background indexer runs on Nextcloud cron. These values bound how much work one cron run may do, so a large library is indexed steadily without slowing the instance down.')); ?>
	</p>

	<div class="eva-field-grid">
		<div class="eva-field">
			<label for="eva-max-concurrent"><?php p($l->t('Parallel index passes')); ?></label>
			<input type="number" id="eva-max-concurrent" name="index_max_concurrent"
				min="1" max="16" step="1" value="<?php p($indexMaxConcurrent); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('How many accounts may be indexed at the same time. 1–16, default 2. Raise it only if the server has spare CPU and the model server can take the load.')); ?>
			</p>
		</div>

		<div class="eva-field">
			<label for="eva-job-budget"><?php p($l->t('Time budget per cron run')); ?></label>
			<input type="number" id="eva-job-budget" name="index_job_max_seconds"
				min="10" max="600" step="5" value="<?php p($indexJobMaxSeconds); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('Seconds one cron run may spend indexing before it hands back to the next tick. 10–600, default 50. The budget is shared fairly across all accounts.')); ?>
			</p>
		</div>

		<div class="eva-field">
			<label for="eva-job-interval"><?php p($l->t('Cron run frequency')); ?></label>
			<input type="number" id="eva-job-interval" name="index_job_interval_minutes"
				min="1" max="60" step="1" value="<?php p($indexJobInterval); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('Minutes between runs, 1–60, default 5. Together with the time budget this is how fast a large library is caught up. Takes effect after the next app update.')); ?>
			</p>
		</div>
	</div>

	<p class="eva-actions">
		<button type="button" id="eva-index-save" class="primary"><?php p($l->t('Save indexing settings')); ?></button>
		<span id="eva-index-status" class="eva-status-text" role="status" aria-live="polite"></span>
	</p>

	<h3><?php p($l->t('Web search')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Web search is opt-in and off by default. Each user turns it on and picks a provider in their personal Eva AI settings; the options below provide the instance-wide infrastructure for everyone.')); ?>
	</p>

	<table class="grid eva-provider-table">
		<thead>
			<tr>
				<th scope="col"><?php p($l->t('Provider')); ?></th>
				<th scope="col"><?php p($l->t('API key')); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($webSearchProviders as $provider): ?>
				<tr>
					<td><?php p($providerLabels[$provider] ?? $provider); ?></td>
					<td>
						<?php if ($providerKeyRequired[$provider] ?? false): ?>
							<?php p($l->t('required')); ?>
						<?php else: ?>
							<?php p($l->t('not required')); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="eva-field-grid">
		<div class="eva-field eva-field--wide">
			<label for="eva-websearch-url"><?php p($l->t('SearxNG base URL')); ?></label>
			<input type="url" id="eva-websearch-url" name="web_search_url"
				value="<?php p($webSearchUrl); ?>"
				placeholder="https://searx.example.org">
			<p class="eva-field-hint">
				<?php p($l->t('Required when a user selects SearxNG. The instance must return JSON results (enable the json format in its settings).')); ?>
			</p>
		</div>

		<div class="eva-field eva-field--wide">
			<label for="eva-websearch-key"><?php p($l->t('Brave / Tavily API key')); ?></label>
			<input type="password" id="eva-websearch-key" name="web_search_api_key"
				autocomplete="new-password"
				placeholder="<?php p($webSearchApiKeyConfigured
					? $l->t('A key is stored — leave empty to keep it')
					: $l->t('Paste the API key')); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('Required when a user selects Brave or Tavily. Stored encrypted and never shown again.')); ?>
			</p>
			<label class="eva-checkbox">
				<input type="checkbox" id="eva-remove-websearch-key" name="remove_web_search_api_key" value="1">
				<span><?php p($l->t('Remove the stored API key')); ?></span>
			</label>
		</div>

		<div class="eva-field">
			<label for="eva-websearch-max"><?php p($l->t('Maximum results per search')); ?></label>
			<input type="number" id="eva-websearch-max" name="web_search_max_results"
				min="1" max="20" step="1" value="<?php p($webSearchMaxResults); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('1–20, default 8. Every result is added to the model context.')); ?>
			</p>
		</div>

		<div class="eva-field">
			<label for="eva-websearch-timeout"><?php p($l->t('Search timeout')); ?></label>
			<input type="number" id="eva-websearch-timeout" name="web_search_timeout"
				min="1" max="30" step="1" value="<?php p($webSearchTimeout); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('Seconds before a search is given up. 1–30, default 10.')); ?>
			</p>
		</div>

		<div class="eva-field">
			<label for="eva-content-chars"><?php p($l->t('Text per result page')); ?></label>
			<input type="number" id="eva-content-chars" name="web_search_content_chars"
				min="200" max="8000" step="100" value="<?php p($webSearchContentChars); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('Characters read from each result page. 200–8000, default 2000. More text means more accurate answers and a larger context.')); ?>
			</p>
		</div>

		<div class="eva-field">
			<label for="eva-candidates"><?php p($l->t('Pages compared before choosing')); ?></label>
			<input type="number" id="eva-candidates" name="web_search_candidates"
				min="3" max="20" step="1" value="<?php p($webSearchCandidates); ?>">
			<p class="eva-field-hint">
				<?php p($l->t('How many hits are read and scored before the best ones are returned. 3–20, default 12. A larger field costs one page fetch per candidate but stops the search from trusting the first hits.')); ?>
			</p>
		</div>
	</div>

	<label class="eva-checkbox">
		<input type="checkbox" id="eva-safesearch-toggle" name="web_search_safe_search" value="1"
			<?php p($webSearchSafeSearch ? 'checked' : ''); ?>>
		<span><?php p($l->t('Ask the provider to filter adult results')); ?></span>
	</label>

	<label class="eva-checkbox">
		<input type="checkbox" id="eva-fetch-content-toggle" name="web_search_fetch_content" value="1"
			<?php p($webSearchFetchContent ? 'checked' : ''); ?>>
		<span><?php p($l->t('Read the result pages and give the model their real text')); ?></span>
	</label>
	<p class="settings-hint eva-indent">
		<?php p($l->t('Enabling this fetches the ranked pages in parallel and is what makes answers accurate rather than a paraphrase of a search teaser. Turn it off to keep a search to a single request.')); ?>
	</p>

	<label class="eva-checkbox">
		<input type="checkbox" id="eva-images-toggle" name="web_search_images" value="1"
			<?php p($webSearchImages ? 'checked' : ''); ?>>
		<span><?php p($l->t('Show images from the result pages')); ?></span>
	</label>
	<p class="settings-hint eva-indent">
		<?php p($l->t('Adds up to three images per result (the page\'s own preview image and pictures inside the article) so the assistant can show a figure instead of describing it. Icons, logos and tracking pixels are filtered out. No extra request is made: the images come from the pages already being read.')); ?>
	</p>

	<label class="eva-checkbox">
		<input type="checkbox" id="eva-browser-toggle" name="web_search_browser" value="1"
			<?php p($webSearchBrowser ? 'checked' : ''); ?>>
		<span><?php p($l->t('Read pages that need JavaScript in a real browser')); ?></span>
	</label>
	<p class="settings-hint eva-indent">
		<?php p($l->t('Many sites deliver their article only after their own scripts have run; a plain request sees an empty shell, so an answer built from it is a guess. With this on, a page whose text did not arrive is loaded in a headless Chromium and read normally. Only such pages are rendered (at most four per search), so ordinary pages are unaffected.')); ?>
	</p>
	<p class="settings-hint eva-indent eva-browser-state<?php p($webSearchBrowserStatus === '' ? ' is-ok' : ' is-missing'); ?>" id="eva-browser-status">
		<?php
		if ($webSearchBrowserStatus === '') {
			p($l->t('Node.js, Playwright and a Chromium build were found, so pages can be rendered.'));
		} else {
			p($l->t('Not usable yet: %s', [$webSearchBrowserStatus]));
		}
		?>
	</p>

	<?php if ($webSearchBrowserStatus !== '') { ?>
	<p class="settings-hint eva-indent eva-browser-help" id="eva-browser-help">
		<?php p($l->t('Searched for a browser in: %s', [$webSearchBrowserDetectedPath === '' ? $l->t('(no home directory could be determined)') : $webSearchBrowserDetectedPath])); ?>
		<br>
		<?php p($l->t('Install command:')); ?>
		<code><?php p($webSearchBrowserInstallCommand); ?></code>
	</p>
	<?php } ?>

	<div class="eva-field">
		<label for="eva-browser-node"><?php p($l->t('Node.js path')); ?></label>
		<input type="text" id="eva-browser-node" name="web_search_browser_node"
			placeholder="node" value="<?php p($webSearchBrowserNode); ?>">
		<p class="eva-field-hint">
			<?php p($l->t('Leave empty to use the node command from PATH. Set an absolute path when the web server process has almost no PATH - that is the usual reason a browser is reported as missing.')); ?>
		</p>
	</div>

	<div class="eva-field">
		<label for="eva-browser-browsers-path"><?php p($l->t('Playwright browsers path')); ?></label>
		<input type="text" id="eva-browser-browsers-path" name="web_search_browser_browsers_path"
			placeholder="<?php p($webSearchBrowserDetectedPath); ?>" value="<?php p($webSearchBrowserBrowsersPath); ?>">
		<p class="eva-field-hint">
			<?php p($l->t('Leave empty to use Playwright\'s own location for the user the web server runs as. Set it when the browser build lives elsewhere - for example in a shared directory, or when the app runs as a user whose home directory the browser installer did not use. The placeholder shows the path that is used right now.')); ?>
		</p>
	</div>

	<div class="eva-field">
		<label for="eva-browser-timeout"><?php p($l->t('Browser timeout')); ?></label>
		<input type="number" id="eva-browser-timeout" name="web_search_browser_timeout"
			min="3" max="60" step="1" value="<?php p($webSearchBrowserTimeout); ?>">
		<p class="eva-field-hint">
			<?php p($l->t('Seconds a rendered page may take before the browser is stopped. 3-60, default 20.')); ?>
		</p>
	</div>

	<p class="eva-actions">
		<button type="button" id="eva-websearch-save" class="primary"><?php p($l->t('Save web search settings')); ?></button>
		<span id="eva-websearch-status" class="eva-status-text" role="status" aria-live="polite"></span>
	</p>

	<h3><?php p($l->t('Test the web search')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Run a real search and see exactly what the assistant would receive: the ranked results, how much page text was read, and the pictures found. This uses your own account settings, so enable web search and pick a provider in your personal Eva AI settings first.')); ?>
	</p>

	<div class="eva-test-row">
		<input type="text" id="eva-test-query" class="eva-test-input"
			placeholder="<?php p($l->t('e.g. Nextcloud Hub release notes')); ?>"
			aria-label="<?php p($l->t('Search query to test')); ?>">
		<select id="eva-test-mode" aria-label="<?php p($l->t('Which index to search')); ?>">
			<option value="web"><?php p($l->t('Web')); ?></option>
			<option value="news"><?php p($l->t('News')); ?></option>
			<option value="all"><?php p($l->t('Web and news')); ?></option>
		</select>
		<button type="button" id="eva-test-run" class="secondary eva-test-button"><?php p($l->t('Run search')); ?></button>
		<span id="eva-test-status" class="eva-status-text" role="status" aria-live="polite"></span>
	</div>

	<div id="eva-test-results" class="eva-test-results" hidden></div>

	<h3><?php p($l->t('Tools')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Instance-wide switches for tools that contact services outside this server.')); ?>
	</p>

	<label class="eva-checkbox">
		<input type="checkbox" id="eva-weather-toggle" name="weather_tool_enabled" value="1"
			<?php p($weatherEnabled ? 'checked' : ''); ?>>
		<span><?php p($l->t('Allow weather forecasts for all users')); ?></span>
	</label>
	<p class="settings-hint eva-indent">
		<?php p($l->t('The weather tool queries the external Open-Meteo service. Turn it off to keep all tool traffic on your own server.')); ?>
	</p>

	<p class="eva-actions">
		<button type="button" id="eva-tools-save" class="primary"><?php p($l->t('Save tool settings')); ?></button>
		<span id="eva-tools-status" class="eva-status-text" role="status" aria-live="polite"></span>
	</p>

	<h3><?php p($l->t('Accounts and indexing')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Manage which accounts build a knowledge base, re-index one account now or delete its index. Original Nextcloud files are never modified.')); ?>
	</p>

	<?php if (empty($users)): ?>
		<p class="eva-empty"><?php p($l->t('No account has enrolled in indexing yet.')); ?></p>
	<?php else: ?>
		<table class="grid eva-user-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Account')); ?></th>
					<th scope="col"><?php p($l->t('Background indexing')); ?></th>
					<th scope="col"><?php p($l->t('Documents')); ?></th>
					<th scope="col"><?php p($l->t('Chunks')); ?></th>
					<th scope="col"><?php p($l->t('Last indexed')); ?></th>
					<th scope="col"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($users as $user): ?>
				<tr data-user-id="<?php p($user['userId']); ?>">
					<td>
						<strong><?php p($user['displayName']); ?></strong>
						<?php if ($user['displayName'] !== $user['userId']): ?>
							<span class="eva-muted eva-block"><?php p($user['userId']); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<label class="eva-checkbox">
							<input type="checkbox" class="eva-enroll-toggle"
								data-user="<?php p($user['userId']); ?>"
								value="1" <?php p($user['enrolled'] ? 'checked' : ''); ?>
								<?php if ($user['indexing']) p('disabled'); ?>>
							<span><?php p($l->t('Enabled')); ?></span>
						</label>
					</td>
					<td><?php p((string)$user['documents']); ?></td>
					<td><?php p((string)$user['chunks']); ?></td>
					<td>
						<?php if ($user['lastIndexedAt'] !== null): ?>
							<span class="eva-mono"><?php p(gmdate('Y-m-d H:i', (int)$user['lastIndexedAt'])); ?></span>
						<?php else: ?>
							<span class="eva-muted">—</span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						// An account with an index but no recurring enrollment is not
						// "inactive": it holds documents. Only a truly empty, unenrolled
						// account is inactive.
						if ($user['indexing']) {
							$stateClass = 'eva-status--busy';
							$stateLabel = $l->t('Indexing');
						} elseif ($user['enrolled']) {
							$stateClass = 'eva-status--ok';
							$stateLabel = $l->t('Enrolled');
						} elseif ((int)$user['documents'] > 0) {
							$stateClass = 'eva-status--ok';
							$stateLabel = $l->t('Indexed');
						} else {
							$stateClass = 'eva-status--idle';
							$stateLabel = $l->t('Inactive');
						}
						?>
						<span class="eva-status <?php p($stateClass); ?>"><?php p($stateLabel); ?></span>
						<?php if ($user['error'] !== ''): ?>
							<span class="eva-error eva-block"><?php p($user['error']); ?></span>
						<?php endif; ?>
						<?php if ((int)($user['failed'] ?? 0) > 0): ?>
							<span class="eva-block eva-muted">
								<?php p($l->t('%s files skipped', [(string)(int)$user['failed']])); ?>
							</span>
						<?php endif; ?>
						<span class="eva-actions eva-actions--inline">
							<button type="button" class="secondary eva-btn-reindex"
								data-user="<?php p($user['userId']); ?>"
								<?php if ($user['indexing']) p('disabled'); ?>>
								<?php p($l->t('Re-index now')); ?>
							</button>
							<button type="button" class="secondary eva-btn-reset"
								data-user="<?php p($user['userId']); ?>"
								<?php if ($user['indexing']) p('disabled'); ?>>
								<?php p($l->t('Delete index')); ?>
							</button>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="eva-actions">
		<button type="button" id="eva-stop-background" class="secondary"><?php p($l->t('Stop background indexing')); ?></button>
		<span id="eva-background-status" class="eva-status-text" role="status" aria-live="polite"></span>
	</p>
</div>
