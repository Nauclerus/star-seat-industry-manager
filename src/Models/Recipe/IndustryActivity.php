<?php

namespace IndustryManager\Models\Recipe;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * industryActivity — base time (seconds) for each (blueprint, activity).
 *
 * Columns (flattened from CCP blueprints.jsonl): typeID, activityID, time
 *
 * Read-only SDE table. Composite key (typeID, activityID) is not declared as a
 * model primary key because Eloquent can't express composite PKs natively and
 * we never find() by it — services query with the DB builder. The model exists
 * for relationship convenience and type-hinting.
 */
class IndustryActivity extends Model
{
    protected $table = 'industry_manager_recipes';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    public function blueprintType()
    {
        return $this->belongsTo(InvType::class, 'typeID', 'typeID');
    }
}
