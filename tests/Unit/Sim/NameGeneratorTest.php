<?php

namespace Tests\Unit\Sim;

use App\Sim\Support\NameGenerator;
use App\Sim\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * TWT-74: names are generated from a per-culture character n-gram trained on a small exemplar corpus,
 * so they sound like they belong to the culture, differ visibly between cultures, and are a pure
 * deterministic function of the sub-stream they are handed (so they never perturb the seeded run).
 */
class NameGeneratorTest extends TestCase
{
    public function test_a_name_stays_within_its_culture_alphabet(): void
    {
        // The Markov property: a coined name only uses character transitions seen in the corpus, so it
        // can never reach for "sounds the region doesn't" (the canon's first rule of a fitting name).
        $generator = new NameGenerator(['Test' => ['kala', 'karan', 'kalim', 'narak', 'malik']]);
        $alphabet = ['k', 'a', 'l', 'r', 'n', 'm', 'i'];

        for ($i = 1; $i <= 50; $i++) {
            $name = $generator->name((new Rng('seed'))->stream('name', $i), 'Test');
            $this->assertNotSame('', $name);
            $this->assertSame(ucfirst($name), $name, 'a name is capitalised');
            foreach (str_split(strtolower($name)) as $char) {
                $this->assertContains($char, $alphabet, "'{$char}' is not in the culture's sounds");
            }
        }
    }

    public function test_a_name_is_a_deterministic_function_of_its_substream(): void
    {
        $generator = NameGenerator::vaeris();

        $first = $generator->name((new Rng('vaeris'))->stream('name', 42), 'Tharadi');
        $second = $generator->name((new Rng('vaeris'))->stream('name', 42), 'Tharadi');

        $this->assertSame($first, $second, 'same seed + agent + culture → same name');
        $this->assertNotSame('', $first);
    }

    public function test_two_cultures_coin_visibly_different_names(): void
    {
        $generator = NameGenerator::vaeris();

        $aetherian = $this->batch($generator, 'Aetherian');
        $draknar = $this->batch($generator, 'Draknar');

        $this->assertNotSame($aetherian, $draknar, 'the cultures produce different names');

        // Melodic Aetherian runs more vowel-rich than hard, clustered Draknar.
        $this->assertGreaterThan($this->meanVowelRatio($draknar), $this->meanVowelRatio($aetherian), 'Aetherian is the more melodic');

        // Sound inventory is culture-specific: the Aetherian corpus has no 'q', so it never coins one.
        foreach ($aetherian as $name) {
            $this->assertStringNotContainsStringIgnoringCase('q', $name, 'Aetherian has no q in its sounds');
        }
    }

    public function test_place_names_are_culture_styled_and_deterministic(): void
    {
        $generator = NameGenerator::vaeris();

        // Deterministic off the place sub-stream.
        $first = $generator->place((new Rng('vaeris'))->stream('placename', 3), 'Tharadi');
        $second = $generator->place((new Rng('vaeris'))->stream('placename', 3), 'Tharadi');
        $this->assertSame($first, $second);
        $this->assertNotSame('', $first);

        // Two cultures coin different places, just as they coin different people.
        $tharadi = [];
        $draknar = [];
        for ($i = 1; $i <= 12; $i++) {
            $tharadi[] = $generator->place((new Rng('vaeris'))->stream('placename', $i), 'Tharadi');
            $draknar[] = $generator->place((new Rng('vaeris'))->stream('placename', $i), 'Draknar');
        }
        $this->assertNotSame($tharadi, $draknar, 'a desert oasis does not sound like a frost-hold');
    }

    /** @return list<string> */
    private function batch(NameGenerator $generator, string $culture): array
    {
        $names = [];
        for ($i = 1; $i <= 30; $i++) {
            $names[] = $generator->name((new Rng('vaeris'))->stream('name', $i), $culture);
        }

        return $names;
    }

    /** @param list<string> $names */
    private function meanVowelRatio(array $names): float
    {
        $sum = 0.0;
        foreach ($names as $name) {
            $sum += strlen($name) > 0 ? preg_match_all('/[aeiou]/i', $name) / strlen($name) : 0.0;
        }

        return $sum / max(1, count($names));
    }
}
