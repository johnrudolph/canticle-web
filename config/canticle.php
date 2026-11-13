<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Card Data Spreadsheet
    |--------------------------------------------------------------------------
    |
    | Canticle's card data (locations, relics, glyphs, golem cards) lives in
    | a Google Sheet that the game designer edits directly. The
    | canticle:sync-cards command pulls each tab and writes it into
    | resources/data/canticle as JSON so the app can consume it without a
    | network call at runtime.
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

];
