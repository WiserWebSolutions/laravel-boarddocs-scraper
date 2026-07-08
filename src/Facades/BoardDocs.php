<?php

namespace BoardDocsScraper\Facades;

use BoardDocsScraper\Resources\Site;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Site site(?string $site = null)
 * @method static \BoardDocsScraper\Client\BoardDocsClient client(?string $site = null)
 * @method static \BoardDocsScraper\Index\IndexSearcher searcher()
 * @method static \BoardDocsScraper\Index\IndexBuilder indexBuilder()
 * @method static array config()
 *
 * @see \BoardDocsScraper\BoardDocsManager
 */
class BoardDocs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'boarddocs';
    }
}
