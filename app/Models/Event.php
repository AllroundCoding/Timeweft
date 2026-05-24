<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A persisted canonical event (TWT-28) — a node in the timeline's causal graph (doc 09). The sparse,
 * path-dependent skeleton that storage keeps and derive-on-demand replays from; its provenance edges
 * live in {@see EventDependency}.
 *
 * @property int $id
 * @property int $sim_id
 * @property int $tick
 * @property string $type
 * @property string $text
 * @property list<int>|null $subjects
 * @property list<string>|null $factors
 */
class Event extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'sim_id' => 'integer',
            'tick' => 'integer',
            'subjects' => 'array',
            'factors' => 'array',
        ];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    /**
     * The prior events this one depended on — the edges a causal-cone walk follows.
     *
     * @return HasMany<EventDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(EventDependency::class);
    }
}
