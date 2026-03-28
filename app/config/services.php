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

    'bedrock' => [
        'region' => env('AWS_BEDROCK_REGION', 'ap-northeast-1'),
    ],

    // System health metrics: CloudWatch ECS + RDS identifiers
    'system_metrics' => [
        'ecs_cluster'  => env('AWS_ECS_CLUSTER',  'kps-dev-cluster'),
        'ecs_service'  => env('AWS_ECS_SERVICE',  'kps-dev-app'),
        'rds_instance' => env('AWS_RDS_INSTANCE', 'kps-dev-postgres'),
    ],

];
