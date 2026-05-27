<?php

namespace App\Sim\Worldgen;

/**
 * The travel-cost / accessibility surface (design doc 13; TWT-136): a per-cell movement *friction* and the
 * least-cost travel time it implies between any two points. Derived from the {@see Substrate} (slope) and
 * {@see Hydrology} (rivers as highways, lakes and open sea as barriers) by {@see AccessibilityGenerator}.
 *
 * This is the backbone of movement — it is what later makes trade reach, migration, cultural contact, and
 * military reach mechanically true (who can reach whom in how many days). A read-only computation utility:
 * it never mutates canonical state, so wiring it into those systems is a separate, deliberate step.
 *
 * Immutable and grid-indexed [y][x]; longitude wraps around the globe and latitude is capped at the poles,
 * the same topology as {@see Substrate::slopeAt()}.
 */
readonly class AccessibilityField
{
    /** @param list<list<float>> $friction per-cell movement cost multiplier (low = easy, high = slow/barrier) */
    public function __construct(
        public int $width,
        public int $height,
        public array $friction,
    ) {}

    public function frictionAt(int $x, int $y): float
    {
        return $this->friction[$y][$x];
    }

    /**
     * Least-cost travel time from one cell to another — the cost of the cheapest path across the friction
     * surface. Returns INF when the destination is unreachable (e.g. across an impassable sea on a land-only
     * surface).
     */
    public function travelTime(int $fromX, int $fromY, int $toX, int $toY): float
    {
        return $this->costSurfaceFrom($fromX, $fromY)[$toY][$toX];
    }

    /**
     * The least-cost travel time from a source cell to every cell — the cost-surface a heat-layer renders
     * and a "time to the nearest town" query reads. Dijkstra over the 4-neighbour grid; an edge costs the
     * mean friction of the two cells it joins.
     *
     * @return list<list<float>>
     */
    public function costSurfaceFrom(int $sourceX, int $sourceY): array
    {
        $cost = [];
        for ($y = 0; $y < $this->height; $y++) {
            $cost[$y] = array_fill(0, $this->width, INF);
        }
        $cost[$sourceY][$sourceX] = 0.0;

        /** @var \SplPriorityQueue<float,array{int,int,float}> $frontier */
        $frontier = new \SplPriorityQueue;
        $frontier->insert([$sourceX, $sourceY, 0.0], 0.0);

        while (! $frontier->isEmpty()) {
            /** @var array{int,int,float} $node */
            $node = $frontier->extract();
            [$x, $y, $reached] = $node;
            if ($reached > $cost[$y][$x]) {
                continue; // a cheaper path to this cell was already settled (lazy deletion)
            }

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $ny = $y + $dy;
                if ($ny < 0 || $ny >= $this->height) {
                    continue; // latitude is capped at the poles
                }
                $nx = ($x + $dx + $this->width) % $this->width; // longitude wraps around the globe

                $step = $reached + ($this->friction[$y][$x] + $this->friction[$ny][$nx]) / 2.0;
                if ($step < $cost[$ny][$nx]) {
                    $cost[$ny][$nx] = $step;
                    $frontier->insert([$nx, $ny, $step], -$step);
                }
            }
        }

        return $cost;
    }
}
