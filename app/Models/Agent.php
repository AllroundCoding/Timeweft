<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted tracked agent (TWT-28) — its canonical identity and the shared, queryable scalars as
 * columns, with the variable bags (traits, needs as {value,capacity}, job history) as JSONB. The
 * commoner masses are statistical cohorts elsewhere, not rows here.
 *
 * @property int $id
 * @property int $world_id
 * @property int|null $village_id
 * @property int $sim_id
 * @property string $name
 * @property string $species
 * @property string $sex
 * @property int $birth_tick
 * @property int|null $death_tick
 * @property bool $alive
 * @property float $money
 * @property string|null $profession
 * @property array<string,float|string> $traits
 * @property array<string,array{value:float,capacity:float}> $needs
 * @property array<string,int>|null $job_history
 * @property list<int>|null $parent_ids
 */
class Agent extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'sim_id' => 'integer',
            'birth_tick' => 'integer',
            'death_tick' => 'integer',
            'alive' => 'boolean',
            'money' => 'double',
            'traits' => 'array',
            'needs' => 'array',
            'job_history' => 'array',
            'parent_ids' => 'array',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return BelongsTo<Village, $this> */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }
}
