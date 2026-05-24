<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provenance edge (TWT-28; doc 09): the event depended on a prior cause event. The relational form of
 * the chronicle's `causes`, so a recursive CTE can walk the causal cone an edit invalidates.
 *
 * @property int $event_id
 * @property int $cause_event_id
 */
class EventDependency extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'cause_event_id');
    }
}
