<?php

namespace Tests\Feature\Sim;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * TWT-132 — the climate preview command. Renders a chosen climate layer (biome, temperature, rainfall,
 * fertility) as a deterministic PNG, so a generated world's climate can be eyeballed. No coupling into
 * the live sim.
 */
class WorldClimateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/testing/climate-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_it_renders_a_biome_map_and_console_summary(): void
    {
        $out = $this->dir.'/biome.png';

        $this->artisan('world:climate', [
            '--seed' => 'vaeris',
            '--width' => 48,
            '--height' => 30,
            '--plates' => 8,
            '--cell' => 3,
            '--out' => $out,
        ])
            ->expectsOutputToContain('seed "vaeris"')
            ->expectsOutputToContain('biomes')
            ->assertExitCode(0);

        $this->assertFileExists($out);
        $info = getimagesize($out);
        $this->assertNotFalse($info, 'the output is a readable image');
        $this->assertSame(IMAGETYPE_PNG, $info[2], 'the output is a PNG');
        $this->assertSame(48 * 3, $info[0]);
        $this->assertSame(30 * 3, $info[1]);
    }

    public function test_each_layer_renders(): void
    {
        foreach (['temperature', 'precipitation', 'fertility'] as $layer) {
            $out = $this->dir.'/'.$layer.'.png';
            $this->artisan('world:climate', [
                '--seed' => 'tharados', '--width' => 32, '--height' => 24, '--plates' => 6, '--cell' => 2,
                '--layer' => $layer, '--out' => $out,
            ])->assertExitCode(0);
            $this->assertFileExists($out);
        }
    }

    public function test_an_unknown_layer_fails(): void
    {
        $this->artisan('world:climate', ['--layer' => 'volcanoes', '--out' => $this->dir.'/x.png'])
            ->assertExitCode(1);
    }

    public function test_same_seed_renders_a_byte_identical_image(): void
    {
        $first = $this->dir.'/a.png';
        $second = $this->dir.'/b.png';
        $args = ['--seed' => 'mirage', '--width' => 32, '--height' => 24, '--plates' => 6, '--cell' => 2, '--layer' => 'fertility'];

        $this->artisan('world:climate', $args + ['--out' => $first])->assertExitCode(0);
        $this->artisan('world:climate', $args + ['--out' => $second])->assertExitCode(0);

        $this->assertFileEquals($first, $second, 'same seed → the same climate, pixel for pixel');
    }

    public function test_it_overlays_water_by_default_and_hides_it_on_request(): void
    {
        $this->artisan('world:climate', [
            '--seed' => 'vaeris', '--width' => 64, '--height' => 40, '--plates' => 10, '--cell' => 2,
            '--out' => $this->dir.'/with-water.png',
        ])
            ->expectsOutputToContain('water')
            ->assertExitCode(0);
        $this->assertFileExists($this->dir.'/with-water.png');

        $this->artisan('world:climate', [
            '--seed' => 'vaeris', '--width' => 64, '--height' => 40, '--plates' => 10, '--cell' => 2,
            '--hide-water' => true, '--out' => $this->dir.'/dry.png',
        ])->assertExitCode(0);
        $this->assertFileExists($this->dir.'/dry.png');
    }
}
