<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Tests\TestCase;

class CreateModuleCommandTest extends TestCase
{
    protected string $testModulesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testModulesPath = 'tests/fixtures/create-module';
        Config::set('cascadedocs.paths.tracking.module_assignment', $this->testModulesPath.'/assignment.json');

        // Create test directories
        File::ensureDirectoryExists(base_path($this->testModulesPath));
        File::ensureDirectoryExists(base_path('docs/source_documents/modules'));
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testModulesPath))) {
            File::deleteDirectory(base_path($this->testModulesPath));
        }

        // Clean up any created module files
        $createdFiles = [
            'docs/source_documents/modules/test-module.md',
            'docs/source_documents/modules/user-management-system.md',
            'docs/source_documents/modules/existing-module.md',
        ];

        foreach ($createdFiles as $file) {
            if (File::exists(base_path($file))) {
                File::delete(base_path($file));
            }
        }

        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:create-module --help')
            ->assertExitCode(0);
    }

    public function test_it_requires_module_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "name")');

        $this->artisan('cascadedocs:create-module');
    }

    public function test_it_creates_basic_module_with_all_options(): void
    {
        $this->artisan('cascadedocs:create-module', [
            'name' => 'test-module',
            '--files' => ['app/Models/Test.php'],
            '--title' => 'Test Module',
            '--description' => 'A test module for testing',
        ])
            ->expectsOutput('Module created at: '.base_path('docs/source_documents/modules/test-module.md'))
            ->expectsOutput('Added 1 files to the module.')
            ->expectsOutput('Updating module assignments...')
            ->expectsOutput('Next steps:')
            ->assertExitCode(0);
    }

    public function test_it_formats_module_name_correctly(): void
    {
        $this->artisan('cascadedocs:create-module', [
            'name' => 'User Management System',
            '--files' => ['app/Models/User.php'],
            '--description' => 'User management functionality',
        ])
            ->expectsOutput('Module created at: '.base_path('docs/source_documents/modules/user-management-system.md'))
            ->assertExitCode(0);
    }

    public function test_it_refuses_to_overwrite_existing_module(): void
    {
        // Create an existing module file
        $modulePath = base_path('docs/source_documents/modules/existing-module.md');
        File::put($modulePath, 'Existing content');

        $this->artisan('cascadedocs:create-module', [
            'name' => 'existing-module',
            '--files' => ['app/Models/Test.php'],
            '--description' => 'Should not overwrite',
        ])
            ->expectsOutput('Module existing-module already exists!')
            ->assertExitCode(1);

        // Verify original content is preserved
        $this->assertEquals('Existing content', File::get($modulePath));
    }

    public function test_it_handles_empty_module_creation(): void
    {
        $this->artisan('cascadedocs:create-module', [
            'name' => 'empty-module',
            '--title' => 'Empty Module',
            '--description' => 'An empty module',
        ])
            ->expectsOutput('No files specified for the module.')
            ->expectsQuestion('Create empty module?', true)
            ->expectsOutput('Added 0 files to the module.')
            ->assertExitCode(0);
    }

    public function test_it_cancels_empty_module_creation(): void
    {
        $this->artisan('cascadedocs:create-module', [
            'name' => 'cancelled-module',
            '--title' => 'Cancelled Module',
            '--description' => 'A cancelled module',
        ])
            ->expectsOutput('No files specified for the module.')
            ->expectsQuestion('Create empty module?', false)
            ->assertExitCode(0);
    }

    public function test_it_prompts_for_description_when_not_provided(): void
    {
        $this->artisan('cascadedocs:create-module', [
            'name' => 'prompt-module',
            '--files' => ['app/Models/Test.php'],
            '--title' => 'Prompt Module',
        ])
            ->expectsQuestion('Enter module description', 'A module created via prompt')
            ->expectsOutput('Added 1 files to the module.')
            ->assertExitCode(0);
    }

    public function test_it_uses_suggestion_files(): void
    {
        // Create a test analysis log with suggestions
        $analysisLog = [
            'potential_modules' => [
                'user-auth' => [
                    'suggested_name' => 'user-auth',
                    'files' => ['app/Models/User.php', 'app/Controllers/AuthController.php'],
                ],
            ],
        ];

        File::put(
            base_path($this->testModulesPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:create-module', [
            'name' => 'user-auth',
            '--from-suggestion' => true,
            '--title' => 'User Authentication',
            '--description' => 'User authentication module',
        ])
            ->expectsOutput('Using files from suggested module: user-auth')
            ->expectsOutput('Added 2 files to the module.')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_suggestion_not_found(): void
    {
        // Create a test analysis log without the requested suggestion
        $analysisLog = [
            'potential_modules' => [],
        ];

        File::put(
            base_path($this->testModulesPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:create-module', [
            'name' => 'nonexistent-suggestion',
            '--from-suggestion' => true,
            '--title' => 'Nonexistent Module',
            '--description' => 'This suggestion does not exist',
        ])
            ->expectsOutput('No suggestion found for module: nonexistent-suggestion')
            ->expectsOutput('No files specified for the module.')
            ->expectsQuestion('Create empty module?', false)
            ->assertExitCode(0);
    }

    public function test_it_formats_title_from_slug(): void
    {
        $this->artisan('cascadedocs:create-module', [
            'name' => 'user-profile-management',
            '--files' => ['app/Models/UserProfile.php'],
            '--description' => 'User profile management functionality',
        ])
            ->assertExitCode(0);

        // The title should be auto-formatted from the slug
        $modulePath = base_path('docs/source_documents/modules/user-profile-management.md');
        if (File::exists($modulePath)) {
            $content = File::get($modulePath);
            $this->assertStringContainsString('User Profile Management Module', $content);
        }
    }
}
