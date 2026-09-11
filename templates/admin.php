<?php
/**
 * Eva AI admin settings — native Nextcloud admin page.
 *
 * Uses only native Nextcloud HTML elements: section, h2, h3, p,
 * table.grid, native input, select, button.
 * Follows the same pattern as the Social app admin settings.
 */

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */

$admin = $_['adminSettings'];
$ollamaOnline = $_['ollamaOnline'];
$ollamaUrl = $_['ollamaUrl'];
$users = $_['users'];
$userCount = $_['userCount'];
$totalDocuments = $_['totalDocuments'];
$totalChunks = $_['totalChunks'];
$scheduler = $_['scheduler'];
$apiBase = $_['apiBase'];

$weatherEnabled = ($admin['weather_tool_enabled'] ?? '1') === '1';
$webSearchUrl = $admin['web_search_url'] ?? '';
$webSearchMaxResults = $admin['web_search_max_results'] ?? '8';
$webSearchFetchContent = ($admin['web_search_fetch_content'] ?? '1') === '1';
$webSearchContentChars = $admin['web_search_content_chars'] ?? '2000';
$webSearchSafeSearch = ($admin['web_search_safe_search'] ?? '1') === '1';
$webSearchKeyConfigured = $_['webSearchKeyConfigured'];
$indexMaxConcurrent = $admin['index_max_concurrent'] ?? '2';
$indexJobMaxSeconds = $admin['index_job_max_seconds'] ?? '50';
?>

<div id="eva-ai-admin" class="section" data-api-base="<?php p($apiBase); ?>">

	<h2><?php p($l->t('Eva AI')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Eva AI runs a private knowledge base powered by local or cloud language models. Configure the instance below.')); ?>
	</p>

	<!-- ====== Instance overview ====== -->
	<h3><?php p($l->t('Instance overview')); ?></h3>

	<table class="grid">
		<thead>
			<tr>
				<th><?php p($l->t('Service')); ?></th>
				<th><?php p($l->t('Status')); ?></th>
				<th><?php p($l->t('Detail')); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php p($l->t('Ollama')); ?></strong></td>
				<td>
					<?php if ($ollamaOnline): ?>
						<span style="color:var(--color-success);">✓ <?php p($l->t('Connected')); ?></span>
					<?php else: ?>
						<span style="color:var(--color-error);">✗ <?php p($l->t('Not connected')); ?></span>
					<?php endif; ?>
				</td>
				<td><?php p($ollamaUrl); ?></td>
			</tr>
			<tr>
				<td><strong><?php p($l->t('Users')); ?></strong></td>
				<td><?php p((string)$userCount); ?></td>
				<td><?php p($l->t('enrolled in indexing')); ?></td>
			</tr>
			<tr>
				<td><strong><?php p($l->t('Knowledge base')); ?></strong></td>
				<td><?php p((string)$totalDocuments); ?> <?php p($l->t('documents')); ?></td>
				<td><?php p((string)$totalChunks); ?> <?php p($l->t('chunks')); ?></td>
			</tr>
			<tr>
				<td><strong><?php p($l->t('Background indexing')); ?></strong></td>
				<td><?php p($scheduler['running'] ?? 0); ?> / <?php p((string)($scheduler['limit'] ?? 2)); ?></td>
				<td><?php p($l->t('concurrent passes')); ?></td>
			</tr>
		</tbody>
	</table>

	<!-- ====== Tools & integrations ====== -->
	<h3><?php p($l->t('Tools & integrations')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Instance-wide switches. Each user configures their own web search provider in the personal Eva AI settings.')); ?>
	</p>

	<p>
		<label>
			<input type="checkbox" id="eva-weather-toggle" name="weather_tool_enabled" value="1" <?php p($weatherEnabled ? 'checked' : ''); ?>>
			<?php p($l->t('Allow weather forecasts for all users')); ?>
		</label>
		<br>
		<em><?php p($l->t('The weather tool queries external Open-Meteo services. Turn it off to keep all tool traffic on your own server.')); ?></em>
	</p>

	<p>
		<label>
			<input type="checkbox" id="eva-websearch-toggle" name="web_search_infra_enabled" value="1" checked>
			<?php p($l->t('Web search infrastructure')); ?>
		</label>
		<br>
		<em><?php p($l->t('Configure the SearxNG URL and API keys below. Users choose their own provider (DuckDuckGo is free, no key needed) in their personal settings.')); ?></em>
	</p>

	<div id="eva-websearch-config">
		<p>
			<label for="eva-websearch-url"><?php p($l->t('SearxNG base URL')); ?></label><br>
			<input type="url" id="eva-websearch-url" name="web_search_url"
				   value="<?php p($webSearchUrl); ?>"
				   placeholder="https://searx.example.org"
				   style="width:400px;">
			<br>
			<em><?php p($l->t('Required when users choose SearxNG. The instance must return JSON results.')); ?></em>
		</p>

		<p>
			<label for="eva-websearch-key"><?php p($l->t('Brave / Tavily API key')); ?></label><br>
			<input type="password" id="eva-websearch-key" name="web_search_api_key"
				   placeholder="<?php p($webSearchKeyConfigured ? $l->t('A key is stored — leave empty to keep it') : $l->t('Paste the API key')); ?>"
				   autocomplete="new-password"
				   style="width:400px;">
			<br>
			<em><?php p($l->t('Required when users choose Brave or Tavily. Stored encrypted, never shown.')); ?></em>
			<br>
			<label>
				<input type="checkbox" id="eva-remove-websearch-key" name="remove_web_search_api_key">
				<?php p($l->t('Remove the stored API key')); ?>
			</label>
		</p>

		<p>
			<label for="eva-websearch-max"><?php p($l->t('Maximum results per search')); ?></label><br>
			<input type="number" id="eva-websearch-max" name="web_search_max_results"
				   min="1" max="20" value="<?php p($webSearchMaxResults); ?>"
				   style="width:100px;">
			<br>
			<em><?php p($l->t('Between 1 and 20. Every result is added to the model context.')); ?></em>
		</p>

		<p>
			<label>
				<input type="checkbox" id="eva-safesearch-toggle" name="web_search_safe_search" value="1" <?php p($webSearchSafeSearch ? 'checked' : ''); ?>>
				<?php p($l->t('Safe search')); ?>
			</label>
			<br>
			<em><?php p($l->t('Ask the provider to filter adult results.')); ?></em>
		</p>

		<p>
			<label>
				<input type="checkbox" id="eva-fetch-content-toggle" name="web_search_fetch_content" value="1" <?php p($webSearchFetchContent ? 'checked' : ''); ?>>
				<?php p($l->t('Read the result pages')); ?>
			</label>
			<br>
			<em><?php p($l->t('Fetch the ranked result pages in parallel and give the model their readable text instead of a search-engine teaser. Turn it off to keep the search to a single request.')); ?></em>
		</p>

		<p>
			<label for="eva-content-chars"><?php p($l->t('Text per page (characters)')); ?></label><br>
			<input type="number" id="eva-content-chars" name="web_search_content_chars"
				   min="200" max="8000" value="<?php p($webSearchContentChars); ?>"
				   style="width:100px;">
			<br>
			<em><?php p($l->t('Between 200 and 8000. More text gives more accurate answers but uses more of the model context.')); ?></em>
		</p>
	</div>

	<p>
		<button type="button" id="eva-tools-save" class="primary"><?php p($l->t('Save tool settings')); ?></button>
		<span id="eva-tools-status"></span>
	</p>

	<!-- ====== Indexing schedule ====== -->
	<h3><?php p($l->t('Indexing schedule')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Control how the background indexer runs across all users on this instance.')); ?>
	</p>

	<p>
		<label for="eva-max-concurrent"><?php p($l->t('Maximum concurrent index passes')); ?></label><br>
		<input type="number" id="eva-max-concurrent" name="index_max_concurrent"
			   min="1" max="16" value="<?php p($indexMaxConcurrent); ?>"
			   style="width:100px;">
		<br>
		<em><?php p($l->t('How many users may be indexed in parallel. Default: 2.')); ?></em>
	</p>

	<p>
		<label for="eva-job-budget"><?php p($l->t('Per-run time budget (seconds)')); ?></label><br>
		<input type="number" id="eva-job-budget" name="index_job_max_seconds"
			   min="10" max="600" value="<?php p($indexJobMaxSeconds); ?>"
			   style="width:100px;">
		<br>
		<em><?php p($l->t('Maximum wall-clock seconds one periodic run may spend. Default: 50.')); ?></em>
	</p>

	<p>
		<button type="button" id="eva-scheduler-save" class="primary"><?php p($l->t('Save scheduler settings')); ?></button>
		<span id="eva-scheduler-status"></span>
	</p>

	<!-- ====== Per-user indexing ====== -->
	<h3><?php p($l->t('Per-user indexing')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Manage which users have an indexed knowledge base and trigger re-indexing or reset.')); ?>
	</p>

	<?php if (empty($users)): ?>
		<p><em><?php p($l->t('No users have enrolled in indexing yet.')); ?></em></p>
	<?php else: ?>
		<table class="grid">
			<thead>
				<tr>
					<th><?php p($l->t('User')); ?></th>
					<th><?php p($l->t('Documents')); ?></th>
					<th><?php p($l->t('Chunks')); ?></th>
					<th><?php p($l->t('Last indexed')); ?></th>
					<th><?php p($l->t('Status')); ?></th>
					<th><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($users as $user): ?>
				<tr data-user-id="<?php p($user['userId']); ?>">
					<td>
						<strong><?php p($user['displayName']); ?></strong>
						<?php if ($user['displayName'] !== $user['userId']): ?>
							<br><small style="color:var(--color-text-maxcontrast);"><?php p($user['userId']); ?></small>
						<?php endif; ?>
					</td>
					<td><?php p((string)$user['documents']); ?></td>
					<td><?php p((string)$user['chunks']); ?></td>
					<td>
						<?php if ($user['lastIndexedAt'] !== null): ?>
							<?php p(gmdate('Y-m-d H:i', (int)$user['lastIndexedAt'])); ?>
						<?php else: ?>
							<em>—</em>
						<?php endif; ?>
					</td>
					<td>
						<?php if ($user['indexing']): ?>
							<span class="warning"><?php p($l->t('Indexing')); ?></span>
						<?php elseif ($user['enrolled']): ?>
							<span style="color:var(--color-success);"><?php p($l->t('Enrolled')); ?></span>
						<?php else: ?>
							<span style="color:var(--color-text-maxcontrast);"><?php p($l->t('Inactive')); ?></span>
						<?php endif; ?>
						<?php if ($user['error'] !== ''): ?>
							<br><small style="color:var(--color-error);"><?php p($user['error']); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<button type="button" class="eva-btn-reindex" data-user="<?php p($user['userId']); ?>"
								<?php if ($user['indexing']) p('disabled'); ?>>
							<?php p($l->t('Reindex')); ?>
						</button>
						<button type="button" class="eva-btn-reset" data-user="<?php p($user['userId']); ?>"
								<?php if ($user['indexing']) p('disabled'); ?>>
							<?php p($l->t('Reset')); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p style="margin-top:12px;">
		<button type="button" id="eva-stop-background" class="secondary"><?php p($l->t('Stop background indexing')); ?></button>
		<span id="eva-background-status"></span>
	</p>
</div>
