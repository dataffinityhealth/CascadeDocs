<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateModuleDocumentationCommand;
use Lumiio\CascadeDocs\Tests\TestCase;

class GenerateModuleDocumentationCommandTest extends TestCase
{
    protected string $moduleAssignmentLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleAssignmentLogPath = 'docs/module-assignment-log.json';

        // Create test directories
        File::ensureDirectoryExists(base_path('docs'));

        // Configure paths
        Config::set('cascadedocs.paths.tracking.module_assignment', $this->moduleAssignmentLogPath);
        Config::set('cascadedocs.ai.default_model', 'gpt-4');

        // Prevent any real HTTP requests and mock all by default
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (File::exists(base_path('docs'))) {
            File::deleteDirectory(base_path('docs'));
        }

        parent::tearDown();
    }

    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(GenerateModuleDocumentationCommand::class));
    }

    public function test_command_has_correct_signature(): void
    {
        $command = new GenerateModuleDocumentationCommand;
        $this->assertEquals('cascadedocs:generate-module-docs', $command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $command = new GenerateModuleDocumentationCommand;
        $this->assertEquals('Generate module documentation by organizing files and creating module overviews', $command->getDescription());
    }

    public function test_command_accepts_all_options(): void
    {
        $command = new GenerateModuleDocumentationCommand;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('model'));
        $this->assertTrue($definition->hasOption('fresh'));
    }
}
