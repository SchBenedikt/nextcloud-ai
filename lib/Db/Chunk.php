<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getDocumentId()
 * @method void setDocumentId(int $id)
 * @method int getChunkIndex()
 * @method void setChunkIndex(int $i)
 * @method string getContent()
 * @method void setContent(string $c)
 * @method string getEmbedding()
 * @method void setEmbedding(string $json)
 * @method int getTokenCount()
 * @method void setTokenCount(int $n)
 * @method ?string getVectorGenerationId()
 * @method void setVectorGenerationId(?string $id)
 * @method string getVectorState()
 * @method void setVectorState(string $state)
 * @method ?string getContentHash()
 * @method void setContentHash(?string $hash)
 * @method int getVectorRevision()
 * @method void setVectorRevision(int $revision)
 * @method ?float getVectorNorm()
 * @method void setVectorNorm(?float $norm)
 */
class Chunk extends Entity {
    protected ?int $documentId = null;
    protected ?int $chunkIndex = 0;
    protected ?string $content = '';
    protected ?string $embedding = '';
    protected ?string $provenance = '{}';
    protected ?int $tokenCount = 0;
    protected ?string $vectorGenerationId = null;
    protected ?string $vectorState = 'legacy';
    protected ?string $contentHash = null;
    protected ?int $vectorRevision = 0;
    protected ?float $vectorNorm = null;

    public function __construct() {
        $this->addType('documentId', 'integer');
        $this->addType('chunkIndex', 'integer');
        $this->addType('tokenCount', 'integer');
        $this->addType('vectorRevision', 'integer');
        $this->addType('vectorNorm', 'double');
    }

    public function setProvenanceArray(array $metadata): void {
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4096) throw new \InvalidArgumentException('Chunk provenance exceeds 4 KiB');
        $this->setProvenance($encoded);
    }

    public function getEmbeddingArray(): array {
        return \OCA\EvaAi\Vector\VectorEmbeddingCodec::decode((string)$this->embedding) ?? [];
    }

    public function setEmbeddingArray(array $vector): void {
        $json = json_encode($vector, JSON_THROW_ON_ERROR);
        $this->setEmbedding($json);
        $this->setVectorNorm(\OCA\EvaAi\Vector\VectorNorm::fromJson($json));
    }
}
