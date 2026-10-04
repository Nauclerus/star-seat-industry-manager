<?php

namespace IndustryManager\Models\Recipe;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * industry_manager_materials — input materials per (blueprint, activity), plugin-owned.
 *
 * Columns (flattened from CCP blueprints.jsonl): typeID, activityID, materialTypeID, quantity
 *
 * `quantity` is the base requirement for ONE run at 0% ME. The calculator
 * applies ME / runs / structure / rig modifiers at compute time; this row is
 * never mutated.
 *
 * This is the manufacturing recipe source. Do NOT confuse with the SeAT-core
 * `invTypeMaterials` table, which is reprocessing/refining composition and is
 * NOT a valid manufacturing recipe post-2014.
 */
class IndustryActivityMaterial extends Model
{
    protected $table = 'industry_manager_materials';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    public function blueprintType()
    {
        return $this->belongsTo(InvType::class, 'typeID', 'typeID');
    }

    public function materialType()
    {
        return $this->belongsTo(InvType::class, 'materialTypeID', 'typeID');
    }
}
