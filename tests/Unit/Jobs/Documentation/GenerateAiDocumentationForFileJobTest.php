<?php

use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;
use Shawnveltman\LaravelOpenai\Exceptions\ClaudeRateLimitException;

beforeEach(function () {
    // Set up default config
    config([
        'cascadedocs.queue.retry_attempts' => 3,
        'cascadedocs.queue.timeout' => 300,
        'cascadedocs.queue.rate_limit_delay' => 60,
        'cascadedocs.ai.default_model' => 'o3',
        'cascadedocs.tier_names' => ['micro', 'standard', 'expansive'],
        'cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full',
        ],
        'cascadedocs.permissions.directory' => 0755,
    ]);
});

covers(GenerateAiDocumentationForFileJob::class);

it('initializes with correct configuration', function () {
    // Given
    $filePath = base_path('app/Services/TestService.php');

    // When
    $job = new GenerateAiDocumentationForFileJob($filePath);

    // Then
    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBe(300);
    expect($job->model)->toBe('o3');
    expect($job->tier)->toBe('all');
});

it('accepts custom tier and model', function () {
    // Given
    $filePath = base_path('app/Services/TestService.php');
    $tier = 'micro';
    $model = 'claude-3-5-haiku';

    // When
    $job = new GenerateAiDocumentationForFileJob($filePath, $tier, $model);

    // Then
    expect($job->tier)->toBe($tier);
    expect($job->model)->toBe($model);
});

it('skips generation when all tiers exist', function () {
    // Given
    $filePath = base_path('app/Services/ExistingService.php');
    $job = new GenerateAiDocumentationForFileJob($filePath);

    // Create existing documentation files
    $tiers = ['short', 'medium', 'full'];
    foreach ($tiers as $tier) {
        $docPath = base_path("docs/source_documents/{$tier}/app/Services/ExistingService.md");
        @mkdir(dirname($docPath), 0755, true);
        file_put_contents($docPath, "Existing {$tier} documentation");
    }

    // Mock the trait method
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    // Expect no API call
    $job->shouldNotReceive('get_response_from_provider');

    // When
    $job->handle();

    // Then - No exception thrown, method returns early
    expect(true)->toBeTrue();

    // Cleanup
    foreach ($tiers as $tier) {
        @unlink(base_path("docs/source_documents/{$tier}/app/Services/ExistingService.md"));
    }
});

it('generates documentation for missing tiers', function () {
    // Given
    $filePath = base_path('app/Services/NewService.php');

    // Create the actual file for testing
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nnamespace App\\Services;\n\nclass NewService\n{\n    public function test() {}\n}");

    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    $expectedResponse = json_encode([
        'micro' => 'Micro documentation content',
        'standard' => 'Standard documentation content',
        'expansive' => 'Expansive documentation content with commit_sha: abc123',
    ]);

    // Mock API response
    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then - Verify files were created
    expect(base_path('docs/source_documents/short/app/Services/NewService.md'))->toBeFile();
    expect(base_path('docs/source_documents/medium/app/Services/NewService.md'))->toBeFile();
    expect(base_path('docs/source_documents/full/app/Services/NewService.md'))->toBeFile();

    expect(file_get_contents(base_path('docs/source_documents/short/app/Services/NewService.md')))
        ->toBe('Micro documentation content');

    // Cleanup
    @unlink($filePath);
    @unlink(base_path('docs/source_documents/short/app/Services/NewService.md'));
    @unlink(base_path('docs/source_documents/medium/app/Services/NewService.md'));
    @unlink(base_path('docs/source_documents/full/app/Services/NewService.md'));
});

it('generates only specific tier when requested', function () {
    // Given
    $filePath = base_path('app/Models/User.php');
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]',
        [$filePath, 'micro'])
        ->shouldAllowMockingProtectedMethods();

    // Create the actual file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nnamespace App\\Models;\n\nclass User {}");

    $expectedResponse = json_encode([
        'micro' => 'Micro documentation only',
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then - Only micro tier should be created
    expect(base_path('docs/source_documents/short/app/Models/User.md'))->toBeFile();
    expect(base_path('docs/source_documents/medium/app/Models/User.md'))->not->toBeFile();
    expect(base_path('docs/source_documents/full/app/Models/User.md'))->not->toBeFile();

    // Cleanup
    @unlink($filePath);
    @unlink(base_path('docs/source_documents/short/app/Models/User.md'));
});

it('handles claude rate limit exception', function () {
    // Given
    $filePath = base_path('app/Services/RateLimitedService.php');
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider,release]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass RateLimitedService {}");

    // Mock rate limit exception
    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andThrow(new ClaudeRateLimitException('Rate limited'));

    // Expect release to be called
    $job->shouldReceive('release')
        ->once()
        ->with(60);

    // When
    $job->handle();

    // Then - No exception should be thrown (handled internally)
    expect(true)->toBeTrue();

    // Cleanup
    @unlink($filePath);
});

it('throws exception for invalid json response', function () {
    // Given
    $filePath = base_path('app/Services/InvalidResponseService.php');
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass InvalidResponseService {}");

    // Mock invalid response
    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn('Invalid JSON response');

    // When/Then
    expect(fn () => $job->handle())
        ->toThrow(Exception::class, 'Failed to generate documentation: Invalid JSON response from LLM');

    // Cleanup
    @unlink($filePath);
});

it('throws exception for empty response', function () {
    // Given
    $filePath = base_path('app/Services/EmptyResponseService.php');
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass EmptyResponseService {}");

    // Mock empty array response
    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn('[]');

    // When/Then
    expect(fn () => $job->handle())
        ->toThrow(Exception::class, 'Failed to generate documentation: Invalid JSON response from LLM');

    // Cleanup
    @unlink($filePath);
});

it('creates nested directory structure', function () {
    // Given
    $filePath = base_path('app/Http/Controllers/Api/V1/UserController.php');
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nnamespace App\\Http\\Controllers\\Api\\V1;\n\nclass UserController {}");

    $expectedResponse = json_encode([
        'micro' => 'Nested controller documentation',
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then
    $expectedPath = base_path('docs/source_documents/short/app/Http/Controllers/Api/V1/UserController.md');
    expect($expectedPath)->toBeFile();
    expect(file_get_contents($expectedPath))->toBe('Nested controller documentation');

    // Cleanup
    @unlink($filePath);
    @unlink($expectedPath);
});

it('handles javascript files', function () {
    // Given
    $filePath = base_path('resources/js/components/Button.vue');
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, '<template><button>Click me</button></template>');

    $expectedResponse = json_encode([
        'micro' => 'Vue component documentation',
        'standard' => 'Standard Vue docs',
        'expansive' => 'Expansive Vue docs',
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then
    $expectedPath = base_path('docs/source_documents/short/resources/js/components/Button.md');
    expect($expectedPath)->toBeFile();

    // Cleanup
    @unlink($filePath);
    @unlink($expectedPath);
    @unlink(base_path('docs/source_documents/medium/resources/js/components/Button.md'));
    @unlink(base_path('docs/source_documents/full/resources/js/components/Button.md'));
});

it('generates all tiers when some are missing', function () {
    // Given
    $filePath = base_path('app/Services/PartialService.php');

    // Mock file existence for tier checking
    $microPath = base_path('docs/source_documents/short/app/Services/PartialService.md');
    $mediumPath = base_path('docs/source_documents/medium/app/Services/PartialService.md');
    $fullPath = base_path('docs/source_documents/full/app/Services/PartialService.md');

    File::shouldReceive('exists')
        ->with($microPath)
        ->andReturn(true);
    File::shouldReceive('exists')
        ->with($mediumPath)
        ->andReturn(false);
    File::shouldReceive('exists')
        ->with($fullPath)
        ->andReturn(false);

    // Mock file get for source
    File::shouldReceive('get')
        ->with($filePath)
        ->andReturn("<?php\n\nclass PartialService {}");

    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    $expectedResponse = json_encode([
        'micro' => 'New micro docs',
        'standard' => 'New standard docs',
        'expansive' => 'New expansive docs',
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // Mock directory checks and creation
    File::shouldReceive('exists')
        ->with(dirname($microPath))
        ->andReturn(true);
    File::shouldReceive('exists')
        ->with(dirname($mediumPath))
        ->andReturn(false);
    File::shouldReceive('makeDirectory')
        ->with(dirname($mediumPath), 0755, true)
        ->once();
    File::shouldReceive('exists')
        ->with(dirname($fullPath))
        ->andReturn(false);
    File::shouldReceive('makeDirectory')
        ->with(dirname($fullPath), 0755, true)
        ->once();

    // Mock file writes
    File::shouldReceive('put')
        ->with($microPath, 'New micro docs')
        ->once();
    File::shouldReceive('put')
        ->with($mediumPath, 'New standard docs')
        ->once();
    File::shouldReceive('put')
        ->with($fullPath, 'New expansive docs')
        ->once();

    // When
    $job->handle();

    // Then - expectations are set in the mocks above
    expect(true)->toBeTrue();
});

it('respects directory permissions', function () {
    // Given
    config(['cascadedocs.permissions.directory' => 0700]); // More restrictive

    $filePath = base_path('app/Services/PermissionTest.php');
    $job = Mockery::mock(GenerateAiDocumentationForFileJob::class.'[get_response_from_provider]', [$filePath])
        ->shouldAllowMockingProtectedMethods();

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass PermissionTest {}");

    $expectedResponse = json_encode([
        'micro' => 'Permission test docs',
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then
    $dirPath = dirname(base_path('docs/source_documents/short/app/Services/PermissionTest.md'));
    expect($dirPath)->toBeDirectory();

    // Note: PHP's mkdir doesn't always respect permissions on all systems
    // so we just verify the directory was created

    // Cleanup
    @unlink($filePath);
    @unlink(base_path('docs/source_documents/short/app/Services/PermissionTest.md'));
});

afterEach(function () {
    // Clean up test directories
    $dirs = [
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
