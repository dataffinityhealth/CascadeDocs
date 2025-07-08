<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateArchitectureDocumentationCommand;
use Lumiio\CascadeDocs\Tests\TestCase;
use Mockery;

class GenerateArchitectureDocumentationCommandTest extends TestCase
{
    protected string $testPath;
    protected string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testPath = 'tests/fixtures/architecture-docs';
        $this->modulesPath = $this->testPath . '/modules/metadata';
        
        // Create test directories
        File::ensureDirectoryExists(base_path($this->modulesPath));
        File::ensureDirectoryExists(base_path('docs/source_documents/architecture'));
        
        // Configure cascadedocs paths
        Config::set('cascadedocs.paths.output', 'docs/source_documents/');
        Config::set('cascadedocs.paths.modules.metadata', $this->modulesPath);
        Config::set('cascadedocs.paths.architecture.main', 'system-architecture.md');
        Config::set('cascadedocs.paths.architecture.summary', 'architecture-summary.md');
        Config::set('cascadedocs.permissions.directory', 0755);
        Config::set('cascadedocs.ai.default_model', 'gpt-4');
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testPath))) {
            File::deleteDirectory(base_path($this->testPath));
        }
        
        // Clean up docs directories
        if (File::exists(base_path('docs/source_documents'))) {
            File::deleteDirectory(base_path('docs/source_documents'));
        }
        
        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:generate-architecture-docs --help')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_no_modules_found(): void
    {
        $this->artisan('cascadedocs:generate-architecture-docs')
            ->expectsOutput('Starting architecture documentation generation...')
            ->expectsOutput('Collecting module summaries...')
            ->expectsOutput('No modules found. Please generate module documentation first.')
            ->assertExitCode(1);
    }

    public function test_it_collects_modules_with_summaries(): void
    {
        // Create test module metadata
        $this->createTestModuleMetadata();
        
        // Mock the command class to control AI responses
        $this->instance(
            GenerateArchitectureDocumentationCommand::class,
            Mockery::mock(GenerateArchitectureDocumentationCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn(
                    '# System Architecture\n\nThis is the generated architecture documentation...',
                    '# Architecture Summary\n\nThis is the architecture summary...'
                )
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-architecture-docs')
            ->expectsOutput('Starting architecture documentation generation...')
            ->expectsOutput('Collecting module summaries...')
            ->expectsOutput('Found 3 modules to analyze.')
            ->expectsOutput('Generating architecture documentation...')
            ->expectsOutputToContain('✓ Architecture documentation saved to:')
            ->expectsOutputToContain('✓ Architecture summary saved to:')
            ->assertExitCode(0);
    }

    public function test_it_uses_custom_model(): void
    {
        // Create test module metadata
        $this->createTestModuleMetadata();
        
        // Mock the command class
        $this->instance(
            GenerateArchitectureDocumentationCommand::class,
            Mockery::mock(GenerateArchitectureDocumentationCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn('# Architecture', '# Summary')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-architecture-docs', [
            '--model' => 'claude-3'
        ])
            ->expectsOutput('Found 3 modules to analyze.')
            ->assertExitCode(0);
    }

    public function test_it_creates_output_directories(): void
    {
        // Create test module metadata
        $this->createTestModuleMetadata();
        
        // Mock the command class
        $this->instance(
            GenerateArchitectureDocumentationCommand::class,
            Mockery::mock(GenerateArchitectureDocumentationCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn('# Architecture', '# Summary')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-architecture-docs')
            ->assertExitCode(0);

        // Check that output directories and files are created
        $this->assertTrue(File::exists(base_path('docs/source_documents/architecture')));
        $this->assertTrue(File::exists(base_path('docs/source_documents/architecture/system-architecture.md')));
        $this->assertTrue(File::exists(base_path('docs/source_documents/architecture/architecture-summary.md')));
    }

    public function test_it_handles_api_errors_gracefully(): void
    {
        // Create test module metadata
        $this->createTestModuleMetadata();
        
        // Mock the command class to throw an exception
        $this->instance(
            GenerateArchitectureDocumentationCommand::class,
            Mockery::mock(GenerateArchitectureDocumentationCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->andThrow(new \Exception('API rate limit exceeded'))
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-architecture-docs')
            ->expectsOutput('Found 3 modules to analyze.')
            ->expectsOutput('Generating architecture documentation...')
            ->expectsOutputToContain('Failed to generate architecture documentation:')
            ->assertExitCode(1);
    }

    public function test_it_ignores_non_json_files(): void
    {
        // Create test module metadata
        $this->createTestModuleMetadata();
        
        // Create a non-JSON file that should be ignored
        File::put(base_path($this->modulesPath . '/readme.txt'), 'This is not a module metadata file');
        
        // Mock the command class
        $this->instance(
            GenerateArchitectureDocumentationCommand::class,
            Mockery::mock(GenerateArchitectureDocumentationCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn('# Architecture', '# Summary')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-architecture-docs')
            ->expectsOutput('Found 3 modules to analyze.')
            ->assertExitCode(0);
    }

    public function test_it_handles_modules_without_summaries(): void
    {
        // Create module metadata without summaries
        $modules = [
            'auth' => [
                'module_name' => 'Authentication',
                'module_slug' => 'auth',
                'files' => [
                    ['path' => 'app/Services/AuthService.php']
                ]
                // No module_summary
            ],
            'users' => [
                'module_name' => 'User Management',
                'module_slug' => 'users',
                'module_summary' => 'Handles user management',
                'files' => [
                    ['path' => 'app/Models/User.php']
                ]
            ]
        ];

        foreach ($modules as $slug => $metadata) {
            File::put(
                base_path($this->modulesPath . '/' . $slug . '.json'),
                json_encode($metadata)
            );
        }
        
        // Mock the command class
        $this->instance(
            GenerateArchitectureDocumentationCommand::class,
            Mockery::mock(GenerateArchitectureDocumentationCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn('# Architecture', '# Summary')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-architecture-docs')
            ->expectsOutput('Found 1 modules to analyze.')
            ->assertExitCode(0);
    }

    public function test_it_handles_malformed_json_gracefully(): void
    {
        // Create valid module metadata
        $this->createTestModuleMetadata();
        
        // Create malformed JSON file
        File::put(base_path($this->modulesPath . '/malformed.json'), '{"invalid": json,}');
        
        // Mock the command class
        $this->instance(
            GenerateArchitectureDocumentationCommand::class,
            Mockery::mock(GenerateArchitectureDocumentationCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn('# Architecture', '# Summary')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-architecture-docs')
            ->expectsOutput('Found 3 modules to analyze.')
            ->assertExitCode(0);
    }

    protected function createTestModuleMetadata(): void
    {
        $modules = [
            'auth' => [
                'module_name' => 'Authentication',
                'module_slug' => 'auth',
                'module_summary' => 'Handles user authentication and authorization',
                'files' => [
                    ['path' => 'app/Services/AuthService.php'],
                    ['path' => 'app/Models/User.php']
                ]
            ],
            'users' => [
                'module_name' => 'User Management',
                'module_slug' => 'users',
                'module_summary' => 'Manages user profiles and settings',
                'files' => [
                    ['path' => 'app/Controllers/UserController.php'],
                    ['path' => 'app/Models/UserProfile.php']
                ]
            ],
            'api' => [
                'module_name' => 'API',
                'module_slug' => 'api',
                'module_summary' => 'RESTful API endpoints and responses',
                'files' => [
                    ['path' => 'app/Http/Controllers/Api/BaseController.php']
                ]
            ]
        ];

        foreach ($modules as $slug => $metadata) {
            File::put(
                base_path($this->modulesPath . '/' . $slug . '.json'),
                json_encode($metadata)
            );
        }
    }
}