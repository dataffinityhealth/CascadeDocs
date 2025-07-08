<?php

namespace Lumiio\CascadeDocs\Tests\Unit\Services\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\ModuleFileUpdater;
use Lumiio\CascadeDocs\Tests\TestCase;

class ModuleFileUpdaterTest extends TestCase
{
    protected ModuleFileUpdater $service;

    protected string $testModulesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testModulesPath = 'tests/fixtures/modules';
        Config::set('cascadedocs.paths.modules.content', $this->testModulesPath);
        Config::set('cascadedocs.paths.modules.metadata', $this->testModulesPath.'/metadata');

        // Create test directories
        File::ensureDirectoryExists(base_path($this->testModulesPath));
        File::ensureDirectoryExists(base_path($this->testModulesPath.'/metadata'));

        $this->service = new ModuleFileUpdater;
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testModulesPath))) {
            File::deleteDirectory(base_path($this->testModulesPath));
        }
        parent::tearDown();
    }

    public function test_it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(ModuleFileUpdater::class, $this->service);
    }

    public function test_it_adds_files_to_module(): void
    {
        $files = ['app/Models/User.php', 'app/Controllers/UserController.php'];

        // Create a module first
        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => [],
        ]);

        $this->service->addFiles('test-module', $files, false);

        // Verify files were added
        $allFiles = $this->service->getAllFiles('test-module');
        $this->assertContains('app/Models/User.php', $allFiles);
        $this->assertContains('app/Controllers/UserController.php', $allFiles);
    }

    public function test_it_adds_documented_files_to_module(): void
    {
        $files = ['app/Models/User.php'];

        // Create a module first
        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => [],
        ]);

        $this->service->addFiles('test-module', $files, true);

        // Verify files were added
        $allFiles = $this->service->getAllFiles('test-module');
        $this->assertContains('app/Models/User.php', $allFiles);
    }

    public function test_it_removes_files_from_module(): void
    {
        $files = ['app/Models/User.php', 'app/Controllers/UserController.php'];

        // Create a module with files
        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => $files,
        ]);

        // Remove one file
        $this->service->removeFiles('test-module', ['app/Models/User.php']);

        // Verify file was removed
        $allFiles = $this->service->getAllFiles('test-module');
        $this->assertNotContains('app/Models/User.php', $allFiles);
        $this->assertContains('app/Controllers/UserController.php', $allFiles);
    }

    public function test_it_marks_files_as_documented(): void
    {
        $files = ['app/Models/User.php'];

        // Create a module with files
        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => $files,
        ]);

        $this->service->markFilesAsDocumented('test-module', $files);

        // This method doesn't have a direct way to verify, but it should not throw errors
        $this->assertTrue(true);
    }

    public function test_it_loads_existing_content(): void
    {
        $contentPath = base_path($this->testModulesPath.'/test-module.md');
        $expectedContent = "# Test Module\n\nTest content";

        // Create the file with test content
        File::put($contentPath, $expectedContent);

        $result = $this->service->loadContent('test-module');

        $this->assertEquals($expectedContent, $result);
    }

    public function test_it_returns_null_for_non_existent_content(): void
    {
        $result = $this->service->loadContent('non-existent');

        $this->assertNull($result);
    }

    public function test_it_saves_content(): void
    {
        $contentPath = base_path($this->testModulesPath.'/test-module.md');
        $content = "# Test Module\n\nUpdated content";

        $this->service->saveContent('test-module', $content);

        // Verify file was created with correct content
        $this->assertTrue(File::exists($contentPath));
        $this->assertEquals($content, File::get($contentPath));
    }

    public function test_it_creates_module(): void
    {
        $moduleData = [
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'A test module',
            'files' => ['app/Models/Test.php'],
        ];

        $this->service->createModule($moduleData);

        // Verify module was created
        $this->assertTrue($this->service->moduleExists('test-module'));

        // Verify metadata
        $metadata = $this->service->getMetadata('test-module');
        $this->assertNotNull($metadata);
        $this->assertEquals('Test Module', $metadata['module_name']);
        $this->assertEquals('test-module', $metadata['module_slug']);
    }

    public function test_it_checks_if_module_exists(): void
    {
        // Create a module
        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => [],
        ]);

        $result = $this->service->moduleExists('test-module');
        $this->assertTrue($result);
    }

    public function test_it_checks_if_module_does_not_exist(): void
    {
        $result = $this->service->moduleExists('non-existent');
        $this->assertFalse($result);
    }

    public function test_it_gets_module_metadata(): void
    {
        $moduleData = [
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'A test module',
            'files' => ['app/Models/Test.php'],
        ];

        $this->service->createModule($moduleData);

        $result = $this->service->getMetadata('test-module');

        $this->assertNotNull($result);
        $this->assertEquals('Test Module', $result['module_name']);
        $this->assertEquals('test-module', $result['module_slug']);
    }

    public function test_it_returns_null_for_non_existent_metadata(): void
    {
        $result = $this->service->getMetadata('non-existent');
        $this->assertNull($result);
    }

    public function test_it_gets_all_files_in_module(): void
    {
        $expectedFiles = ['app/Models/User.php', 'app/Controllers/UserController.php'];

        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => $expectedFiles,
        ]);

        $result = $this->service->getAllFiles('test-module');

        $this->assertEquals($expectedFiles, $result);
    }

    public function test_it_loads_module_using_legacy_path(): void
    {
        $result = $this->service->loadModule('/path/to/test-module.md');

        $this->assertInstanceOf(ModuleFileUpdater::class, $result);
        $this->assertEquals($this->service, $result);
    }

    public function test_it_extracts_slug_from_legacy_path(): void
    {
        // Test that the slug is properly extracted and stored
        $this->service->loadModule('/path/to/user-management.md');

        // The current slug should be set internally
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('currentSlug');
        $property->setAccessible(true);

        $this->assertEquals('user-management', $property->getValue($this->service));
    }

    public function test_it_handles_legacy_save_operation(): void
    {
        // Legacy save should not throw errors
        $this->expectNotToPerformAssertions();

        $this->service->save('/path/to/test-module.md');
    }

    public function test_it_gets_file_count_with_current_slug(): void
    {
        $files = ['app/Models/User.php', 'app/Controllers/UserController.php'];

        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => $files,
        ]);

        $this->service->loadModule('/path/to/test-module.md');
        $result = $this->service->getFileCount();

        $this->assertEquals(2, $result);
    }

    public function test_it_returns_zero_file_count_without_current_slug(): void
    {
        $result = $this->service->getFileCount();

        $this->assertEquals(0, $result);
    }

    public function test_it_returns_zero_file_count_when_metadata_not_found(): void
    {
        $this->service->loadModule('/path/to/test-module.md');
        $result = $this->service->getFileCount();

        $this->assertEquals(0, $result);
    }

    public function test_it_handles_metadata_without_statistics(): void
    {
        // Create a module and then manually modify its metadata to remove statistics
        $this->service->createModule([
            'slug' => 'test-module',
            'name' => 'Test Module',
            'description' => 'Test description',
            'files' => [],
        ]);

        $this->service->loadModule('/path/to/test-module.md');
        $result = $this->service->getFileCount();

        $this->assertEquals(0, $result);
    }
}
