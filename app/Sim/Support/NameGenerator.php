<?php

namespace App\Sim\Support;

/**
 * Culture-aware name generation by character n-gram (design docs 03 + 11; TWT-74/120). Rather than a
 * brittle phoneme table, each culture carries small exemplar corpora that "sound right" for its
 * region — one of people, one of places — and an order-k Markov chain over characters learns the feel
 * and coins *fresh* names that resemble the exemplars without enumerating them. Order 2 is the
 * gibberish↔plagiarism sweet spot.
 *
 * Stateless w.r.t. the simulation: a name is a pure function of the sub-stream it is handed (keyed
 * per-entity, `stream('name', id)` / `stream('placename', n)`) and the culture, so it never perturbs
 * the seeded births/deaths (TWT-118) — and a culture's corpus can change without touching emergence.
 *
 * The corpora's sounds are drawn from the Vaeris canon naming conventions: Tharadi is guttural and
 * back-consonant (Arabic-ish), Aetherian is melodic and vowel-balanced (Latinate), Draknar is hard
 * and clustered (Norse-ish) — so two cultures coin visibly different people and places.
 */
final class NameGenerator
{
    private const START = "\x02";

    private const END = "\x03";

    /** @var array<string,array<string,list<string>>> trained models, lazily built per register:culture */
    private array $models = [];

    /**
     * @param  array<string,list<string>>  $corpora  culture => exemplar person names
     * @param  array<string,list<string>>  $placeCorpora  culture => exemplar place names
     */
    public function __construct(
        private readonly array $corpora,
        private readonly array $placeCorpora = [],
        private readonly int $order = 2,
    ) {}

    /** The Vaeris cultures the engine births today, with person + place corpora apiece, grounded in the canon. */
    public static function vaeris(): self
    {
        return new self(
            corpora: [
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
            ],
            placeCorpora: [
                // Tharadi places — oases, holds, and mineral towns of the deep desert.
                'Tharadi' => [
                    'Azrak', 'Marraka', 'Jasara', 'Sayara', 'Zharkhan', 'Khanzar', 'Tahara', 'Qadira', 'Mirak', 'Sundaz',
                    'Kharaz', 'Razin', 'Qaranis', 'Naqab', 'Suwar', 'Imruzar',
                ],
                // Aetherian places — vowel-balanced kingdoms, groves, and river towns.
                'Aetherian' => [
                    'Thaloria', 'Eldergrove', 'Cinara', 'Veresh', 'Lakarin', 'Avalor', 'Lorien', 'Veremont', 'Selvana', 'Cyrene',
                    'Thalor', 'Aurevia', 'Lumina', 'Caelora', 'Aetheria',
                ],
                // Draknar places — stark, compounded holds of a hard land.
                'Draknar' => [
                    'Stormhold', 'Frostfall', 'Shadowpeak', 'Skadimar', 'Ironpeak', 'Stoneclaw', 'Bloodfjord', 'Grimhold', 'Wolfden', 'Drakfell',
                    'Skarnholm', 'Thorngard', 'Bjornfell', 'Hragnar', 'Kjeldun',
                ],
            ],
        );
    }

    /**
     * Coin a person's name for a culture, drawing every choice from the supplied sub-stream. An unknown
     * culture falls back to the first registered corpus (deterministic).
     */
    public function name(Rng $rng, string $culture): string
    {
        return $this->coin($rng, $this->modelFor('person', $this->corpora, $culture));
    }

    /** Coin a place name (settlement, region) for a culture — the toponym half of the same machinery. */
    public function place(Rng $rng, string $culture): string
    {
        return $this->coin($rng, $this->modelFor('place', $this->placeCorpora, $culture));
    }

    /** Walk the model from the start context, picking weighted transitions until the end marker. */
    private function coin(Rng $rng, array $model): string
    {
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

    /**
     * The trained transition table for a register (person/place) × culture, built once and cached.
     *
     * @param  array<string,list<string>>  $corpora
     * @return array<string,list<string>>
     */
    private function modelFor(string $register, array $corpora, string $culture): array
    {
        $key = isset($corpora[$culture]) ? $culture : (string) array_key_first($corpora);

        return $this->models["{$register}:{$key}"] ??= $this->train($corpora[$key]);
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
