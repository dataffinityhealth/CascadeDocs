<?php

use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;

beforeEach(function () {
    // Set up default config
    config([
        'cascadedocs.paths.tracking.module_assignment' => 'docs/tracking/module-assignment-log.json',
        'cascadedocs.paths.output' => 'docs/source_documents/',
        'cascadedocs.tier_directories' => ['short', 'medium', 'full'],
        'cascadedocs.file_extensions.javascript' => ['js', 'vue', 'jsx', 'ts', 'tsx'],
        'cascadedocs.limits.module_detection.min_files_for_module' => 3,
        'cascadedocs.limits.module_detection.min_word_length' => 2,
        'cascadedocs.limits.module_detection.confidence_divisor' => 10,
        'cascadedocs.limits.module_detection.min_common_prefix_length' => 3,
        'cascadedocs.limits.module_detection.max_confidence' => 1.0,
        'cascadedocs.excluded_namespace_parts' => ['app', 'src', 'resources', 'js'],
        'cascadedocs.exclude.words' => ['php', 'js', 'vue', 'jsx'],
    ]);

    $this->service = new ModuleAssignmentService;
});

covers(ModuleAssignmentService::class);

it('analyzes module assignments for documented files', function () {
    // Create some documented files
    $docFiles = [
        'docs/source_documents/short/app/Services/UserService.md',
        'docs/source_documents/short/app/Services/OrderService.md',
        'docs/source_documents/short/app/Models/User.md',
        'docs/source_documents/medium/resources/js/components/UserList.md',
    ];

    foreach ($docFiles as $docFile) {
        @mkdir(dirname(base_path($docFile)), 0755, true);
        file_put_contents(base_path($docFile), '# Documentation');
    }

    // Create corresponding source files
    $sourceFiles = [
        'app/Services/UserService.php',
        'app/Services/OrderService.php',
        'app/Models/User.php',
        'resources/js/components/UserList.vue',
    ];

    foreach ($sourceFiles as $sourceFile) {
        @mkdir(dirname(base_path($sourceFile)), 0755, true);
        file_put_contents(base_path($sourceFile), '<?php // content');
    }

    // Mock the module mapping service
    $mappingService = Mockery::mock(ModuleMappingService::class);
    $mappingService->shouldReceive('get_module_for_file')
        ->with(base_path('app/Services/UserService.php'))
        ->andReturn('user-management');
    $mappingService->shouldReceive('get_module_for_file')
        ->with(base_path('app/Services/OrderService.php'))
        ->andReturn('order-management');
    $mappingService->shouldReceive('get_module_for_file')
        ->with(base_path('app/Models/User.php'))
        ->andReturn('user-management');
    $mappingService->shouldReceive('get_module_for_file')
        ->with(base_path('resources/js/components/UserList.vue'))
        ->andReturn(null);

    // Replace the module service in our instance
    $reflection = new ReflectionClass($this->service);
    $property = $reflection->getProperty('module_service');
    $property->setAccessible(true);
    $property->setValue($this->service, $mappingService);

    // When
    $result = $this->service->analyze_module_assignments();

    // Then
    expect($result)->toHaveKeys(['last_analysis', 'assigned_files', 'unassigned_files', 'potential_modules', 'module_suggestions']);
    expect($result['assigned_files'])->toHaveCount(2);
    expect($result['assigned_files']['user-management'])->toHaveCount(2);
    expect($result['assigned_files']['order-management'])->toHaveCount(1);
    expect($result['unassigned_files'])->toHaveCount(1);
    expect($result['unassigned_files'][0])->toBe('resources/js/components/UserList.vue');

    // Cleanup
    foreach ($docFiles as $docFile) {
        @unlink(base_path($docFile));
    }
    foreach ($sourceFiles as $sourceFile) {
        @unlink(base_path($sourceFile));
    }
});

it('identifies potential modules from unassigned files', function () {
    // Create documented files in same directory
    $docFiles = [
        'docs/source_documents/short/app/Services/PaymentService.md',
        'docs/source_documents/short/app/Services/InvoiceService.md',
        'docs/source_documents/short/app/Services/BillingService.md',
        'docs/source_documents/short/app/Services/SubscriptionService.md',
    ];

    foreach ($docFiles as $docFile) {
        @mkdir(dirname(base_path($docFile)), 0755, true);
        file_put_contents(base_path($docFile), '# Documentation');
    }

    // Create corresponding source files
    $sourceFiles = [
        'app/Services/PaymentService.php',
        'app/Services/InvoiceService.php',
        'app/Services/BillingService.php',
        'app/Services/SubscriptionService.php',
    ];

    foreach ($sourceFiles as $sourceFile) {
        @mkdir(dirname(base_path($sourceFile)), 0755, true);
        file_put_contents(base_path($sourceFile), '<?php // content');
    }

    // Mock all files as unassigned
    $mappingService = Mockery::mock(ModuleMappingService::class);
    $mappingService->shouldReceive('get_module_for_file')->andReturn(null);

    $reflection = new ReflectionClass($this->service);
    $property = $reflection->getProperty('module_service');
    $property->setAccessible(true);
    $property->setValue($this->service, $mappingService);

    // When
    $result = $this->service->analyze_module_assignments();

    // Then
    expect($result['unassigned_files'])->toHaveCount(4);
    expect($result['potential_modules'])->toHaveKey('app/Services');
    expect($result['potential_modules']['app/Services']['file_count'])->toBe(4);
    expect($result['potential_modules']['app/Services']['suggested_name'])->toBe('services');

    // Cleanup
    foreach ($docFiles as $docFile) {
        @unlink(base_path($docFile));
    }
    foreach ($sourceFiles as $sourceFile) {
        @unlink(base_path($sourceFile));
    }
});

it('excludes files in do_not_document list', function () {
    // Create existing log with do_not_document files
    $logPath = base_path('docs/tracking/module-assignment-log.json');
    @mkdir(dirname($logPath), 0755, true);
    file_put_contents($logPath, json_encode([
        'do_not_document' => ['app/Services/IgnoredService.php'],
    ]));

    // Create documented files
    $docFiles = [
        'docs/source_documents/short/app/Services/IgnoredService.md',
        'docs/source_documents/short/app/Services/ActiveService.md',
    ];

    foreach ($docFiles as $docFile) {
        @mkdir(dirname(base_path($docFile)), 0755, true);
        file_put_contents(base_path($docFile), '# Documentation');
    }

    // Create source files
    $sourceFiles = [
        'app/Services/IgnoredService.php',
        'app/Services/ActiveService.php',
    ];

    foreach ($sourceFiles as $sourceFile) {
        @mkdir(dirname(base_path($sourceFile)), 0755, true);
        file_put_contents(base_path($sourceFile), '<?php // content');
    }

    // Mock module mapping
    $mappingService = Mockery::mock(ModuleMappingService::class);
    $mappingService->shouldReceive('get_module_for_file')
        ->with(base_path('app/Services/ActiveService.php'))
        ->once()
        ->andReturn(null);

    $reflection = new ReflectionClass($this->service);
    $property = $reflection->getProperty('module_service');
    $property->setAccessible(true);
    $property->setValue($this->service, $mappingService);

    // When
    $result = $this->service->analyze_module_assignments();

    // Then
    expect($result['unassigned_files'])->toHaveCount(1);
    expect($result['unassigned_files'][0])->toBe('app/Services/ActiveService.php');
    expect($result['do_not_document'])->toContain('app/Services/IgnoredService.php');

    // Cleanup
    @unlink($logPath);
    foreach ($docFiles as $docFile) {
        @unlink(base_path($docFile));
    }
    foreach ($sourceFiles as $sourceFile) {
        @unlink(base_path($sourceFile));
    }
});

it('generates module suggestions with confidence scores', function () {
    // Create documented files with common prefix
    $docFiles = [
        'docs/source_documents/short/app/Http/Controllers/UserController.md',
        'docs/source_documents/short/app/Http/Controllers/UserProfileController.md',
        'docs/source_documents/short/app/Http/Controllers/UserSettingsController.md',
        'docs/source_documents/short/app/Http/Controllers/UserActivityController.md',
    ];

    foreach ($docFiles as $docFile) {
        @mkdir(dirname(base_path($docFile)), 0755, true);
        file_put_contents(base_path($docFile), '# Documentation');
    }

    // Create source files
    foreach ($docFiles as $docFile) {
        $sourceFile = str_replace(['docs/source_documents/short/', '.md'], ['', '.php'], $docFile);
        @mkdir(dirname(base_path($sourceFile)), 0755, true);
        file_put_contents(base_path($sourceFile), '<?php // content');
    }

    // Mock all as unassigned
    $mappingService = Mockery::mock(ModuleMappingService::class);
    $mappingService->shouldReceive('get_module_for_file')->andReturn(null);

    $reflection = new ReflectionClass($this->service);
    $property = $reflection->getProperty('module_service');
    $property->setAccessible(true);
    $property->setValue($this->service, $mappingService);

    // When
    $result = $this->service->analyze_module_assignments();

    // Then
    expect($result['module_suggestions'])->toHaveCount(1);
    $suggestion = $result['module_suggestions'][0];
    expect($suggestion['suggested_name'])->toBe('http-controllers');
    expect($suggestion['file_count'])->toBe(4);
    expect($suggestion['confidence'])->toBeGreaterThan(0.5); // Has common prefix "User"
    expect($suggestion['reason'])->toContain('same directory');

    // Cleanup
    foreach ($docFiles as $docFile) {
        @unlink(base_path($docFile));
        $sourceFile = str_replace(['docs/source_documents/short/', '.md'], ['', '.php'], $docFile);
        @unlink(base_path($sourceFile));
    }
});

it('detects javascript files with correct extensions', function () {
    // Create documented JS files
    $docFiles = [
        'docs/source_documents/short/resources/js/components/Button.md',
        'docs/source_documents/short/resources/js/components/Modal.md',
        'docs/source_documents/short/resources/js/utils/helpers.md',
    ];

    foreach ($docFiles as $docFile) {
        @mkdir(dirname(base_path($docFile)), 0755, true);
        file_put_contents(base_path($docFile), '# Documentation');
    }

    // Create source files with different extensions
    $sourceFiles = [
        'resources/js/components/Button.vue',
        'resources/js/components/Modal.jsx',
        'resources/js/utils/helpers.js',
    ];

    foreach ($sourceFiles as $sourceFile) {
        @mkdir(dirname(base_path($sourceFile)), 0755, true);
        file_put_contents(base_path($sourceFile), '// JS content');
    }

    // Mock module mapping
    $mappingService = Mockery::mock(ModuleMappingService::class);
    $mappingService->shouldReceive('get_module_for_file')->andReturn(null);

    $reflection = new ReflectionClass($this->service);
    $property = $reflection->getProperty('module_service');
    $property->setAccessible(true);
    $property->setValue($this->service, $mappingService);

    // When
    $result = $this->service->analyze_module_assignments();

    // Then
    expect($result['unassigned_files'])->toHaveCount(3);
    expect($result['unassigned_files'])->toContain('resources/js/components/Button.vue');
    expect($result['unassigned_files'])->toContain('resources/js/components/Modal.jsx');
    expect($result['unassigned_files'])->toContain('resources/js/utils/helpers.js');

    // Cleanup
    foreach ($docFiles as $docFile) {
        @unlink(base_path($docFile));
    }
    foreach ($sourceFiles as $sourceFile) {
        @unlink(base_path($sourceFile));
    }
});

it('loads empty log when file does not exist', function () {
    // When
    $log = $this->service->load_log();

    // Then
    expect($log)->toBe([
        'last_analysis' => null,
        'assigned_files' => [],
        'unassigned_files' => [],
        'do_not_document' => [],
        'potential_modules' => [],
        'module_suggestions' => [],
    ]);
});

it('saves and loads log correctly', function () {
    // Given
    $testLog = [
        'last_analysis' => '2024-01-01T00:00:00Z',
        'assigned_files' => ['module1' => ['file1.php']],
        'unassigned_files' => ['file2.php'],
        'do_not_document' => ['file3.php'],
        'potential_modules' => [],
        'module_suggestions' => [],
    ];

    // Save log using reflection to access protected method
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('save_log');
    $method->setAccessible(true);
    $method->invoke($this->service, $testLog);

    // When
    $loadedLog = $this->service->load_log();

    // Then
    expect($loadedLog)->toBe($testLog);

    // Cleanup
    @unlink(base_path('docs/tracking/module-assignment-log.json'));
});

it('generates unassigned files report', function () {
    // Create a log with unassigned files
    $testLog = [
        'last_analysis' => '2024-01-01T00:00:00Z',
        'assigned_files' => [],
        'unassigned_files' => [
            'app/Services/Service1.php',
            'app/Services/Service2.php',
            'app/Models/Model1.php',
            'resources/js/components/Component1.vue',
        ],
        'do_not_document' => [],
        'potential_modules' => [],
        'module_suggestions' => [
            ['suggested_name' => 'services', 'file_count' => 2, 'confidence' => 0.8],
        ],
    ];

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('save_log');
    $method->setAccessible(true);
    $method->invoke($this->service, $testLog);

    // When
    $report = $this->service->get_unassigned_files_report();

    // Then
    expect($report['total_unassigned'])->toBe(4);
    expect($report['by_directory'])->toHaveKey('app/Services');
    expect($report['by_directory']['app/Services'])->toBe(2);
    expect($report['by_directory']['app/Models'])->toBe(1);
    expect($report['by_directory']['resources/js/components'])->toBe(1);
    expect($report['suggestions'])->toHaveCount(1);

    // Cleanup
    @unlink(base_path('docs/tracking/module-assignment-log.json'));
});

it('finds common words in file names', function () {
    // Use reflection to test protected method
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('find_common_words_in_files');
    $method->setAccessible(true);

    $files = collect([
        'UserService.php',
        'UserController.php',
        'UserRepository.php',
        'ProfileService.php',
    ]);

    // When
    $commonWords = $method->invoke($this->service, $files);

    // Then
    expect($commonWords[0])->toBe('user'); // Most common
    expect($commonWords)->toContain('service');
});

it('calculates module confidence correctly', function () {
    // Use reflection to test protected method
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('calculate_module_confidence');
    $method->setAccessible(true);

    // Test with directory-based module
    $moduleInfo = [
        'file_count' => 5,
        'files' => [
            'app/Services/UserService.php',
            'app/Services/UserRepository.php',
            'app/Services/UserValidator.php',
        ],
    ];

    // When
    $confidence = $method->invoke($this->service, $moduleInfo);

    // Then
    expect($confidence)->toBeGreaterThan(0.5); // Directory-based + common prefix
    expect($confidence)->toBeLessThanOrEqual(1.0);
});

afterEach(function () {
    // Clean up test directories
    $dirs = [
        base_path('app/Services'),
        base_path('app/Models'),
        base_path('app/Http/Controllers'),
        base_path('app/Http'),
        base_path('app'),
        base_path('resources/js/components'),
        base_path('resources/js/utils'),
        base_path('resources/js'),
        base_path('resources'),
        base_path('docs/source_documents/short/app/Services'),
        base_path('docs/source_documents/short/app/Models'),
        base_path('docs/source_documents/short/app/Http/Controllers'),
        base_path('docs/source_documents/short/app/Http'),
        base_path('docs/source_documents/short/app'),
        base_path('docs/source_documents/short/resources/js/components'),
        base_path('docs/source_documents/short/resources/js/utils'),
        base_path('docs/source_documents/short/resources/js'),
        base_path('docs/source_documents/short/resources'),
        base_path('docs/source_documents/short'),
        base_path('docs/source_documents/medium/resources/js/components'),
        base_path('docs/source_documents/medium/resources/js'),
        base_path('docs/source_documents/medium/resources'),
        base_path('docs/source_documents/medium'),
        base_path('docs/source_documents'),
        base_path('docs/tracking'),
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

    Mockery::close();
});
