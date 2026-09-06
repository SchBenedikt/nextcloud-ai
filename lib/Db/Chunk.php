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
 */
class Chunk extends Entity {
    protected $provenance;

    protected ?int $documentId = null;
    protected ?int $chunkIndex = 0;
    protected ?string $content = '';
    protected ?string $embedding = '';
    protected ?int $tokenCount = 0;

    public function __construct() {
        $this->addType('documentId', 'integer');
        $this->addType('chunkIndex', 'integer');
        $this->addType('tokenCount', 'integer');
    }

    public function getEmbeddingArray(): array {
        return json_decode((string)$this->embedding, true) ?? [];
    }

    public function setEmbeddingArray(array $vector): void {
        $this->setEmbedding(json_encode($vector));
    }
}