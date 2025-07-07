<?php

use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;

beforeEach(function () {
    // Set up default config
    config([
        'cascadedocs.paths.modules.metadata' => 'docs/source_documents/modules/metadata/',
        'cascadedocs.paths.modules.content' => 'docs/source_documents/modules/content/',
    ]);

    // Create directories
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    $contentPath = base_path('docs/source_documents/modules/content/');
    @mkdir($metadataPath, 0755, true);
    @mkdir($contentPath, 0755, true);

    $this->service = new ModuleMetadataService;
});

covers(ModuleMetadataService::class);

it('loads module metadata from json file', function () {
    // Create test metadata
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'module_summary' => 'A test module',
        'files' => [],
        'undocumented_files' => [],
    ];

    $metadataPath = base_path('docs/source_documents/modules/metadata/test-module.json');
    file_put_contents($metadataPath, json_encode($metadata));

    // When
    $loaded = $this->service->loadMetadata('test-module');

    // Then
    expect($loaded)->toBeArray();
    expect($loaded['module_name'])->toBe('Test Module');
    expect($loaded['module_summary'])->toBe('A test module');

    // Cleanup
    @unlink($metadataPath);
});

it('returns null when metadata file does not exist', function () {
    // When
    $result = $this->service->loadMetadata('non-existent-module');

    // Then
    expect($result)->toBeNull();
});

it('saves metadata with updated statistics and timestamp', function () {
    // Given
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [
            ['path' => 'file1.php'],
            ['path' => 'file2.php'],
        ],
        'undocumented_files' => ['file3.php'],
    ];

    // When
    $this->service->saveMetadata('test-module', $metadata);

    // Then
    $saved = json_decode(file_get_contents(base_path('docs/source_documents/modules/metadata/test-module.json')), true);
    expect($saved['statistics'])->toBe([
        'total_files' => 3,
        'documented_files' => 2,
        'undocumented_files' => 1,
    ]);
    expect($saved)->toHaveKey('last_updated');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
});

it('adds documented files to module', function () {
    // Setup existing module
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [
            ['path' => 'existing.php'],
        ],
        'undocumented_files' => [],
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // Create documentation files to determine tier
    $docPath = base_path('docs/source_documents/full/app/Services/NewService.md');
    @mkdir(dirname($docPath), 0755, true);
    file_put_contents($docPath, '# Documentation');

    // When
    $updated = $this->service->addFiles('test-module', ['app/Services/NewService.php'], true);

    // Then
    expect($updated['files'])->toHaveCount(2);
    $newFile = collect($updated['files'])->firstWhere('path', 'app/Services/NewService.php');
    expect($newFile)->not->toBeNull();
    expect($newFile['documented'])->toBeTrue();
    expect($newFile)->toHaveKey('added_date');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
    @unlink($docPath);
});

it('adds undocumented files to module', function () {
    // Setup existing module
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [],
        'undocumented_files' => ['existing.php'],
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // When
    $updated = $this->service->addFiles('test-module', ['new.php'], false);

    // Then
    expect($updated['undocumented_files'])->toHaveCount(2);
    expect($updated['undocumented_files'])->toContain('new.php');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
});

it('throws exception when adding files to non-existent module', function () {
    // When/Then
    expect(fn () => $this->service->addFiles('non-existent', ['file.php']))
        ->toThrow(Exception::class, 'Module not found: non-existent');
});

it('removes files from module', function () {
    // Setup
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [
            ['path' => 'file1.php'],
            ['path' => 'file2.php'],
        ],
        'undocumented_files' => ['file3.php', 'file4.php'],
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // When
    $updated = $this->service->removeFiles('test-module', ['file1.php', 'file3.php']);

    // Then
    expect($updated['files'])->toHaveCount(1);
    expect($updated['files'][0]['path'])->toBe('file2.php');
    expect($updated['undocumented_files'])->toHaveCount(1);
    expect($updated['undocumented_files'][0])->toBe('file4.php');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
});

it('marks files as documented', function () {
    // Setup
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [],
        'undocumented_files' => ['file1.php', 'file2.php'],
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // Create documentation to determine tier
    $docPath = base_path('docs/source_documents/medium/file1.md');
    @mkdir(dirname($docPath), 0755, true);
    file_put_contents($docPath, '# Documentation');

    // When
    $updated = $this->service->markFilesAsDocumented('test-module', ['file1.php']);

    // Then
    expect($updated['files'])->toHaveCount(1);
    expect($updated['files'][0]['path'])->toBe('file1.php');
    expect($updated['files'][0]['documented'])->toBeTrue();
    expect($updated['undocumented_files'])->toHaveCount(1);
    expect($updated['undocumented_files'][0])->toBe('file2.php');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
    @unlink($docPath);
});

it('updates module summary', function () {
    // Setup
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'module_summary' => 'Old summary',
        'files' => [],
        'undocumented_files' => [],
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // When
    $this->service->updateModuleSummary('test-module', 'New summary');

    // Then
    $updated = $this->service->loadMetadata('test-module');
    expect($updated['module_summary'])->toBe('New summary');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
});

it('moves file to undocumented status', function () {
    // Setup
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [
            ['path' => 'file1.php', 'documented' => true],
            ['path' => 'file2.php', 'documented' => true],
        ],
        'undocumented_files' => [],
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // When
    $this->service->moveFileToUndocumented('test-module', 'file1.php');

    // Then
    $updated = $this->service->loadMetadata('test-module');
    expect($updated['files'])->toHaveCount(1);
    expect($updated['files'][0]['path'])->toBe('file2.php');
    expect($updated['undocumented_files'])->toContain('file1.php');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
});

it('creates new module with metadata and content file', function () {
    // Mock git command for commit SHA
    exec('cd '.base_path().' && git init && git config user.email "test@example.com" && git config user.name "Test" && git commit --allow-empty -m "test" 2>&1', $output, $returnCode);

    // Given
    $moduleData = [
        'slug' => 'new-module',
        'name' => 'New Module',
        'description' => 'A brand new module',
        'files' => ['file1.php', 'file2.php'],
    ];

    // When
    $this->service->createModule($moduleData);

    // Then - Check metadata
    $metadata = $this->service->loadMetadata('new-module');
    expect($metadata['module_name'])->toBe('New Module');
    expect($metadata['module_slug'])->toBe('new-module');
    expect($metadata['module_summary'])->toBe('');
    expect($metadata['undocumented_files'])->toHaveCount(2);
    expect($metadata['statistics']['total_files'])->toBe(2);
    expect($metadata['statistics']['documented_files'])->toBe(0);

    // Check content file
    $contentPath = base_path('docs/source_documents/modules/content/new-module.md');
    expect($contentPath)->toBeFile();
    $content = file_get_contents($contentPath);
    expect($content)->toContain('# New Module Module');
    expect($content)->toContain('A brand new module');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/new-module.json'));
    @unlink($contentPath);
    exec('cd '.base_path().' && rm -rf .git');
});

it('gets all module slugs', function () {
    // Create test modules
    $this->service->saveMetadata('module-a', ['module_slug' => 'module-a']);
    $this->service->saveMetadata('module-b', ['module_slug' => 'module-b']);
    $this->service->saveMetadata('module-c', ['module_slug' => 'module-c']);

    // When
    $slugs = $this->service->getAllModuleSlugs();

    // Then
    expect($slugs)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($slugs)->toHaveCount(3);
    expect($slugs->all())->toBe(['module-a', 'module-b', 'module-c']); // Sorted

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/module-a.json'));
    @unlink(base_path('docs/source_documents/modules/metadata/module-b.json'));
    @unlink(base_path('docs/source_documents/modules/metadata/module-c.json'));
});

it('gets all files from a module', function () {
    // Setup
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [
            ['path' => 'file1.php'],
            ['path' => 'file2.php'],
        ],
        'undocumented_files' => ['file3.php', 'file1.php'], // Duplicate to test unique
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // When
    $files = $this->service->getAllModuleFiles('test-module');

    // Then
    expect($files)->toBeArray();
    expect($files)->toHaveCount(3); // Unique files only
    expect($files)->toContain('file1.php', 'file2.php', 'file3.php');

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
});

it('returns empty array for non-existent module files', function () {
    // When
    $files = $this->service->getAllModuleFiles('non-existent');

    // Then
    expect($files)->toBeArray();
    expect($files)->toBeEmpty();
});

it('checks if module exists', function () {
    // Create a module
    $this->service->saveMetadata('existing-module', ['module_slug' => 'existing-module']);

    // When/Then
    expect($this->service->moduleExists('existing-module'))->toBeTrue();
    expect($this->service->moduleExists('non-existent'))->toBeFalse();

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/existing-module.json'));
});


it('skips duplicate files when adding', function () {
    // Setup
    $metadata = [
        'module_name' => 'Test Module',
        'module_slug' => 'test-module',
        'files' => [
            ['path' => 'existing.php'],
        ],
        'undocumented_files' => ['undoc.php'],
    ];
    $this->service->saveMetadata('test-module', $metadata);

    // When - Try to add existing files
    $updated = $this->service->addFiles('test-module', ['existing.php', 'undoc.php'], false);

    // Then - No duplicates
    expect($updated['files'])->toHaveCount(1);
    expect($updated['undocumented_files'])->toHaveCount(1);

    // Cleanup
    @unlink(base_path('docs/source_documents/modules/metadata/test-module.json'));
});

afterEach(function () {
    // Clean up test directories
    $dirs = [
        base_path('docs/source_documents/full/app/Services'),
        base_path('docs/source_documents/full/app'),
        base_path('docs/source_documents/full'),
        base_path('docs/source_documents/medium/app/Services'),
        base_path('docs/source_documents/medium/app'),
        base_path('docs/source_documents/medium'),
        base_path('docs/source_documents/short/app/Services'),
        base_path('docs/source_documents/short/app'),
        base_path('docs/source_documents/short'),
        base_path('docs/source_documents/modules/metadata'),
        base_path('docs/source_documents/modules/content'),
        base_path('docs/source_documents/modules'),
        base_path('docs/source_documents'),
        base_path('docs'),
    ];

    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }
});
