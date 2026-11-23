<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Card Data Spreadsheet (legacy)
    |--------------------------------------------------------------------------
    |
    | Canticle's card data (locations, relics, glyphs, golem cards) USED TO
    | live in a Google Sheet, pulled via canticle:sync-cards into
    | resources/data/canticle as JSON. The database is the source of truth
    | now (see the admin card editor + App\Game\Catalog\*Catalog, which
    | read from the DB). canticle:sync-cards is left in place but unused —
    | canticle:pull-changes now keeps these same JSON files up to date
    | instead, refreshed from prod's public /api/cards on every pull, purely
    | so card data has a readable git history independent of DB access.
    |
    */

    'sheet_id' => env('CANTICLE_SHEET_ID', '1xRufGjfR3NB1XXasTg9pZDCyzSATx0j9Wq8QbB8PeKk'),

    'sheets' => [
        'locations',
        'relics',
        'glyphs',
        'golem_cards',
        'key',
    ],

    'output_path' => resource_path('data/canticle'),

    /*
    |--------------------------------------------------------------------------
    | Prod sync
    |--------------------------------------------------------------------------
    |
    | Where canticle:pull-changes and canticle:resolve-comment reach to —
    | the production site's dev-sync API (routes/dev_sync.php), gated by
    | the same DEBUG_API_KEY as /api/debug/*.
    |
    */

    'prod_url' => env('PROD_URL'),

];
