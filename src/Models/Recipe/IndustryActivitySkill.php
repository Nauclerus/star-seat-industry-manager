<?php

namespace IndustryManager\Models\Recipe;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * industryActivitySkills — skill requirements per (blueprint, activity).
 *
 * Columns (flattened from CCP blueprints.jsonl): typeID, activityID, skillID, level
 *
 * Used to tell a user whether their character can actually run a given job,
 * and to flag missing prerequisites on the production tree.
 */
class IndustryActivitySkill extends Model
{
    protected $table = 'industry_manager_skills';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    public function blueprintType()
    {
        return $this->belongsTo(InvType::class, 'typeID', 'typeID');
    }

    public function skillType()
    {
        return $this->belongsTo(InvType::class, 'skillID', 'typeID');
    }
}
