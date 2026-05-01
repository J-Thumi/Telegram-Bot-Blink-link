<?php

return [
    'blink' => [
        'api_key' => env('BLINK_API_KEY'),
        'invoice_amount' => env('BLINK_INVOICE_AMOUNT', 100), // Amount in satoshis
        'graphql_url' => env('BLINK_GRAPHQL_URL', 'https://api.blink.sv/graphql'),
        'invoice_expiry' => env('BLINK_INVOICE_EXPIRY', 600), // Invoice expiry in seconds (default 10 minutes)
        'instant_buy_amount' => env('BLINK_AMOUNT_INSTANT_BUY_AMOUNT', 100), // Amount in satoshis for instant buy
    ],
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'channel_username' => env('TELEGRAM_CHANNEL_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'owner_id' => env('TELEGRAM_OWNER_ID'),
        'channel_id' => env('TELEGRAM_CHANNEL_ID'),
        'bot_user_id' => env('TELEGRAM_BOT_USER_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
