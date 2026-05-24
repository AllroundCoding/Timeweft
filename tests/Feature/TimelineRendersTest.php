<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimelineRendersTest extends TestCase
{
    public function test_the_timeline_route_renders_the_gantt_for_a_seeded_run(): void
    {
        // No built front-end in the test/CI environment — stub the Vite directive.
        $this->withoutVite();

        $response = $this->get('/?seed=vaeris&years=2&population=6');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Timeline')
            ->where('run.seed', 'vaeris')
            ->where('run.years', 2)
            ->where('run.population', 6)
            ->has('axis')
            ->has('world')
            ->has('milestones')
            ->where('counts.total', fn (int $total): bool => $total >= 6)
            ->has('lives', fn (Assert $lives) => $lives
                ->each(fn (Assert $life) => $life
                    ->has('id')
                    ->has('name')
                    ->has('birthTick')
                    ->has('events')
                    ->etc()
                )
            )
        );
    }
}
