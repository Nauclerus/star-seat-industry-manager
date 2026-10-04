<?php

namespace IndustryManager\Tests\Feature;

use IndustryManager\Services\RecipeSources\CcpJsonlSource;
use IndustryManager\Services\RecipeSources\FuzzworkSource;
use IndustryManager\Services\RecipeSources\RecipeSourceResolver;
use IndustryManager\Tests\TestCase;

/**
 * CCP's own JSONL SDE is the source; the community dumps are only a fallback.
 */
class RecipeSourceResolverTest extends TestCase
{
    public function test_ccp_jsonl_is_always_preferred(): void
    {
        $this->assertSame('ccp-jsonl', RecipeSourceResolver::preferredName());
        $this->assertInstanceOf(CcpJsonlSource::class, RecipeSourceResolver::preferred());
    }

    public function test_fuzzwork_is_the_fallback(): void
    {
        $this->assertInstanceOf(FuzzworkSource::class, RecipeSourceResolver::fallback());
    }

    public function test_explicit_overrides_are_honoured(): void
    {
        $this->assertInstanceOf(FuzzworkSource::class, RecipeSourceResolver::make('fuzzwork'));
        $this->assertInstanceOf(CcpJsonlSource::class, RecipeSourceResolver::make('ccp-jsonl'));
    }

    public function test_both_sources_are_listed_ccp_first(): void
    {
        $this->assertSame(
            ['ccp-jsonl', 'fuzzwork'],
            array_keys(RecipeSourceResolver::available())
        );
    }
}
