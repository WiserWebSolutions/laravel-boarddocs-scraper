<?php

use BoardDocsScraper\BoardDocsManager;
use BoardDocsScraper\Resources\Site;

if (! function_exists('BoardDocs')) {
    /**
     * Fluent entry point for the BoardDocs scraper.
     *
     * Called with no arguments it returns the manager:
     *   BoardDocs()->site('pa/phoe')->committees()->first()->agenda()->toPdf();
     *
     * Called with a site slug it returns that Site directly:
     *   BoardDocs('pa/phoe')->committees();
     */
    function BoardDocs(?string $site = null): BoardDocsManager|Site
    {
        /** @var BoardDocsManager $manager */
        $manager = app('boarddocs');

        return $site !== null ? $manager->site($site) : $manager;
    }
}
