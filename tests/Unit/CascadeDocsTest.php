<?php

use Lumiio\CascadeDocs\CascadeDocs;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationParser;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;

beforeEach(function () {
    $this->parser = Mockery::mock(DocumentationParser::class);
    $this->metadataService = Mockery::mock(ModuleMetadataService::class);
    $this->mappingService = Mockery::mock(ModuleMappingService::class);
    
    $this->cascadeDocs = new CascadeDocs(
        $this->parser,
        $this->metadataService,
        $this->mappingService
    );
});

covers(CascadeDocs::class);

it('gets file module information', function () {
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
    expect($result)->toBe($expectedModule);
});

it('returns null when file has no module', function () {
    // Given - A file without module assignment
    $filePath = base_path('app/Helpers/random.php');
    
    $this->mappingService->shouldReceive('getFileModule')
        ->once()
        ->with($filePath)
        ->andReturn(null);
    
    // When - Get file module
    $result = $this->cascadeDocs->getFileModule($filePath);
    
    // Then - Should return null
    expect($result)->toBeNull();
});

it('parses documentation from string', function () {
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
    expect($result)->toBe($expectedParsed);
});

it('gets documentation for a specific file when exists', function () {
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
    expect($result)->toBe('Test documentation content');
    
    // Cleanup
    @unlink($docPath);
});

it('returns null when documentation does not exist', function () {
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
    expect($result)->toBeNull();
});

it('uses default tier when not specified', function () {
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
    expect($result)->toBe('User model documentation');
    
    // Cleanup
    @unlink($docPath);
});

it('gets all modules when metadata exists', function () {
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
    expect($result)->toHaveCount(2);
    
    // Sort results by slug for consistent testing
    usort($result, fn($a, $b) => strcmp($a['slug'], $b['slug']));
    
    expect($result[0]['slug'])->toBe('billing');
    expect($result[0]['name'])->toBe('Billing System');
    expect($result[0]['file_count'])->toBe(3);
    expect($result[1]['slug'])->toBe('user-management');
    expect($result[1]['name'])->toBe('User Management');
    expect($result[1]['file_count'])->toBe(2);
    
    // Cleanup
    @unlink($metadataPath . 'user-management.json');
    @unlink($metadataPath . 'billing.json');
    @rmdir($metadataPath);
});

it('returns empty array when no modules directory exists', function () {
    // Given - No metadata directory
    config(['cascadedocs.paths.modules.metadata' => 'docs/source_documents/modules/metadata/']);
    
    // When - Get all modules
    $result = $this->cascadeDocs->getModules();
    
    // Then - Should return empty array
    expect($result)->toBeArray()->toBeEmpty();
});

it('gets module documentation when exists', function () {
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
    expect($result)->toBe('# User Management Module Documentation');
    
    // Cleanup
    @unlink($contentPath . 'user-management.md');
});

it('returns null when module documentation does not exist', function () {
    // Given - A non-existent module
    $moduleSlug = 'non-existent';
    config(['cascadedocs.paths.modules.content' => 'docs/source_documents/modules/content/']);
    
    // When - Get module documentation
    $result = $this->cascadeDocs->getModuleDocumentation($moduleSlug);
    
    // Then - Should return null
    expect($result)->toBeNull();
});

it('handles different tier mappings', function () {
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
        expect($result)->toBe("Content for {$testCase['tier']}");
        
        // Cleanup
        @unlink($docPath);
    }
});

it('handles files with different extensions', function () {
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
        expect($result)->toBe("Documentation for {$file}");
        
        // Cleanup
        @unlink($docPath);
    }
});

it('handles invalid tier gracefully', function () {
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
    expect($result)->toBe('Invalid tier content');
    
    // Cleanup
    @unlink($docPath);
});

afterEach(function () {
    // Clean up any remaining test files
    $testDirs = [
        base_path('app/Services'),
        base_path('app/Models'),
        base_path('app/Http/Controllers/Api/V1'),
        base_path('app/Http/Controllers/Api'),
        base_path('app/Http/Controllers'),
        base_path('app/Http'),
        base_path('app'),
        base_path('resources/js/components'),
        base_path('resources/js'),
        base_path('resources'),
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
});