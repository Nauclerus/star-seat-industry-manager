<?php

namespace IndustryManager\Models\Recipe;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * industryActivityProbabilities — base success chance per (blueprint, activity,
 * product). Primarily activityID = 8 (invention).
 *
 * Columns (flattened from CCP blueprints.jsonl): typeID, activityID, productTypeID, probability
 *
 * `probability` is the BASE chance (0..1) before decryptor + skill modifiers.
 * The invention calculator layers those on top.
 */
class IndustryActivityProbability extends Model
{
    protected $table = 'industry_manager_probabilities';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    public function blueprintType()
    {
        return $this->belongsTo(InvType::class, 'typeID', 'typeID');
    }

    public function productType()
    {
        return $this->belongsTo(InvType::class, 'productTypeID', 'typeID');
    }
}
