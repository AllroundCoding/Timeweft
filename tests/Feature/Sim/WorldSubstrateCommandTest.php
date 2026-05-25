<?php

namespace Tests\Feature\Sim;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * TWT-261 — the substrate preview command. A throwaway-grade way to eyeball worldgen (TWT-130): it
 * renders a deterministic colored elevation PNG and prints a summary, so a generated world can be
 * sanity-checked before the full map view (TWT-134). No coupling into the live sim.
 */
class WorldSubstrateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/testing/substrate-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_it_renders_a_substrate_png_and_console_preview(): void
    {
        $out = $this->dir.'/world.png';

        $this->artisan('world:substrate', [
            '--seed' => 'vaeris',
            '--width' => 40,
            '--height' => 30,
            '--plates' => 8,
            '--cell' => 3,
            '--out' => $out,
        ])
            ->expectsOutputToContain('seed "vaeris"')
            ->expectsOutputToContain('land')
            ->assertExitCode(0);

        $this->assertFileExists($out);

        $info = getimagesize($out);
        $this->assertNotFalse($info, 'the output is a readable image');
        $this->assertSame(IMAGETYPE_PNG, $info[2], 'the output is a PNG');
        $this->assertSame(40 * 3, $info[0], 'image width = grid columns × cell size');
        $this->assertSame(30 * 3, $info[1], 'image height = grid rows × cell size');
    }

    public function test_same_seed_renders_a_byte_identical_image(): void
    {
        $first = $this->dir.'/a.png';
        $second = $this->dir.'/b.png';
        $args = ['--seed' => 'tharados', '--width' => 32, '--height' => 24, '--plates' => 6, '--cell' => 2];

        $this->artisan('world:substrate', $args + ['--out' => $first])->assertExitCode(0);
        $this->artisan('world:substrate', $args + ['--out' => $second])->assertExitCode(0);

        $this->assertFileEquals($first, $second, 'same seed → the same world, pixel for pixel');
    }
}
