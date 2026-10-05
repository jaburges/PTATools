<?php
/**
 * Embedding vector helpers. Vectors are unit-normalised before storage so
 * cosine similarity is a plain dot product, and stored as base64 packed
 * float32 (about 2.7 KB each at 512 dimensions).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Vectors {

    // text-embedding-3 models accept a shorter output; 512 keeps a few
    // hundred posts' vectors small enough to score in one PHP request.
    const DIMENSIONS = 512;

    public static function normalize(array $v) {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        if ($sum <= 0) {
            return $v;
        }
        $len = sqrt($sum);
        foreach ($v as $i => $x) {
            $v[$i] = $x / $len;
        }
        return $v;
    }

    public static function pack(array $v) {
        return base64_encode(pack('g*', ...array_map('floatval', array_values($v))));
    }

    public static function unpack($packed) {
        $raw = base64_decode((string) $packed, true);
        if ($raw === false || $raw === '') {
            return array();
        }
        return array_values(unpack('g*', $raw));
    }

    public static function dot(array $a, array $b) {
        $n = min(count($a), count($b));
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }

    /**
     * IDs of the most similar vectors, best first.
     *
     * @param array $target     Normalised query vector.
     * @param array $candidates post_id => normalised vector.
     * @param int[] $exclude    IDs never returned.
     * @return array post_id => score
     */
    public static function nearest(array $target, array $candidates, $limit, array $exclude = array()) {
        $skip = array_flip(array_map('intval', $exclude));
        $scores = array();
        foreach ($candidates as $id => $vector) {
            if (isset($skip[(int) $id]) || empty($vector)) {
                continue;
            }
            $scores[(int) $id] = self::dot($target, $vector);
        }
        arsort($scores);
        return array_slice($scores, 0, max(0, (int) $limit), true);
    }
}
