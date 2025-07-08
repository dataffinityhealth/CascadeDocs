<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Services\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationParser;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;
use Lumiio\CascadeDocs\Tests\TestCase;

class DocumentationServiceCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test directories
        File::ensureDirectoryExists(base_path('docs'));
        File::ensureDirectoryExists(base_path('docs/source_documents'));
        File::ensureDirectoryExists(base_path('docs/source_documents/modules/metadata'));
        
        // Configure paths
        Config::set('cascadedocs.paths.modules.metadata', 'docs/source_documents/modules/metadata/');
        Config::set('cascadedocs.paths.documentation.short', 'docs/short/');
        Config::set('cascadedocs.paths.documentation.full', 'docs/full/');
        Config::set('cascadedocs.paths.output', 'docs/');
        Config::set('cascadedocs.tiers.micro', 'short');
        Config::set('cascadedocs.tiers.standard', 'medium');
        Config::set('cascadedocs.tiers.expansive', 'full');
    }

    protected function tearDown(): void
    {
        if (File::exists(base_path('docs'))) {
            File::deleteDirectory(base_path('docs'));
        }
        
        parent::tearDown();
    }

    public function test_documentation_parser_batch_operations(): void
    {
        $parser = new DocumentationParser();
        
        // Create test documentation files
        $files = [
            'app/Services/Service1.php' => "## Service1\n\nFirst service",
            'app/Services/Service2.php' => "## Service2\n\nSecond service",
        ];
        
        foreach ($files as $file => $content) {
            $docPath = 'docs/short/' . str_replace('.php', '.md', $file);
            File::ensureDirectoryExists(base_path(dirname($docPath)));
            File::put(base_path($docPath), $content);
        }
        
        // Test batch retrieval
        $docs = $parser->getShortDocumentationBatch(array_keys($files));
        
        $this->assertCount(2, $docs);
        $this->assertArrayHasKey('app/Services/Service1.php', $docs);
        $this->assertArrayHasKey('app/Services/Service2.php', $docs);
    }

    public function test_documentation_parser_has_short_documentation(): void
    {
        $parser = new DocumentationParser();
        
        // Create short documentation
        $shortDocPath = 'docs/short/app/Services/TestService.md';
        File::ensureDirectoryExists(base_path(dirname($shortDocPath)));
        File::put(base_path($shortDocPath), "## TestService\n\nShort documentation content");
        
        $hasDoc = $parser->hasShortDocumentation('app/Services/TestService.php');
        $this->assertTrue($hasDoc);
        
        $hasNoDoc = $parser->hasShortDocumentation('app/Services/NonExistent.php');
        $this->assertFalse($hasNoDoc);
    }

    public function test_documentation_parser_module_extraction(): void
    {
        $parser = new DocumentationParser();
        
        // Create a module file with front matter
        $modulePath = base_path('docs/test-module.md');
        $moduleContent = "---\ntitle: Authentication Module\nslug: auth\n---\n\n# Authentication Module\n\n## Overview\n\nThis module handles authentication";
        File::put($modulePath, $moduleContent);
        
        $metadata = $parser->extractModuleMetadata($modulePath);
        
        $this->assertArrayHasKey('title', $metadata);
        $this->assertEquals('Authentication Module', $metadata['title']);
        $this->assertArrayHasKey('slug', $metadata);
        $this->assertEquals('auth', $metadata['slug']);
        
        // Clean up
        File::delete($modulePath);
    }

    public function test_module_assignment_service_coverage(): void
    {
        $service = new ModuleAssignmentService();
        
        // Test load_log - it should create default structure if not exists
        $log = $service->load_log();
        $this->assertIsArray($log);
        $this->assertArrayHasKey('assigned_files', $log);
        $this->assertArrayHasKey('unassigned_files', $log);
    }

    public function test_module_mapping_service_additional_coverage(): void
    {
        $service = new ModuleMappingService();
        
        // Create test module
        File::put(base_path('docs/source_documents/modules/metadata/test-module.json'), json_encode([
            'module_slug' => 'test-module',
            'module_name' => 'Test Module',
            'files' => [
                ['path' => 'app/Services/TestService1.php'],
                ['path' => 'app/Services/TestService2.php'],
                ['path' => 'app/Services/TestService3.php'],
            ]
        ]));
        
        // Refresh mappings
        $service->refresh_mappings();
        
        // Test getting all modules
        $modules = $service->get_all_modules();
        $this->assertContains('test-module', $modules);
        
        // Test getting module for file
        $module = $service->get_module_for_file('app/Services/TestService1.php');
        $this->assertEquals('test-module', $module);
        
        // Test getting module metadata
        $metadata = $service->get_module_metadata('test-module');
        $this->assertArrayHasKey('module_name', $metadata);
        $this->assertEquals('Test Module', $metadata['module_name']);
        
        // Test suggesting module for new file
        $suggestion = $service->suggest_module_for_new_file('app/Services/TestService4.php');
        $this->assertEquals('test-module', $suggestion);
    }

    public function test_documentation_parser_missing_files(): void
    {
        $parser = new DocumentationParser();
        
        // Test non-existent short documentation
        $shortDoc = $parser->getShortDocumentation('non/existent/file.php');
        $this->assertNull($shortDoc);
    }

    public function test_module_assignment_service_log_operations(): void
    {
        $service = new ModuleAssignmentService();
        
        // Test loading non-existent log - it creates a default structure
        $log = $service->load_log();
        $this->assertArrayHasKey('assigned_files', $log);
        $this->assertArrayHasKey('unassigned_files', $log);
        
        // We can't test save_log as it's protected, but we've tested load_log
        $this->assertTrue(true);
    }

    public function test_module_mapping_service_edge_cases(): void
    {
        $service = new ModuleMappingService();
        
        // Test with empty module directory
        $modules = $service->get_all_modules();
        $this->assertIsArray($modules);
        
        // Test with invalid module metadata
        File::put(base_path('docs/source_documents/modules/metadata/invalid.json'), 'invalid json');
        
        $service->refresh_mappings();
        
        // Should handle gracefully
        $metadata = $service->get_module_metadata('invalid');
        $this->assertNull($metadata);
        
        // Clean up
        File::delete(base_path('docs/source_documents/modules/metadata/invalid.json'));
    }
}