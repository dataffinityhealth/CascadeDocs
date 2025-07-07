<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Documentation Paths
    |--------------------------------------------------------------------------
    |
    | Configure the paths for your documentation system.
    |
    */
    'paths' => [
        'source' => env('CASCADEDOCS_SOURCE_PATHS', ['app/', 'resources/js/']),
        'output' => env('CASCADEDOCS_OUTPUT_PATH', 'docs/source_documents/'),
        'logs' => env('CASCADEDOCS_LOGS_PATH', 'docs/cascadedocs_logs/'),
        'modules' => [
            'content' => env('CASCADEDOCS_MODULE_CONTENT_PATH', 'docs/source_documents/modules/content/'),
            'metadata' => env('CASCADEDOCS_MODULE_METADATA_PATH', 'docs/source_documents/modules/metadata/'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Types
    |--------------------------------------------------------------------------
    |
    | The file types that will be documented.
    |
    */
    'file_types' => env('CASCADEDOCS_FILE_TYPES', ['php', 'js', 'vue', 'jsx', 'ts', 'tsx']),

    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the AI provider and model settings.
    |
    */
    'ai' => [
        'default_provider' => env('CASCADEDOCS_AI_PROVIDER', 'openai'),
        'default_model' => env('CASCADEDOCS_AI_MODEL', 'gpt-o3'),
        'temperature' => env('CASCADEDOCS_AI_TEMPERATURE', 0.3),
        'max_tokens' => env('CASCADEDOCS_AI_MAX_TOKENS', 25000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation Tiers
    |--------------------------------------------------------------------------
    |
    | The different levels of documentation detail.
    |
    */
    'tiers' => [
        'short' => [
            'name' => 'micro',
            'description' => 'Brief summary documentation',
        ],
        'medium' => [
            'name' => 'standard',
            'description' => 'Standard level documentation',
        ],
        'full' => [
            'name' => 'expansive',
            'description' => 'Comprehensive documentation',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the queue settings for documentation jobs.
    |
    */
    'queue' => [
        'connection' => env('CASCADEDOCS_QUEUE_CONNECTION', null),
        'name' => env('CASCADEDOCS_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    |
    | Files and directories to exclude from documentation.
    |
    */
    'exclude' => [
        'directories' => [
            'vendor',
            'node_modules',
            'storage',
            'bootstrap/cache',
            '.git',
            '.idea',
            '.vscode',
        ],
        'files' => [
            '.env',
            '.env.example',
            'composer.lock',
            'package-lock.json',
            'yarn.lock',
        ],
        'patterns' => [
            '*.min.js',
            '*.min.css',
            '*.map',
            '*Test.php',
            '*Seeder.php',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Assignment
    |--------------------------------------------------------------------------
    |
    | Configuration for module assignment behavior.
    |
    */
    'modules' => [
        'auto_assign' => env('CASCADEDOCS_AUTO_ASSIGN_MODULES', true),
        'assignment_log' => 'module-assignment-log.json',
        'update_log' => 'documentation-update-log.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance
    |--------------------------------------------------------------------------
    |
    | Performance-related configuration.
    |
    */
    'performance' => [
        'chunk_size' => env('CASCADEDOCS_CHUNK_SIZE', 10),
        'timeout' => env('CASCADEDOCS_TIMEOUT', 300),
        'memory_limit' => env('CASCADEDOCS_MEMORY_LIMIT', '512M'),
    ],
];
