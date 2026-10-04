<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;

/**
 * ReactionService — browse list of reaction formulas (activityID 11).
 *
 * The per-formula breakdown (inputs / output / time / skills) reuses
 * ProductionCalculator::recipe($formulaTypeId, IndustryActivity::REACTIONS),
 * so there's a single recipe code path. This service only supplies the
 * searchable formula catalogue, cached per SDE version.
 */
class ReactionService
{
    private const TTL = 604800;

    /**
     * @return Collection<int,array>
     */
    public function formulas(): Collection
    {
        if (! IndustryData::isInstalled()) {
            return collect();
        }

        $cached = Cache::remember('im:reaction_formulas:' . $this->sdeVersion(), self::TTL, function () {
            return DB::table(IndustryData::TABLE_PRODUCTS . ' as p')
                ->leftJoin('invTypes as ft', 'ft.typeID', '=', 'p.typeID')
                ->leftJoin('invTypes as ot', 'ot.typeID', '=', 'p.productTypeID')
                ->where('p.activityID', IndustryActivity::REACTIONS)
                ->orderBy('ot.typeName')
                ->get([
                    'p.typeID as formula_type_id',
                    'ft.typeName as formula_name',
                    'p.productTypeID as output_type_id',
                    'ot.typeName as output_name',
                    'p.quantity as output_qty',
                ])
                ->map(fn ($r) => [
                    'formula_type_id' => (int) $r->formula_type_id,
                    'formula_name' => $r->formula_name ?? ('Formula #' . $r->formula_type_id),
                    'output_type_id' => (int) $r->output_type_id,
                    'output_name' => $r->output_name ?? ('Type #' . $r->output_type_id),
                    'output_qty' => (int) $r->output_qty,
                ])
                ->all();
        });

        return collect($cached);
    }

    private function sdeVersion(): string
    {
        try {
            $v = setting('installed_sde', true);

            return $v ? (string) $v : 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
