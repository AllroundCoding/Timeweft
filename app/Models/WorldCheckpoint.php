<?php

namespace App\Models;

use App\Sim\Persistence\Checkpoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable form of a {@see Checkpoint} (TWT-30) — a world's boundary state at a
 * tick, serialized, from which it resumes byte-identically. Anchored to a tick so a world can be reloaded
 * "as of" any saved point.
 *
 * @property int $world_id
 * @property int $tick
 * @property string $boundary_state
 */
class WorldCheckpoint extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['tick' => 'integer'];
    }

    /** @return BelongsTo<World, $this> */
    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
