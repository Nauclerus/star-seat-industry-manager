<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure capability tables: which industry activity a fitted service module
 * actually makes available.
 *
 * A structure does not have an industry capability because of what it is; it has
 * one because of the service module fitted in its service slots. CCP publishes
 * that mapping in two files:
 *
 *   industryAssemblyLines.jsonl    assembly line -> activity + the product
 *                                   groups/categories that line accepts
 *   industryInstallationTypes.jsonl service module typeID -> assembly lines
 *
 * So "can this run happen in this structure" is: read the modules in the
 * structure's ServiceSlot* asset locations, map them to assembly lines, and see
 * whether one of those lines covers the activity and the product.
 *
 * Both tables are plugin-owned and dropped cleanly by down(). Nothing in the core
 * SDE namespace is touched.
 *
 * Only CCP publishes this: Fuzzwork's `ramAssemblyLineTypes` (the old equivalent
 * of industryInstallationTypes) is deprecated and dumps empty, so when the
 * fallback recipe source is in use the capability tables stay unpopulated and the
 * plugin falls back to its curated structure-type gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_manager_assembly_lines', function (Blueprint $table) {
            $table->integer('assemblyLineID');
            $table->integer('activityID');
            $table->string('name')->nullable();
            // JSON arrays: the product groups and categories this line accepts.
            // An empty pair means the line is not product-specific (lab slots).
            $table->text('groupIDs')->nullable();
            $table->text('categoryIDs')->nullable();
            $table->float('baseMaterialMultiplier')->nullable();
            $table->float('baseTimeMultiplier')->nullable();
            $table->float('baseCostMultiplier')->nullable();
            $table->primary('assemblyLineID');

            $table->index('activityID');
        });

        Schema::create('industry_manager_installation_types', function (Blueprint $table) {
            $table->integer('typeID');
            // JSON array of assemblyLineID
            $table->text('assemblyLineIDs');
            $table->primary('typeID');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_manager_installation_types');
        Schema::dropIfExists('industry_manager_assembly_lines');
    }
};
