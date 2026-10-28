<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Service\{AppConfig, EmailService, IndexDocumentText, TalkTranscriptService};
use OCP\Files\{File, IRootFolder};

/** Reconstruct the exact canonical source; never stitch overlapping legacy chunks. */
class GenerationSourceReader {
    public const MAX_FILE_BYTES = 20971520;
    public const MAX_TEXT_CHARS = 2000000;

    public function __construct(private AppConfig $config, private VectorSourceAccess $access,
        private IRootFolder $files, private IndexDocumentText $text,
        private EmailService $mail, private TalkTranscriptService $talk) {}

    public function read(VectorDocumentSnapshot $source): string {
        if ($this->config->userId() !== $source->userId) throw new VectorStoreException('embedding_owner_mismatch');
        $metadata = $source->metadata;
        $point = ['source' => $metadata['source'], 'file_id' => $metadata['file_id'], 'path' => $metadata['path'],
            'source_mtime' => $metadata['file_mtime'], 'source_size' => $metadata['size']];
        $this->access->assertAccessible($source->userId, $point);
        if ($metadata['source'] === 'files') {
            $limit = min(self::MAX_FILE_BYTES, max(1, $this->config->getInt('max_file_size', self::MAX_FILE_BYTES)));
            if ($metadata['size'] < 0 || $metadata['size'] > $limit) throw new VectorStoreException('source_too_large');
            $home = $this->files->getUserFolder($source->userId);
            $content = null;
            foreach ($home->getById($metadata['file_id']) as $file) {
                if ($file instanceof File && $file->isReadable() && (int)$file->getSize() === $metadata['size']
                    && (int)$file->getMTime() === $metadata['file_mtime']
                    && ($relative = $home->getRelativePath($file->getPath())) !== null
                    && ltrim($relative, '/') === $metadata['path']) {
                    try { $content = $this->text->file($file, $limit); }
                    catch (\LengthException) { throw new VectorStoreException('source_too_large'); }
                    break;
                }
            }
            if ($content === null) throw new VectorStoreException('source_unavailable');
        } elseif ($metadata['source'] === 'mail') {
            $message = $this->mail->messageForIndexing($source->userId, -$metadata['file_id']);
            if ($message === null) throw new VectorStoreException('source_unavailable');
            $content = $this->text->mail($source->userId, $message);
        } else {
            if (preg_match('~^talk://([1-9][0-9]{0,17})$~D', $metadata['path'], $matches) !== 1) throw new VectorStoreException('source_unavailable');
            $transcript = $this->talk->transcript($source->userId, (int)$matches[1]);
            if ($transcript === null) throw new VectorStoreException('source_unavailable');
            $content = $this->text->talk($transcript['text']);
        }
        // No silent truncation or guessed source identity. A changed transcript,
        // IMAP fallback or extraction configuration requires an ordinary reindex.
        if (!mb_check_encoding($content, 'UTF-8') || mb_strlen($content) > self::MAX_TEXT_CHARS) throw new VectorStoreException('source_too_large');
        $hash = hash(strlen($metadata['content_hash']) === 32 ? 'md5' : 'sha256', $content);
        if (!hash_equals($metadata['content_hash'], $hash)) throw new VectorStoreException('source_changed');
        $this->access->assertAccessible($source->userId, $point);
        return $content;
    }
}
