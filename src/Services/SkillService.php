<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
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
     * Time multiplier (0,1] for one activity from a character's skills.
     * Multiply the activity's base time by this (then apply the blueprint's TE
     * levels and the structure/rig time bonuses separately).
     *
     * Manufacturing reads Industry + Advanced Industry; copying reads Science;
     * the two research types read Research and Metallurgy; invention and
     * reactions each read one skill. Advanced Industry is the only one that
     * covers several activities. See IndustrySkill::ACTIVITY_SKILLS.
     */
    public function timeMultiplier(int $characterId, int $activityId): float
    {
        return IndustrySkill::timeMultiplier($activityId, $this->levels($characterId));
    }

    /**
     * Manufacturing time multiplier, for callers that only deal with
     * manufacturing.
     */
    public function manufacturingTimeMultiplier(int $characterId): float
    {
        return $this->timeMultiplier($characterId, IndustryActivity::MANUFACTURING);
    }

    /**
     * Across a set of the user's characters, find the one best able to run a
     * recipe (meets all skills; tie-break on best time multiplier). Returns
     * null if none fully qualify.
     *
     * @param  int[]  $characterIds
     * @param  array  $requiredSkills
     */
    public function bestQualifiedCharacter(array $characterIds, array $requiredSkills, int $activityId = IndustryActivity::MANUFACTURING): ?array
    {
        $best = null;

        foreach ($characterIds as $cid) {
            $cid = (int) $cid;
            if (! $this->meets($cid, $requiredSkills)['ok']) {
                continue;
            }

            $mult = $this->timeMultiplier($cid, $activityId);
            if ($best === null || $mult < $best['time_multiplier']) {
                $best = ['character_id' => $cid, 'time_multiplier' => $mult];
            }
        }

        return $best;
    }

    /**
     * Across a set of characters, the one with the best time multiplier for an
     * activity, ignoring prerequisites. Used as a fallback when nobody fully
     * qualifies for a recipe, so a run is still attributed to someone.
     *
     * @param  int[]  $characterIds
     */
    public function bestTimeMultiplier(array $characterIds, int $activityId = IndustryActivity::MANUFACTURING): ?array
    {
        $best = null;

        foreach ($characterIds as $cid) {
            $cid = (int) $cid;
            $mult = $this->timeMultiplier($cid, $activityId);

            if ($best === null || $mult < $best['time_multiplier']) {
                $best = ['character_id' => $cid, 'time_multiplier' => $mult];
            }
        }

        return $best;
    }
}
