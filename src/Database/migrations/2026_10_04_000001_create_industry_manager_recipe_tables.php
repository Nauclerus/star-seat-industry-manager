<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Industry Manager recipe tables.
 *
 * These are plugin-owned. They are NOT part of SeAT's core SDE, and nothing
 * outside this plugin creates or writes them — the plugin's own importer fills
 * them from CCP's JSONL SDE or from Fuzzwork's per-table dumps. Column names
 * stay canonical (CamelCase, as CCP and Fuzzwork publish them) so the recipe
 * code reads naturally, but the tables themselves belong to the plugin.
 *
 * Removing the plugin drops these cleanly via down(); nothing in the core SDE
 * namespace is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Manufacturing / invention / reaction recipes.
        if (! Schema::hasTable('industry_manager_recipes')) {
            Schema::create('industry_manager_recipes', function (Blueprint $table) {
                $table->integer('typeID');
                $table->integer('activityID');
                $table->bigInteger('time')->nullable();

                $table->index(['typeID', 'activityID'], 'im_recipes_type_activity');
                $table->index('activityID', 'im_recipes_activity');
            });
        }

        if (! Schema::hasTable('industry_manager_materials')) {
            Schema::create('industry_manager_materials', function (Blueprint $table) {
                $table->integer('typeID');
                $table->integer('activityID');
                $table->integer('materialTypeID');
                $table->bigInteger('quantity');

                $table->index(['typeID', 'activityID'], 'im_materials_type_activity');
                $table->index('materialTypeID', 'im_materials_material');
            });
        }

        if (! Schema::hasTable('industry_manager_products')) {
            Schema::create('industry_manager_products', function (Blueprint $table) {
                $table->integer('typeID');
                $table->integer('activityID');
                $table->integer('productTypeID');
                $table->bigInteger('quantity');

                $table->index(['typeID', 'activityID'], 'im_products_type_activity');
                $table->index('productTypeID', 'im_products_product');
            });
        }

        if (! Schema::hasTable('industry_manager_skills')) {
            Schema::create('industry_manager_skills', function (Blueprint $table) {
                $table->integer('typeID');
                $table->integer('activityID');
                $table->integer('skillID');
                $table->integer('level');

                $table->index(['typeID', 'activityID'], 'im_skills_type_activity');
                $table->index('skillID', 'im_skills_skill');
            });
        }

        if (! Schema::hasTable('industry_manager_probabilities')) {
            Schema::create('industry_manager_probabilities', function (Blueprint $table) {
                $table->integer('typeID');
                $table->integer('activityID');
                $table->integer('productTypeID');
                $table->double('probability')->nullable();

                $table->index(['typeID', 'activityID'], 'im_probabilities_type_activity');
                $table->index('productTypeID', 'im_probabilities_product');
            });
        }

        // Planetary Industry schematics.
        if (! Schema::hasTable('industry_manager_pi_schematics')) {
            Schema::create('industry_manager_pi_schematics', function (Blueprint $table) {
                $table->integer('schematicID');
                $table->string('schematicName', 255)->nullable();
                $table->integer('cycleTime')->nullable();

                $table->index('schematicID', 'im_pi_schematics_id');
            });
        }

        if (! Schema::hasTable('industry_manager_pi_types')) {
            Schema::create('industry_manager_pi_types', function (Blueprint $table) {
                $table->integer('schematicID');
                $table->integer('typeID');
                $table->bigInteger('quantity');
                $table->integer('isInput');

                $table->index('schematicID', 'im_pi_types_schematic');
                $table->index('typeID', 'im_pi_types_type');
                $table->index('isInput', 'im_pi_types_isinput');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_manager_pi_types');
        Schema::dropIfExists('industry_manager_pi_schematics');
        Schema::dropIfExists('industry_manager_probabilities');
        Schema::dropIfExists('industry_manager_skills');
        Schema::dropIfExists('industry_manager_products');
        Schema::dropIfExists('industry_manager_materials');
        Schema::dropIfExists('industry_manager_recipes');
    }
};
