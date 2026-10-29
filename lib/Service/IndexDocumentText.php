<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\File;

/** One canonical text representation for ordinary indexing and generation rebuilds. */
class IndexDocumentText {
    public function __construct(private IndexTextExtractor $extractor, private EmailService $email) {}

    public function file(File $file, ?int $maxBytes = null): string {
        return $this->extractor->extractText($file, $maxBytes);
    }

    public function isSupported(?string $mime, string $name = ''): bool {
        return $this->extractor->isTextMime($mime, $name);
    }

    /** The metadata must come from an owner-filtered EmailService query. */
    public function mail(string $userId, array $mail, ?\Closure $guard = null): string {
        $id = (int)$mail['id'];
        $body = '';
        if($guard)$guard();
        try { $body = $this->email->bodyText($id, $userId); }
        catch (\Throwable) {}
        if($guard)$guard();
        $text = "EMAIL\nFrom: " . $mail['from'] . "\nTo: " . implode(', ', (array)($mail['to'] ?? []))
            . "\nDate: " . date('Y-m-d H:i', (int)$mail['sent']) . "\nSubject: " . $mail['subject'] . "\n\n" . trim($body);
        $text=$this->extractor->normalize($text);
        if(mb_strlen($text)>=30000)return mb_substr($text,0,30000);
        try { $attachments=$this->email->fetchAttachments($userId,$id); }
        catch(\Throwable){$attachments=[];}
        if($guard)$guard();
        foreach($attachments as $attachment) {
            if($guard)$guard();
            try {
                $content = $this->extractor->extractTextFromBlob($attachment['content'], $attachment['mime']);
                if (trim($content) !== '') $text .= "\n\nATTACHMENT " . $attachment['name'] . "\n" . $content;
            } catch (\Throwable) {
                // Preserve ordinary indexing's best-effort attachment semantics.
                // A generation reader additionally requires the complete stored hash.
                // One unreadable attachment must not hide later readable ones.
                continue;
            }
            if($guard)$guard();
            $text=$this->extractor->normalize($text);
            if(mb_strlen($text)>=30000)break;
        }
        return mb_substr($this->extractor->normalize($text), 0, 30000);
    }

    public function talk(string $transcript): string {
        return mb_substr($this->extractor->normalize($transcript), 0, 2000000);
    }
}
