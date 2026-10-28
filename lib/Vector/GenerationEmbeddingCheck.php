<?php
declare(strict_types=1);
namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Service\AppConfig;

/** Explicit public-text probe shared by native administration and OCC. */
class GenerationEmbeddingCheck {
    public function __construct(private VectorGenerationRepository $generations, private VectorSourceAccess $access,
        private AppConfig $config, private GenerationEmbeddingProvider $embeddings) {}

    public function check(string $userId, string $id): array {
        $previous = $this->config->userId();
        try {
            $this->access->assertOwner($userId);
            $generation = $this->generations->find($userId, $id);
            if ($generation === null) throw new VectorStoreException('generation_missing');
            $guard = function () use ($userId, $id, $generation): void {
                $this->access->assertOwner($userId);
                $current = $this->generations->find($userId, $id);
                if ($current === null || $current->toRow() !== $generation->toRow()
                    || $this->generations->status($userId, $id) !== 'building'
                    || $this->generations->ownerStatus($userId) !== 'open') throw new VectorStoreException('generation_unavailable');
            };
            $this->config->setUserId($userId);
            $this->embeddings->embed($generation, ['Vector generation compatibility check.'], $guard, false);
            return ['available'=>true, 'generation_id'=>$id, 'dimension'=>$generation->dimension,
                'retrieval_changed'=>false, 'document_content_sent'=>false];
        } finally { $this->config->setUserId($previous); }
    }
}
