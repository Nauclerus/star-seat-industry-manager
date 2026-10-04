<?php

namespace IndustryManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * PiProjectObjective — "produce N units of type X" within a project.
 */
class PiProjectObjective extends Model
{
    protected $table = 'industry_manager_pi_project_objectives';

    protected $fillable = ['project_id', 'type_id', 'target_quantity'];

    protected $casts = [
        'target_quantity' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PiProject::class, 'project_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(InvType::class, 'type_id', 'typeID');
    }
}
