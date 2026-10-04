<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustrySkill;

/**
 * SkillService — reads SeAT-synced character_skills and answers industry
 * questions: does a character meet a blueprint's skill prerequisites, and what
 * is their manufacturing-time multiplier.
 *
 * Skills affect TIME and ELIGIBILITY only — never material quantities.
 */
class SkillService
{
    /** Per-request memo of skill levels keyed by character_id. */
    private array $levelMemo = [];

    /**
     * trained_skill_level keyed by skill_id for a character.
     *
     * @return array<int,int>
     */
    public function levels(int $characterId): array
    {
        if (isset($this->levelMemo[$characterId])) {
            return $this->levelMemo[$characterId];
        }

        return $this->levelMemo[$characterId] = DB::table('character_skills')
            ->where('character_id', $characterId)
            ->pluck('trained_skill_level', 'skill_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    /**
     * Evaluate a character against a recipe's skill requirements.
     *
     * @param  array  $requiredSkills  list of ['skill_id','name','level'] from a recipe
     * @return array{ok:bool, missing:array, met:array}
     */
    public function meets(int $characterId, array $requiredSkills): array
    {
        $have = $this->levels($characterId);
        $missing = [];
        $met = [];

        foreach ($requiredSkills as $req) {
            $skillId = (int) ($req['skill_id'] ?? 0);
            $need = (int) ($req['level'] ?? 0);
            $cur = $have[$skillId] ?? 0;

            $entry = [
                'skill_id' => $skillId,
                'name' => $req['name'] ?? ('Skill #' . $skillId),
                'required' => $need,
                'have' => $cur,
            ];

            if ($cur >= $need) {
                $met[] = $entry;
            } else {
                $missing[] = $entry;
            }
        }

        return [
            'ok' => empty($missing),
            'missing' => $missing,
            'met' => $met,
        ];
    }

    /**
     * Manufacturing time multiplier (0,1] from the character's Industry +
     * Advanced Industry levels. Multiply blueprint base time by this (then
     * apply TE and structure/rig time bonuses separately).
     */
    public function manufacturingTimeMultiplier(int $characterId): float
    {
        $lv = $this->levels($characterId);

        return IndustrySkill::manufacturingTimeMultiplier(
            $lv[IndustrySkill::INDUSTRY] ?? 0,
            $lv[IndustrySkill::ADVANCED_INDUSTRY] ?? 0
        );
    }

    /**
     * Across a set of the user's characters, find the one best able to run a
     * recipe (meets all skills; tie-break on best time multiplier). Returns
     * null if none fully qualify.
     *
     * @param  int[]  $characterIds
     * @param  array  $requiredSkills
     * @return array{character_id:int, time_multiplier:float}|null
     */
    public function bestQualifiedCharacter(array $characterIds, array $requiredSkills): ?array
    {
        $best = null;

        foreach ($characterIds as $cid) {
            $cid = (int) $cid;
            if (! $this->meets($cid, $requiredSkills)['ok']) {
                continue;
            }

            $mult = $this->manufacturingTimeMultiplier($cid);
            if ($best === null || $mult < $best['time_multiplier']) {
                $best = ['character_id' => $cid, 'time_multiplier' => $mult];
            }
        }

        return $best;
    }
}
