<?php

namespace App\Sim\Support;

/** Tiny syllable generator producing names consistent with Tharadi phonology. */
final class TharadiNameGenerator
{
    private const ONSETS = ['k', 't', 'd', 'n', 'm', 'r', 's', 'z', 'j', 'kh', 'sh', 'q', 'l', 'b', 'g', 'f', 'v'];

    private const NUCLEI = ['a', 'a', 'i', 'i', 'e', 'o', 'u'];

    private const CODAS = ['', '', '', 'n', 'r', 'k', 's', 'l', 'm', 'th'];

    /**
     * Draw a name from a caller-supplied sub-stream. The generator is stateless: a name is a pure
     * function of whichever stream is passed (keyed per-agent, `stream('name', id)`), so it never
     * consumes from the emergence stream — and the algorithm can change without perturbing the
     * seeded births and deaths (TWT-118, and the seam TWT-74 swaps the corpus-driven generator into).
     */
    public function name(Rng $rng): string
    {
        $syllables = $rng->int(2, 3);
        $out = '';
        for ($i = 0; $i < $syllables; $i++) {
            $out .= $rng->pick(self::ONSETS).$rng->pick(self::NUCLEI);
            if ($i === $syllables - 1) {
                $out .= $rng->pick(self::CODAS);
            }
        }

        return ucfirst($out);
    }
}
