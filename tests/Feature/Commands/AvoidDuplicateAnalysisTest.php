<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

describe('Avoid Duplicate Analysis', function () {
    beforeEach(function () {
        // Set up config paths
        Config::set('cascadedocs.paths.tracking.module_assignment', 'docs/module-assignment-log.json');
        Config::set('cascadedocs.paths.modules.metadata', 'docs/module_metadata/');
        Config::set('cascadedocs.paths.modules.content', 'docs/source_documents/modules/');
        Config::set('cascadedocs.paths.output', 'docs/source_documents/');
        Config::set('cascadedocs.tier_directories', ['full', 'medium', 'short']);
        Config::set('cascadedocs.ai.default_model', 'gpt-4o');
        Config::set('cascadedocs.ai.default_provider', 'openai');
        
        // Create test directories
        File::makeDirectory(base_path('docs'), 0755, true, true);
        File::makeDirectory(base_path('docs/source_documents'), 0755, true, true);
        File::makeDirectory(base_path('docs/module_metadata'), 0755, true, true);

        // Clean up any existing files
        if (File::exists(base_path('docs/module-assignment-log.json'))) {
            File::delete(base_path('docs/module-assignment-log.json'));
        }
    });

    afterEach(function () {
        // Clean up
        File::deleteDirectory(base_path('docs'));
    });

    it('uses existing analysis when available', function () {
        // Create existing analysis log
        $existingLog = [
            'last_analysis' => now()->toIso8601String(),
            'assigned_files' => [
                'test-module' => ['app/Test.php'],
            ],
            'unassigned_files' => ['app/Unassigned.php'],
            'do_not_document' => [],
            'potential_modules' => [],
            'module_suggestions' => [],
            'ai_created_modules' => [],
        ];

        File::put(
            base_path('docs/module-assignment-log.json'),
            json_encode($existingLog, JSON_PRETTY_PRINT)
        );

        // Create some dummy documentation files so getUnassignedFilesWithDocs works
        File::makeDirectory(base_path('docs/source_documents/short/app'), 0755, true, true);
        File::put(base_path('docs/source_documents/short/app/Unassigned.php.md'), '# Unassigned file doc');

        // Run command - should use existing analysis
        $this->artisan('documentation:assign-files-to-modules', ['--dry-run' => true])
            ->expectsOutput('Loading current module assignments...')
            ->doesntExpectOutput('No existing analysis found. Running initial analysis...')
            ->expectsOutput('Found 1 unassigned files.')
            ->assertExitCode(0);
    });

    it('runs analysis only when no log exists', function () {
        // Ensure no log exists
        $this->assertFalse(File::exists(base_path('docs/module-assignment-log.json')));

        // Mock HTTP for the AI call
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'modules' => [],
                                'unassigned_files' => [],
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], 200),
        ]);

        // Run command - should detect no log and run analysis
        $this->artisan('documentation:assign-files-to-modules', ['--dry-run' => true])
            ->expectsOutput('Loading current module assignments...')
            ->expectsOutput('No existing analysis found. Running initial analysis...')
            ->assertExitCode(0);
    });

    it('full flow does not duplicate analysis', function () {
        // Mock HTTP responses for the flow
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'modules' => [
                                    [
                                        'module_name' => 'Test Module',
                                        'module_slug' => 'test-module',
                                        'description' => 'Test module for testing',
                                        'files' => ['app/Test.php'],
                                    ],
                                ],
                                'unassigned_files' => ['app/Unassigned.php'],
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], 200),
        ]);

        // Create some dummy documentation files
        File::makeDirectory(base_path('docs/source_documents/short/app'), 0755, true, true);
        File::put(base_path('docs/source_documents/short/app/Test.php.md'), '# Test file doc');
        File::put(base_path('docs/source_documents/short/app/Unassigned.php.md'), '# Unassigned file doc');

        // Step 1: Run analyze-modules (creates the initial analysis)
        $this->artisan('documentation:analyze-modules', ['--update' => true])
            ->expectsOutput('Analyzing module assignments using AI...')
            ->assertExitCode(0);

        // Verify log was created
        $this->assertTrue(File::exists(base_path('docs/module-assignment-log.json')));
        $log = json_decode(File::get(base_path('docs/module-assignment-log.json')), true);
        $this->assertNotNull($log['last_analysis']);

        // Step 2: Run assign-files-to-modules (should NOT re-analyze)
        $this->artisan('documentation:assign-files-to-modules', ['--dry-run' => true])
            ->expectsOutput('Loading current module assignments...')
            ->doesntExpectOutput('No existing analysis found. Running initial analysis...')
            ->expectsOutput('Found 1 unassigned files.')
            ->assertExitCode(0);
    });
});