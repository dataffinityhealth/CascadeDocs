<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Tests\TestCase;

class UpdateAllModuleDocumentationCommandTest extends TestCase
{
    protected string $metadataPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->metadataPath = 'docs/source_documents/modules/metadata';
        
        // Create test directories
        File::ensureDirectoryExists(base_path($this->metadataPath));
        
        // Configure paths
        Config::set('cascadedocs.ai.default_model', 'gpt-4');
        
        // Fake the queue
        Queue::fake();
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path('docs/source_documents'))) {
            File::deleteDirectory(base_path('docs/source_documents'));
        }
        
        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('documentation:update-all-modules --help')
            ->assertExitCode(0);
    }

    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(\Lumiio\CascadeDocs\Commands\Documentation\UpdateAllModuleDocumentationCommand::class));
    }

    public function test_command_has_correct_name(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateAllModuleDocumentationCommand();
        $this->assertEquals('documentation:update-all-modules', $command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateAllModuleDocumentationCommand();
        $this->assertEquals('Update documentation for all modules that have undocumented files', $command->getDescription());
    }

    public function test_it_reports_no_modules_when_metadata_directory_empty(): void
    {
        $this->artisan('documentation:update-all-modules')
            ->expectsOutput('Starting module documentation update process...')
            ->expectsOutput('Found 0 total modules')
            ->expectsOutput('No modules have undocumented files. All documentation is up to date!')
            ->assertExitCode(0);
    }

    public function test_it_finds_modules_with_undocumented_files(): void
    {
        // Create module metadata with undocumented files
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php', 'app/Models/User.php']
        ]);
        
        $this->createModuleMetadata('api', [
            'module_name' => 'API',
            'undocumented_files' => ['app/Http/Controllers/ApiController.php']
        ]);
        
        // Module with no undocumented files
        $this->createModuleMetadata('complete', [
            'module_name' => 'Complete Module',
            'undocumented_files' => []
        ]);

        $this->artisan('documentation:update-all-modules', ['--force' => true])
            ->expectsOutput('Found 3 total modules')
            ->expectsOutput('Found 2 modules with undocumented files:')
            ->expectsOutputToContain('Dispatching module documentation update jobs...')
            ->expectsOutputToContain('Successfully queued 2 module update jobs.')
            ->assertExitCode(0);
        
        // Verify jobs were dispatched
        Queue::assertPushed(UpdateModuleDocumentationJob::class, 2);
        Queue::assertPushed(function (UpdateModuleDocumentationJob $job) {
            return in_array($job->module_slug, ['auth', 'api']);
        });
    }

    public function test_it_updates_specific_module(): void
    {
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php']
        ]);

        $this->artisan('documentation:update-all-modules', [
            '--module' => 'auth'
        ])
            ->expectsOutput('Module: Authentication')
            ->expectsOutput('Undocumented files: 1')
            ->expectsOutput('Module documentation update job queued for: auth')
            ->assertExitCode(0);
        
        Queue::assertPushed(UpdateModuleDocumentationJob::class, 1);
    }

    public function test_it_reports_error_for_nonexistent_module(): void
    {
        $this->artisan('documentation:update-all-modules', [
            '--module' => 'nonexistent'
        ])
            ->expectsOutput('Module not found: nonexistent')
            ->assertExitCode(1);
    }

    public function test_it_reports_when_specific_module_has_no_undocumented_files(): void
    {
        $this->createModuleMetadata('complete', [
            'module_name' => 'Complete Module',
            'undocumented_files' => []
        ]);

        $this->artisan('documentation:update-all-modules', [
            '--module' => 'complete'
        ])
            ->expectsOutput('Module \'complete\' has no undocumented files.')
            ->assertExitCode(0);
        
        Queue::assertNothingPushed();
    }

    public function test_it_respects_dry_run_option(): void
    {
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php', 'app/Models/User.php']
        ]);

        $this->artisan('documentation:update-all-modules', ['--dry-run' => true])
            ->expectsOutput('Found 1 modules with undocumented files:')
            ->expectsOutputToContain('Dry run mode - no changes will be made.')
            ->assertExitCode(0);
        
        // No jobs should be dispatched in dry run mode
        Queue::assertNothingPushed();
    }

    public function test_it_shows_files_in_dry_run_for_specific_module(): void
    {
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php', 'app/Models/User.php']
        ]);

        $this->artisan('documentation:update-all-modules', [
            '--module' => 'auth',
            '--dry-run' => true
        ])
            ->expectsOutput('Module: Authentication')
            ->expectsOutput('Undocumented files: 2')
            ->expectsOutput('Would update documentation for module: auth')
            ->expectsOutput('Files to document:')
            ->expectsOutput('  - app/Services/AuthService.php')
            ->expectsOutput('  - app/Models/User.php')
            ->assertExitCode(0);
        
        Queue::assertNothingPushed();
    }

    public function test_it_asks_for_confirmation_without_force(): void
    {
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php']
        ]);

        $this->artisan('documentation:update-all-modules')
            ->expectsConfirmation('Do you want to update documentation for these modules?', 'no')
            ->expectsOutput('Update cancelled.')
            ->assertExitCode(0);
        
        Queue::assertNothingPushed();
    }

    public function test_it_respects_limit_option(): void
    {
        // Create multiple modules
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php']
        ]);
        
        $this->createModuleMetadata('api', [
            'module_name' => 'API',
            'undocumented_files' => ['app/Http/Controllers/ApiController.php']
        ]);
        
        $this->createModuleMetadata('users', [
            'module_name' => 'Users',
            'undocumented_files' => ['app/Models/User.php']
        ]);

        $this->artisan('documentation:update-all-modules', [
            '--limit' => 2,
            '--force' => true
        ])
            ->expectsOutput('Found 3 total modules')
            ->expectsOutput('Limiting to 2 modules as requested.')
            ->expectsOutput('Found 2 modules with undocumented files:')
            ->expectsOutput('Successfully queued 2 module update jobs.')
            ->assertExitCode(0);
        
        // Only 2 jobs should be dispatched
        Queue::assertPushed(UpdateModuleDocumentationJob::class, 2);
    }

    public function test_it_uses_custom_model(): void
    {
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php']
        ]);

        $this->artisan('documentation:update-all-modules', [
            '--module' => 'auth',
            '--model' => 'claude-3'
        ])
            ->assertExitCode(0);
        
        Queue::assertPushed(function (UpdateModuleDocumentationJob $job) {
            return $job->model === 'claude-3';
        });
    }

    public function test_it_handles_modules_without_undocumented_files_array(): void
    {
        // Create module without undocumented_files key
        $metadata = [
            'module_name' => 'Legacy Module',
            'module_slug' => 'legacy',
            'files' => []
        ];
        
        File::put(base_path($this->metadataPath . '/legacy.json'), json_encode($metadata));

        $this->artisan('documentation:update-all-modules')
            ->expectsOutput('Found 1 total modules')
            ->expectsOutput('No modules have undocumented files. All documentation is up to date!')
            ->assertExitCode(0);
    }

    public function test_it_shows_queue_worker_instructions(): void
    {
        $this->createModuleMetadata('auth', [
            'module_name' => 'Authentication',
            'undocumented_files' => ['app/Services/AuthService.php']
        ]);

        $this->artisan('documentation:update-all-modules', ['--force' => true])
            ->expectsOutput('Check your queue worker for processing status.')
            ->expectsOutput('Run: php artisan queue:work --queue=module_updates')
            ->assertExitCode(0);
    }

    /**
     * Helper method to create module metadata
     */
    protected function createModuleMetadata(string $slug, array $data): void
    {
        $metadata = array_merge([
            'module_slug' => $slug,
            'module_name' => ucfirst($slug),
            'files' => [],
            'undocumented_files' => []
        ], $data);
        
        File::put(
            base_path($this->metadataPath . '/' . $slug . '.json'),
            json_encode($metadata, JSON_PRETTY_PRINT)
        );
    }
}