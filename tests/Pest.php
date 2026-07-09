<?php

use BoardDocsScraper\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * The Feature/Ai/ suite exercises BoardDocsAgent, the AI SDK tool classes,
 * and VectorStoreSync — all of which need laravel/ai. It's a require-dev
 * dependency (kept out of require so consumers aren't forced to install it),
 * so each test in that suite calls this first to skip gracefully instead of
 * fataling when it's absent.
 */
function skipUnlessAiSdkInstalled(): void
{
    if (! class_exists(\Laravel\Ai\AiServiceProvider::class)) {
        test()->markTestSkipped('laravel/ai is not installed — run `composer require laravel/ai` to run the AI test suite.');
    }
}
