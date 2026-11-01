<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\OllamaUrlValidator;
use OCA\EvaAi\Service\ModelSelectionValidator;
use OCA\EvaAi\Service\ProviderCredentials;
use OCA\EvaAi\Service\SettingsPersistence;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** User-owned settings and their validation/persistence boundary. */
final class SettingsController extends OCSController {
	use ReadsRequestParameters;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private AppConfig $config,
		private Ollama $ollama,
		private KnowledgeInitializer $knowledgeInitializer,
		private ProviderCredentials $credentials,
		private SettingsPersistence $settingsPersistence,
		private ModelSelectionValidator $modelSelectionValidator,
	) {
		parent::__construct($appName, $request);
		$this->config->setUserId($this->userId);
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}
	#[NoAdminRequired]
	public function settings(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		$this->knowledgeInitializer->ensureInitialized($user);
		return new ErrorDataResponse($this->config->all() + [
			// Instance-wide kill switch: dictation requires both admin and user opt-in.
			'voice_input_available' => $this->config->get('voice_input_available'),
		], 200, ['Cache-Control' => 'private, max-age=300']);
	}

	#[NoAdminRequired]
	public function saveSettings(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		$this->config->setUserId($user);
		$this->config->recoverAbandonedRun();
		$indexRunning = $this->config->get('index_running') === '1';
		try {
			$input = [];
			foreach (array_merge(\OCA\EvaAi\Dto\SettingsUpdateRequest::FIELDS, \OCA\EvaAi\Dto\SettingsCredentialsRequest::FIELDS) as $key) {
				$input[$key] = $this->requestParam($key);
			}
			$update = \OCA\EvaAi\Dto\SettingsUpdateRequest::fromArray($input);
			$secrets = \OCA\EvaAi\Dto\SettingsCredentialsRequest::fromArray($input);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}
		$pending = $update->values;
		$validationErrors = [];
		foreach ($pending as $key => $value) {
			$validationValue = $value;
			$limitError = $this->config->validateValue($key, $validationValue);
			if ($limitError !== null) {
				$validationErrors[$key] = $key . ' ' . $limitError . '.';
			}
			if ($key === 'index_enrolled' && $this->config->validateValue($key, $value) !== null) {
				$validationErrors[$key] = $key . ' must be a boolean value.';
			}
			if ($key === 'ollama_url') {
				$urlError = $this->validateOllamaUrl((string)$value);
				if ($urlError !== null) {
					$validationErrors[$key] = $urlError;
				}
			}
			if (in_array($key, ['scope_path', 'exclude_paths'], true)
				&& preg_match('~(^|[\\\\/])\\.\\.?([\\\\/]|$)~', (string)$value)) {
				$validationErrors[$key] = $key . ' may not contain relative path traversal.';
			}
			if ($key === 'proactive_schedules') {
				$scheduleError = $this->validateProactiveSchedules($value);
				if ($scheduleError !== null) {
					$validationErrors[$key] = $scheduleError;
				}
			}
			if ($key === 'provider_profiles') {
				$profileError = $this->config->validateValue($key, $validationValue);
				if ($profileError !== null) $validationErrors[$key] = 'Provider profiles ' . $profileError . '.';
			}
		}
		$embeddingProfileId = trim((string)($pending['generation_embedding_profile_id'] ?? $this->config->get('generation_embedding_profile_id')));
		if ($embeddingProfileId !== '') {
			$profiles = $pending['provider_profiles'] ?? $this->config->get('provider_profiles');
			if (is_string($profiles)) {
				try { $profiles = json_decode($profiles, true, 64, JSON_THROW_ON_ERROR); }
				catch (\Throwable) { $profiles = []; }
			}
			$embeddingProfile = null;
			foreach (is_array($profiles) ? $profiles : [] as $profile) {
				if (is_array($profile) && ($profile['id'] ?? null) === $embeddingProfileId) { $embeddingProfile = $profile; break; }
			}
			if (!is_array($embeddingProfile) || !is_string($embeddingProfile['embedding_model'] ?? null)
				|| !isset($embeddingProfile['embedding_dimension'])) {
				$validationErrors['generation_embedding_profile_id'] = 'The selected embedding profile must configure an embedding model and dimension.';
			}
		}
		if ($indexRunning && array_diff(array_keys($pending), ['proactive_enabled', 'proactive_schedules']) !== []) {
			return new ErrorDataResponse(['error' => 'Only scheduled briefings can be changed while indexing is running.'], 409);
		}
		if ($validationErrors !== []) {
			return new ErrorDataResponse([
				'error' => 'Invalid settings.',
				'validationErrors' => array_values($validationErrors),
			], 400);
		}
		$selectedProvider = (string)($pending['chat_provider'] ?? $this->config->get('chat_provider'));
		if ($selectedProvider !== 'ollama' && $selectedProvider !== 'groq') {
			$profiles = $pending['provider_profiles'] ?? $this->config->get('provider_profiles');
			if (is_string($profiles)) $profiles = json_decode($profiles, true);
			$selectedProfile = null;
			foreach (is_array($profiles) ? $profiles : [] as $profile) {
				if (is_array($profile) && (string)($profile['id'] ?? '') === $selectedProvider) { $selectedProfile = $profile; break; }
			}
			$customUrl = trim((string)($selectedProfile['url'] ?? $pending['custom_provider_url'] ?? $this->config->get('custom_provider_url')));
			$customModel = trim((string)($selectedProfile['model'] ?? $pending['custom_provider_model'] ?? $this->config->get('custom_provider_model')));
			if ($customUrl === '' || $customModel === '') {
				return new ErrorDataResponse(['error' => 'Custom provider requires both an endpoint URL and model name.'], 400);
			}
			$parts = parse_url($customUrl);
			if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || empty($parts['host'])) {
				return new ErrorDataResponse(['error' => 'Custom provider endpoint must be a plain URL without credentials, query or fragment.'], 400);
			}
		}
		$groqKey = $secrets->groqKey;
		$removeGroqKey = $secrets->removeGroqKey;
		$customKey = $secrets->customKey;
		$customCredentialProviderId = $secrets->customProviderId ?? $selectedProvider;
		$removeCustomKey = $secrets->removeCustomKey;
		$nextcloudToken = $secrets->nextcloudToken;
		$removeNextcloudToken = $secrets->removeNextcloudToken;
		$roleErrors = $this->validateModelRoles($pending);
		if ($roleErrors !== []) {
			return new ErrorDataResponse([
				'error' => 'Invalid settings.',
				'validationErrors' => array_values($roleErrors),
			], 400);
		}
		$providerId = $selectedProvider;
		$credentials = null;
		if ($customKey !== null || $removeCustomKey) {
			if (in_array($customCredentialProviderId, ['ollama', 'groq'], true)) {
				return new ErrorDataResponse(['error' => 'Custom credentials must target a custom provider profile.'], 400);
			}
			$credentials = $this->credentials;
			// New clients identify the exact profile being edited. Validate it
			// against the profile list in this same request or the saved list;
			// older clients continue to target the currently selected profile.
			if ($secrets->customProviderId !== null) {
				$credentialProfiles = $pending['provider_profiles'] ?? $this->config->get('provider_profiles');
				if (is_string($credentialProfiles)) $credentialProfiles = json_decode($credentialProfiles, true);
				$credentialProfileExists = false;
				foreach (is_array($credentialProfiles) ? $credentialProfiles : [] as $profile) {
					if (is_array($profile) && (string)($profile['id'] ?? '') === $customCredentialProviderId) {
						$credentialProfileExists = true;
						break;
					}
				}
				if (!$credentialProfileExists) {
					return new ErrorDataResponse(['error' => 'The custom credential target is not a configured provider profile.'], 400);
				}
			}
		}
		if ($providerId !== 'ollama' && $providerId !== 'groq') {
			$credentials ??= $this->credentials;
			$customKeyTargetsSelectedProvider = $customCredentialProviderId === $providerId && is_string($customKey) && $customKey !== '';
			$removingSelectedProviderKey = $customCredentialProviderId === $providerId && $removeCustomKey;
			if (($selectedProfile['auth'] ?? 'api_key') !== 'none'
				&& !$removingSelectedProviderKey
				&& !$customKeyTargetsSelectedProvider
				&& !$credentials->customConfigured($user, $providerId)) {
				return new ErrorDataResponse(['error' => 'Save an API key for the selected custom provider first.'], 400);
			}
		}
		// All values, model roles and credential requirements are valid now.
		if ($removeGroqKey) $this->ollama->saveGroqKey('');
		elseif ($groqKey !== null && $groqKey !== '') $this->ollama->saveGroqKey($groqKey);
		if ($customCredentialProviderId !== 'ollama' && $customCredentialProviderId !== 'groq') {
			if ($removeCustomKey) $credentials->saveCustom($user, $customCredentialProviderId, '');
			elseif ($customKey !== null && $customKey !== '') $credentials->saveCustom($user, $customCredentialProviderId, $customKey);
		}
		if ($removeNextcloudToken || (is_string($nextcloudToken) && $nextcloudToken !== '')) {
			$this->credentials->saveNextcloudToken(
				$user,
				$removeNextcloudToken ? '' : $nextcloudToken
			);
		}
		$removedProviderIds = [];
		if (array_key_exists('provider_profiles', $pending)) {
			$existingProfiles = json_decode((string)$this->config->get('provider_profiles'), true);
			$existingProfiles = is_array($existingProfiles) ? $existingProfiles : [];
			$remainingIds = [];
			foreach ($pending['provider_profiles'] as $profile) {
				if (is_array($profile) && isset($profile['id'])) $remainingIds[(string)$profile['id']] = true;
			}
			foreach ($existingProfiles as $profile) {
				$id = is_array($profile) ? (string)($profile['id'] ?? '') : '';
				if ($id !== '' && !isset($remainingIds[$id])) $removedProviderIds[] = $id;
			}
		}
		$savedSettings = $this->settingsPersistence->save($update);
		if ($removedProviderIds !== []) {
			$credentials ??= $this->credentials;
			foreach ($removedProviderIds as $removedProviderId) {
				$credentials->saveCustom($user, $removedProviderId, '');
			}
		}
		return new ErrorDataResponse($savedSettings);
	}

	/** Validate the small, deliberately data-only scheduler format. */
	private function validateProactiveSchedules(mixed $value): ?string {
		if (!is_string($value) || strlen($value) > 20000) {
			return 'Scheduled briefings must be a small JSON list.';
		}
		$rows = json_decode($value, true);
		if (!is_array($rows) || count($rows) > 20) {
			return 'Scheduled briefings must contain at most 20 entries.';
		}
		foreach ($rows as $row) {
			if (!is_array($row)
				|| preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string)($row['id'] ?? '')) !== 1
				|| !is_string($row['prompt'] ?? null) || mb_strlen(trim((string)$row['prompt'])) < 1 || mb_strlen((string)$row['prompt']) > 2000
				|| preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', (string)($row['time'] ?? '')) !== 1
				|| !is_array($row['days'] ?? null) || $row['days'] === []) {
				return 'Every scheduled briefing needs an id, prompt, HH:MM time and at least one weekday.';
			}
			if (array_key_exists('allow_actions', $row) && !is_bool($row['allow_actions'])) {
				return 'Scheduled briefing allow_actions must be a boolean.';
			}
			if (array_key_exists('type', $row) && !in_array($row['type'], ['morning', 'document_digest', 'custom'], true)) {
				return 'Scheduled briefing type must be morning, document_digest or custom.';
			}
			if (array_key_exists('channels', $row)) {
				$channels = $row['channels'];
				if (!is_array($channels) || $channels === [] || count($channels) > 3 || array_diff($channels, ['notification', 'email', 'talk']) !== []) {
					return 'Choose one or more supported briefing delivery channels.';
				}
				if (in_array('talk', $channels, true)
					&& (!is_string($row['talk_room'] ?? null) || trim($row['talk_room']) === '' || mb_strlen($row['talk_room']) > 128)) {
					return 'A Talk briefing needs a room name or room token of at most 128 characters.';
				}
			}
			foreach ($row['days'] as $day) {
				if (!is_int($day) && !ctype_digit((string)$day) || (int)$day < 1 || (int)$day > 7) {
					return 'Scheduled briefing weekdays must be between 1 (Monday) and 7 (Sunday).';
				}
			}
		}
		return null;
	}

	/**
	 * Reject role-mismatched model selections before they are stored (Issue
	 * #148): when the endpoint reports capabilities for an installed model,
	 * an embedding model must actually produce vectors and a chat model must
	 * accept chat/completion. Selections for models that are not installed
	 * yet (or an offline endpoint) are allowed - the pull may still be
	 * pending, and indexing/chat will surface the real error.
	 */
	private function validateModelRoles(array $pending): array {
		return $this->modelSelectionValidator->validate($pending);
	}

	private function validateOllamaUrl(string $url): ?string {
		return OllamaUrlValidator::validate($url);
	}

}
