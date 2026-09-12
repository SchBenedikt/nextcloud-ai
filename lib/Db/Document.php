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
 * @method int getFileMtime()
 * @method void setFileMtime(int $mtime)
 * @method string getContentHash()
 * @method void setContentHash(string $hash)
 * @method int getChunkCount()
 * @method void setChunkCount(int $count)
 * @method ?int getIndexedAt()
 * @method void setIndexedAt(?int $ts)
 * @method string getSource()
 * @method void setSource(string $source)
 */
class Document extends Entity {
    /** Producer of a row: an indexed file, a mail message, a Talk transcript. */
    public const SOURCE_FILES = 'files';
    public const SOURCE_MAIL = 'mail';
    public const SOURCE_TALK = 'talk';

    protected ?string $userId = null;
    protected ?int $fileId = null;
    protected ?string $path = '';
    protected ?string $name = '';
    protected ?string $mime = null;
    protected ?int $size = 0;
    protected ?int $fileMtime = 0;
    protected ?string $contentHash = '';
    protected ?int $chunkCount = 0;
    protected ?int $indexedAt = null;
    protected ?string $source = self::SOURCE_FILES;

    public function __construct() {
        $this->addType('fileId', 'integer');
        $this->addType('size', 'integer');
        $this->addType('fileMtime', 'integer');
        $this->addType('chunkCount', 'integer');
        $this->addType('indexedAt', 'integer');
    }
}