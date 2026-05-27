<?php

namespace Tests\Feature\Sim;

use Tests\TestCase;

/**
 * TWT-275 — the `world:hexmap` preview projects the procedural worldgen onto a hex grid and renders it.
 * A boundary view over the deterministic projection; additive, so the seeded run is untouched.
 */
class WorldHexmapCommandTest extends TestCase
{
    public function test_it_previews_a_hex_map(): void
    {
        $this->artisan('world:hexmap', [
            '--cols' => 20,
            '--rows' => 10,
            '--width' => 60,
            '--height' => 40,
            '--plates' => 8,
            '--seed' => 'vaeris',
        ])
            ->expectsOutputToContain('Hex map')
            ->expectsOutputToContain('Legend')
            ->assertSuccessful();
    }
}
