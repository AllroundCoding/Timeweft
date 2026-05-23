<?php

namespace App\Sim\Support;

/**
 * Culture-aware name generation by character n-gram (design docs 03 + 11; TWT-74). Rather than a
 * brittle phoneme table, each culture carries a small exemplar corpus that "sounds right" for its
 * region, and an order-k Markov chain over characters learns the feel and coins *fresh* names that
 * resemble the exemplars without enumerating them. Order 2 is the gibberish↔plagiarism sweet spot.
 *
 * Stateless w.r.t. the simulation: a name is a pure function of the sub-stream it is handed (keyed
 * per-agent, `stream('name', id)`) and the culture, so it never perturbs the seeded births/deaths
 * (TWT-118) — and a culture's corpus can change without touching the emergent skeleton.
 *
 * The corpora's sounds are drawn from the Vaeris canon naming conventions: Tharadi is guttural and
 * back-consonant (Arabic-ish), Aetherian is melodic and vowel-balanced (Latinate), Draknar is hard
 * and clustered (Norse-ish) — so two cultures coin visibly different names.
 */
final class NameGenerator
{
    private const START = "\x02";

    private const END = "\x03";

    /** @var array<string,array<string,list<string>>> trained models, lazily built per culture */
    private array $models = [];

    /** @param  array<string,list<string>>  $corpora  culture name => exemplar names that sound right */
    public function __construct(private readonly array $corpora, private readonly int $order = 2) {}

    /** The Vaeris cultures the engine births today, with a corpus apiece grounded in the canon. */
    public static function vaeris(): self
    {
        return new self([
            // Tharadi — guttural, sun-baked, back consonants (q, kh, gh, h) and the glottal stop.
            'Tharadi' => [
                'Azrak', 'Jasara', 'Marraka', 'Qaran', "Ra'an", 'Nara', 'Zarak', 'Lunara', 'Tahir', 'Karim',
                'Amal', 'Layali', 'Sayara', 'Kalim', 'Jarek', 'Varis', 'Mirah', 'Khalida', 'Qadira', 'Rashida',
                'Tariq', 'Zahra', 'Farida', 'Halima', 'Najwa', 'Samira', 'Bashir', 'Hakim', 'Nadir', 'Qasim',
                'Rahim', 'Sahar', 'Thara', 'Zaida', 'Imruzar', 'Razali', 'Naran', 'Khadir', 'Ghassan', 'Qamar',
                'Marwan', "Za'id", 'Saif', 'Zarakon',
            ],
            // Aetherian — Latinate, ordered, melodic; clear vowels, soft consonants, -is/-or/-ir endings.
            'Aetherian' => [
                'Arandor', 'Reanne', 'Selene', 'Vidoris', 'Thaloria', 'Cinara', 'Aurelia', 'Cassian', 'Lucan', 'Marius',
                'Selena', 'Valeria', 'Cyrene', 'Lorin', 'Aderyn', 'Elara', 'Vidar', 'Lyrin', 'Corvin', 'Severin',
                'Tavian', 'Rosalind', 'Celestine', 'Florian', 'Octavia', 'Lavinia', 'Sabine', 'Theron', 'Verena', 'Aldric',
                'Emeric', 'Mirelle', 'Sennan', 'Aeris', 'Ignathor', 'Aerisun', 'Cassia', 'Liora', 'Sorin', 'Aurelian',
                'Caelum', 'Tessaly',
            ],
            // Draknar — Norse, hard, blunt; consonant clusters (st, kj, thr, rk), endings -ar/-ith/-fel.
            'Draknar' => [
                'Kharad', 'Skadi', 'Bjorn', 'Fenrir', 'Skadimar', "Tho'rak", "Va'la", 'Ragnar', 'Astrid', 'Stenar',
                'Ulfar', 'Gunnar', 'Brynja', 'Sigurd', 'Halvar', 'Torvald', 'Eirik', 'Skarn', 'Thrain', 'Grimar',
                'Bjorg', 'Kethil', 'Rurik', 'Yngvar', 'Hadrith', 'Frostfel', 'Kjell', 'Sten', 'Vidarth', 'Brand',
            ],
        ]);
    }

    /**
     * Coin a name for a culture, drawing every choice from the supplied sub-stream. An unknown culture
     * falls back to the first registered corpus (deterministic) — generating place/culture names and
     * removing that fallback for generated worlds is TWT-120's job.
     */
    public function name(Rng $rng, string $culture): string
    {
        $model = $this->modelFor($culture);
        $context = str_repeat(self::START, $this->order);
        $out = '';

        for ($i = 0; $i < 24; $i++) { // a hard cap guards against a runaway walk
            $next = $model[$context] ?? null;
            if ($next === null) {
                break;
            }
            $char = $rng->pick($next);
            if ($char === self::END) {
                break;
            }
            $out .= $char;
            $context = substr($context.$char, -$this->order);
        }

        return $out === '' ? '' : ucfirst($out);
    }

    /** @return array<string,list<string>> the trained transition table for a culture (context => next chars) */
    private function modelFor(string $culture): array
    {
        $key = isset($this->corpora[$culture]) ? $culture : array_key_first($this->corpora);

        return $this->models[$key] ??= $this->train($this->corpora[$key]);
    }

    /**
     * Train an order-k character model: for each padded exemplar, record which character follows each
     * k-character context. Frequencies are encoded by repetition, so picking uniformly is weighted.
     *
     * @param  list<string>  $corpus
     * @return array<string,list<string>>
     */
    private function train(array $corpus): array
    {
        $table = [];
        foreach ($corpus as $name) {
            $padded = str_repeat(self::START, $this->order).strtolower($name).self::END;
            $length = strlen($padded);
            for ($i = $this->order; $i < $length; $i++) {
                $table[substr($padded, $i - $this->order, $this->order)][] = $padded[$i];
            }
        }

        return $table;
    }
}
