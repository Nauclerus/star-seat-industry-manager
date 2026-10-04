<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Models\PiProject;
use IndustryManager\Models\PiProjectObjective;
use IndustryManager\Models\PiProjectPlanet;

/**
 * PiProjectService — account-level PI production planning.
 *
 * CRUD for projects / objectives / assigned planets, plus the read-time
 * compute that turns objectives into a full PI material requirement (via the
 * schematic tree) and matches it against the output rates of the project's
 * assigned planets.
 *
 * All scoped to the current user: projects belong to a user_id, and only the
 * user's own planets may be assigned.
 */
class PiProjectService
{
    private CharacterResolver $resolver;
    private PiSchematicService $schematics;
    private PlanetaryService $planetary;

    public function __construct(
        ?CharacterResolver $resolver = null,
        ?PiSchematicService $schematics = null,
        ?PlanetaryService $planetary = null
    ) {
        $this->resolver = $resolver ?? new CharacterResolver();
        $this->schematics = $schematics ?? new PiSchematicService();
        $this->planetary = $planetary ?? new PlanetaryService($this->resolver);
    }

    private function userId(): ?int
    {
        $u = $this->resolver->user();

        return $u ? (int) $u->id : null;
    }

    public function userProjects(): Collection
    {
        $uid = $this->userId();
        if (! $uid) {
            return collect();
        }

        return PiProject::where('user_id', $uid)
            ->withCount(['objectives', 'planets'])
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?PiProject
    {
        $uid = $this->userId();
        if (! $uid) {
            return null;
        }

        return PiProject::where('user_id', $uid)->where('id', $id)->first();
    }

    public function create(string $name, ?string $description): PiProject
    {
        return PiProject::create([
            'user_id' => $this->userId(),
            'name' => $name,
            'description' => $description,
        ]);
    }

    public function delete(PiProject $project): void
    {
        $project->objectives()->delete();
        $project->planets()->delete();
        $project->delete();
    }

    public function addObjective(PiProject $project, int $typeId, int $quantity): void
    {
        PiProjectObjective::create([
            'project_id' => $project->id,
            'type_id' => $typeId,
            'target_quantity' => max(0, $quantity),
        ]);
    }

    public function removeObjective(PiProject $project, int $objectiveId): void
    {
        PiProjectObjective::where('project_id', $project->id)->where('id', $objectiveId)->delete();
    }

    public function assignPlanet(PiProject $project, int $characterId, int $planetId): void
    {
        // Only the user's own planets may be assigned.
        if (! in_array($characterId, $this->resolver->characterIds(), true)) {
            return;
        }

        PiProjectPlanet::firstOrCreate([
            'project_id' => $project->id,
            'character_id' => $characterId,
            'planet_id' => $planetId,
        ]);
    }

    public function unassignPlanet(PiProject $project, int $id): void
    {
        PiProjectPlanet::where('project_id', $project->id)->where('id', $id)->delete();
    }

    /**
     * Compute the project plan: required materials (all tiers) vs the output
     * rates of assigned planets.
     *
     * @return array{project:PiProject, objectives:array, required:array, assigned_planets:array, available_planets:array}
     */
    public function overview(PiProject $project): array
    {
        $objectives = $project->objectives()->get();

        $required = [];
        $objList = [];
        foreach ($objectives as $o) {
            $name = DB::table('invTypes')->where('typeID', $o->type_id)->value('typeName') ?? ('Type #' . $o->type_id);
            $objList[] = [
                'id' => $o->id,
                'type_id' => (int) $o->type_id,
                'name' => $name,
                'target' => (int) $o->target_quantity,
            ];

            $tree = $this->schematics->tree((int) $o->type_id, $o->target_quantity ?: null);
            if ($tree) {
                $this->flatten($tree['root'], $required);
            } else {
                $this->addRequired($required, (int) $o->type_id, $name, null, (int) $o->target_quantity);
            }
        }

        // Supply rates (per hour) from assigned planets.
        $assigned = $project->planets()->get();
        $assignedSet = $assigned->map(fn ($a) => $a->character_id . ':' . $a->planet_id)->all();

        $supply = [];
        foreach ($this->planetary->extractors() as $e) {
            if (in_array($e['character_id'] . ':' . $e['planet_id'], $assignedSet, true)) {
                $supply[$e['product_type_id']] = ($supply[$e['product_type_id']] ?? 0) + $e['qty_per_hour'];
            }
        }
        foreach ($this->planetary->factories() as $f) {
            if ($f['output_type_id'] && $f['cycle_time'] && in_array($f['character_id'] . ':' . $f['planet_id'], $assignedSet, true)) {
                $rate = $f['output_qty'] * $f['factory_count'] * (3600 / $f['cycle_time']);
                $supply[$f['output_type_id']] = ($supply[$f['output_type_id']] ?? 0) + $rate;
            }
        }

        $reqList = array_values($required);
        foreach ($reqList as &$r) {
            $rate = $supply[$r['type_id']] ?? 0;
            $r['supplied_rate'] = (int) round($rate);
            $r['covered'] = $rate > 0;
        }
        unset($r);
        usort($reqList, fn ($a, $b) => [$b['tier'] ?? -1, $a['name']] <=> [$a['tier'] ?? -1, $b['name']]);

        // Assigned planet display + the user's still-unassigned planets.
        $charNames = DB::table('character_infos')
            ->whereIn('character_id', $this->resolver->characterIds() ?: [0])
            ->pluck('name', 'character_id');

        $assignedList = $assigned->map(function ($a) use ($charNames) {
            $pl = DB::table('planets')->where('planet_id', $a->planet_id)->value('name');

            return [
                'id' => $a->id,
                'character_id' => (int) $a->character_id,
                'character_name' => $charNames[$a->character_id] ?? ('Character #' . $a->character_id),
                'planet_id' => (int) $a->planet_id,
                'planet_name' => $pl ?? ('Planet #' . $a->planet_id),
            ];
        })->all();

        $available = $this->planetary->colonies()->filter(function ($c) use ($assignedSet) {
            return ! in_array($c['character_id'] . ':' . $c['planet_id'], $assignedSet, true);
        })->values()->all();

        return [
            'project' => $project,
            'objectives' => $objList,
            'required' => $reqList,
            'assigned_planets' => $assignedList,
            'available_planets' => $available,
        ];
    }

    private function flatten(array $node, array &$acc): void
    {
        $this->addRequired($acc, (int) $node['type_id'], $node['name'], $node['tier'] ?? null, (int) $node['quantity']);
        foreach (($node['children'] ?? []) as $c) {
            $this->flatten($c, $acc);
        }
    }

    private function addRequired(array &$acc, int $typeId, string $name, ?int $tier, int $qty): void
    {
        if (! isset($acc[$typeId])) {
            $acc[$typeId] = ['type_id' => $typeId, 'name' => $name, 'tier' => $tier, 'quantity' => 0];
        }
        $acc[$typeId]['quantity'] += $qty;
    }
}
