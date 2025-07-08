<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;
use Lumiio\CascadeDocs\Tests\TestCase;

class GenerateAiDocumentationForAllFilesCommandTest extends TestCase
{
    protected string $testPath;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        $this->testPath = 'tests/fixtures/generate-all-docs';

        // Create test directories and files
        File::ensureDirectoryExists(base_path($this->testPath.'/app'));
        File::ensureDirectoryExists(base_path($this->testPath.'/resources/js'));

        // Create some test PHP files
        File::put(base_path($this->testPath.'/app/TestModel.php'), '<?php class TestModel {}');
        File::put(base_path($this->testPath.'/app/TestController.php'), '<?php class TestController {}');
        File::put(base_path($this->testPath.'/resources/js/app.js'), 'console.log("test");');

        Config::set('cascadedocs.ai.default_model', 'gpt-4');
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
        $this->artisan('generate:ai-documentation --help')
            ->assertExitCode(0);
    }

    public function test_it_validates_tier_option(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--tier' => 'invalid-tier',
        ])
            ->expectsOutput('Invalid tier option. Must be one of: micro, standard, expansive, all')
            ->assertExitCode(0);
    }

    public function test_it_uses_default_paths_when_none_provided(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--tier' => 'micro',
        ])
            ->expectsOutput('Starting multi-tier documentation generation...')
            ->expectsOutput('Scanning the following directories: app, resources/js')
            ->assertExitCode(0);
    }

    public function test_it_uses_default_paths_with_flag(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--default-paths' => true,
            '--tier' => 'standard',
        ])
            ->expectsOutput('Scanning the following directories: app, resources/js')
            ->assertExitCode(0);
    }

    public function test_it_scans_custom_paths(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'micro',
        ])
            ->expectsOutput('Scanning the following directories: '.$this->testPath.'/app')
            ->expectsOutput('Found 2 PHP files in '.$this->testPath.'/app')
            ->assertExitCode(0);
    }

    public function test_it_warns_about_missing_paths(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => ['nonexistent/path'],
            '--tier' => 'micro',
        ])
            ->expectsOutput('Path not found: nonexistent/path')
            ->assertExitCode(0);
    }

    public function test_it_dispatches_jobs_for_found_files(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'micro',
        ])
            ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 2);
    }

    public function test_it_uses_custom_model(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'standard',
            '--model' => 'claude-3',
        ])
            ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, function ($job) {
            return $job->model === 'claude-3';
        });
    }

    public function test_it_uses_default_model_when_not_specified(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'expansive',
        ])
            ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, function ($job) {
            return $job->model === 'gpt-4';
        });
    }

    public function test_it_processes_all_tiers(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'all',
        ])
            ->assertExitCode(0);

        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, function ($job) {
            return $job->tier === 'all';
        });
    }

    public function test_it_creates_documentation_directories(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'micro',
        ])
            ->assertExitCode(0);

        // Check that documentation directories are created
        $this->assertTrue(File::exists(base_path('docs/source_documents/short')));
        $this->assertTrue(File::exists(base_path('docs/source_documents/medium')));
        $this->assertTrue(File::exists(base_path('docs/source_documents/full')));
    }

    public function test_it_finds_multiple_file_types(): void
    {
        // Create additional file types
        File::put(base_path($this->testPath.'/app/component.vue'), '<template></template>');
        File::put(base_path($this->testPath.'/app/script.ts'), 'const test: string = "test";');
        File::put(base_path($this->testPath.'/app/component.jsx'), 'const Component = () => <div></div>;');

        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'micro',
        ])
            ->expectsOutput('Found 5 PHP files in '.$this->testPath.'/app') // PHP, vue, ts, jsx files
            ->assertExitCode(0);

        // Should dispatch jobs for all supported file types
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 5);
    }

    public function test_it_skips_files_with_existing_documentation(): void
    {
        // Create existing documentation for one file
        $docPath = base_path('docs/source_documents/short/'.$this->testPath.'/app/TestModel.md');
        File::ensureDirectoryExists(dirname($docPath));
        File::put($docPath, 'Existing documentation');

        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'micro',
        ])
            ->expectsOutput('Skipped 1 files (documentation already exists).')
            ->assertExitCode(0);

        // Should only dispatch job for the file without documentation
        Bus::assertDispatched(GenerateAiDocumentationForFileJob::class, 1);
    }

    public function test_it_shows_processing_summary(): void
    {
        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/app'],
            '--tier' => 'standard',
        ])
            ->expectsOutput('Found 2 total files to process.')
            ->expectsOutput('Dispatched 2 documentation generation jobs.')
            ->expectsOutput('All jobs have been queued. Check your queue worker for processing status.')
            ->assertExitCode(0);
    }

    public function test_it_handles_empty_directories(): void
    {
        File::ensureDirectoryExists(base_path($this->testPath.'/empty'));

        $this->artisan('generate:ai-documentation', [
            '--paths' => [$this->testPath.'/empty'],
            '--tier' => 'micro',
        ])
            ->expectsOutput('Found 0 PHP files in '.$this->testPath.'/empty')
            ->expectsOutput('Found 0 total files to process.')
            ->expectsOutput('Dispatched 0 documentation generation jobs.')
            ->assertExitCode(0);

        Bus::assertNotDispatched(GenerateAiDocumentationForFileJob::class);
    }
}
