<?php

namespace Tests\Feature\Sim;

use App\Sim\Behavior\Activity;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Persistence\Skeleton;
use App\Sim\Persistence\Texture;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-31: the skeleton/texture boundary (design doc 01) made explicit in code — sparse canonical state
 * that must be persisted vs dense detail that can be re-derived — so persistence (TWT-28/30) and
 * derive-on-demand (TWT-38) have an unambiguous seam to work against.
 */
class SkeletonTextureTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_canonical_events_are_skeleton_and_derived_detail_is_texture(): void
    {
        $event = new ChronicleEvent(1, 0, 'birth', 'someone is born');
        $this->assertInstanceOf(Skeleton::class, $event, 'the chronicle is the canonical timeline');
        $this->assertNotInstanceOf(Texture::class, $event);

        $this->assertInstanceOf(Texture::class, Activity::Working, 'what an agent is doing is derived');
        $this->assertNotInstanceOf(Skeleton::class, Activity::Working);
    }

    public function test_the_world_skeleton_captures_the_canonical_persistable_state(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 6);
        $world->advance(self::TICKS_PER_YEAR * 2);

        $skeleton = $world->skeleton();

        $this->assertInstanceOf(Skeleton::class, $skeleton);
        $this->assertSame((new Rng('vaeris'))->seed(), $skeleton->seed, 'the seed it reproduces from');
        $this->assertSame($world->tick, $skeleton->tick, 'the canonical clock');
        $this->assertNotEmpty($skeleton->chronicle, 'two years of history leaves canonical events');
        $this->assertContainsOnlyInstancesOf(ChronicleEvent::class, $skeleton->chronicle);
        $this->assertSame($world->villages, $skeleton->villages, 'the path-dependent settlements');
    }

    public function test_reading_the_skeleton_does_not_perturb_the_run(): void
    {
        // Observing the skeleton mid-run must draw no RNG and mutate nothing — same seed, same chronicle.
        $observed = World::seedTharadosVillage(new Rng('vaeris'), 6);
        $observed->advance(self::TICKS_PER_YEAR);
        $observed->skeleton();
        $observed->advance(self::TICKS_PER_YEAR);

        $untouched = World::seedTharadosVillage(new Rng('vaeris'), 6);
        $untouched->advance(self::TICKS_PER_YEAR * 2);

        $this->assertSame(
            array_map(static fn (ChronicleEvent $e): string => $e->text, $untouched->chronicle->all()),
            array_map(static fn (ChronicleEvent $e): string => $e->text, $observed->chronicle->all()),
            'the skeleton is a pure view, not a mutation',
        );
    }
}
