<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The play surface (TWT-285): the /map route projects a generated world's continuous terrain (one char
 * per cell) and a coarse hex grid for the management zoom (one char per hex) to the React view. A
 * read-only projection — it never touches the canonical sim.
 */
class MapPageTest extends TestCase
{
    public function test_it_renders_the_world_terrain_and_hex_grid(): void
    {
        $this->get('/map?seed=vaeris&width=60&height=40&plates=8')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Map')
                ->where('run.seed', 'vaeris')
                ->where('width', 60)
                ->where('height', 40)
                ->has('rows', 40)
                ->where('hex.cols', 20)
                ->where('hex.rows', 13)
                ->has('hex.cells', 13)
                ->has('settlements'));
    }
}
