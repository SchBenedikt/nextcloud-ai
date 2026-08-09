<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getMime()
 * @method void setMime(?string $mime)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getContentHash()
 * @method void setContentHash(string $hash)
 * @method int getChunkCount()
 * @method void setChunkCount(int $count)
 * @method ?int getIndexedAt()
 * @method void setIndexedAt(?int $ts)
 */
class Document extends Entity {
    protected ?string $userId = null;
    protected ?int $fileId = null;
    protected ?string $path = '';
    protected ?string $name = '';
    protected ?string $mime = null;
    protected ?int $size = 0;
    protected ?string $contentHash = '';
    protected ?int $chunkCount = 0;
    protected ?int $indexedAt = null;

    public function __construct() {
        $this->addType('fileId', 'integer');
        $this->addType('size', 'integer');
        $this->addType('chunkCount', 'integer');
        $this->addType('indexedAt', 'integer');
    }
}