<?php

namespace App\Sim\Causality;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;

/**
 * The headline trick (design doc 09): edit a past event and watch the
 * consequences ripple. The cone query (TWT-33) says *what* an edit can touch;
 * this *recomputes* it — replaying the seeded world with the edit applied and
 * diffing the result against the true history.
 *
 * Recompute here is a full deterministic re-run from genesis (correct, if not
 * yet incremental). Checkpoints (TWT-32) will later let it resume from just
 * before the edit instead of from the beginning.
 */
final class RetroactiveRipple
{
    /**
     * Replay the given world with an edit applied and return what diverges from the true history.
     */
    public static function replay(string $seed, int $population, int $years, Intervention $edit): RippleResult
    {
        $trueHistory = self::run($seed, $population, $years, null);
        $counterfactual = self::run($seed, $population, $years, $edit);

        return RippleResult::between($trueHistory, $counterfactual);
    }

    /**
     * The events an edit to $eventId *would* invalidate, read straight from the recorded
     * causal graph (no replay) — its declared downstream cone, including the event itself.
     *
     * @param  list<ChronicleEvent>  $trueHistory
     * @return list<ChronicleEvent>
     */
    public static function declaredConeOf(array $trueHistory, int $eventId): array
    {
        $graph = new CausalGraph($trueHistory);
        $cone = $graph->downstreamCone($eventId);

        return $graph->events([$eventId, ...$cone]);
    }

    /** @return list<ChronicleEvent> */
    private static function run(string $seed, int $population, int $years, ?Intervention $edit): array
    {
        $world = World::seedTharadosVillage(new Rng($seed), $population);
        $world->intervention = $edit;
        $world->advance(TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR * $years);

        return $world->chronicle->all();
    }
}
