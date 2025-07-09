<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Tests\TestCase;

class SyncModuleAssignmentsCommandTest extends TestCase
{
    protected string $testPath;

    protected string $metadataPath;

    protected string $contentPath;

    protected string $assignmentLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPath = 'tests/fixtures/sync-module-assignments';
        $this->metadataPath = 'docs/source_documents/modules/metadata';
        $this->contentPath = 'docs/source_documents/modules/content';
        $this->assignmentLogPath = 'docs/module-assignment-log.json';

        // Create test directories
        File::ensureDirectoryExists(base_path($this->metadataPath));
        File::ensureDirectoryExists(base_path($this->contentPath));

        // Configure paths to match what the command expects
        Config::set('cascadedocs.paths.tracking.module_assignment', $this->assignmentLogPath);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testPath))) {
            File::deleteDirectory(base_path($this->testPath));
        }

        if (File::exists(base_path('docs/source_documents'))) {
            File::deleteDirectory(base_path('docs/source_documents'));
        }

        if (File::exists(base_path($this->assignmentLogPath))) {
            File::delete(base_path($this->assignmentLogPath));
        }

        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:sync-module-assignments --help')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_no_modules_found(): void
    {
        $this->artisan('cascadedocs:sync-module-assignments')
            ->expectsOutput('Syncing module assignments from module metadata and documentation files...')
            ->expectsOutput('No module assignments found.')
            ->assertExitCode(1);
    }

    public function test_it_parses_metadata_files(): void
    {
        // Create test metadata file
        $metadata = [
            'module_slug' => 'auth',
            'module_name' => 'Authentication',
            'files' => [
                ['path' => 'app/Services/AuthService.php'],
                ['path' => 'app/Models/User.php'],
            ],
            'undocumented_files' => ['app/Http/Middleware/Authenticate.php'],
        ];

        File::put(base_path($this->metadataPath.'/auth.json'), json_encode($metadata));

        $this->artisan('cascadedocs:sync-module-assignments')
            ->expectsOutput('Syncing module assignments from module metadata and documentation files...')
            ->expectsOutputToContain('Module File References Found')
            ->expectsOutputToContain('auth: 3 files')
            ->expectsOutputToContain('Total modules: 1')
            ->expectsOutputToContain('Total file references: 3')
            ->run();
    }

    public function test_it_parses_markdown_documentation(): void
    {
        // Create test markdown file with various file reference patterns
        $markdown = <<<'MD'
# Authentication Module

## Files

- `app/Services/AuthService.php` - Main authentication service
- **`app/Models/User.php`** - User model
- File location: (app/Http/Controllers/AuthController.php)

### Additional Files

The module also includes `app/Http/Middleware/Authenticate.php` for middleware support.

`resources/js/components/LoginForm.vue` handles the frontend.
MD;

        File::put(base_path($this->contentPath.'/auth.md'), $markdown);

        $this->artisan('cascadedocs:sync-module-assignments')
            ->expectsOutputToContain('auth: 5 files')
            ->run();
    }

    public function test_it_merges_metadata_and_documentation(): void
    {
        // Create metadata
        $metadata = [
            'module_slug' => 'users',
            'module_name' => 'User Management',
            'files' => [
                ['path' => 'app/Models/User.php'],
                ['path' => 'app/Models/UserProfile.php'],
            ],
        ];

        File::put(base_path($this->metadataPath.'/users.json'), json_encode($metadata));

        // Create markdown with additional files
        $markdown = <<<'MD'
# User Management Module

Files:
- `app/Models/User.php` - User model (duplicate, should be merged)
- `app/Controllers/UserController.php` - User controller
- `app/Services/UserService.php` - User service
MD;

        File::put(base_path($this->contentPath.'/users.md'), $markdown);

        $this->artisan('cascadedocs:sync-module-assignments')
            ->expectsOutputToContain('users: 4 files') // Should merge to 4 unique files
            ->run();
    }

    public function test_it_supports_dry_run_mode(): void
    {
        // Create test metadata
        $metadata = [
            'module_slug' => 'api',
            'module_name' => 'API',
            'files' => [['path' => 'app/Http/Controllers/ApiController.php']],
        ];

        File::put(base_path($this->metadataPath.'/api.json'), json_encode($metadata));

        $this->artisan('cascadedocs:sync-module-assignments', [
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('api: 1 files')
            ->expectsOutputToContain('Dry run mode - no changes made.')
            ->run();

        // Assignment log should not be created in dry run mode
        $this->assertFalse(File::exists(base_path($this->assignmentLogPath)));
    }

    public function test_it_shows_detailed_parsing_info(): void
    {
        // Create test markdown
        $markdown = '# Test Module\n\n- `app/Services/TestService.php` - Test service';
        File::put(base_path($this->contentPath.'/test.md'), $markdown);

        $this->artisan('cascadedocs:sync-module-assignments', [
            '--detailed' => true,
        ])
            ->expectsOutputToContain('Parsing module documentation: test')
            ->expectsOutputToContain('Found: app/Services/TestService.php')
            ->expectsOutputToContain('Found 1 file references in documentation')
            ->run();
    }

    public function test_it_ignores_non_json_metadata_files(): void
    {
        // Create non-JSON file
        File::put(base_path($this->metadataPath.'/readme.txt'), 'This is not JSON');

        // Create valid JSON file
        $metadata = [
            'module_slug' => 'valid',
            'module_name' => 'Valid Module',
            'files' => [['path' => 'app/Models/Valid.php']],
        ];
        File::put(base_path($this->metadataPath.'/valid.json'), json_encode($metadata));

        $this->artisan('cascadedocs:sync-module-assignments')
            ->expectsOutputToContain('valid: 1 files')
            ->expectsOutputToContain('Total modules: 1')
            ->run();
    }

    public function test_it_ignores_non_markdown_content_files(): void
    {
        // Create non-markdown file
        File::put(base_path($this->contentPath.'/test.txt'), 'app/Services/TestService.php');

        // Create valid markdown file
        File::put(base_path($this->contentPath.'/valid.md'), '`app/Models/Valid.php`');

        $this->artisan('cascadedocs:sync-module-assignments')
            ->expectsOutputToContain('valid: 1 files')
            ->expectsOutputToContain('Total modules: 1')
            ->run();
    }

    public function test_it_validates_file_paths(): void
    {
        // Create markdown with various invalid paths
        $markdown = <<<'MD'
# Test Module

- `invalid-path` - No extension
- `app/Services/Test Service.php` - Contains space
- `notapp/Services/Test.php` - Doesn't start with app/ or resources/js/
- `app/Services/Test.txt` - Invalid extension
- `app/Services/Valid.php` - This one is valid
MD;

        File::put(base_path($this->contentPath.'/test.md'), $markdown);

        $this->artisan('cascadedocs:sync-module-assignments')
            ->expectsOutputToContain('test: 1 files') // Should only find the valid file
            ->run();
    }

    public function test_it_preserves_do_not_document_files(): void
    {
        // Create existing assignment log with do_not_document files
        $existingLog = [
            'last_analysis' => '2024-01-01T00:00:00+00:00',
            'assigned_files' => [],
            'unassigned_files' => [],
            'do_not_document' => ['app/Services/IgnoreMe.php', 'app/Helpers/Debug.php'],
            'potential_modules' => [],
            'module_suggestions' => [],
        ];

        File::put(base_path($this->assignmentLogPath), json_encode($existingLog));

        // Create module with files
        $metadata = [
            'module_slug' => 'test',
            'module_name' => 'Test Module',
            'files' => [['path' => 'app/Services/TestService.php']],
        ];
        File::put(base_path($this->metadataPath.'/test.json'), json_encode($metadata));

        $this->artisan('cascadedocs:sync-module-assignments')
            ->run();

        // Verify do_not_document files are preserved
        $updatedLog = json_decode(File::get(base_path($this->assignmentLogPath)), true);
        $this->assertEquals(['app/Services/IgnoreMe.php', 'app/Helpers/Debug.php'], $updatedLog['do_not_document']);
    }

    public function test_it_handles_multiple_modules(): void
    {
        // Create multiple modules
        $modules = [
            'auth' => ['app/Services/AuthService.php', 'app/Models/User.php'],
            'api' => ['app/Http/Controllers/ApiController.php'],
            'users' => ['app/Models/UserProfile.php', 'app/Services/UserService.php'],
        ];

        foreach ($modules as $slug => $files) {
            $metadata = [
                'module_slug' => $slug,
                'module_name' => ucfirst($slug).' Module',
                'files' => array_map(fn ($path) => ['path' => $path], $files),
            ];
            File::put(base_path($this->metadataPath."/{$slug}.json"), json_encode($metadata));
        }

        $output = $this->artisan('cascadedocs:sync-module-assignments');

        // Just verify it runs without errors and finds multiple modules
        $output->expectsOutputToContain('Total modules: 3')
            ->expectsOutputToContain('Total file references: 5')
            ->run();
    }

    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(\Lumiio\CascadeDocs\Commands\Documentation\SyncModuleAssignmentsCommand::class));
    }

    public function test_command_has_correct_name(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\SyncModuleAssignmentsCommand;
        $this->assertEquals('cascadedocs:sync-module-assignments', $command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\SyncModuleAssignmentsCommand;
        $this->assertEquals('Sync module assignments from both module documentation and metadata files', $command->getDescription());
    }
}
