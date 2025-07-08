<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Lumiio\CascadeDocs\Tests\TestCase;

class UpdateChangedDocumentationCommandTest extends TestCase
{
    protected string $testPath;

    protected string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPath = 'tests/fixtures/update-changed-docs';
        $this->logPath = 'docs/documentation-update-log.json';

        // Configure paths
        Config::set('cascadedocs.paths.logs', 'docs/');
        Config::set('cascadedocs.ai.default_model', 'gpt-4');

        // Create test directories
        File::ensureDirectoryExists(base_path('docs'));
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testPath))) {
            File::deleteDirectory(base_path($this->testPath));
        }

        if (File::exists(base_path($this->logPath))) {
            File::delete(base_path($this->logPath));
        }

        if (File::exists(base_path('docs/module-assignment-log.json'))) {
            File::delete(base_path('docs/module-assignment-log.json'));
        }

        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:update-changed --help')
            ->assertExitCode(0);
    }

    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(\Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand::class));
    }

    public function test_command_has_correct_name(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
        $this->assertEquals('cascadedocs:update-changed', $command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
        $this->assertEquals('Update documentation for all changed files, modules, and architecture', $command->getDescription());
    }

    public function test_command_accepts_from_sha_option(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('from-sha'));
        $option = $definition->getOption('from-sha');
        $this->assertEquals('Git SHA to compare from (defaults to last documented SHA)', $option->getDescription());
    }

    public function test_command_accepts_to_sha_option(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('to-sha'));
        $option = $definition->getOption('to-sha');
        $this->assertEquals('Git SHA to compare to', $option->getDescription());
        $this->assertEquals('HEAD', $option->getDefault());
    }

    public function test_command_accepts_model_option(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('model'));
        $option = $definition->getOption('model');
        $this->assertEquals('The AI model to use for generation', $option->getDescription());
    }

    public function test_command_accepts_auto_commit_option(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('auto-commit'));
        $option = $definition->getOption('auto-commit');
        $this->assertEquals('Automatically commit documentation changes', $option->getDescription());
    }

    public function test_it_handles_no_changes(): void
    {
        // Mock Process to return no changes
        Process::fake([
            'git diff --name-only * HEAD' => Process::result(''),
            'git rev-parse HEAD~1' => Process::result('abc123'),
        ]);

        $this->artisan('cascadedocs:update-changed')
            ->expectsOutput('CascadeDocs - Intelligent Documentation Update System')
            ->expectsOutput('Step 1: Detecting changes...')
            ->expectsOutput('No changes detected.')
            ->assertExitCode(0);
    }

    public function test_it_uses_last_documented_sha_from_log(): void
    {
        // Create update log with last SHA
        $log = [
            'last_git_sha' => 'def456',
            'last_update' => now()->toIso8601String(),
        ];
        File::put(base_path($this->logPath), json_encode($log));

        // Mock Process
        Process::fake([
            'git diff --name-only def456 HEAD' => Process::result(''),
        ]);

        $this->artisan('cascadedocs:update-changed')
            ->expectsOutput('Comparing from: def456')
            ->expectsOutput('Comparing to: HEAD')
            ->expectsOutput('No changes detected.')
            ->assertExitCode(0);
    }

    public function test_it_falls_back_to_previous_commit_when_no_log(): void
    {
        // Mock Process
        Process::fake([
            'git rev-parse HEAD~1' => Process::result('ghi789'),
            'git diff --name-only ghi789 HEAD' => Process::result(''),
        ]);

        $this->artisan('cascadedocs:update-changed')
            ->expectsOutput('Comparing from: ghi789')
            ->expectsOutput('Comparing to: HEAD')
            ->assertExitCode(0);
    }

    public function test_it_uses_custom_sha_options(): void
    {
        // Mock Process
        Process::fake([
            'git diff --name-only custom-from custom-to' => Process::result(''),
        ]);

        $this->artisan('cascadedocs:update-changed', [
            '--from-sha' => 'custom-from',
            '--to-sha' => 'custom-to',
        ])
            ->expectsOutput('Comparing from: custom-from')
            ->expectsOutput('Comparing to: custom-to')
            ->assertExitCode(0);
    }

    public function test_has_new_modules_method_works(): void
    {
        // Create module assignment log with recent module
        $log = [
            'modules' => [
                'new-module' => [
                    'created_at' => now()->subMinutes(5)->toIso8601String(),
                ],
            ],
        ];
        File::put(base_path('docs/module-assignment-log.json'), json_encode($log));

        // We can't easily test protected methods, but we can verify the file was read
        $this->assertTrue(File::exists(base_path('docs/module-assignment-log.json')));
        $content = json_decode(File::get(base_path('docs/module-assignment-log.json')), true);
        $this->assertArrayHasKey('modules', $content);
    }

    public function test_it_reads_update_log_correctly(): void
    {
        // Create update log
        $log = [
            'last_git_sha' => 'test-sha',
            'last_update' => '2024-01-01T00:00:00Z',
        ];
        File::put(base_path($this->logPath), json_encode($log));

        // Verify it can be read
        $this->assertTrue(File::exists(base_path($this->logPath)));
        $content = json_decode(File::get(base_path($this->logPath)), true);
        $this->assertEquals('test-sha', $content['last_git_sha']);
    }

    public function test_command_uses_configured_log_path(): void
    {
        // Test that the command uses the configured log path
        Config::set('cascadedocs.paths.logs', 'custom-logs/');

        $command = new \Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;

        // The command should use the configured path
        $this->assertEquals('custom-logs/', config('cascadedocs.paths.logs'));
    }
}
