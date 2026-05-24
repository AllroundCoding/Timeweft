<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A persisted settlement (TWT-28) — its land/position/tech as columns, its culture vector, communal
 * stockpile, and flag/event-id state as JSONB.
 *
 * @property int $id
 * @property string $name
 * @property string $region
 * @property float $x
 * @property float $y
 * @property float $land_yield
 * @property float $technology
 * @property int $carrying_capacity
 * @property array<string,float> $culture
 * @property array<string,float>|null $stockpile
 * @property array<string,mixed>|null $state
 */
class Village extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'x' => 'double',
            'y' => 'double',
            'land_yield' => 'double',
            'technology' => 'double',
            'carrying_capacity' => 'integer',
            'culture' => 'array',
            'stockpile' => 'array',
            'state' => 'array',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /** @return HasMany<Agent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /** @return HasMany<Institution, $this> */
    public function institutions(): HasMany
    {
        return $this->hasMany(Institution::class);
    }
}
