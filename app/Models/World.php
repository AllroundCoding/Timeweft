<?php

namespace App\Models;

use App\Sim\Persistence\WorldSkeleton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The persisted skeleton of a simulated world (TWT-28; design doc 10) — the run handle (seed + tick) and
 * its world-level ledgers, with the entities and timeline hanging off it. The boundary-side image of
 * {@see WorldSkeleton}; the sim core stays in-memory and pure.
 *
 * @property int $id
 * @property int $seed
 * @property int $tick
 * @property array<string,float>|null $relations
 * @property array<string,array{ageYears:int,lastYear:int}>|null $routes
 * @property list<array<string,mixed>>|null $milestones
 */
class World extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'tick' => 'integer',
            'relations' => 'array',
            'routes' => 'array',
            'milestones' => 'array',
        ];
    }

    /** @return HasMany<Agent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /** @return HasMany<Village, $this> */
    public function villages(): HasMany
    {
        return $this->hasMany(Village::class);
    }

    /** @return HasMany<Institution, $this> */
    public function institutions(): HasMany
    {
        return $this->hasMany(Institution::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<WorldCheckpoint, $this> */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(WorldCheckpoint::class);
    }
}
