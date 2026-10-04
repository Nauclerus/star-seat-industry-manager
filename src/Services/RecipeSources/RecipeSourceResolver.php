<?php

namespace IndustryManager\Services\RecipeSources;

/**
 * Picks the recipe source for this install.
 *
 * CCP's official JSONL SDE is always preferred: it is the authoritative source
 * and it appears the day a patch lands, while the community dumps follow it.
 * FuzzworkSource is the fallback, reached when CCP cannot be contacted or when
 * an operator explicitly asks for it with --source=fuzzwork.
 *
 * Both write only to the plugin's own tables, so this choice never affects core.
 */
class RecipeSourceResolver
{
    /**
     * All sources, best first. Used by the Settings page to show what the
     * fallback would be.
     *
     * @return array<string, string>  name => label
     */
    public static function available(): array
    {
        $all = [];

        foreach (['ccp-jsonl', 'fuzzwork'] as $name) {
            $all[$name] = self::make($name)->label();
        }

        return $all;
    }

    /**
     * The source that will actually run on this install.
     */
    public static function preferred(): RecipeSource
    {
        return new CcpJsonlSource();
    }

    /**
     * The source to fall back to when the preferred one cannot deliver.
     */
    public static function fallback(): RecipeSource
    {
        return new FuzzworkSource();
    }

    /**
     * A source by name, for an explicit override.
     */
    public static function make(string $name): RecipeSource
    {
        return $name === 'ccp-jsonl' ? new CcpJsonlSource() : new FuzzworkSource();
    }

    /**
     * Preferred source name, for the UI.
     */
    public static function preferredName(): string
    {
        return 'ccp-jsonl';
    }
}
