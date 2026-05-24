<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted institution (TWT-28) — the settlement-scale structure that rose from a cohesion deficit,
 * with the mandate/effectiveness that drive its rise and fall.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property int $founded_tick
 * @property float $mandate
 * @property float $effectiveness
 */
class Institution extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'founded_tick' => 'integer',
            'mandate' => 'double',
            'effectiveness' => 'double',
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
