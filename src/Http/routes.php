<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => '\IndustryManager\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'industry-manager',
], function () {

    // Dashboard
    Route::get('/', [
        'as' => 'industry-manager.index',
        'uses' => 'IndustryManagerController@index',
        'middleware' => 'can:industry-manager.view',
    ]);

    // Blueprint library
    Route::get('/blueprints', [
        'as' => 'industry-manager.blueprints',
        'uses' => 'IndustryManagerController@blueprints',
        'middleware' => 'can:industry-manager.view',
    ]);

    // Blueprint detail
    Route::get('/blueprint/{type_id}', [
        'as' => 'industry-manager.blueprint.detail',
        'uses' => 'IndustryManagerController@blueprintDetail',
        'middleware' => 'can:industry-manager.view',
    ])->where('type_id', '[0-9]+');

    // Calculator
    Route::get('/calculator', [
        'as' => 'industry-manager.calculator',
        'uses' => 'IndustryManagerController@calculator',
        'middleware' => 'can:industry-manager.calculate',
    ]);

    // Structures (eligible industry structures with resolved rig bonuses)
    Route::get('/structures', [
        'as' => 'industry-manager.structures',
        'uses' => 'IndustryManagerController@structures',
        'middleware' => 'can:industry-manager.view',
    ]);

    // Active + historical jobs
    Route::get('/jobs', [
        'as' => 'industry-manager.jobs',
        'uses' => 'IndustryManagerController@jobs',
        'middleware' => 'can:industry-manager.view',
    ]);

    // Invention chain calculator
    Route::get('/invention', [
        'as' => 'industry-manager.invention',
        'uses' => 'IndustryManagerController@invention',
        'middleware' => 'can:industry-manager.view',
    ]);

    // Reaction blueprints
    Route::get('/reactions', [
        'as' => 'industry-manager.reactions',
        'uses' => 'IndustryManagerController@reactions',
        'middleware' => 'can:industry-manager.view',
    ]);

    // Settings
    Route::get('/settings', [
        'as' => 'industry-manager.settings',
        'uses' => 'IndustryManagerController@settings',
        'middleware' => 'can:industry-manager.manage',
    ]);

    // Help & Documentation
    Route::get('/help', [
        'as' => 'industry-manager.help',
        'uses' => 'IndustryManagerController@help',
        'middleware' => 'can:industry-manager.view',
    ]);

    // -----------------------------------------------------------------
    // Planetary Industry (read-only consumer of SeAT's character_planet_*
    // tables + the registered PI schematic SDE)
    // -----------------------------------------------------------------
    Route::get('/planetary', [
        'as' => 'industry-manager.pi.overview',
        'uses' => 'PlanetaryController@overview',
        'middleware' => 'can:industry-manager.view',
    ]);

    Route::get('/planetary/schematics', [
        'as' => 'industry-manager.pi.schematics',
        'uses' => 'PlanetaryController@schematics',
        'middleware' => 'can:industry-manager.view',
    ]);

    // PI Projects (account-level production planning, user-scoped)
    Route::get('/planetary/projects', [
        'as' => 'industry-manager.pi.projects.index',
        'uses' => 'PiProjectController@index',
        'middleware' => 'can:industry-manager.view',
    ]);
    Route::post('/planetary/projects', [
        'as' => 'industry-manager.pi.projects.store',
        'uses' => 'PiProjectController@store',
        'middleware' => 'can:industry-manager.view',
    ]);
    Route::get('/planetary/projects/{id}', [
        'as' => 'industry-manager.pi.projects.show',
        'uses' => 'PiProjectController@show',
        'middleware' => 'can:industry-manager.view',
    ])->where('id', '[0-9]+');
    Route::post('/planetary/projects/{id}/delete', [
        'as' => 'industry-manager.pi.projects.destroy',
        'uses' => 'PiProjectController@destroy',
        'middleware' => 'can:industry-manager.view',
    ])->where('id', '[0-9]+');
    Route::post('/planetary/projects/{id}/objectives', [
        'as' => 'industry-manager.pi.projects.objectives.add',
        'uses' => 'PiProjectController@addObjective',
        'middleware' => 'can:industry-manager.view',
    ])->where('id', '[0-9]+');
    Route::post('/planetary/projects/{id}/objectives/{objectiveId}/delete', [
        'as' => 'industry-manager.pi.projects.objectives.remove',
        'uses' => 'PiProjectController@removeObjective',
        'middleware' => 'can:industry-manager.view',
    ])->where(['id' => '[0-9]+', 'objectiveId' => '[0-9]+']);
    Route::post('/planetary/projects/{id}/planets', [
        'as' => 'industry-manager.pi.projects.planets.assign',
        'uses' => 'PiProjectController@assignPlanet',
        'middleware' => 'can:industry-manager.view',
    ])->where('id', '[0-9]+');
    Route::post('/planetary/projects/{id}/planets/{rowId}/delete', [
        'as' => 'industry-manager.pi.projects.planets.unassign',
        'uses' => 'PiProjectController@unassignPlanet',
        'middleware' => 'can:industry-manager.view',
    ])->where(['id' => '[0-9]+', 'rowId' => '[0-9]+']);

    // Diagnostic — admin-only, NOT in sidebar. URL-only access.
    // Hosts the Sprint-0 attribute-ID discovery tool as its first tab.
    Route::get('/diagnostic', [
        'as' => 'industry-manager.diagnostic',
        'uses' => 'IndustryManagerController@diagnostic',
        'middleware' => 'can:industry-manager.admin',
    ]);
});
