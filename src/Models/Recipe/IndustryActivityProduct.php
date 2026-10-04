<?php

namespace IndustryManager\Models\Recipe;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * industryActivityProducts — output product per (blueprint, activity).
 *
 * Columns (flattened from CCP blueprints.jsonl): typeID, activityID, productTypeID, quantity
 *
 * For activityID = 1 (manufacturing) this maps a blueprint to the item it
 * produces and the per-run output quantity. Reversed (productTypeID -> typeID)
 * it answers "which blueprint builds this item?", the lookup the production
 * tree uses to decide whether a material is itself buildable.
 *
 * For activityID = 8 (invention) the product is the resulting T2/T3 BPC.
 */
class IndustryActivityProduct extends Model
{
    protected $table = 'industry_manager_products';

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
