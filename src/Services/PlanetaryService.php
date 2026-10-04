<?php

namespace IndustryManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;

/**
 * PlanetaryService — reads the user's planetary colonies, extractors,
 * factories and stockpiles from SeAT's already-synced character_planet_*
 * tables. Pure read, scoped to the user's own characters, no ESI.
 *
 * The standout signal is extractor EXPIRY: character_planet_pins.expiry_time
 * tells us when an extractor stops (idle planet = wasted output). The overview
 * surfaces this with a live countdown, and expiringExtractors() powers an
 * "attention needed" panel.
 *
 * Planet/system names come from SeAT's post-explode `planets` + `solar_systems`
 * tables. Factory recipes come from the registered PI schematic SDE (guarded by
 * IndustryData::isPiInstalled()).
 */
class PlanetaryService
{
    private CharacterResolver $resolver;

    public function __construct(?CharacterResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new CharacterResolver();
    }

    private function charIds(): array
    {
        return $this->resolver->characterIds();
    }

    public function hasColonies(): bool
    {
        $ids = $this->charIds();
        if (empty($ids)) {
            return false;
        }

        return DB::table('character_planets')->whereIn('character_id', $ids)->exists();
    }

    /**
     * @return Collection<int,array>
     */
    public function colonies(): Collection
    {
        $ids = $this->charIds();
        if (empty($ids)) {
            return collect();
        }

        $names = DB::table('character_infos')->whereIn('character_id', $ids)->pluck('name', 'character_id');

        return DB::table('character_planets as cp')
            ->leftJoin('planets as pl', 'pl.planet_id', '=', 'cp.planet_id')
            ->leftJoin('solar_systems as ss', 'ss.system_id', '=', 'cp.solar_system_id')
            ->whereIn('cp.character_id', $ids)
            ->get([
                'cp.character_id', 'cp.planet_id', 'cp.planet_type', 'cp.upgrade_level',
                'cp.num_pins', 'cp.last_update',
                'pl.name as planet_name', 'pl.type_id as planet_type_id',
                'ss.name as system_name', 'ss.security',
            ])
            ->map(fn ($r) => [
                'character_id' => (int) $r->character_id,
                'character_name' => $names[$r->character_id] ?? ('Character #' . $r->character_id),
                'planet_id' => (int) $r->planet_id,
                'planet_name' => $r->planet_name ?? ('Planet #' . $r->planet_id),
                'planet_type' => ucfirst((string) $r->planet_type),
                'planet_type_id' => $r->planet_type_id !== null ? (int) $r->planet_type_id : null,
                'system_name' => $r->system_name ?? 'Unknown',
                'security' => $r->security !== null ? round((float) $r->security, 1) : null,
                'upgrade_level' => (int) $r->upgrade_level,
                'num_pins' => (int) $r->num_pins,
                'last_update' => $r->last_update,
            ])
            ->sortBy(['character_name', 'planet_name'])
            ->values();
    }

    /**
     * Flat list of extractor heads across all the user's colonies, soonest
     * expiry first.
     *
     * @return Collection<int,array>
     */
    public function extractors(): Collection
    {
        $ids = $this->charIds();
        if (empty($ids)) {
            return collect();
        }

        $names = DB::table('character_infos')->whereIn('character_id', $ids)->pluck('name', 'character_id');

        return DB::table('character_planet_extractors as e')
            ->join('character_planet_pins as p', function ($j) {
                $j->on('p.character_id', '=', 'e.character_id')
                    ->on('p.planet_id', '=', 'e.planet_id')
                    ->on('p.pin_id', '=', 'e.pin_id');
            })
            ->leftJoin('invTypes as t', 't.typeID', '=', 'e.product_type_id')
            ->leftJoin('planets as pl', 'pl.planet_id', '=', 'e.planet_id')
            ->whereIn('e.character_id', $ids)
            ->get([
                'e.character_id', 'e.planet_id', 'e.product_type_id', 'e.cycle_time', 'e.qty_per_cycle',
                'p.install_time', 'p.expiry_time',
                't.typeName as product_name', 'pl.name as planet_name',
            ])
            ->map(function ($r) use ($names) {
                $cycle = (int) $r->cycle_time;
                $qty = (int) $r->qty_per_cycle;

                return [
                    'character_id' => (int) $r->character_id,
                    'character_name' => $names[$r->character_id] ?? ('Character #' . $r->character_id),
                    'planet_id' => (int) $r->planet_id,
                    'planet_name' => $r->planet_name ?? ('Planet #' . $r->planet_id),
                    'product_type_id' => (int) $r->product_type_id,
                    'product_name' => $r->product_name ?? ('Type #' . $r->product_type_id),
                    'cycle_time' => $cycle,
                    'qty_per_cycle' => $qty,
                    'qty_per_hour' => $cycle > 0 ? (int) round((3600 / $cycle) * $qty) : 0,
                    'install_time' => $r->install_time,
                    'expiry_time' => $r->expiry_time,
                ];
            })
            ->sortBy('expiry_time')
            ->values();
    }

    /**
     * Extractors whose expiry falls within the next $withinHours. Powers the
     * "attention needed" panel. Extractors with no expiry are excluded.
     *
     * @return Collection<int,array>
     */
    public function expiringExtractors(int $withinHours = 24): Collection
    {
        $cutoff = Carbon::now()->addHours($withinHours);

        return $this->extractors()->filter(function ($e) use ($cutoff) {
            if (empty($e['expiry_time'])) {
                return false;
            }
            try {
                return Carbon::parse($e['expiry_time'])->lessThanOrEqualTo($cutoff);
            } catch (\Throwable $ex) {
                return false;
            }
        })->values();
    }

    /**
     * Factories grouped per (character, planet, schematic) with a count and
     * resolved output. One row per distinct production line.
     *
     * @return Collection<int,array>
     */
    public function factories(): Collection
    {
        $ids = $this->charIds();
        if (empty($ids)) {
            return collect();
        }

        // schematic_id is stored as a float on character_planet_factories;
        // grouping by the float is DB-agnostic (whole numbers group cleanly),
        // and we cast to int in PHP for the schematic lookup.
        $rows = DB::table('character_planet_factories as f')
            ->whereIn('f.character_id', $ids)
            ->select('f.character_id', 'f.planet_id', 'f.schematic_id', DB::raw('COUNT(*) as factory_count'))
            ->groupBy('f.character_id', 'f.planet_id', 'f.schematic_id')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $names = DB::table('character_infos')->whereIn('character_id', $ids)->pluck('name', 'character_id');
        $planetNames = DB::table('planets')
            ->whereIn('planet_id', $rows->pluck('planet_id')->unique()->all())
            ->pluck('name', 'planet_id');

        $schIds = $rows->pluck('schematic_id')->map(fn ($v) => (int) $v)->unique()->all();
        $sch = $this->schematicSummaries($schIds);

        return $rows->map(function ($r) use ($names, $planetNames, $sch) {
            $sid = (int) $r->schematic_id;
            $s = $sch[$sid] ?? null;

            return [
                'character_id' => (int) $r->character_id,
                'character_name' => $names[$r->character_id] ?? ('Character #' . $r->character_id),
                'planet_id' => (int) $r->planet_id,
                'planet_name' => $planetNames[$r->planet_id] ?? ('Planet #' . $r->planet_id),
                'schematic_id' => $sid,
                'schematic_name' => $s['name'] ?? ('Schematic #' . $sid),
                'output_type_id' => $s['output_type_id'] ?? null,
                'output_name' => $s['output_name'] ?? null,
                'output_qty' => $s['output_qty'] ?? null,
                'cycle_time' => $s['cycle_time'] ?? null,
                'factory_count' => (int) $r->factory_count,
            ];
        })->sortBy(['character_name', 'planet_name'])->values();
    }

    /**
     * Per-planet material stockpile (sum of contents across pins by type).
     *
     * @return Collection<int,array>
     */
    public function storage(): Collection
    {
        $ids = $this->charIds();
        if (empty($ids)) {
            return collect();
        }

        $names = DB::table('character_infos')->whereIn('character_id', $ids)->pluck('name', 'character_id');

        return DB::table('character_planet_contents as c')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'c.type_id')
            ->leftJoin('planets as pl', 'pl.planet_id', '=', 'c.planet_id')
            ->whereIn('c.character_id', $ids)
            ->select(
                'c.character_id', 'c.planet_id', 'c.type_id',
                DB::raw('SUM(c.amount) as amount'),
                't.typeName as type_name', 'pl.name as planet_name'
            )
            ->groupBy('c.character_id', 'c.planet_id', 'c.type_id', 't.typeName', 'pl.name')
            ->get()
            ->map(fn ($r) => [
                'character_id' => (int) $r->character_id,
                'character_name' => $names[$r->character_id] ?? ('Character #' . $r->character_id),
                'planet_id' => (int) $r->planet_id,
                'planet_name' => $r->planet_name ?? ('Planet #' . $r->planet_id),
                'type_id' => (int) $r->type_id,
                'type_name' => $r->type_name ?? ('Type #' . $r->type_id),
                'amount' => (int) $r->amount,
            ])
            ->sortBy(['character_name', 'planet_name', 'type_name'])
            ->values();
    }

    /**
     * Resolve name / cycle / output for a set of schematic IDs in two queries.
     *
     * @param  int[]  $schIds
     * @return array<int,array>
     */
    private function schematicSummaries(array $schIds): array
    {
        if (empty($schIds) || ! IndustryData::isPiInstalled()) {
            return [];
        }

        $base = DB::table(IndustryData::TABLE_PI_SCHEMATICS)
            ->whereIn('schematicID', $schIds)
            ->get(['schematicID', 'schematicName', 'cycleTime']);

        $outputs = DB::table(IndustryData::TABLE_PI_TYPEMAP . ' as m')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'm.typeID')
            ->whereIn('m.schematicID', $schIds)
            ->where('m.isInput', 0)
            ->get(['m.schematicID', 'm.typeID', 'm.quantity', 't.typeName']);

        $outMap = [];
        foreach ($outputs as $o) {
            $outMap[(int) $o->schematicID] = [
                'type_id' => (int) $o->typeID,
                'name' => $o->typeName,
                'qty' => (int) $o->quantity,
            ];
        }

        $res = [];
        foreach ($base as $b) {
            $sid = (int) $b->schematicID;
            $o = $outMap[$sid] ?? null;
            $res[$sid] = [
                'name' => $b->schematicName,
                'cycle_time' => (int) $b->cycleTime,
                'output_type_id' => $o['type_id'] ?? null,
                'output_name' => $o['name'] ?? null,
                'output_qty' => $o['qty'] ?? null,
            ];
        }

        return $res;
    }
}
