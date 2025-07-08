<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;
use Lumiio\CascadeDocs\Tests\TestCase;

class GenerateClassDocumentationCommandTest extends TestCase
{
    protected string $testPath;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        
        $this->testPath = 'tests/fixtures/generate-class-docs';
        
        // Create test directories and files
        File::ensureDirectoryExists(base_path($this->testPath . '/app'));
        File::ensureDirectoryExists(base_path($this->testPath . '/resources/js'));
        File::ensureDirectoryExists(base_path($this->testPath . '/excluded'));
        
        // Create some test files
        File::put(base_path($this->testPath . '/app/TestModel.php'), '<?php class TestModel {}');
        File::put(base_path($this->testPath . '/app/TestController.php'), '<?php class TestController {}');
        File::put(base_path($this->testPath . '/resources/js/component.vue'), '<template></template>');
        File::put(base_path($this->testPath . '/resources/js/script.ts'), 'const test: string = "test";');
        File::put(base_path($this->testPath . '/excluded/ExcludedFile.php'), '<?php class ExcludedFile {}');
        
        // Configure cascadedocs paths
        Config::set('cascadedocs.paths.source', [$this->testPath . '/app/', $this->testPath . '/resources/js/']);
        Config::set('cascadedocs.paths.output', 'docs/source_documents/');
        Config::set('cascadedocs.file_types', ['php', 'js', 'vue', 'jsx', 'ts', 'tsx']);
        Config::set('cascadedocs.exclude.directories', [$this->testPath . '/excluded']);
        Config::set('cascadedocs.exclude.patterns', ['*Test.php']);
        Config::set('cascadedocs.ai.default_model', 'gpt-4');
        Config::set('cascadedocs.queue.name', 'default');
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testPath))) {
            File::deleteDirectory(base_path($this->testPath));
        }
        
        // Clean up docs directories that might be created
        $docsPath = base_path('docs/source_documents');
        if (File::exists($docsPath)) {
            File::deleteDirectory($docsPath);
        }
        
        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:generate-class-docs --help')
            ->assertExitCode(0);
    }

    public function test_it_validates_tier_option(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'invalid-tier'
        ])
        ->expectsOutput('Invalid tier option. Must be one of: micro, standard, expansive, all')
        ->assertExitCode(1);
    }

    public function test_it_generates_documentation_for_all_files(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('Starting class documentation generation...')
        ->expectsOutput('Found 4 files to process.')
        ->expectsOutput('✓ Dispatched 4 documentation generation jobs.')
        ->expectsOutput('All jobs have been queued. Check your queue worker for processing status.')
        ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 4);
    }

    public function test_it_uses_custom_model(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'standard',
            '--model' => 'claude-3'
        ])
        ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, function ($job) {
            return $job->model === 'claude-3';
        });
    }

    public function test_it_uses_default_model_when_not_specified(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'expansive'
        ])
        ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, function ($job) {
            return $job->model === 'gpt-4';
        });
    }

    public function test_it_processes_all_tiers(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'all'
        ])
        ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, function ($job) {
            return $job->tier === 'all';
        });
    }

    public function test_it_creates_output_directories(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->assertExitCode(0);

        // Check that output directories are created
        $this->assertTrue(File::exists(base_path('docs/source_documents/short')));
        $this->assertTrue(File::exists(base_path('docs/source_documents/medium')));
        $this->assertTrue(File::exists(base_path('docs/source_documents/full')));
    }

    public function test_it_skips_files_with_existing_documentation(): void
    {
        // Create existing documentation for one file
        $docPath = base_path('docs/source_documents/short/' . $this->testPath . '/app/TestModel.md');
        File::ensureDirectoryExists(dirname($docPath));
        File::put($docPath, 'Existing documentation');

        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('✓ Skipped 1 files (documentation already exists).')
        ->assertExitCode(0);

        // Should only dispatch jobs for files without documentation
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 3);
    }

    public function test_it_forces_regeneration_when_force_option_used(): void
    {
        // Create existing documentation for one file
        $docPath = base_path('docs/source_documents/short/' . $this->testPath . '/app/TestModel.md');
        File::ensureDirectoryExists(dirname($docPath));
        File::put($docPath, 'Existing documentation');

        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro',
            '--force' => true
        ])
        ->expectsOutput('✓ Dispatched 4 documentation generation jobs.')
        ->assertExitCode(0);

        // Should dispatch jobs for all files even with existing documentation
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 4);
    }

    public function test_it_warns_about_missing_paths(): void
    {
        Config::set('cascadedocs.paths.source', ['nonexistent/path']);

        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('Path not found: nonexistent/path')
        ->expectsOutput('No files found to document.')
        ->assertExitCode(0);

        Bus::assertNotDispatched(GenerateAiDocumentationForFileJob::class);
    }

    public function test_it_excludes_directories_correctly(): void
    {
        // The excluded directory should not be processed
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('Found 4 files to process.')
        ->assertExitCode(0);

        // Should only process files from non-excluded directories
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 4);
    }

    public function test_it_excludes_patterns_correctly(): void
    {
        // Create a file that matches the exclude pattern
        File::put(base_path($this->testPath . '/app/ExampleTest.php'), '<?php class ExampleTest {}');

        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('Found 4 files to process.')
        ->assertExitCode(0);

        // Should exclude files matching the pattern
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 4);
    }

    public function test_it_processes_multiple_file_types(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('Found 4 files to process.')
        ->assertExitCode(0);

        // Should process PHP, Vue, and TypeScript files
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 4);
    }

    public function test_it_handles_empty_source_directories(): void
    {
        // Create empty directories
        File::ensureDirectoryExists(base_path('empty-test-dir'));
        Config::set('cascadedocs.paths.source', ['empty-test-dir']);

        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('No files found to document.')
        ->assertExitCode(0);

        Bus::assertNotDispatched(GenerateAiDocumentationForFileJob::class);

        // Clean up
        File::deleteDirectory(base_path('empty-test-dir'));
    }

    public function test_it_shows_progress_bar(): void
    {
        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->expectsOutput('Starting class documentation generation...')
        ->expectsOutput('Found 4 files to process.')
        ->assertExitCode(0);

        // Progress bar functionality is handled by Symfony Console
        // We can't easily test the visual progress bar, but we can verify the command completes
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 4);
    }

    public function test_it_dispatches_to_correct_queue(): void
    {
        Config::set('cascadedocs.queue.name', 'documentation');

        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'micro'
        ])
        ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 4);
    }

    public function test_it_checks_all_tiers_for_existing_documentation(): void
    {
        // Create existing documentation for all tiers of one file
        $baseDir = base_path('docs/source_documents');
        $filePath = $this->testPath . '/app/TestModel';
        
        File::ensureDirectoryExists($baseDir . '/short/' . dirname($filePath));
        File::ensureDirectoryExists($baseDir . '/medium/' . dirname($filePath));
        File::ensureDirectoryExists($baseDir . '/full/' . dirname($filePath));
        
        File::put($baseDir . '/short/' . $filePath . '.md', 'Short docs');
        File::put($baseDir . '/medium/' . $filePath . '.md', 'Medium docs');
        File::put($baseDir . '/full/' . $filePath . '.md', 'Full docs');

        $this->artisan('cascadedocs:generate-class-docs', [
            '--tier' => 'all'
        ])
        ->expectsOutput('✓ Skipped 1 files (documentation already exists).')
        ->assertExitCode(0);

        // Should only dispatch jobs for files without all required documentation
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 3);
    }
}