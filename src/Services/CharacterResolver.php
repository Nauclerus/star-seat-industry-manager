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
