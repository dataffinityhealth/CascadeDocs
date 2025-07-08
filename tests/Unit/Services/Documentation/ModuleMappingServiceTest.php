<?php

use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;

beforeEach(function () {
    // Set up default config
    config([
        'cascadedocs.paths.modules.metadata' => 'docs/source_documents/modules/metadata/',
        'cascadedocs.exclude.directories' => ['vendor/', 'node_modules/', 'tests/'],
        'cascadedocs.excluded_namespace_parts' => ['app', 'src', 'resources', 'js'],
    ]);

    $this->service = new ModuleMappingService;
});

covers(ModuleMappingService::class);

it('loads module mappings from metadata files', function () {
    // Create metadata directory and files
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    // Create user management module metadata
    $userMetadata = [
        'module_slug' => 'user-management',
        'module_name' => 'User Management',
        'files' => [
            ['path' => 'app/Services/UserService.php'],
            ['path' => 'app/Models/User.php'],
        ],
        'undocumented_files' => [
            'app/Http/Controllers/UserController.php',
        ],
    ];
    file_put_contents($metadataPath.'user-management.json', json_encode($userMetadata));

    // Create billing module metadata
    $billingMetadata = [
        'module_slug' => 'billing',
        'module_name' => 'Billing System',
        'files' => [
            ['path' => 'app/Services/PaymentService.php'],
        ],
    ];
    file_put_contents($metadataPath.'billing.json', json_encode($billingMetadata));

    // Create new service instance to load mappings
    $service = new ModuleMappingService;

    // When/Then
    expect($service->get_module_for_file(base_path('app/Services/UserService.php')))->toBe('user-management');
    expect($service->get_module_for_file(base_path('app/Models/User.php')))->toBe('user-management');
    expect($service->get_module_for_file(base_path('app/Http/Controllers/UserController.php')))->toBe('user-management');
    expect($service->get_module_for_file(base_path('app/Services/PaymentService.php')))->toBe('billing');

    // Cleanup
    @unlink($metadataPath.'user-management.json');
    @unlink($metadataPath.'billing.json');
    @rmdir($metadataPath);
});

it('returns null when file has no module', function () {
    // When
    $result = $this->service->get_module_for_file(base_path('app/Services/UnassignedService.php'));

    // Then
    expect($result)->toBeNull();
});

it('gets files for a specific module', function () {
    // Create metadata
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    $metadata = [
        'module_slug' => 'test-module',
        'module_name' => 'Test Module',
        'files' => [
            ['path' => 'app/Services/TestService.php'],
            ['path' => 'app/Models/TestModel.php'],
        ],
    ];
    file_put_contents($metadataPath.'test-module.json', json_encode($metadata));

    // Create new service instance
    $service = new ModuleMappingService;

    // When
    $files = $service->get_files_for_module('test-module');

    // Then
    expect($files)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($files)->toHaveCount(2);
    expect($files)->toContain('app/Services/TestService.php');
    expect($files)->toContain('app/Models/TestModel.php');

    // Cleanup
    @unlink($metadataPath.'test-module.json');
    @rmdir($metadataPath);
});

it('returns empty collection for non-existent module', function () {
    // When
    $files = $this->service->get_files_for_module('non-existent-module');

    // Then
    expect($files)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($files)->toBeEmpty();
});

it('gets all modules', function () {
    // Create metadata
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    file_put_contents($metadataPath.'module-one.json', json_encode(['module_slug' => 'module-one']));
    file_put_contents($metadataPath.'module-two.json', json_encode(['module_slug' => 'module-two']));

    // Create new service instance
    $service = new ModuleMappingService;

    // When
    $modules = $service->get_all_modules();

    // Then
    expect($modules)->toBeArray();
    expect($modules)->toHaveCount(2);
    expect($modules)->toContain('module-one');
    expect($modules)->toContain('module-two');

    // Cleanup
    @unlink($metadataPath.'module-one.json');
    @unlink($metadataPath.'module-two.json');
    @rmdir($metadataPath);
});

it('gets module metadata', function () {
    // Create metadata
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    $metadata = [
        'module_slug' => 'test-module',
        'module_name' => 'Test Module',
        'module_summary' => 'A test module for testing',
        'files' => [],
    ];
    file_put_contents($metadataPath.'test-module.json', json_encode($metadata));

    // Create new service instance
    $service = new ModuleMappingService;

    // When
    $result = $service->get_module_metadata('test-module');

    // Then
    expect($result)->toBeArray();
    expect($result['module_name'])->toBe('Test Module');
    expect($result['module_summary'])->toBe('A test module for testing');

    // Cleanup
    @unlink($metadataPath.'test-module.json');
    @rmdir($metadataPath);
});

it('returns null for non-existent module metadata', function () {
    // When
    $result = $this->service->get_module_metadata('non-existent');

    // Then
    expect($result)->toBeNull();
});

it('suggests module for new file based on directory siblings', function () {
    // Create metadata
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    $metadata = [
        'module_slug' => 'payment-processing',
        'module_name' => 'Payment Processing',
        'files' => [
            ['path' => 'app/Services/PaymentService.php'],
            ['path' => 'app/Services/InvoiceService.php'],
            ['path' => 'app/Services/SubscriptionService.php'],
        ],
    ];
    file_put_contents($metadataPath.'payment-processing.json', json_encode($metadata));

    // Create new service instance
    $service = new ModuleMappingService;

    // When - New file in same directory
    $suggestion = $service->suggest_module_for_new_file(base_path('app/Services/RefundService.php'));

    // Then
    expect($suggestion)->toBe('payment-processing');

    // Cleanup
    @unlink($metadataPath.'payment-processing.json');
    @rmdir($metadataPath);
});

it('does not suggest module for documentation files', function () {
    // Create metadata
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    $metadata = [
        'module_slug' => 'some-module',
        'module_name' => 'Some Module',
        'files' => [
            ['path' => 'app/Services/SomeService.php'],
        ],
    ];
    file_put_contents($metadataPath.'some-module.json', json_encode($metadata));

    // Create new service instance
    $service = new ModuleMappingService;

    // When - Documentation related files
    expect($service->suggest_module_for_new_file(base_path('app/Services/Documentation/DocService.php')))->toBeNull();
    expect($service->suggest_module_for_new_file(base_path('app/documentation/SomeDoc.php')))->toBeNull();

    // Cleanup
    @unlink($metadataPath.'some-module.json');
    @rmdir($metadataPath);
});

it('suggests module based on namespace pattern matching', function () {
    // Create metadata
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    $metadata = [
        'module_slug' => 'user-management',
        'module_name' => 'User Management',
        'files' => [
            ['path' => 'app/Models/User.php'],
        ],
    ];
    file_put_contents($metadataPath.'user-management.json', json_encode($metadata));

    // Create new service instance
    $service = new ModuleMappingService;

    // When - File with "User" in path but different directory
    $suggestion = $service->suggest_module_for_new_file(base_path('app/Repositories/UserRepository.php'));

    // Then
    expect($suggestion)->toBe('user-management');

    // Cleanup
    @unlink($metadataPath.'user-management.json');
    @rmdir($metadataPath);
});

it('refreshes mappings when called', function () {
    // Create initial metadata
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    $metadata = [
        'module_slug' => 'initial-module',
        'module_name' => 'Initial Module',
        'files' => [
            ['path' => 'app/Services/InitialService.php'],
        ],
    ];
    file_put_contents($metadataPath.'initial-module.json', json_encode($metadata));

    // Create service and verify initial state
    $service = new ModuleMappingService;
    expect($service->get_module_for_file(base_path('app/Services/InitialService.php')))->toBe('initial-module');

    // Update metadata
    $metadata['files'][] = ['path' => 'app/Services/NewService.php'];
    file_put_contents($metadataPath.'initial-module.json', json_encode($metadata));

    // Before refresh, new file not mapped
    expect($service->get_module_for_file(base_path('app/Services/NewService.php')))->toBeNull();

    // When - Refresh mappings
    $service->refresh_mappings();

    // Then - New file is mapped
    expect($service->get_module_for_file(base_path('app/Services/NewService.php')))->toBe('initial-module');

    // Cleanup
    @unlink($metadataPath.'initial-module.json');
    @rmdir($metadataPath);
});

it('handles missing metadata directory gracefully', function () {
    // Ensure directory doesn't exist
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    if (is_dir($metadataPath)) {
        rmdir($metadataPath);
    }

    // When - Create service
    $service = new ModuleMappingService;

    // Then - Should not throw error
    expect($service->get_all_modules())->toBeEmpty();
    expect($service->get_module_for_file('any/file.php'))->toBeNull();
});

it('skips non-json files in metadata directory', function () {
    // Create metadata directory with mixed files
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    // Create valid JSON
    file_put_contents($metadataPath.'valid.json', json_encode(['module_slug' => 'valid-module']));

    // Create non-JSON files
    file_put_contents($metadataPath.'readme.txt', 'This is a readme');
    file_put_contents($metadataPath.'.gitkeep', '');

    // Create new service instance
    $service = new ModuleMappingService;

    // When
    $modules = $service->get_all_modules();

    // Then - Only valid JSON module loaded
    expect($modules)->toHaveCount(1);
    expect($modules)->toContain('valid-module');

    // Cleanup
    @unlink($metadataPath.'valid.json');
    @unlink($metadataPath.'readme.txt');
    @unlink($metadataPath.'.gitkeep');
    @rmdir($metadataPath);
});

it('handles invalid json gracefully', function () {
    // Create metadata directory
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    // Create invalid JSON
    file_put_contents($metadataPath.'invalid.json', 'This is not valid JSON!');

    // Create valid JSON
    file_put_contents($metadataPath.'valid.json', json_encode(['module_slug' => 'valid-module']));

    // Create new service instance
    $service = new ModuleMappingService;

    // When
    $modules = $service->get_all_modules();

    // Then - Only valid module loaded
    expect($modules)->toHaveCount(1);
    expect($modules)->toContain('valid-module');

    // Cleanup
    @unlink($metadataPath.'invalid.json');
    @unlink($metadataPath.'valid.json');
    @rmdir($metadataPath);
});

it('requires minimum files in directory for suggestion', function () {
    // Create metadata with only one file in directory
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    @mkdir($metadataPath, 0755, true);

    $metadata = [
        'module_slug' => 'sparse-module',
        'module_name' => 'Sparse Module',
        'files' => [
            ['path' => 'app/Services/OnlyService.php'],
        ],
    ];
    file_put_contents($metadataPath.'sparse-module.json', json_encode($metadata));

    // Create new service instance
    $service = new ModuleMappingService;

    // When - New file in same directory but not enough siblings
    $suggestion = $service->suggest_module_for_new_file(base_path('app/Services/NewService.php'));

    // Then - No suggestion because only 1 file in directory
    expect($suggestion)->toBeNull();

    // Cleanup
    @unlink($metadataPath.'sparse-module.json');
    @rmdir($metadataPath);
});


afterEach(function () {
    // Clean up test directories
    $metadataPath = base_path('docs/source_documents/modules/metadata/');
    if (is_dir($metadataPath)) {
        $files = glob($metadataPath.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($metadataPath);
    }

    // Clean up parent directories
    $dirs = [
        base_path('docs/source_documents/modules'),
        base_path('docs/source_documents'),
        base_path('docs'),
    ];

    foreach ($dirs as $dir) {
        if (is_dir($dir) && count(scandir($dir)) == 2) { // Only . and ..
            @rmdir($dir);
        }
    }
});
