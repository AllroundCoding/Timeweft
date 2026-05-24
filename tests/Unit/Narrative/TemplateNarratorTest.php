<?php

namespace Tests\Unit\Narrative;

use App\Narrative\Saga;
use App\Narrative\TemplateNarrator;
use PHPUnit\Framework\TestCase;

class TemplateNarratorTest extends TestCase
{
    private function saga(): Saga
    {
        return new Saga(
            world: 'Testhaven',
            region: 'Verdant Reach',
            seed: 'unit',
            startYear: 1,
            endYear: 3,
            events: [
                ['year' => 1, 'text' => '1 Naralis, Year 1 — Aza and Bel become partners.', 'type' => 'pairing'],
                ['year' => 2, 'text' => '5 Varith, Year 2 — Cyra is born to Aza and Bel.', 'type' => 'birth'],
                ['year' => 3, 'text' => '9 Kalimos, Year 3 — Bel dies at age 61.', 'type' => 'death'],
            ],
            population: ['founders' => 2, 'born' => 1, 'died' => 1, 'living' => 2],
        );
    }

    public function test_it_opens_with_the_settlement_and_population(): void
    {
        $prose = (new TemplateNarrator)->retell($this->saga());

        $this->assertStringContainsString('Testhaven', $prose);
        $this->assertStringContainsString('Verdant Reach', $prose);
        $this->assertStringContainsString('2 founders', $prose);
    }

    public function test_it_groups_events_by_year_and_strips_the_in_world_date(): void
    {
        $prose = (new TemplateNarrator)->retell($this->saga());

        $this->assertStringContainsString('Year 1 — Aza and Bel become partners.', $prose);
        $this->assertStringContainsString('Year 2 — Cyra is born to Aza and Bel.', $prose);
        // The "1 Naralis, Year 1 —" date prefix is dropped; the year heading carries the time.
        $this->assertStringNotContainsString('Naralis', $prose);
    }

    public function test_it_is_deterministic_for_a_saga(): void
    {
        $narrator = new TemplateNarrator;

        $this->assertSame($narrator->retell($this->saga()), $narrator->retell($this->saga()));
    }
}
