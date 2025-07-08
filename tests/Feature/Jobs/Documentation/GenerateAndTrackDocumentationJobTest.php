<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Jobs\Documentation;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAndTrackDocumentationJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Tests\TestCase;

class GenerateAndTrackDocumentationJobTest extends TestCase
{
    protected string $updateLogPath;
    protected string $metadataPath;
    protected string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->updateLogPath = 'docs/documentation-update-log.json';
        $this->metadataPath = 'docs/source_documents/modules/metadata';
        $this->testFilePath = base_path('app/Services/TestService.php');
        
        // Create test directories
        File::ensureDirectoryExists(base_path('docs'));
        File::ensureDirectoryExists(base_path($this->metadataPath));
        File::ensureDirectoryExists(dirname($this->testFilePath));
        
        // Create test file
        File::put($this->testFilePath, '<?php namespace App\Services; class TestService {}');
        
        // Configure paths
        Config::set('cascadedocs.paths.tracking.documentation_update', $this->updateLogPath);
        Config::set('cascadedocs.paths.modules.metadata', 'docs/source_documents/modules/metadata/');
        Config::set('cascadedocs.ai.default_model', 'gpt-4');
        
        // Fake the queue
        Queue::fake();
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (File::exists($this->testFilePath)) {
            File::delete($this->testFilePath);
        }
        
        if (File::exists(base_path('docs'))) {
            File::deleteDirectory(base_path('docs'));
        }
        
        parent::tearDown();
    }

    public function test_job_basic_properties(): void
    {
        $job = new GenerateAndTrackDocumentationJob('app/Services/TestService.php', 'sha123');
        
        $this->assertEquals('app/Services/TestService.php', $job->file_path);
        $this->assertEquals('sha123', $job->to_sha);
        $this->assertEquals('gpt-4', $job->model);
    }

    public function test_job_custom_model(): void
    {
        $job = new GenerateAndTrackDocumentationJob('app/Services/TestService.php', 'sha123', 'claude-3');
        
        $this->assertEquals('claude-3', $job->model);
    }

    public function test_job_dispatches_generate_documentation_job(): void
    {
        $job = new GenerateAndTrackDocumentationJob($this->testFilePath, 'sha123');
        
        $job->handle();
        
        // Verify that GenerateAiDocumentationForFileJob was dispatched synchronously
        // Since it's dispatchSync, it won't appear in Queue::assertPushed
        // Instead, we'll verify the update log was created
        $this->assertFileExists(base_path($this->updateLogPath));
        
        $log = json_decode(File::get(base_path($this->updateLogPath)), true);
        $this->assertArrayHasKey('files', $log);
        $this->assertArrayHasKey('app/Services/TestService.php', $log['files']);
    }

    public function test_job_updates_tracking_log(): void
    {
        // Create initial log
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'old_sha',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => []
        ]));

        Carbon::setTestNow('2024-01-15 12:00:00');
        
        $job = new GenerateAndTrackDocumentationJob($this->testFilePath, 'sha123');
        $job->handle();
        
        $log = json_decode(File::get(base_path($this->updateLogPath)), true);
        
        $this->assertArrayHasKey('app/Services/TestService.php', $log['files']);
        $this->assertEquals('sha123', $log['files']['app/Services/TestService.php']['sha']);
        $this->assertEquals('2024-01-15T12:00:00+00:00', $log['files']['app/Services/TestService.php']['last_updated']);
        
        Carbon::setTestNow();
    }

    public function test_job_dispatches_module_update_when_module_found(): void
    {
        // Create a module that includes Services directory
        File::put(base_path($this->metadataPath . '/services.json'), json_encode([
            'module_slug' => 'services',
            'module_name' => 'Services',
            'files' => [
                ['path' => 'app/Services/ExistingService.php'],
                ['path' => 'app/Services/AnotherService.php'],
                ['path' => 'app/Services/ThirdService.php'] // Need at least 3 files
            ]
        ]));

        $job = new GenerateAndTrackDocumentationJob($this->testFilePath, 'sha123');
        $job->handle();
        
        Queue::assertPushed(UpdateModuleDocumentationJob::class, function ($job) {
            return $job->module_slug === 'services' && $job->to_sha === 'sha123';
        });
    }

    public function test_job_does_not_dispatch_module_update_when_no_module_found(): void
    {
        // Don't create any modules
        
        $job = new GenerateAndTrackDocumentationJob($this->testFilePath, 'sha123');
        $job->handle();
        
        Queue::assertNotPushed(UpdateModuleDocumentationJob::class);
    }

    public function test_job_handles_absolute_file_path(): void
    {
        $absolutePath = base_path('app/Services/TestService.php');
        
        $job = new GenerateAndTrackDocumentationJob($absolutePath, 'sha123');
        $job->handle();
        
        $log = json_decode(File::get(base_path($this->updateLogPath)), true);
        
        // Should store relative path in log
        $this->assertArrayHasKey('app/Services/TestService.php', $log['files']);
        $this->assertArrayNotHasKey($absolutePath, $log['files']);
    }

    public function test_job_creates_new_log_if_not_exists(): void
    {
        $this->assertFalse(File::exists(base_path($this->updateLogPath)));
        
        $job = new GenerateAndTrackDocumentationJob($this->testFilePath, 'sha123');
        $job->handle();
        
        $this->assertFileExists(base_path($this->updateLogPath));
        
        $log = json_decode(File::get(base_path($this->updateLogPath)), true);
        $this->assertArrayHasKey('files', $log);
        $this->assertArrayHasKey('modules', $log);
    }

    public function test_job_uses_provided_sha_when_git_sha_not_available(): void
    {
        $job = new GenerateAndTrackDocumentationJob($this->testFilePath, 'provided_sha');
        $job->handle();
        
        $log = json_decode(File::get(base_path($this->updateLogPath)), true);
        
        // When git SHA is not available, it should use the provided SHA
        $this->assertEquals('provided_sha', $log['files']['app/Services/TestService.php']['sha']);
    }

    public function test_job_is_queueable(): void
    {
        $job = new GenerateAndTrackDocumentationJob($this->testFilePath, 'sha123');
        
        // The job should be queueable
        $this->assertContains('Illuminate\Bus\Queueable', class_uses($job));
        $this->assertContains('Illuminate\Queue\InteractsWithQueue', class_uses($job));
        $this->assertContains('Illuminate\Foundation\Bus\Dispatchable', class_uses($job));
    }
}