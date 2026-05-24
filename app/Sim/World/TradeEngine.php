<?php

namespace App\Sim\World;

use App\Sim\Economy\Pricing;
use App\Sim\Time\TharadiDate;

/**
 * Inter-settlement trade (design doc 06): settlements with a staple surplus ship it to settlements
 * that are short, so a settlement's *effective* carrying capacity rises above what its own land can
 * feed — the historical engine of cities, where a breadbasket region sustains a crowded one. Built
 * on regional specialization (TWT-46): an abundant, low-pressure land runs a surplus to export, a
 * crowded or lean one runs a deficit to import.
 *
 * World-level (it moves goods *between* settlements) and evaluated daily so relief is continuous, but
 * chronicled once a year per active route to keep the timeline legible. With fewer than two
 * settlements there is nowhere to trade, so it does nothing — and draws no RNG, leaving the
 * single-settlement run byte-identical. Deterministic: pure function of the settlements' stores.
 *
 * Payment moves with the goods at the cleared local price (Pricing, TWT-47): dear in the short
 * settlement, cheap in the flush one, so a shipment is valued by the scarcity it relieves — and as
 * goods flow the two prices converge, the mark of a self-regulating market.
 */
final class TradeEngine
{
    /** The survival staples worth shipping between settlements. */
    private const STAPLES = ['food', 'water'];

    /** Below this many days of stored staple per head, a settlement imports (up to this level). */
    private const IMPORT_THRESHOLD_DAYS = 5.0;

    /** A settlement exports only what it holds beyond this comfortable buffer per head. */
    private const EXPORT_KEEP_DAYS = 15.0;

    /** Map units of distance over which transit loss climbs to its cap — distance taxes a shipment. */
    private const LOSS_SCALE = 500.0;

    /** The most a long haul can lose in transit (a fresh, unimproved route). */
    private const MAX_TRANSIT_LOSS = 0.6;

    /** How fast a route matures per active year (0..1): roads, known paths, trust — caps at 1. */
    private const MATURITY_PER_YEAR = 0.15;

    /** How much of the distance tax a fully-mature route relieves — time makes distance cheaper. */
    private const MATURITY_LOSS_RELIEF = 0.6;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if (count($world->villages) < 2) {
            return; // nowhere to trade
        }
        foreach (self::STAPLES as $staple) {
            self::redistribute($world, $staple, $tick, $date);
        }
    }

    /**
     * Route one staple from the settlements that hold a surplus to those that are short, neediest and
     * most-able first. Greedy and deterministic — no RNG, ordered by need and offer.
     */
    private static function redistribute(World $world, string $staple, int $tick, TharadiDate $date): void
    {
        $importers = [];
        $exporters = [];
        foreach ($world->villages as $village) {
            $population = $village->headcount();
            if ($population <= 0.0) {
                continue;
            }
            $perCapita = $village->stockpile->amount($staple) / $population;
            if ($perCapita < self::IMPORT_THRESHOLD_DAYS) {
                $importers[] = ['village' => $village, 'need' => (self::IMPORT_THRESHOLD_DAYS - $perCapita) * $population];
            } elseif ($perCapita > self::EXPORT_KEEP_DAYS) {
                $exporters[] = ['village' => $village, 'offer' => ($perCapita - self::EXPORT_KEEP_DAYS) * $population];
            }
        }
        if ($importers === [] || $exporters === []) {
            return; // no imbalance to settle
        }

        usort($importers, static fn (array $a, array $b): int => $b['need'] <=> $a['need']);
        usort($exporters, static fn (array $a, array $b): int => $b['offer'] <=> $a['offer']);

        foreach ($importers as &$importer) {
            foreach ($exporters as &$exporter) {
                if ($importer['need'] <= 0.0 || $exporter['offer'] <= 0.0) {
                    continue;
                }
                if (RelationsEngine::hostile($world, $exporter['village'], $importer['village'])) {
                    continue; // no one ships grain to a sworn enemy (TWT-125)
                }
                $amount = min($importer['need'], $exporter['offer']);
                self::ship($world, $exporter['village'], $importer['village'], $staple, $amount, $tick, $date);
                $importer['need'] -= $amount;
                $exporter['offer'] -= $amount;
            }
            unset($exporter);
        }
        unset($importer);
    }

    /** Move the staple from exporter to importer, settle payment at the cleared local price, and chronicle the year's route. */
    private static function ship(World $world, Village $from, Village $to, string $staple, float $amount, int $tick, TharadiDate $date): void
    {
        // Price the trade at the midpoint of the two local markets, read *before* the goods move:
        // dear in the short settlement, cheap in the flush one — the gain from trade split between them.
        $base = $world->goods->get($staple)?->value ?? 1.0;
        $buyerPrice = Pricing::localPrice($base, $to->stockpile->amount($staple), (int) round($to->headcount()));
        $sellerPrice = Pricing::localPrice($base, $from->stockpile->amount($staple), (int) round($from->headcount()));
        $unitPrice = ($buyerPrice + $sellerPrice) / 2.0;

        $shipped = $from->stockpile->withdraw($staple, $amount);
        if ($shipped <= 0.0) {
            return;
        }

        // Distance taxes the haul: a fraction is lost in transit, scaling with how far the goods travel
        // and shrinking as the route matures (TWT-127) — so an established route effectively reaches
        // farther over time. The exporter parts with the full amount; only the delivered share arrives.
        $maturity = self::routeMaturity($world, $from, $to);
        $loss = min(self::MAX_TRANSIT_LOSS, $from->distanceTo($to) / self::LOSS_SCALE) * (1.0 - $maturity * self::MATURITY_LOSS_RELIEF);
        $delivered = $shipped * (1.0 - $loss);
        $to->stockpile->add($staple, $delivered);
        self::mature($world, $from, $to, $date->year);

        // Payment follows the *delivered* goods at the cleared price, capped at what the buyer can pay
        // (the unpaid remainder is implicit reciprocity for now).
        $payment = min($delivered * $unitPrice, $to->stockpile->amount('money'));
        if ($payment > 0.0) {
            $to->stockpile->withdraw('money', $payment);
            $from->stockpile->add('money', $payment);
        }

        // One line per active route per year, recorded at the turn of the year — the relief flows daily.
        if ($date->monthIndex === 0 && $date->dayOfMonth === 1) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s ships %s to %s, raising what its land alone could feed.',
                $date->dayOfMonth, $date->monthName, $date->year, $from->name, $staple, $to->name,
            ), 'trade', [], [], ['trade']);
        }
    }

    /** A route's maturity 0..1, from the number of distinct years it has carried goods. */
    public static function routeMaturity(World $world, Village $a, Village $b): float
    {
        $age = $world->routes[self::routeKey($a, $b)]['ageYears'] ?? 0;

        return min(1.0, $age * self::MATURITY_PER_YEAR);
    }

    /** Age a route one step the first time it ships in a given year — roads worn, paths learned, trust built. */
    private static function mature(World $world, Village $a, Village $b, int $year): void
    {
        $key = self::routeKey($a, $b);
        $route = $world->routes[$key] ?? ['ageYears' => 0, 'lastYear' => PHP_INT_MIN];
        if ($route['lastYear'] !== $year) {
            $route['ageYears']++;
            $route['lastYear'] = $year;
        }
        $world->routes[$key] = $route;
    }

    /** A direction-independent key for the route between two settlements. */
    public static function routeKey(Village $a, Village $b): string
    {
        return $a->pairKey($b);
    }
}
