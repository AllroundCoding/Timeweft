<?php

namespace App\Sim\World;

use App\Sim\Economy\JobMarket;
use App\Sim\Economy\JobRequest;
use App\Sim\Economy\Pricing;
use App\Sim\Time\TharadiDate;

/**
 * Agent-driven trade (design doc 16; TWT-99) — the bottom-up complement to the top-down trade routes of
 * {@see TradeEngine}. With needs posted and priced by the labor market ({@see JobMarket}, TWT-97), an
 * individual trader from a settlement with something to spare answers a *distant* settlement's shortfall.
 * "The village is starving; a trader arrives with grain." The trader is the actor: it earns the trade
 * (and a coin) and, doing it again and again, settles into a trader by trade (TWT-98).
 *
 * A trader sets out for one of two reasons:
 *   - profit (paid-to): the good is dear where it is short and cheap where it is plentiful, so a haul down
 *     that price gradient pays — once distance has taken its cut;
 *   - generosity (mutual aid, TWT-85, turned from an abstract dampening factor into an action): where there
 *     is no profit, the open-handed still help — so a settlement that cannot pay is not left to starve.
 *
 * World-level, evaluated daily, deterministic and RNG-free. It runs *after* bulk trade, so it reads only
 * the shortfall the routes left behind — and it can draw on the thin surplus bulk trade won't part with,
 * and give to those who can't pay, which routes never do. With fewer than two settlements there is nowhere
 * to carry anything, so it does nothing and draws no RNG — the single-settlement run stays byte-identical.
 */
final class CaravanEngine
{
    private const ADULT_AGE = 16;

    /** Days of a staple per head a settlement keeps for itself before a trader will carry any away. */
    private const SUBSISTENCE_DAYS = 8.0;

    /** The most days-per-head of the recipient's need a single caravan carries — an individual load, not a convoy. */
    private const CARAVAN_LOAD_DAYS = 3.0;

    /** Map units over which transit loss climbs to its cap — distance taxes a haul, as it does for routes. */
    private const LOSS_SCALE = 500.0;

    /** The most a long haul loses in transit. */
    private const MAX_TRANSIT_LOSS = 0.6;

    /** Willingness (profit or generosity, 0..1) a trader needs before setting out. */
    private const WILLINGNESS_THRESHOLD = 0.3;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if (count($world->villages) < 2) {
            return; // nowhere to carry anything
        }

        foreach ($world->villages as $needy) {
            $population = count($needy->livingAgents());
            if ($population === 0) {
                continue;
            }
            foreach (JobMarket::post($world, $needy, $population) as $job) {
                if ($job->good === null || $job->shortfall <= 0.0) {
                    continue; // only a shortfall of an actual good can be carried
                }
                self::dispatch($world, $needy, $job, $tick, $date);
            }
        }
    }

    /** Send the most-willing trader from the best supplier to answer one settlement's posted shortfall. */
    private static function dispatch(World $world, Village $needy, JobRequest $job, int $tick, TharadiDate $date): void
    {
        $good = (string) $job->good;
        $supplier = self::bestSupplier($world, $needy, $good);
        if ($supplier === null) {
            return; // no one within reach has anything to spare
        }

        $supplierPopulation = count($supplier->livingAgents());
        $base = self::baseValue($world, $good);
        $loss = self::transitLoss($supplier->distanceTo($needy));
        $supplierPrice = Pricing::localPrice($base, $supplier->stockpile->amount($good), $supplierPopulation);
        $profit = $job->price > 0.0 ? max(0.0, ($job->price - $supplierPrice) / $job->price - $loss) : 0.0;

        $adults = array_values(array_filter(
            $supplier->livingAgents(),
            static fn (Agent $a): bool => $a->ageInYears($tick) >= self::ADULT_AGE,
        ));
        $trader = self::chooseTrader($adults, $profit, $supplier);
        if ($trader === null) {
            return; // no profit worth the road, and no one open-handed enough to go without it
        }
        $aid = self::generosityOf($trader, $supplier) > $profit;

        $spare = $supplier->stockpile->amount($good) - self::SUBSISTENCE_DAYS * $supplierPopulation;
        $load = min(max(0.0, $spare), self::CARAVAN_LOAD_DAYS * count($needy->livingAgents()), $job->shortfall);
        if ($load <= 0.0) {
            return;
        }

        $shipped = $supplier->stockpile->withdraw($good, $load);
        if ($shipped <= 0.0) {
            return;
        }
        $delivered = $shipped * (1.0 - $loss);
        $needy->stockpile->add($good, $delivered);

        // Profit follows the delivered goods at the settlement's dear local price, capped at what the
        // buyer can pay; mutual aid asks nothing. The coin is the trader's own — personal wealth from trade.
        if (! $aid) {
            $payment = min($delivered * $job->price, $needy->stockpile->amount('money'));
            if ($payment > 0.0) {
                $needy->stockpile->withdraw('money', $payment);
                $trader->stockpile->add('money', $payment);
            }
        }

        $trader->jobHistory['trading'] = ($trader->jobHistory['trading'] ?? 0) + 1;

        // One line per active caravan per year, at the turn of the year — the relief itself flows daily.
        if ($date->monthIndex === 0 && $date->dayOfMonth === 1) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s carries %s from %s to %s%s.',
                $date->dayOfMonth, $date->monthName, $date->year, $trader->name, $good, $supplier->name, $needy->name,
                $aid ? ' in its need, asking nothing' : '',
            ), 'caravan', [$trader->id], [], [$aid ? 'mutual-aid' : 'trade']);
        }
    }

    /** The reachable settlement with the most to spare of a good, nearer suppliers preferred. */
    private static function bestSupplier(World $world, Village $needy, string $good): ?Village
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($world->villages as $village) {
            if ($village === $needy || RelationsEngine::hostile($world, $village, $needy)) {
                continue; // no caravan crosses to a sworn enemy (TWT-125)
            }
            $population = count($village->livingAgents());
            if ($population === 0) {
                continue;
            }
            $spare = $village->stockpile->amount($good) / $population - self::SUBSISTENCE_DAYS;
            if ($spare <= 0.0) {
                continue; // keeps all it has for its own
            }
            $score = $spare * (1.0 - self::transitLoss($village->distanceTo($needy)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $village;
            }
        }

        return $best;
    }

    /**
     * Who sets out: if the haul pays, the canniest trader takes it; if it doesn't, the most open-handed
     * soul may still go — and only if either motive clears the threshold.
     *
     * @param  list<Agent>  $adults
     */
    private static function chooseTrader(array $adults, float $profit, Village $supplier): ?Agent
    {
        if ($adults === []) {
            return null;
        }

        if ($profit >= self::WILLINGNESS_THRESHOLD) {
            return self::mostTradeDisposed($adults);
        }

        $best = null;
        $bestGenerosity = self::WILLINGNESS_THRESHOLD;
        foreach ($adults as $agent) {
            $generosity = self::generosityOf($agent, $supplier);
            if ($generosity >= $bestGenerosity) {
                $bestGenerosity = $generosity;
                $best = $agent;
            }
        }

        return $best;
    }

    /**
     * @param  list<Agent>  $adults
     */
    private static function mostTradeDisposed(array $adults): Agent
    {
        $best = $adults[0];
        $bestAffinity = -1.0;
        foreach ($adults as $agent) {
            $affinity = JobMarket::affinity($agent, 'trading');
            if ($affinity > $bestAffinity) {
                $bestAffinity = $affinity;
                $best = $agent;
            }
        }

        return $best;
    }

    /** An agent's readiness to give: its generosity, scaled by how much its settlement shares in scarcity. */
    private static function generosityOf(Agent $agent, Village $supplier): float
    {
        return (float) ($agent->trait('generosity') ?? 50.0) / 100.0 * $supplier->mutualAid;
    }

    private static function transitLoss(float $distance): float
    {
        return min(self::MAX_TRANSIT_LOSS, $distance / self::LOSS_SCALE);
    }

    private static function baseValue(World $world, string $good): float
    {
        $definition = $world->goods->get($good);

        return $definition !== null ? $definition->value : 1.0;
    }
}
