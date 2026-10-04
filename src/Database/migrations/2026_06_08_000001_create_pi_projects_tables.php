<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planetary Industry Projects — account-level production planning.
 *
 * A project groups production objectives (targets) and assigned planets
 * (the colonies that supply them). The required-vs-supply comparison is
 * computed at read time from the PI schematic tree + the assigned planets'
 * live outputs, so nothing here duplicates SeAT-synced data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('industry_manager_pi_projects')) {
            Schema::create('industry_manager_pi_projects', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('user_id')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('industry_manager_pi_project_objectives')) {
            Schema::create('industry_manager_pi_project_objectives', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('project_id')->index();
                $table->integer('type_id');
                $table->bigInteger('target_quantity')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('industry_manager_pi_project_planets')) {
            Schema::create('industry_manager_pi_project_planets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('project_id')->index();
                $table->bigInteger('character_id');
                $table->integer('planet_id');
                $table->timestamps();

                $table->unique(['project_id', 'character_id', 'planet_id'], 'industry_manager_pi_proj_planet_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_manager_pi_project_planets');
        Schema::dropIfExists('industry_manager_pi_project_objectives');
        Schema::dropIfExists('industry_manager_pi_projects');
    }
};
