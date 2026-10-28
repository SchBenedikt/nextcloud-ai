<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Dto\SettingsUpdateRequest;

/** One normalization boundary for already validated personal settings. */
final class SettingsPersistence {
    public function __construct(private AppConfig $config) {}

    public function save(SettingsUpdateRequest $request): array {
        $normalized = [];
        foreach ($request->values as $key => $value) {
            $normalized[$key] = (string)$this->config->normalizeSetting($key, $value);
        }
        foreach ($normalized as $key => $value) {
            $this->config->set($key, $value);
        }
        return $this->config->all();
    }
}
