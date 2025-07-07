<?php

namespace Lumiio\CascadeDocs\Tests\Unit;

use Lumiio\CascadeDocs\CascadeDocs;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationParser;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Lumiio\CascadeDocs\Tests\TestCase;
use Mockery;

#[CoversClass(CascadeDocs::class)]
class CascadeDocsTest extends TestCase
{
    protected CascadeDocs $cascadeDocs;
    protected DocumentationParser $parser;
    protected ModuleMetadataService $metadataService;
    protected ModuleMappingService $mappingService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->parser = Mockery::mock(DocumentationParser::class);
        $this->metadataService = Mockery::mock(ModuleMetadataService::class);
        $this->mappingService = Mockery::mock(ModuleMappingService::class);
        
        $this->cascadeDocs = new CascadeDocs(
            $this->parser,
            $this->metadataService,
            $this->mappingService
        );
    }

    /** @test */
    public function it_gets_file_module_information(): void
    {
        // Given - A file path
        $filePath = base_path('app/Services/UserService.php');
        $expectedModule = [
            'module_name' => 'User Management',
            'module_slug' => 'user-management'
        ];
        
        // Mock the mapping service response
        $this->mappingService->shouldReceive('getFileModule')
            ->once()
            ->with($filePath)
            ->andReturn($expectedModule);
        
        // When - Get file module
        $result = $this->cascadeDocs->getFileModule($filePath);
        
        // Then - Should return module information
        $this->assertEquals($expectedModule, $result);
    }

    /** @test */
    public function it_returns_null_when_file_has_no_module(): void
    {
        // Given - A file without module assignment
        $filePath = base_path('app/Helpers/random.php');
        
        $this->mappingService->shouldReceive('getFileModule')
            ->once()
            ->with($filePath)
            ->andReturn(null);
        
        // When - Get file module
        $result = $this->cascadeDocs->getFileModule($filePath);
        
        // Then - Should return null
        $this->assertNull($result);
    }

    /** @test */
    public function it_parses_documentation_from_string(): void
    {
        // Given - Documentation content
        $content = '# Test Documentation';
        $expectedParsed = [
            'title' => 'Test Documentation',
            'sections' => []
        ];
        
        $this->parser->shouldReceive('parse')
            ->once()
            ->with($content)
            ->andReturn($expectedParsed);
        
        // When - Parse documentation
        $result = $this->cascadeDocs->parseDocumentation($content);
        
        // Then - Should return parsed data
        $this->assertEquals($expectedParsed, $result);
    }

    /** @test */
    public function it_gets_documentation_for_a_specific_file_when_exists(): void
    {
        // Given - A file path and tier
        $filePath = base_path('app/Services/TestService.php');
        $tier = 'standard';
        
        // Set up config
        config(['cascadedocs.paths.output' => 'docs/source_documents/']);
        config(['cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full'
        ]]);
        
        // Create a test documentation file
        $docPath = base_path('docs/source_documents/medium/app/Services/TestService.md');
        @mkdir(dirname($docPath), 0755, true);
        file_put_contents($docPath, 'Test documentation content');
        
        // When - Get documentation
        $result = $this->cascadeDocs->getDocumentation($filePath, $tier);
        
        // Then - Should return the documentation content
        $this->assertEquals('Test documentation content', $result);
        
        // Cleanup
        @unlink($docPath);
    }

    /** @test */
    public function it_returns_null_when_documentation_does_not_exist(): void
    {
        // Given - A file without documentation
        $filePath = base_path('app/Services/NonExistent.php');
        
        config(['cascadedocs.paths.output' => 'docs/source_documents/']);
        config(['cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full'
        ]]);
        
        // When - Get documentation
        $result = $this->cascadeDocs->getDocumentation($filePath);
        
        // Then - Should return null
        $this->assertNull($result);
    }

    /** @test */
    public function it_uses_default_tier_when_not_specified(): void
    {
        // Given - A file path without tier specification
        $filePath = base_path('app/Models/User.php');
        
        config(['cascadedocs.paths.output' => 'docs/source_documents/']);
        config(['cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full'
        ]]);
        
        // Create test documentation in medium tier (default)
        $docPath = base_path('docs/source_documents/medium/app/Models/User.md');
        @mkdir(dirname($docPath), 0755, true);
        file_put_contents($docPath, 'User model documentation');
        
        // When - Get documentation without specifying tier
        $result = $this->cascadeDocs->getDocumentation($filePath);
        
        // Then - Should use default 'medium' tier
        $this->assertEquals('User model documentation', $result);
        
        // Cleanup
        @unlink($docPath);
    }

    /** @test */
    public function it_gets_all_modules_when_metadata_exists(): void
    {
        // Given - Module metadata files exist
        config(['cascadedocs.paths.modules.metadata' => 'docs/source_documents/modules/metadata/']);
        $metadataPath = base_path('docs/source_documents/modules/metadata/');
        
        // Create test metadata files
        @mkdir($metadataPath, 0755, true);
        file_put_contents($metadataPath . 'user-management.json', json_encode(['test' => 'data']));
        file_put_contents($metadataPath . 'billing.json', json_encode(['test' => 'data']));
        
        // Mock metadata service responses
        $this->metadataService->shouldReceive('loadMetadata')
            ->with('user-management')
            ->andReturn([
                'module_name' => 'User Management',
                'module_summary' => 'Handles user operations',
                'files' => ['file1.php', 'file2.php']
            ]);
            
        $this->metadataService->shouldReceive('loadMetadata')
            ->with('billing')
            ->andReturn([
                'module_name' => 'Billing System',
                'module_summary' => 'Handles payments',
                'files' => ['billing1.php', 'billing2.php', 'billing3.php']
            ]);
        
        // When - Get all modules
        $result = $this->cascadeDocs->getModules();
        
        // Then - Should return module information
        $this->assertCount(2, $result);
        
        // Sort results by slug for consistent testing
        usort($result, fn($a, $b) => strcmp($a['slug'], $b['slug']));
        
        $this->assertEquals('billing', $result[0]['slug']);
        $this->assertEquals('Billing System', $result[0]['name']);
        $this->assertEquals(3, $result[0]['file_count']);
        $this->assertEquals('user-management', $result[1]['slug']);
        $this->assertEquals('User Management', $result[1]['name']);
        $this->assertEquals(2, $result[1]['file_count']);
        
        // Cleanup
        @unlink($metadataPath . 'user-management.json');
        @unlink($metadataPath . 'billing.json');
        @rmdir($metadataPath);
    }

    /** @test */
    public function it_returns_empty_array_when_no_modules_directory_exists(): void
    {
        // Given - No metadata directory
        config(['cascadedocs.paths.modules.metadata' => 'docs/source_documents/modules/metadata/']);
        
        // When - Get all modules
        $result = $this->cascadeDocs->getModules();
        
        // Then - Should return empty array
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /** @test */
    public function it_gets_module_documentation_when_exists(): void
    {
        // Given - A module slug
        $moduleSlug = 'user-management';
        config(['cascadedocs.paths.modules.content' => 'docs/source_documents/modules/content/']);
        
        // Create test module documentation
        $contentPath = base_path('docs/source_documents/modules/content/');
        @mkdir($contentPath, 0755, true);
        file_put_contents($contentPath . 'user-management.md', '# User Management Module Documentation');
        
        // When - Get module documentation
        $result = $this->cascadeDocs->getModuleDocumentation($moduleSlug);
        
        // Then - Should return documentation content
        $this->assertEquals('# User Management Module Documentation', $result);
        
        // Cleanup
        @unlink($contentPath . 'user-management.md');
    }

    /** @test */
    public function it_returns_null_when_module_documentation_does_not_exist(): void
    {
        // Given - A non-existent module
        $moduleSlug = 'non-existent';
        config(['cascadedocs.paths.modules.content' => 'docs/source_documents/modules/content/']);
        
        // When - Get module documentation
        $result = $this->cascadeDocs->getModuleDocumentation($moduleSlug);
        
        // Then - Should return null
        $this->assertNull($result);
    }

    /** @test */
    public function it_handles_different_tier_mappings(): void
    {
        // Given - Different tier names
        config(['cascadedocs.paths.output' => 'docs/source_documents/']);
        config(['cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full'
        ]]);
        
        $testCases = [
            ['tier' => 'micro', 'expected_dir' => 'short'],
            ['tier' => 'standard', 'expected_dir' => 'medium'],
            ['tier' => 'expansive', 'expected_dir' => 'full'],
        ];
        
        foreach ($testCases as $testCase) {
            // Create test doc
            $filePath = base_path('app/Test.php');
            $docPath = base_path("docs/source_documents/{$testCase['expected_dir']}/app/Test.md");
            @mkdir(dirname($docPath), 0755, true);
            file_put_contents($docPath, "Content for {$testCase['tier']}");
            
            // When - Get documentation
            $result = $this->cascadeDocs->getDocumentation($filePath, $testCase['tier']);
            
            // Then
            $this->assertEquals("Content for {$testCase['tier']}", $result);
            
            // Cleanup
            @unlink($docPath);
        }
    }

    /** @test */
    public function it_handles_files_with_different_extensions(): void
    {
        // Given - Files with different extensions
        $testFiles = [
            'app/Services/TestService.php',
            'resources/js/components/Test.vue',
            'resources/js/utils/helper.js',
        ];
        
        config(['cascadedocs.paths.output' => 'docs/source_documents/']);
        config(['cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full'
        ]]);
        
        foreach ($testFiles as $file) {
            $filePath = base_path($file);
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $withoutExt = substr($file, 0, -(strlen($extension) + 1));
            $docPath = base_path("docs/source_documents/medium/{$withoutExt}.md");
            
            @mkdir(dirname($docPath), 0755, true);
            file_put_contents($docPath, "Documentation for {$file}");
            
            // When - Get documentation
            $result = $this->cascadeDocs->getDocumentation($filePath);
            
            // Then - Should handle extension correctly
            $this->assertEquals("Documentation for {$file}", $result);
            
            // Cleanup
            @unlink($docPath);
        }
    }

    /** @test */
    public function it_handles_invalid_tier_gracefully(): void
    {
        // Given - An invalid tier
        $filePath = base_path('app/Test.php');
        config(['cascadedocs.paths.output' => 'docs/source_documents/']);
        config(['cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full'
        ]]);
        
        // When using invalid tier, it should use the tier name as-is
        $docPath = base_path('docs/source_documents/invalid/app/Test.md');
        @mkdir(dirname($docPath), 0755, true);
        file_put_contents($docPath, 'Invalid tier content');
        
        // When
        $result = $this->cascadeDocs->getDocumentation($filePath, 'invalid');
        
        // Then
        $this->assertEquals('Invalid tier content', $result);
        
        // Cleanup
        @unlink($docPath);
    }

    protected function tearDown(): void
    {
        // Clean up any remaining test files
        $testDirs = [
            base_path('docs/source_documents/short'),
            base_path('docs/source_documents/medium'),
            base_path('docs/source_documents/full'),
            base_path('docs/source_documents/invalid'),
            base_path('docs/source_documents/modules/metadata'),
            base_path('docs/source_documents/modules/content'),
            base_path('docs/source_documents/modules'),
            base_path('docs/source_documents'),
            base_path('docs'),
        ];
        
        foreach ($testDirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($dir);
            }
        }
        
        parent::tearDown();
    }
}