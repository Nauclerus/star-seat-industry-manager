<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\DB;

/**
 * CharacterResolver — answers "which characters and corporations may the
 * current user see?" using the same refresh_tokens / character_affiliations
 * pattern Blueprint Manager uses (BlueprintLibraryController::getUserCorporations).
 *
 * Scoping rules:
 *   - characterIds():       the user's own linked characters (always own).
 *   - ownCorporationIds():  the corps of the user's own characters (always own,
 *                           even for admins — this is the "default view" scope).
 *   - corporationIds():     null for superusers (= all corps), else own corps.
 *                           Used where an admin genuinely should see everything.
 *
 * Results are memoised per request.
 */
class CharacterResolver
{
    private ?array $charMemo = null;
    private ?array $ownCorpMemo = null;
    private ?array $allianceCorpMemo = null;

    public function user()
    {
        return auth()->user();
    }

    public function isSuperuser(): bool
    {
        $u = $this->user();

        return $u && method_exists($u, 'isAdmin') ? (bool) $u->isAdmin() : false;
    }

    /**
     * The user's own linked character IDs.
     *
     * @return int[]
     */
    public function characterIds(): array
    {
        if ($this->charMemo !== null) {
            return $this->charMemo;
        }

        $u = $this->user();
        if (! $u) {
            return $this->charMemo = [];
        }

        return $this->charMemo = DB::table('refresh_tokens')
            ->where('user_id', $u->id)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The corporation IDs of the user's own characters. Always the user's own,
     * regardless of admin status — this is the default scope for "my industry".
     *
     * @return int[]
     */
    public function ownCorporationIds(): array
    {
        if ($this->ownCorpMemo !== null) {
            return $this->ownCorpMemo;
        }

        $u = $this->user();
        if (! $u) {
            return $this->ownCorpMemo = [];
        }

        return $this->ownCorpMemo = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $u->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Corporations of the alliances the user's characters belong to — the user's
     * own corps plus their alliance-mates.
     *
     * Structures are an alliance-wide question: a run can be done in a corp next
     * door. Jobs are not, which is why this is separate from ownCorporationIds()
     * and only the structure queries use it.
     *
     * @return int[]
     */
    public function allianceCorporationIds(): array
    {
        if ($this->allianceCorpMemo !== null) {
            return $this->allianceCorpMemo;
        }

        $u = $this->user();

        if (!$u) {
            return $this->allianceCorpMemo = [];
        }

        $allianceIds = DB::table('refresh_tokens as rt')
            ->join('character_affiliations as ca', 'ca.character_id', '=', 'rt.character_id')
            ->where('rt.user_id', $u->id)
            ->whereNull('rt.deleted_at')
            ->whereNotNull('ca.alliance_id')
            ->pluck('ca.alliance_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($allianceIds)) {
            return $this->allianceCorpMemo = $this->ownCorporationIds();
        }

        $corpIds = DB::table('corporation_infos')
            ->whereIn('alliance_id', $allianceIds)
            ->pluck('corporation_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->allianceCorpMemo = array_values(array_unique(array_merge($this->ownCorporationIds(), $corpIds)));
    }

    /**
     * May the user use this corporation's structures? Their own corps, their
     * alliance's corps, and everything for a superuser.
     */
    public function canUseCorporation(int $corporationId): bool
    {
        if ($this->isSuperuser()) {
            return true;
        }

        return in_array($corporationId, $this->allianceCorporationIds(), true);
    }

    /**
     * Corp IDs for access checks. null = superuser (all corps).
     *
     * @return int[]|null
     */
    public function corporationIds(): ?array
    {
        if (! $this->user()) {
            return [];
        }

        if ($this->isSuperuser()) {
            return null;
        }

        return $this->ownCorporationIds();
    }

    /**
     * May the user view the given corporation's data?
     */
    public function canViewCorporation(int $corporationId): bool
    {
        if ($this->isSuperuser()) {
            return true;
        }

        return in_array($corporationId, $this->ownCorporationIds(), true);
    }
}
