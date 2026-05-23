<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Culture\Culture;
use App\Sim\Economy\Stockpile;
use App\Sim\Institutions\Institution;
use App\Sim\Institutions\InstitutionEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-116: an institution must be afforded. Upkeep is drawn from the granary, but a settlement too poor
 * to feed its institution lets it wither — unpaid upkeep costs standing on top of ossification, so an
 * institution can fall to insolvency as well as to age (Tainter: complexity shed when its cost outruns
 * its return). A fed institution is unaffected, so a viable seed run is unchanged.
 */
class InstitutionUpkeepTest extends TestCase
{
    public function test_a_fed_institution_pays_its_upkeep_and_only_ages(): void
    {
        $world = $this->worldWithInstitution(effectiveness: 1.0, food: 1_000.0);
        $this->turnOfYear($world);

        $this->assertEqualsWithDelta(0.9, $world->village->institution?->effectiveness ?? 0.0, 1e-9, 'a fed institution only ossifies');
        $this->assertEqualsWithDelta(950.0, $world->village->stockpile->amount('food'), 1e-9, 'and pays its full upkeep');
    }

    public function test_unpaid_upkeep_withers_an_institution_faster_than_age_alone(): void
    {
        $world = $this->worldWithInstitution(effectiveness: 1.0, food: 0.0); // a bare granary
        $this->turnOfYear($world);

        // ossification 0.1, then the full insolvency penalty 0.2 → 0.7, below the fed institution's 0.9.
        $this->assertEqualsWithDelta(0.7, $world->village->institution?->effectiveness ?? 0.0, 1e-9, 'a settlement too poor to feed it lets it wither');
    }

    public function test_insolvency_can_drive_a_collapse_age_alone_would_not(): void
    {
        $world = $this->worldWithInstitution(effectiveness: 0.55, food: 0.0);
        $this->turnOfYear($world);

        $this->assertNull($world->village->institution, 'starved of upkeep, the institution is shed');
        $collapse = $this->collapse($world);
        $this->assertNotNull($collapse);
        $this->assertContains('insolvency', $collapse->factors, 'the fall is attributed to insolvency, not age');
        $this->assertStringContainsString('collapses', $collapse->text);
        $this->assertStringContainsString('too poor', $collapse->text);
        $this->assertContains(7, $collapse->causes, 'and cites the founding it undoes (TWT-27)');
    }

    public function test_a_fed_institution_still_falls_to_ossification(): void
    {
        $world = $this->worldWithInstitution(effectiveness: 0.45, food: 1_000.0);
        $this->turnOfYear($world);

        $this->assertNull($world->village->institution, 'age alone still topples a fed institution');
        $collapse = $this->collapse($world);
        $this->assertNotNull($collapse);
        $this->assertContains('ossification', $collapse->factors, 'a fed collapse is still ossification');
        $this->assertStringContainsString('ossified', $collapse->text);
    }

    private function worldWithInstitution(float $effectiveness, float $food): World
    {
        $world = new World(new Rng('upkeep'));
        $village = new Village('Holdfast', 'Tharados');
        $village->stockpile = new Stockpile(['food' => $food]);
        $institution = Institution::emergeFor(Culture::tharados(), 0);
        $institution->effectiveness = $effectiveness;
        $village->institution = $institution;
        $village->institutionEventId = 7; // a founding event for the collapse to cite
        $world->village = $village;
        $world->villages = [$village];

        return $world;
    }

    private function turnOfYear(World $world): void
    {
        $tick = (int) (TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR);
        InstitutionEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));
    }

    private function collapse(World $world): ?ChronicleEvent
    {
        foreach ($world->chronicle->all() as $event) {
            if ($event->type === 'institution-collapsed') {
                return $event;
            }
        }

        return null;
    }
}
