<?php

namespace IndustryManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PiProject — an account-level planetary production project owned by a user.
 */
class PiProject extends Model
{
    protected $table = 'industry_manager_pi_projects';

    protected $fillable = ['user_id', 'name', 'description'];

    public function objectives(): HasMany
    {
        return $this->hasMany(PiProjectObjective::class, 'project_id');
    }

    public function planets(): HasMany
    {
        return $this->hasMany(PiProjectPlanet::class, 'project_id');
    }
}
