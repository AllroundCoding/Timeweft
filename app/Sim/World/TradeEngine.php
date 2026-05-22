<?php

namespace App\Sim\World;

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
 * Payment moves with the goods at each staple's catalog value; dynamic supply/demand pricing is the
 * next step (TWT-47), so this v1 keeps a fixed price and never blocks a shipment on the buyer's purse.
 */
final class TradeEngine
{
    /** The survival staples worth shipping between settlements. */
    private const STAPLES = ['food', 'water'];

    /** Below this many days of stored staple per head, a settlement imports (up to this level). */
    private const IMPORT_THRESHOLD_DAYS = 5.0;

    /** A settlement exports only what it holds beyond this comfortable buffer per head. */
    private const EXPORT_KEEP_DAYS = 15.0;

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
            $population = count($village->livingAgents());
            if ($population === 0) {
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
                $amount = min($importer['need'], $exporter['offer']);
                self::ship($world, $exporter['village'], $importer['village'], $staple, $amount, $tick, $date);
                $importer['need'] -= $amount;
                $exporter['offer'] -= $amount;
            }
            unset($exporter);
        }
        unset($importer);
    }

    /** Move the staple from exporter to importer, settle payment at its catalog value, and chronicle the year's route. */
    private static function ship(World $world, Village $from, Village $to, string $staple, float $amount, int $tick, TharadiDate $date): void
    {
        $shipped = $from->stockpile->withdraw($staple, $amount);
        if ($shipped <= 0.0) {
            return;
        }
        $to->stockpile->add($staple, $shipped);

        // Payment follows the goods at the staple's catalog value, capped at what the buyer can pay
        // (the unpaid remainder is implicit reciprocity for now; market pricing is TWT-47).
        $unitValue = $world->goods->get($staple)?->value ?? 1.0;
        $payment = min($shipped * $unitValue, $to->stockpile->amount('money'));
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
}
