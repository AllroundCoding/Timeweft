<?php

namespace App\Sim\Economy;

/**
 * Local supply/demand pricing (design doc 06): a good's price in a settlement rises as it grows
 * scarce there and falls when it gluts — the signal that makes the economy self-regulating rather
 * than fixed-rate. Scarcity steers trade (TWT-45): a good is dear where it is short and cheap where
 * it is plentiful, so it flows down the price gradient, and as it flows the prices converge.
 *
 * A pure function of the good's base value and how much of it the settlement holds per head, so it
 * draws no RNG and stores no state — a derived read, re-computable at any tick.
 */
final class Pricing
{
    /** Stock per head at which a good trades at its base value — the "normal" holding. */
    private const REFERENCE_PER_CAPITA = 5.0;

    /** A glut can only drive the price down to this fraction of base; scarcity, up to its inverse. */
    private const MIN_FACTOR = 0.25;

    private const MAX_FACTOR = 4.0;

    /**
     * The local price of a good: its base value scaled by how scarce it is per head. At the reference
     * holding the price is the base value; below it the price climbs (toward MAX_FACTOR×), above it
     * the price falls (toward MIN_FACTOR×). With no one to trade with, the price is just the base.
     */
    public static function localPrice(float $baseValue, float $stock, int $population): float
    {
        if ($population <= 0) {
            return $baseValue;
        }

        $perCapita = $stock / $population;
        $factor = $perCapita > 0.0
            ? self::REFERENCE_PER_CAPITA / $perCapita
            : self::MAX_FACTOR;

        return $baseValue * max(self::MIN_FACTOR, min(self::MAX_FACTOR, $factor));
    }
}
