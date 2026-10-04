<?php

namespace IndustryManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PiProjectPlanet — a colony (character_id + planet_id) assigned to supply a
 * project. Uniqueness is enforced at the DB level per (project, character, planet).
 */
class PiProjectPlanet extends Model
{
    protected $table = 'industry_manager_pi_project_planets';

    protected $fillable = ['project_id', 'character_id', 'planet_id'];

    protected $casts = [
        'character_id' => 'integer',
        'planet_id' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PiProject::class, 'project_id');
    }
}
