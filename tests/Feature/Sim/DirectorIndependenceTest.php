<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Direction\Milestone;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-107: each concern draws from its own keyed sub-stream, so authoring a
 * story beat (which makes the director draw) never reshuffles the emergent
 * world. This is the property TWT-39 ran into the lack of — and the foundation
 * for legible ripple and reproducible parallel work (design docs 09, 18).
 */
class DirectorIndependenceTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    private const EMERGENCE = ['pairing', 'birth', 'death'];

    public function test_authoring_a_milestone_does_not_perturb_the_emergent_world(): void
    {
        $plain = $this->emergence(withExtraBeat: false);
        $authored = $this->emergence(withExtraBeat: true);

        $this->assertNotEmpty($plain, 'the seeded run produces births and deaths');
        $this->assertSame($plain, $authored, 'adding a story beat leaves every birth, death and pairing identical');
    }

    /**
     * The emergence events (pairings, births, deaths) of a seeded run, optionally with an extra
     * milestone that makes the director draw heavily (prereqPopulation 0 → it evaluates every day).
     *
     * @return list<string>
     */
    private function emergence(bool $withExtraBeat): array
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        if ($withExtraBeat) {
            $world->milestones[] = new Milestone(name: 'a grand temple to Nara', deadlineYear: 999, prereqPopulation: 0);
        }
        $world->advance(self::TICKS_PER_YEAR * 30);

        return array_values(array_map(
            static fn (ChronicleEvent $e): string => $e->text,
            array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => in_array($e->type, self::EMERGENCE, true)),
        ));
    }
}
