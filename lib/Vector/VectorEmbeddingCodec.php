<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Compact, portable SQL representation for local generation vectors. */
final class VectorEmbeddingCodec {
    private const PREFIX = 'eva64:';
    private const MAX_DIMENSION = 16384;

    /** Store compact IEEE-754 doubles only when the encoded value is smaller. */
    public static function encode(array $vector): string {
        if (!array_is_list($vector) || $vector === [] || count($vector) > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException('Invalid SQL generation vector.');
        }
        $parts = [];
        $values = [];
        foreach ($vector as $value) {
            if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException('Invalid SQL generation vector.');
            }
            $values[] = (float)$value;
            $parts[] = pack('E', (float)$value);
        }
        $packed = implode('', $parts);
        $compact = self::PREFIX . count($vector) . ':' . base64_encode($packed);
        $json = json_encode($values, JSON_THROW_ON_ERROR);
        return strlen($compact) < strlen($json) ? $compact : $json;
    }

    /** Decode compact values and historical JSON rows through one read path. */
    public static function decode(string $stored, ?int $expectedDimension = null): ?array {
        if ($stored === '' || strlen($stored) > 524288) return null;
        if (str_starts_with($stored, self::PREFIX)) {
            if (preg_match('/^eva64:([1-9][0-9]{0,4}):([A-Za-z0-9+\/=]+)$/D', $stored, $matches) !== 1) return null;
            $dimension = (int)$matches[1];
            if ($dimension > self::MAX_DIMENSION || ($expectedDimension !== null && $dimension !== $expectedDimension)) return null;
            $packed = base64_decode($matches[2], true);
            if (!is_string($packed) || strlen($packed) !== $dimension * 8 || base64_encode($packed) !== $matches[2]) return null;
            $decoded = unpack('E*', $packed);
            if (!is_array($decoded) || count($decoded) !== $dimension) return null;
            $vector = array_values($decoded);
        } else {
            try { $vector = json_decode($stored, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { return null; }
            if (!is_array($vector) || !array_is_list($vector)) return null;
        }

        if ($vector === [] || count($vector) > self::MAX_DIMENSION
            || ($expectedDimension !== null && count($vector) !== $expectedDimension)) return null;
        foreach ($vector as $value) {
            if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) return null;
        }
        return $vector;
    }
}
