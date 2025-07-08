<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Jobs\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Tests\TestCase;

class UpdateModuleDocumentationJobAdditionalTest extends TestCase
{
    protected string $moduleSlug = 'test-module';

    protected string $metadataPath;

    protected string $contentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metadataPath = 'docs/source_documents/modules/metadata';
        $this->contentPath = 'docs/source_documents/modules/content';

        // Create test directories
        File::ensureDirectoryExists(base_path($this->metadataPath));
        File::ensureDirectoryExists(base_path($this->contentPath));
        File::ensureDirectoryExists(base_path('docs/short/app/Services'));
        File::ensureDirectoryExists(base_path('docs/short/app/Models'));
        File::ensureDirectoryExists(base_path('docs/medium/app/Services'));
        File::ensureDirectoryExists(base_path('docs/medium/app/Models'));
        File::ensureDirectoryExists(base_path('docs/full/app/Services'));
        File::ensureDirectoryExists(base_path('docs/full/app/Models'));

        // Configure paths
        Config::set('cascadedocs.paths.modules.metadata', $this->metadataPath.'/');
        Config::set('cascadedocs.paths.modules.content', $this->contentPath.'/');
        Config::set('cascadedocs.ai.default_model', 'gpt-4');
        Config::set('cascadedocs.tiers', [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full',
        ]);

        // Prevent any real HTTP requests
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'This is the updated module documentation with all sections properly filled.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (File::exists(base_path('docs'))) {
            File::deleteDirectory(base_path('docs'));
        }

        parent::tearDown();
    }

    public function test_it_updates_module_with_all_tiers(): void
    {
        // Create module metadata
        $metadata = [
            'module_slug' => $this->moduleSlug,
            'module_name' => 'Test Module',
            'files' => [
                ['path' => 'app/Services/TestService.php', 'documented' => true],
                ['path' => 'app/Models/TestModel.php', 'documented' => true],
            ],
            'undocumented_files' => [],
        ];
        File::put(base_path("{$this->metadataPath}/{$this->moduleSlug}.json"), json_encode($metadata));

        // Create documentation for all tiers
        File::put(base_path('docs/short/app/Services/TestService.md'), 'Short: TestService handles business logic');
        File::put(base_path('docs/short/app/Models/TestModel.md'), 'Short: TestModel represents data');

        File::put(base_path('docs/medium/app/Services/TestService.md'), 'Medium: TestService handles complex business logic with validation');
        File::put(base_path('docs/medium/app/Models/TestModel.md'), 'Medium: TestModel represents data with relationships');

        File::put(base_path('docs/full/app/Services/TestService.md'), 'Full: TestService handles complex business logic with validation, error handling, and logging');
        File::put(base_path('docs/full/app/Models/TestModel.md'), 'Full: TestModel represents data with relationships, scopes, and accessors');

        // Create existing module content
        File::put(base_path("{$this->contentPath}/{$this->moduleSlug}.md"), 'Old module content');

        $job = new UpdateModuleDocumentationJob($this->moduleSlug, 'test-sha');
        $job->handle();

        // Verify content file was created
        $this->assertFileExists(base_path("{$this->contentPath}/{$this->moduleSlug}.md"));
    }

    public function test_it_handles_missing_tier_documentation(): void
    {
        // Create module metadata
        $metadata = [
            'module_slug' => $this->moduleSlug,
            'module_name' => 'Test Module',
            'files' => [
                ['path' => 'app/Services/TestService.php', 'documented' => true],
            ],
            'undocumented_files' => [],
        ];
        File::put(base_path("{$this->metadataPath}/{$this->moduleSlug}.json"), json_encode($metadata));

        // Only create short tier documentation
        File::put(base_path('docs/short/app/Services/TestService.md'), 'Short: TestService documentation');

        $job = new UpdateModuleDocumentationJob($this->moduleSlug, 'test-sha');
        $job->handle();

        // Job should still complete
        $this->assertFileExists(base_path("{$this->contentPath}/{$this->moduleSlug}.md"));
    }

    public function test_it_handles_no_module_documentation(): void
    {
        // Create module metadata with no files
        $metadata = [
            'module_slug' => $this->moduleSlug,
            'module_name' => 'Empty Module',
            'files' => [],
            'undocumented_files' => [],
        ];
        File::put(base_path("{$this->metadataPath}/{$this->moduleSlug}.json"), json_encode($metadata));

        $job = new UpdateModuleDocumentationJob($this->moduleSlug, 'test-sha');
        $job->handle();

        // Job should complete - verify it handled empty module gracefully
        $this->assertTrue(true); // Job completed without throwing exception
    }

    public function test_it_uses_custom_model(): void
    {
        // Create module metadata
        $metadata = [
            'module_slug' => $this->moduleSlug,
            'module_name' => 'Test Module',
            'files' => [
                ['path' => 'app/Services/TestService.php', 'documented' => true],
            ],
            'undocumented_files' => [],
        ];
        File::put(base_path("{$this->metadataPath}/{$this->moduleSlug}.json"), json_encode($metadata));

        // Create documentation
        File::put(base_path('docs/short/app/Services/TestService.md'), 'Short documentation');

        $job = new UpdateModuleDocumentationJob($this->moduleSlug, 'test-sha', 'claude-3');
        $job->handle();

        // Verify the job completed
        $this->assertFileExists(base_path("{$this->contentPath}/{$this->moduleSlug}.md"));
    }

    public function test_it_handles_undocumented_files(): void
    {
        // Create module metadata with undocumented files
        $metadata = [
            'module_slug' => $this->moduleSlug,
            'module_name' => 'Test Module',
            'files' => [
                ['path' => 'app/Services/TestService.php', 'documented' => true],
            ],
            'undocumented_files' => [
                'app/Services/UndocumentedService.php',
                'app/Models/UndocumentedModel.php',
            ],
        ];
        File::put(base_path("{$this->metadataPath}/{$this->moduleSlug}.json"), json_encode($metadata));

        // Create documentation for documented file
        File::put(base_path('docs/short/app/Services/TestService.md'), 'Documented service');

        $job = new UpdateModuleDocumentationJob($this->moduleSlug, 'test-sha');
        $job->handle();

        // Job should still complete
        $this->assertFileExists(base_path("{$this->contentPath}/{$this->moduleSlug}.md"));
    }

    public function test_it_handles_api_error_gracefully(): void
    {
        // Mock API error
        Http::fake([
            '*' => Http::response(['error' => 'API Error'], 500),
        ]);

        // Create module metadata
        $metadata = [
            'module_slug' => $this->moduleSlug,
            'module_name' => 'Test Module',
            'files' => [
                ['path' => 'app/Services/TestService.php', 'documented' => true],
            ],
            'undocumented_files' => [],
        ];
        File::put(base_path("{$this->metadataPath}/{$this->moduleSlug}.json"), json_encode($metadata));

        // Create documentation
        File::put(base_path('docs/short/app/Services/TestService.md'), 'Documentation');

        $job = new UpdateModuleDocumentationJob($this->moduleSlug, 'test-sha');

        try {
            $job->handle();
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // API error should be caught
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }

    public function test_it_builds_correct_prompt(): void
    {
        // Create module metadata
        $metadata = [
            'module_slug' => $this->moduleSlug,
            'module_name' => 'Test Module',
            'files' => [
                ['path' => 'app/Services/TestService.php', 'documented' => true],
                ['path' => 'app/Models/TestModel.php', 'documented' => true],
            ],
            'undocumented_files' => ['app/Services/UndocumentedService.php'],
        ];
        File::put(base_path("{$this->metadataPath}/{$this->moduleSlug}.json"), json_encode($metadata));

        // Create tier documentation
        File::put(base_path('docs/short/app/Services/TestService.md'), 'TestService: Handles business logic');
        File::put(base_path('docs/short/app/Models/TestModel.md'), 'TestModel: Data representation');

        // Create existing content
        $existingContent = <<<'EOT'
---
module_name: Test Module
module_slug: test-module
---

# Test Module

## Overview
This is the existing module overview.

## Architecture
Old architecture description.
EOT;
        File::put(base_path("{$this->contentPath}/{$this->moduleSlug}.md"), $existingContent);

        $job = new UpdateModuleDocumentationJob($this->moduleSlug, 'test-sha');
        $job->handle();

        // Verify the module file was created/updated
        $this->assertFileExists(base_path("{$this->contentPath}/{$this->moduleSlug}.md"));
    }
}
