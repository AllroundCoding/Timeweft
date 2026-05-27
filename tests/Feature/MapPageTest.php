<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The play surface (TWT-285/275): the /map route projects a generated world onto the hex grid and serves
 * it to the React view. A read-only projection — it never touches the canonical sim.
 */
class MapPageTest extends TestCase
{
    public function test_it_renders_the_world_map(): void
    {
        $this->get('/map?seed=vaeris&cols=24&rows=16&width=60&height=40&plates=8')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Map')
                ->where('run.seed', 'vaeris')
                ->where('run.cols', 24)
                ->has('hexes', 24 * 16, fn (Assert $hex) => $hex
                    ->has('q')
                    ->has('r')
                    ->has('biome')
                    ->has('land')
                    ->has('river')
                    ->has('lake')
                    ->has('elevation'))
                ->has('settlements'));
    }
}
