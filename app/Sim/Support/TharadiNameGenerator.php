<?php

namespace App\Sim\Support;

/** Tiny syllable generator producing names consistent with Tharadi phonology. */
final class TharadiNameGenerator
{
    private const ONSETS = ['k', 't', 'd', 'n', 'm', 'r', 's', 'z', 'j', 'kh', 'sh', 'q', 'l', 'b', 'g', 'f', 'v'];
    private const NUCLEI = ['a', 'a', 'i', 'i', 'e', 'o', 'u'];
    private const CODAS = ['', '', '', 'n', 'r', 'k', 's', 'l', 'm', 'th'];

    public function __construct(private readonly Rng $rng) {}

    public function name(): string
    {
        $syllables = $this->rng->int(2, 3);
        $out = '';
        for ($i = 0; $i < $syllables; $i++) {
            $out .= $this->rng->pick(self::ONSETS) . $this->rng->pick(self::NUCLEI);
            if ($i === $syllables - 1) {
                $out .= $this->rng->pick(self::CODAS);
            }
        }

        return ucfirst($out);
    }
}
