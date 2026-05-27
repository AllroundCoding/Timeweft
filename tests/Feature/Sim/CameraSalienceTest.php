<?php

namespace Tests\Feature\Sim;

use App\Sim\Play\CameraSalience;
use App\Sim\Play\Viewport;
use App\Sim\Support\Rng;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-285 — the camera is the LOD salience source: the settlements within the view become salient, those
 * outside fall away. A pure projection from a viewport to {@see World::$salient}; a headless run has no
 * camera so salience stays empty and the canonical run is byte-identical (the hash gate pins that). This
 * is the deterministic tie under the zoomable view; the React rendering itself fans out to a child.
 */
class CameraSalienceTest extends TestCase
{
    private function world(): World
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 6); // Sunwell Oasis at the origin
        $world->foundVillage('Eastwatch', 6, x: 100.0, y: 0.0);
        $world->foundVillage('Westhold', 6, x: -100.0, y: 0.0);

        return $world;
    }

    public function test_the_camera_marks_what_it_sees_salient(): void
    {
        $world = $this->world();

        $salient = CameraSalience::focus($world, Viewport::around(100.0, 0.0, 20.0));

        $this->assertContains('Eastwatch', $salient, 'the settlement in view is salient');
        $this->assertNotContains('Westhold', $salient, 'one out of view is not');
        $this->assertSame(array_keys($world->salient), $salient, 'world salience matches the returned focus');
    }

    public function test_zooming_out_brings_the_whole_world_into_view(): void
    {
        $world = $this->world();

        $salient = CameraSalience::focus($world, Viewport::around(0.0, 0.0, 1_000.0));

        $this->assertContains('Eastwatch', $salient);
        $this->assertContains('Westhold', $salient);
        $this->assertContains('Sunwell Oasis', $salient);
    }

    public function test_refocusing_replaces_the_prior_focus(): void
    {
        $world = $this->world();

        CameraSalience::focus($world, Viewport::around(100.0, 0.0, 20.0)); // Eastwatch
        $salient = CameraSalience::focus($world, Viewport::around(-100.0, 0.0, 20.0)); // pan to Westhold

        $this->assertContains('Westhold', $salient);
        $this->assertNotContains('Eastwatch', $salient, 'the camera moved on');
    }

    public function test_a_viewport_contains_only_what_is_inside_it(): void
    {
        $view = Viewport::around(0.0, 0.0, 10.0);

        $this->assertTrue($view->contains(5.0, -5.0));
        $this->assertTrue($view->contains(10.0, 10.0), 'the edge is inside');
        $this->assertFalse($view->contains(50.0, 0.0));
    }
}
