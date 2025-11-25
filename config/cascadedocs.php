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
        // Directories to scan for source files
        'source' => env('CASCADEDOCS_SOURCE_PATHS', ['app/', 'resources/js/']),

        // Base output directory for documentation
        'output' => env('CASCADEDOCS_OUTPUT_PATH', 'docs/source_documents/'),

        // Log directory
        'logs' => env('CASCADEDOCS_LOGS_PATH', 'docs/'),

        // Module-specific paths
        'modules' => [
            'content' => env('CASCADEDOCS_MODULE_CONTENT_PATH', 'docs/source_documents/modules/content/'),
            'metadata' => env('CASCADEDOCS_MODULE_METADATA_PATH', 'docs/source_documents/modules/metadata/'),
        ],

        // Log and tracking files
        'tracking' => [
            'module_assignment' => 'docs/module-assignment-log.json',
            'feedback' => 'docs/module-assignment-feedback.txt',
            'generated_prompt' => 'docs/generated-assignment-prompt.md',
        ],

        // Other documentation paths
        'code_documentation' => 'docs/code_documentation',
        'architecture' => [
            'main' => 'system-architecture.md',
            'summary' => 'architecture-summary.md',
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

    // File extensions grouped by type
    'file_extensions' => [
        'php' => ['php'],
        'javascript' => ['js', 'vue', 'jsx', 'ts', 'tsx'],
        'documentation' => ['md'],
        'data' => ['json', 'yml', 'yaml'],
    ],

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
        'default_model' => env('CASCADEDOCS_AI_MODEL', 'gpt-5.1'),
        'filament_model' => env('CASCADEDOCS_FILAMENT_MODEL', 'gpt-4o-mini'),
        // Note: For thinking/reasoning models, temperature should be 1 (or omitted)
        'temperature' => env('CASCADEDOCS_AI_TEMPERATURE', 1),
        'thinking_effort' => env('CASCADEDOCS_AI_THINKING_EFFORT', 'high'),
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
        'micro' => 'short',
        'standard' => 'medium',
        'expansive' => 'full',
    ],

    // Array format for tier names
    'tier_names' => ['micro', 'standard', 'expansive'],
    'tier_directories' => ['short', 'medium', 'full'],

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
        'retry_attempts' => 3,
        'timeout' => 300, // 5 minutes
        'rate_limit_delay' => 60, // 60 seconds
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
            'Documentation/',
            'documentation',
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
    | Excluded Namespace Parts
    |--------------------------------------------------------------------------
    |
    | Namespace parts to exclude from module naming.
    |
    */
    'excluded_namespace_parts' => [
        'app', 'resources', 'js', 'php', 'src',
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Limits and Thresholds
    |--------------------------------------------------------------------------
    |
    | Configure various limits and thresholds used during processing.
    |
    */
    'limits' => [
        // Word count limits for different documentation types
        'word_counts' => [
            'micro_blurb' => 120,
            'standard_summary' => 500,
            'system_overview' => ['min' => 200, 'max' => 300],
            'executive_summary' => ['min' => 100, 'max' => 150],
        ],

        // Module detection thresholds
        'module_detection' => [
            'min_files_for_module' => 3,
            'min_files_for_conceptual_grouping' => 3,
            'min_files_in_directory' => 2,
            'min_word_length' => 2,
            'min_common_prefix_length' => 3,
            'confidence_divisor' => 10,
            'max_confidence' => 1.0,
        ],

        // File size limits
        'max_file_size' => env('CASCADEDOCS_MAX_FILE_SIZE', 50000), // 50KB
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
        'default_confidence_threshold' => 0.7,
        // Module granularity preference: 'granular' (smaller, focused modules) or 'consolidated' (larger, broader modules)
        'granularity' => env('CASCADEDOCS_MODULE_GRANULARITY', 'granular'),
        // Minimum files required to form a module
        'min_files_per_module' => env('CASCADEDOCS_MIN_FILES_PER_MODULE', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | File System Permissions
    |--------------------------------------------------------------------------
    |
    | Set the permissions for created directories and files.
    |
    */
    'permissions' => [
        'directory' => 0755,
        'file' => 0644,
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Settings specific to Filament documentation generation.
    |
    */
    'filament' => [
        'namespace_pattern' => 'use Filament\\',
        'livewire_path' => 'app/Livewire',
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
        'timeout' => env('CASCADEDOCS_TIMEOUT', 300),
        'memory_limit' => env('CASCADEDOCS_MEMORY_LIMIT', '512M'),
    ],
];
