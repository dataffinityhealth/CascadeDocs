<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateDocumentationForFileJob;
use Lumiio\CascadeDocs\Tests\TestCase;

class UpdateDocumentationAfterMergeCommandTest extends TestCase
{
    protected string $updateLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->updateLogPath = 'docs/documentation-update-log.json';

        // Create test directories
        File::ensureDirectoryExists(base_path('docs'));

        // Configure paths
        Config::set('cascadedocs.paths.tracking.documentation_update', $this->updateLogPath);
        Config::set('cascadedocs.ai.default_model', 'gpt-4');
        Config::set('cascadedocs.file_types', ['php', 'js', 'vue']);
        Config::set('cascadedocs.paths.source', ['app/', 'resources/js/']);

        // Fake the queue
        Queue::fake();
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path('docs'))) {
            File::deleteDirectory(base_path('docs'));
        }

        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:update-after-merge --help')
            ->assertExitCode(0);
    }

    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(\Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationAfterMergeCommand::class));
    }

    public function test_command_has_correct_name(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationAfterMergeCommand;
        $this->assertEquals('cascadedocs:update-after-merge', $command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationAfterMergeCommand;
        $this->assertEquals('Complete documentation update workflow after merging a branch', $command->getDescription());
    }

    public function test_it_requires_previous_sha_or_since_option(): void
    {
        // Create empty update log
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => null,
            'last_update_timestamp' => null,
            'files' => [],
            'modules' => [],
        ]));

        $this->artisan('cascadedocs:update-after-merge')
            ->expectsOutput('Starting complete documentation update workflow...')
            ->expectsOutput('No previous update SHA found. Please run with --since=<commit-sha>')
            ->assertExitCode(1);
    }

    public function test_it_reports_when_already_up_to_date(): void
    {
        // Mock git to return same SHA
        Process::fake([
            'git rev-parse HEAD' => Process::result('abc123'),
        ]);

        // Create update log with same SHA
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'abc123',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => [],
        ]));

        $this->artisan('cascadedocs:update-after-merge')
            ->expectsOutput('Documentation is already up to date.')
            ->assertExitCode(0);
    }

    public function test_it_reports_no_changes(): void
    {
        // Mock git commands
        Process::fake([
            'git rev-parse HEAD' => Process::result('def456'),
            'git diff --name-only abc123 def456' => Process::result(''),
            'git diff --name-status abc123 def456' => Process::result(''),
        ]);

        // Create update log
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'abc123',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => [],
        ]));

        $this->artisan('cascadedocs:update-after-merge')
            ->expectsOutput('Analyzing changes from abc123 to def456')
            ->expectsOutput('No documentable files have changed.')
            ->assertExitCode(0);
    }

    public function test_it_handles_dry_run_mode(): void
    {
        // Mock git commands
        Process::fake([
            'git rev-parse HEAD' => Process::result('def456'),
            'git diff --name-only abc123 def456' => Process::result(
                "app/Services/UserService.php\napp/Models/User.php"
            ),
            'git diff --name-status abc123 def456' => Process::result(''),
        ]);

        // Create update log
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'abc123',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => [],
        ]));

        $this->artisan('cascadedocs:update-after-merge', ['--dry-run' => true])
            ->expectsOutput('Found 2 changed files:')
            ->expectsOutputToContain('Dry run mode - no changes will be made.')
            ->expectsOutputToContain('Files that would be updated:')
            ->expectsOutputToContain('- app/Services/UserService.php')
            ->expectsOutputToContain('- app/Models/User.php')
            ->assertExitCode(0);

        // Verify no jobs were dispatched
        Queue::assertNothingPushed();
    }

    public function test_it_uses_since_option(): void
    {
        // Mock git commands
        Process::fake([
            'git rev-parse HEAD' => Process::result('def456'),
            'git diff --name-only custom123 def456' => Process::result(''),
            'git diff --name-status custom123 def456' => Process::result(''),
        ]);

        // Create update log with different SHA
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'abc123',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => [],
        ]));

        $this->artisan('cascadedocs:update-after-merge', ['--since' => 'custom123'])
            ->expectsOutput('Analyzing changes from custom123 to def456')
            ->expectsOutput('No documentable files have changed.')
            ->assertExitCode(0);
    }

    public function test_it_uses_custom_model(): void
    {
        // Mock git commands
        Process::fake([
            'git rev-parse HEAD' => Process::result('def456'),
            'git diff --name-only abc123 def456' => Process::result(''),
            'git diff --name-status abc123 def456' => Process::result(
                "A\tapp/Services/NewService.php"
            ),
        ]);

        // Create update log
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'abc123',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => [],
        ]));

        // Create empty module assignment log to avoid issues
        File::put(base_path('docs/module-assignment-log.json'), json_encode([
            'assigned_files' => [],
            'unassigned_files' => [],
        ]));

        $this->artisan('cascadedocs:update-after-merge', ['--model' => 'claude-3'])
            ->expectsOutputToContain('Processing 1 new files...')
            ->assertExitCode(0);

        Queue::assertPushed(function (GenerateAiDocumentationForFileJob $job) {
            return $job->model === 'claude-3';
        });
    }

    public function test_it_handles_new_changed_and_deleted_files(): void
    {
        // Mock git commands
        Process::fake([
            'git rev-parse HEAD' => Process::result('def456'),
            'git diff --name-only abc123 def456' => Process::result('app/Services/ExistingService.php'),
            'git diff --name-status abc123 def456' => Process::result(
                "M\tapp/Services/ExistingService.php\n".
                "A\tapp/Services/NewService.php\n".
                "D\tapp/Services/OldService.php"
            ),
        ]);

        // Create update log
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'abc123',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => [],
        ]));

        // Create module assignment log
        File::put(base_path('docs/module-assignment-log.json'), json_encode([
            'assigned_files' => [
                'services' => ['app/Services/ExistingService.php', 'app/Services/OldService.php'],
            ],
            'unassigned_files' => [],
        ]));

        $this->artisan('cascadedocs:update-after-merge')
            ->expectsOutput('Found 2 changed files:')
            ->expectsOutputToContain('Processing 1 new files...')
            ->expectsOutputToContain('Processing 1 changed files...')
            ->expectsOutputToContain('Processing 1 deleted files...')
            ->assertExitCode(0);

        // Verify appropriate jobs were dispatched
        Queue::assertPushed(GenerateAiDocumentationForFileJob::class, 1); // For new file
        Queue::assertPushed(UpdateDocumentationForFileJob::class, 1); // For changed file
    }

    public function test_it_respects_limit_option(): void
    {
        // Mock git commands
        Process::fake([
            'git rev-parse HEAD' => Process::result('def456'),
            'git diff --name-only abc123 def456' => Process::result(''),
            'git diff --name-status abc123 def456' => Process::result(''),
        ]);

        // Create update log
        File::put(base_path($this->updateLogPath), json_encode([
            'last_update_sha' => 'abc123',
            'last_update_timestamp' => '2024-01-01T00:00:00Z',
            'files' => [],
            'modules' => [],
        ]));

        // Create module assignment log with many unassigned files
        $unassignedFiles = [];
        for ($i = 1; $i <= 500; $i++) {
            $unassignedFiles[] = "app/Services/Service{$i}.php";
        }

        File::put(base_path('docs/module-assignment-log.json'), json_encode([
            'assigned_files' => [],
            'unassigned_files' => $unassignedFiles,
        ]));

        $this->artisan('cascadedocs:update-after-merge', ['--limit' => 10])
            ->expectsOutput('No documentable files have changed.')
            ->assertExitCode(0);
    }

    public function test_command_accepts_all_options(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationAfterMergeCommand;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('since'));
        $this->assertTrue($definition->hasOption('model'));
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasOption('limit'));

        $limitOption = $definition->getOption('limit');
        $this->assertEquals('400', $limitOption->getDefault());
    }
}
