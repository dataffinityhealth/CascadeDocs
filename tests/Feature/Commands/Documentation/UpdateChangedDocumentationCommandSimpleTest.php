<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Lumiio\CascadeDocs\Tests\TestCase;

class UpdateChangedDocumentationCommandSimpleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Configure paths
        Config::set('cascadedocs.paths.logs', 'docs/');
        Config::set('cascadedocs.ai.default_model', 'gpt-4');

        // Create test directories
        File::ensureDirectoryExists(base_path('docs'));

        // Prevent any real HTTP requests
        Http::preventStrayRequests();
        Http::fake();

        // Fake queue to prevent actual job dispatch
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (File::exists(base_path('docs'))) {
            File::deleteDirectory(base_path('docs'));
        }

        parent::tearDown();
    }

    public function test_command_runs_successfully_with_no_changes(): void
    {
        // Mock Process to return no changes
        Process::fake([
            'git rev-parse HEAD~1' => Process::result('abc123'),
            'git diff --name-only abc123 HEAD' => Process::result(''),
        ]);

        $this->artisan('cascadedocs:update-changed')
            ->expectsOutput('CascadeDocs - Intelligent Documentation Update System')
            ->expectsOutput('Step 1: Detecting changes...')
            ->expectsOutput('No changes detected.')
            ->assertExitCode(0);
    }

    public function test_command_detects_from_sha_from_log(): void
    {
        // Create update log
        File::put(base_path('docs/documentation-update-log.json'), json_encode([
            'last_git_sha' => 'def456',
            'last_update' => now()->toIso8601String(),
        ]));

        // Mock Process
        Process::fake([
            'git diff --name-only def456 HEAD' => Process::result(''),
        ]);

        $this->artisan('cascadedocs:update-changed')
            ->expectsOutput('Comparing from: def456')
            ->expectsOutput('Comparing to: HEAD')
            ->assertExitCode(0);
    }

    public function test_command_uses_custom_sha_options(): void
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

    public function test_command_with_model_option(): void
    {
        // Mock Process
        Process::fake([
            'git rev-parse HEAD~1' => Process::result('abc123'),
            'git diff --name-only abc123 HEAD' => Process::result(''),
        ]);

        $this->artisan('cascadedocs:update-changed', ['--model' => 'claude-3'])
            ->expectsOutput('No changes detected.')
            ->assertExitCode(0);
    }
}
