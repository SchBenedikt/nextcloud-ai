<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\OpenAICompatible;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToSpeech;
use RuntimeException;

final class EvaTextToSpeechProvider implements ISynchronousProvider {
	private const LANGUAGE_RULE = 'same language as the input';
	public function __construct(private AppConfig $appConfig, private OpenAICompatible $audio, private IRootFolder $rootFolder, private IL10N $l) {}
	public function getId(): string { return 'eva_ai:text2speech:openai-compatible'; }
	public function getName(): string { return $this->l->t('Eva (speech)'); }
	public function getTaskTypeId(): string { return TextToSpeech::ID; }
	public function getExpectedRuntime(): int { return 180; }
	public function getInputShapeEnumValues(): array { return []; }
	public function getInputShapeDefaults(): array { return []; }
	public function getOptionalInputShape(): array { return []; }
	public function getOptionalInputShapeEnumValues(): array { return []; }
	public function getOptionalInputShapeDefaults(): array { return []; }
	public function getOutputShapeEnumValues(): array { return []; }
	public function getOptionalOutputShape(): array { return []; }
	public function getOptionalOutputShapeEnumValues(): array { return []; }
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$text = trim((string)($input['input'] ?? ''));
		if ($text === '') throw new RuntimeException('Empty speech input');
		$reportProgress(0.15);
		$audio = $this->audio->synthesizeSpeech($text, 180);
		$home = $this->rootFolder->getUserFolder($userId);
		$folder = $home->nodeExists('EVA') ? $home->get('EVA') : $home->newFolder('EVA');
		if (!$folder instanceof Folder) throw new RuntimeException('The EVA folder exists but is not a folder.');
		$name = 'eva-speech-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.mp3';
		$id = (int)$folder->newFile($name, $audio['bytes'])->getId();
		$reportProgress(1.0);
		return ['speech' => $id];
	}
}
