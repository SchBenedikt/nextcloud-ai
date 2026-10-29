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
$totals = $_['totals'];
$storage = $_['vectorStorage'] ?? ['available' => false];
$sqlAccounts = (int)($storage['active_sql_accounts'] ?? 0);
$qdrantAccounts = (int)($storage['active_qdrant_accounts'] ?? 0);
$storageBackend = !$storage['available'] ? $l->t('Status unavailable')
	: ($qdrantAccounts > 0 && $sqlAccounts > 0
		? $l->t('SQL generations serve {sql} accounts; Qdrant canaries serve {qdrant} accounts', ['sql' => $sqlAccounts, 'qdrant' => $qdrantAccounts])
		: ($qdrantAccounts > 0
			? $l->t('Optional Qdrant canary active for {count} accounts', ['count' => $qdrantAccounts])
			: ($sqlAccounts > 0
				? $l->t('SQL generations active for {count} accounts', ['count' => $sqlAccounts])
				: $l->t('Built-in Nextcloud SQL'))));
$scheduler = $_['scheduler'];
$apiBase = $_['apiBase'];
$providers = $_['webSearchProviders'] ?? [];

$webSearchUrl = $admin['web_search_url'] ?? '';
$webSearchApiKeyConfigured = !empty($_['webSearchKeyConfigured']);
$webSearchProviders = $providers;
$webSearchBrowserNode = $admin['web_search_browser_node'] ?? '';
$webSearchBrowserBrowsersPath = $admin['web_search_browser_browsers_path'] ?? '';
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
$rateLimitChat = $admin['rate_limit_chat_per_minute'] ?? '30';
$rateLimitStream = $admin['rate_limit_stream_per_minute'] ?? '10';
$rateLimitBackground = $admin['rate_limit_background_per_minute'] ?? '5';

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
		<span class="eva-chip <?php p($schedulerRunning > 0 ? 'eva-chip--busy' : 'eva-chip--idle'); ?>">
			<?php p($schedulerRunning > 0
				? $l->n('%n index pass running', '%n index passes running', $schedulerRunning)
				: $l->t('Indexer idle')); ?>
		</span>
		<span class="eva-chip eva-chip--idle">
			<?php p($l->n('%n indexed account', '%n indexed accounts', (int)$totals['accounts'])); ?>
		</span>
	</p>

	<h3><?php p($l->t('Instance status')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Indexing totals, the active search path and optional service configuration. Opening this page does not contact model or vector services.')); ?>
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
				<td><?php p($l->t('%s documents', [(string)$totals['documents']])); ?></td>
				<td><?php p($l->t('%s text chunks', [(string)$totals['chunks']])); ?></td>
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

	<h3><?php p($l->t('Knowledge storage')); ?></h3>
	<p><a class="button" href="<?php p($_['storageAdminUrl']); ?>"><?php p($l->t('Manage storage and background builds')); ?></a></p>
	<p class="settings-hint"><?php p($l->t('EVA uses Nextcloud SQL automatically. Optional Qdrant is available for explicitly configured larger installations.')); ?></p>
	<?php if (!$storage['available']): ?>
		<p role="alert"><?php p($l->t('Storage status could not be loaded. Retry or check the database migrations. This does not indicate an empty queue.')); ?></p>
	<?php else: ?>
		<table class="grid">
			<tbody>
				<tr><th scope="row"><?php p($l->t('Search backend')); ?></th><td><?php p($storageBackend); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Optional Qdrant connection')); ?></th><td><?php p(!$storage['connection']['configured'] ? $l->t('Not configured') : ($storage['connection']['enabled'] ? $l->t('Configured · requests allowed') : $l->t('Configured · requests disabled'))); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Generation cleanup')); ?></th><td><?php p($storage['erasure_worker_enabled'] ? $l->t('Enabled') : $l->t('Paused')); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Chunk synchronization')); ?></th><td><?php p($storage['point_worker_enabled'] ? $l->t('Enabled') : $l->t('Paused')); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Generation chunks in SQL')); ?></th><td><?php p((string)$storage['staged_chunks']); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Waiting operations')); ?></th><td><?php p((string)$storage['queue']['pending']); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Claimed operations')); ?></th><td><?php p((string)$storage['queue']['processing']); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Operations needing attention')); ?></th><td><?php p((string)$storage['queue']['dead']); ?></td></tr>
			</tbody>
		</table>
		<?php if ($storage['queue']['dead'] > 0): ?>
			<p role="alert"><?php p($l->t('Some background operations exhausted their retries. An administrator needs to inspect them before retrying.')); ?></p>
		<?php endif; ?>
		<p class="settings-hint"><?php p($l->t('Configuration status is not a connection test. Generation cleanup only removes retired search collections; it does not index files or switch search backends.')); ?></p>
		<p class="settings-hint"><?php p($l->t('Removing a file clears its local index and queues cleanup for prepared vector points. Renaming or changing a source discards its prepared entries for rebuilding. Prepared point updates and cleanup run only when their delivery switches allow them.')); ?></p>
		<?php if ($storage['staged_chunks'] > 0): ?>
			<p class="settings-hint"><?php p($l->t('Prepared generation chunks are separate from the SQL search index. Their presence does not mean migration is verified or active.')); ?></p>
		<?php endif; ?>
	<?php endif; ?>

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

	<h3><?php p($l->t('Chat request limits')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Per-user limits per minute. Lower values protect small instances; higher values support larger deployments.')); ?></p>
	<div class="eva-field-grid">
		<div class="eva-field"><label for="eva-rate-chat"><?php p($l->t('Chat requests/minute')); ?></label><input type="number" id="eva-rate-chat" min="1" max="120" value="<?php p($rateLimitChat); ?>"></div>
		<div class="eva-field"><label for="eva-rate-stream"><?php p($l->t('Streaming requests/minute')); ?></label><input type="number" id="eva-rate-stream" min="1" max="60" value="<?php p($rateLimitStream); ?>"></div>
		<div class="eva-field"><label for="eva-rate-background"><?php p($l->t('Background requests/minute')); ?></label><input type="number" id="eva-rate-background" min="1" max="30" value="<?php p($rateLimitBackground); ?>"></div>
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
	</div>

	<p class="settings-hint eva-indent">
		<?php p($l->t('Search limits, page reading, images, safe search and browser rendering are configured by each user in personal Eva AI settings. This page only stores shared provider credentials and browser infrastructure.')); ?>
	</p>

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

	<p class="settings-hint">
		<?php p($l->t('Tool permissions, including weather forecasts and file actions, are configured by each user in personal Eva AI settings.')); ?>
	</p>


	<h3><?php p($l->t('Accounts and indexing')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Indexing is managed by each account from personal EVA settings. This page shows instance totals and the active search backend without exposing individual account activity.')); ?>
	</p>

	<p class="eva-actions">
		<button type="button" id="eva-stop-background" class="secondary"><?php p($l->t('Stop background indexing')); ?></button>
		<span id="eva-background-status" class="eva-status-text" role="status" aria-live="polite"></span>
	</p>
</div>
