<?php

namespace IndustryManager\Services\RecipeSources;

/**
 * Picks the recipe source for this install.
 *
 * CcpJsonlSource is preferred whenever SeAT core can provide it — checked by the
 * seeder classes being present, not by a config flag, so the plugin switches
 * automatically the day that lands. FuzzworkSource is the fallback and is what
 * runs on stock SeAT v5 today.
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
        return CcpJsonlSource::available() ? new CcpJsonlSource() : new FuzzworkSource();
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
        return CcpJsonlSource::available() ? 'ccp-jsonl' : 'fuzzwork';
    }
}
