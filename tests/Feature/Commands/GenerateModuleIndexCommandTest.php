<?php

use Illuminate\Support\Facades\File;

describe('GenerateModuleIndexCommand', function () {
    beforeEach(function () {
        $this->metadataPath = base_path(config('cascadedocs.paths.modules.metadata'));
        $this->outputPath = base_path(config('cascadedocs.output_directory', 'docs/source_documents')).'/modules';

        // Ensure directories exist
        File::makeDirectory($this->metadataPath, 0755, true, true);
        File::makeDirectory($this->outputPath, 0755, true, true);
    });

    afterEach(function () {
        // Clean up test files
        File::deleteDirectory($this->metadataPath);
        File::deleteDirectory($this->outputPath);
    });

    it('generates module index with correct structure', function () {
        // Create test metadata files
        createTestMetadata($this->metadataPath, 'user-management', [
            'module_name' => 'User Management',
            'module_slug' => 'user-management',
            'module_summary' => 'Handles user authentication, profiles, and permissions.',
            'files' => [
                ['path' => 'app/Models/User.php', 'documented' => true],
                ['path' => 'app/Http/Controllers/UserController.php', 'documented' => true],
            ],
            'undocumented_files' => ['app/Services/UserService.php'],
        ]);

        createTestMetadata($this->metadataPath, 'api-integration', [
            'module_name' => 'API Integration',
            'module_slug' => 'api-integration',
            'module_summary' => 'Manages external API connections and data synchronization.',
            'files' => [
                ['path' => 'app/Services/ApiClient.php', 'documented' => true],
            ],
            'undocumented_files' => [],
        ]);

        // Run the command
        $this->artisan('cascadedocs:generate-module-index')
            ->expectsOutput('Generating module index...')
            ->expectsOutput('Module index generated successfully at: '.$this->outputPath.'/index.md')
            ->expectsOutput('Total modules indexed: 2')
            ->assertExitCode(0);

        // Check the generated file
        $indexPath = $this->outputPath.'/index.md';
        expect(file_exists($indexPath))->toBeTrue();

        $content = File::get($indexPath);

        // Check structure
        expect($content)->toContain('# Module Index');
        expect($content)->toContain('## Table of Contents');
        expect($content)->toContain('## Module Summary');
        expect($content)->toContain('## Module Details');

        // Check module entries
        expect($content)->toContain('[API Integration](#api-integration)');
        expect($content)->toContain('[User Management](#user-management)');

        // Check table
        expect($content)->toContain('| [API Integration](content/api-integration.md) | 1 |');
        expect($content)->toContain('| [User Management](content/user-management.md) | 2 (1 undocumented) |');

        // Check summaries
        expect($content)->toContain('Handles user authentication, profiles, and permissions.');
        expect($content)->toContain('Manages external API connections and data synchronization.');
    });

    it('handles missing metadata directory', function () {
        // Remove metadata directory
        File::deleteDirectory($this->metadataPath);

        $this->artisan('cascadedocs:generate-module-index')
            ->expectsOutput('Generating module index...')
            ->assertExitCode(1);
    });

    it('handles empty metadata directory', function () {
        // Ensure directory exists but is empty
        File::cleanDirectory($this->metadataPath);

        $this->artisan('cascadedocs:generate-module-index')
            ->expectsOutput('Generating module index...')
            ->assertExitCode(1);
    });

    it('handles metadata with missing optional fields', function () {
        // Create metadata with minimal structure
        createTestMetadata($this->metadataPath, 'minimal-module', [
            'module_name' => 'Minimal Module',
            'module_slug' => 'minimal-module',
            // No module_summary
            'files' => [],
            // No undocumented_files
        ]);

        $this->artisan('cascadedocs:generate-module-index')
            ->assertExitCode(0);

        $content = File::get($this->outputPath.'/index.md');
        expect($content)->toContain('No summary available');
        expect($content)->toContain('| [Minimal Module](content/minimal-module.md) | 0 |');
    });

    it('truncates long summaries in table', function () {
        $longSummary = str_repeat('This is a very long summary that should be truncated. ', 10);

        createTestMetadata($this->metadataPath, 'long-summary', [
            'module_name' => 'Long Summary Module',
            'module_slug' => 'long-summary',
            'module_summary' => $longSummary,
            'files' => [],
            'undocumented_files' => [],
        ]);

        $this->artisan('cascadedocs:generate-module-index')
            ->assertExitCode(0);

        $content = File::get($this->outputPath.'/index.md');

        // Check that summary is truncated in table
        expect($content)->toContain('...');

        // But full summary should appear in details section
        expect($content)->toContain($longSummary);
    });

    it('uses custom output path when provided', function () {
        $customPath = base_path('custom-output/index.md');

        createTestMetadata($this->metadataPath, 'test-module', [
            'module_name' => 'Test Module',
            'module_slug' => 'test-module',
            'module_summary' => 'Test summary',
            'files' => [],
            'undocumented_files' => [],
        ]);

        $this->artisan('cascadedocs:generate-module-index', [
            '--output' => $customPath,
        ])
            ->expectsOutput("Module index generated successfully at: {$customPath}")
            ->assertExitCode(0);

        expect(file_exists($customPath))->toBeTrue();

        // Clean up
        File::delete($customPath);
        File::deleteDirectory(dirname($customPath));
    });
});

function createTestMetadata(string $metadataPath, string $slug, array $data): void
{
    $defaults = [
        'doc_version' => '1.0',
        'generated_at' => now()->toIso8601String(),
        'last_updated' => now()->toIso8601String(),
        'git_commit_sha' => 'test-sha',
        'statistics' => [
            'total_files' => count($data['files'] ?? []) + count($data['undocumented_files'] ?? []),
            'documented_files' => count($data['files'] ?? []),
            'undocumented_files' => count($data['undocumented_files'] ?? []),
        ],
    ];

    $metadata = array_merge($defaults, $data);

    File::put(
        "{$metadataPath}/{$slug}.json",
        json_encode($metadata, JSON_PRETTY_PRINT)
    );
}
