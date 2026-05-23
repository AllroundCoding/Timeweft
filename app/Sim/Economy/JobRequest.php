<?php

namespace App\Sim\Economy;

/**
 * A posted unit of work a settlement needs done (design doc 16; TWT-97) — the generalization of the
 * single hard-coded communal project into *needs as priced demand*. A food shortfall, an empty cistern,
 * an unfinished preparation, the sick going untended: each becomes a job, priced from scarcity (Pricing,
 * TWT-47) so a starving settlement pays more, and pulling labor in proportion to how badly it is needed.
 *
 * The labor market ({@see JobMarket}) posts these from a settlement's unmet needs and allocates agents
 * to them; the work an agent takes settles it into a profession (TWT-98), and a priced shortfall a
 * distant settlement can answer is the seed of agent-driven trade (TWT-99).
 */
readonly class JobRequest
{
    /**
     * @param  string  $type  the kind of work — what an agent who takes it becomes known for
     * @param  float  $price  the work's money value, scarcity-priced (TWT-47) — what a shipment answering it is worth
     * @param  float  $pull  0..1 the wage's pull on labor (the participation calculus' paid-to); scarcer need → stronger pull
     * @param  ?string  $good  the good the work supplies, if any (null for labor like building or tending)
     * @param  float  $shortfall  how short the settlement is of that good — how much a supplier could answer (TWT-99)
     */
    public function __construct(
        public string $type,
        public float $price,
        public float $pull,
        public ?string $good = null,
        public float $shortfall = 0.0,
    ) {}
}
