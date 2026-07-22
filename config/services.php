<?php

return [

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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_V2BOARD_REGION', 'us-east-1'),
    ],

    'dns_tool' => [
        'host'  => env('DNS_TOOL_HOST',  '127.0.0.1:8080'),
        'token' => env('DNS_TOOL_TOKEN', 'test'),
    ],

    'feishu' => [
        'project_traffic_report_webhook_url' => env('FEISHU_PROJECT_TRAFFIC_REPORT_WEBHOOK_URL', ''),
        'project_traffic_report_timeout_seconds' => env('FEISHU_PROJECT_TRAFFIC_REPORT_TIMEOUT_SECONDS', 10),
    ],

    'traffic_platform_service' => [
        'base_url' => env('TRAFFIC_PLATFORM_SERVICE_BASE_URL', 'http://127.0.0.1:8080'),
        'api_key' => env('TRAFFIC_PLATFORM_SERVICE_API_KEY', ''),
        'timeout_seconds' => env('TRAFFIC_PLATFORM_SERVICE_TIMEOUT_SECONDS', 15),
    ],

    'ad_spend_admin_user_sync' => [
        'enabled' => env('AD_SPEND_ADMIN_USER_SYNC_ENABLED', false),
        'account_id' => env('AD_SPEND_ADMIN_USER_SYNC_ACCOUNT_ID'),
        'platform_code' => env('AD_SPEND_ADMIN_USER_SYNC_PLATFORM_CODE', 'adsmakeup'),
        'team_id' => env('AD_SPEND_ADMIN_USER_SYNC_TEAM_ID', ''),
        'role_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('AD_SPEND_ADMIN_USER_SYNC_ROLE_IDS', ''))
        ))),
        'timeout_seconds' => env('AD_SPEND_ADMIN_USER_SYNC_TIMEOUT_SECONDS', 20),
    ],

];
