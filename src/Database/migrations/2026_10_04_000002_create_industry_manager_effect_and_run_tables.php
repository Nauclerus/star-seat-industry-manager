<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rig scope + production plan tables.
 *
 * Both are plugin-owned, dropped cleanly by down(), and nothing in the core SDE
 * namespace is touched.
 *
 * `industry_manager_effects` holds the dogma effect definitions. SeAT core seeds
 * `dgmTypeEffects` (type -> effect) but not `dgmEffects` (effect -> name and
 * modifierInfo), which is where a rig's scope actually lives. The plugin imports
 * it from either source so the scope can be resolved without a core change.
 *
 * `industry_manager_production_runs` stores the plan: one row per run in the
 * production tree, with the structure and character assigned to it. Only the
 * assignments are stored — the bonuses and quantities are recomputed at read time
 * so nothing here duplicates data the calculator already derives.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('industry_manager_effects')) {
            Schema::create('industry_manager_effects', function (Blueprint $table) {
                $table->integer('effectID');
                $table->string('effectName', 400)->nullable();
                $table->text('modifierInfo')->nullable();

                $table->unique('effectID', 'im_effects_id');
                $table->index('effectName', 'im_effects_name');
            });
        }

        if (! Schema::hasTable('industry_manager_production_runs')) {
            Schema::create('industry_manager_production_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('project_id')->index();
                $table->integer('blueprint_type_id');
                $table->integer('activity_id');
                $table->integer('product_type_id')->nullable();
                $table->integer('runs')->default(1);

                // Assignment: the auto-best pick, or the user's override.
                $table->bigInteger('structure_id')->nullable();
                $table->integer('character_id')->nullable();
                $table->string('scope', 32)->nullable();
                $table->tinyInteger('is_override')->default(0);

                $table->timestamps();

                $table->unique(
                    ['project_id', 'blueprint_type_id', 'activity_id'],
                    'im_runs_plan_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_manager_production_runs');
        Schema::dropIfExists('industry_manager_effects');
    }
};
