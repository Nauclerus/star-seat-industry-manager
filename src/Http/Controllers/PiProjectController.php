<?php

namespace IndustryManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use IndustryManager\Services\PiProjectService;

/**
 * PiProjectController — account-level PI production projects.
 *
 * Every action is scoped to the current user via PiProjectService::find()
 * (which filters by user_id), so a user can only ever see or mutate their own
 * projects. Form-based POST CRUD, no client framework.
 */
class PiProjectController extends Controller
{
    public function index(PiProjectService $svc)
    {
        return view('industry-manager::pi.projects.index', [
            'projects' => $svc->userProjects(),
        ]);
    }

    public function store(Request $request, PiProjectService $svc)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);

        $project = $svc->create($data['name'], $data['description'] ?? null);

        return redirect()
            ->route('industry-manager.pi.projects.show', ['id' => $project->id])
            ->with('success', 'Project created.');
    }

    public function show(int $id, PiProjectService $svc, \IndustryManager\Services\PiSchematicService $schematics)
    {
        $project = $svc->find($id);
        if (! $project) {
            abort(404);
        }

        $data = $svc->overview($project);
        // Schematic outputs power the "add objective" product picker.
        $data['outputs'] = $schematics->allSchematics();

        return view('industry-manager::pi.projects.show', $data);
    }

    public function destroy(int $id, PiProjectService $svc)
    {
        $project = $svc->find($id);
        if (! $project) {
            abort(404);
        }

        $svc->delete($project);

        return redirect()
            ->route('industry-manager.pi.projects.index')
            ->with('success', 'Project deleted.');
    }

    public function addObjective(Request $request, int $id, PiProjectService $svc)
    {
        $project = $svc->find($id);
        if (! $project) {
            abort(404);
        }

        $data = $request->validate([
            'type_id' => 'required|integer|min:1',
            'target_quantity' => 'required|integer|min:0',
        ]);

        $svc->addObjective($project, (int) $data['type_id'], (int) $data['target_quantity']);

        return redirect()
            ->route('industry-manager.pi.projects.show', ['id' => $id])
            ->with('success', 'Objective added.');
    }

    public function removeObjective(int $id, int $objectiveId, PiProjectService $svc)
    {
        $project = $svc->find($id);
        if (! $project) {
            abort(404);
        }

        $svc->removeObjective($project, $objectiveId);

        return redirect()
            ->route('industry-manager.pi.projects.show', ['id' => $id])
            ->with('success', 'Objective removed.');
    }

    public function assignPlanet(Request $request, int $id, PiProjectService $svc)
    {
        $project = $svc->find($id);
        if (! $project) {
            abort(404);
        }

        $data = $request->validate(['planet' => 'required|string']);
        $parts = explode(':', $data['planet']);
        if (count($parts) === 2) {
            $svc->assignPlanet($project, (int) $parts[0], (int) $parts[1]);
        }

        return redirect()
            ->route('industry-manager.pi.projects.show', ['id' => $id])
            ->with('success', 'Planet assigned.');
    }

    public function unassignPlanet(int $id, int $rowId, PiProjectService $svc)
    {
        $project = $svc->find($id);
        if (! $project) {
            abort(404);
        }

        $svc->unassignPlanet($project, $rowId);

        return redirect()
            ->route('industry-manager.pi.projects.show', ['id' => $id])
            ->with('success', 'Planet unassigned.');
    }
}
