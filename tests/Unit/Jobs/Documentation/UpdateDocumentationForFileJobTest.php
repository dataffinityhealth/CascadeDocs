<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAndTrackDocumentationJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateDocumentationForFileJob;
use Shawnveltman\LaravelOpenai\Exceptions\ClaudeRateLimitException;

beforeEach(function () {
    // Set up default config
    config([
        'cascadedocs.queue.retry_attempts' => 3,
        'cascadedocs.queue.timeout' => 300,
        'cascadedocs.queue.rate_limit_delay' => 60,
        'cascadedocs.ai.default_model' => 'o3',
        'cascadedocs.tiers' => [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full',
        ],
        'cascadedocs.paths.output' => 'docs/source_documents/',
        'cascadedocs.permissions.directory' => 0755,
    ]);

    Queue::fake();
});

covers(UpdateDocumentationForFileJob::class);

it('initializes with correct configuration', function () {
    // Given
    $filePath = base_path('app/Services/TestService.php');
    $fromSha = 'abc123';
    $toSha = 'def456';

    // When
    $job = new UpdateDocumentationForFileJob($filePath, $fromSha, $toSha);

    // Then
    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBe(300);
    expect($job->model)->toBe('o3');
    expect($job->file_path)->toBe($filePath);
    expect($job->from_sha)->toBe($fromSha);
    expect($job->to_sha)->toBe($toSha);
});

it('accepts custom model', function () {
    // Given
    $filePath = base_path('app/Services/TestService.php');
    $fromSha = 'abc123';
    $toSha = 'def456';
    $model = 'claude-3-5-haiku';

    // When
    $job = new UpdateDocumentationForFileJob($filePath, $fromSha, $toSha, $model);

    // Then
    expect($job->model)->toBe($model);
});

it('handles deleted files by removing documentation', function () {
    // Given
    $filePath = base_path('app/Services/DeletedService.php');
    $job = new UpdateDocumentationForFileJob($filePath, 'abc123', 'def456');

    // Create existing documentation files
    $tiers = ['short', 'medium', 'full'];
    foreach ($tiers as $tier) {
        $docPath = base_path("docs/source_documents/{$tier}/app/Services/DeletedService.md");
        @mkdir(dirname($docPath), 0755, true);
        file_put_contents($docPath, "Documentation for {$tier}");
    }

    // Mock Process for git commands
    Process::fake([
        'git log -1 --format=%H -- app/Services/DeletedService.php' => Process::result(''),
    ]);

    // When
    $job->handle();

    // Then - All documentation should be deleted
    foreach ($tiers as $tier) {
        $docPath = base_path("docs/source_documents/{$tier}/app/Services/DeletedService.md");
        expect($docPath)->not->toBeFile();
    }

    // Cleanup
    foreach ($tiers as $tier) {
        $dirPath = dirname(base_path("docs/source_documents/{$tier}/app/Services/DeletedService.md"));
        @rmdir($dirPath);
    }
});

it('dispatches generation job for new files with no diff', function () {
    // Given
    $filePath = base_path('app/Services/NewService.php');

    // Create the file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nnamespace App\\Services;\n\nclass NewService {}");

    // Mock git diff to return empty (new file)
    Process::fake([
        'git diff abc123 def456 -- app/Services/NewService.php' => Process::result(''),
    ]);

    $job = new UpdateDocumentationForFileJob($filePath, 'abc123', 'def456');

    // When
    $job->handle();

    // Then
    Queue::assertPushed(GenerateAndTrackDocumentationJob::class, function ($job) use ($filePath) {
        return $job->file_path === $filePath
            && $job->to_sha === 'def456'
            && $job->model === 'o3';
    });

    // Cleanup
    @unlink($filePath);
});

it('dispatches generation job when no existing documentation', function () {
    // Given
    $filePath = base_path('app/Services/UndocumentedService.php');

    // Create the file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nnamespace App\\Services;\n\nclass UndocumentedService {}");

    // Mock git diff
    Process::fake([
        'git diff abc123 def456 -- app/Services/UndocumentedService.php' => Process::result(
            "diff --git a/app/Services/UndocumentedService.php b/app/Services/UndocumentedService.php\n".
            "index abc123..def456 100644\n".
            "--- a/app/Services/UndocumentedService.php\n".
            "+++ b/app/Services/UndocumentedService.php\n".
            "@@ -5,0 +6,4 @@ class UndocumentedService\n".
            "+    public function newMethod()\n".
            "+    {\n".
            "+        return true;\n".
            '+    }'
        ),
    ]);

    $job = new UpdateDocumentationForFileJob($filePath, 'abc123', 'def456');

    // When
    $job->handle();

    // Then
    Queue::assertPushed(GenerateAndTrackDocumentationJob::class, function ($job) use ($filePath) {
        return $job->file_path === $filePath;
    });

    // Cleanup
    @unlink($filePath);
});

it('updates existing documentation when changes are meaningful', function () {
    // Given
    $filePath = base_path('app/Services/ExistingService.php');

    // Create the file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nnamespace App\\Services;\n\nclass ExistingService {\n    public function newMethod() { return true; }\n}");

    // Create existing documentation
    $microPath = base_path('docs/source_documents/short/app/Services/ExistingService.md');
    $standardPath = base_path('docs/source_documents/medium/app/Services/ExistingService.md');
    $expansivePath = base_path('docs/source_documents/full/app/Services/ExistingService.md');

    @mkdir(dirname($microPath), 0755, true);
    @mkdir(dirname($standardPath), 0755, true);
    @mkdir(dirname($expansivePath), 0755, true);

    file_put_contents($microPath, '## ExistingService · Micro-blurb

This service handles existing functionality.');
    file_put_contents($standardPath, '# ExistingService

## Purpose
Handles existing functionality.');
    file_put_contents($expansivePath, '---
commit_sha: old123
---

# ExistingService

Comprehensive documentation.');

    // Mock git commands
    Process::fake([
        'git diff abc123 def456 -- app/Services/ExistingService.php' => Process::result(
            "diff --git a/app/Services/ExistingService.php b/app/Services/ExistingService.php\n".
            "index abc123..def456 100644\n".
            "--- a/app/Services/ExistingService.php\n".
            "+++ b/app/Services/ExistingService.php\n".
            "@@ -5,0 +6,4 @@ class ExistingService\n".
            "+    public function newMethod()\n".
            "+    {\n".
            "+        return true;\n".
            '+    }'
        ),
        'git log -1 --format=%H -- app/Services/ExistingService.php' => Process::result("def456\n"),
        'git rev-parse HEAD' => Process::result('def456'),
    ]);

    $job = Mockery::mock(UpdateDocumentationForFileJob::class.'[get_response_from_provider]',
        [$filePath, 'abc123', 'def456'])
        ->shouldAllowMockingProtectedMethods();

    $expectedResponse = json_encode([
        'micro' => '## ExistingService · Micro-blurb

This service handles existing functionality and includes a new method.',
        'standard' => null,
        'expansive' => '---
commit_sha: def456
---

# ExistingService

Comprehensive documentation with new method details.',
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then
    expect(file_get_contents($microPath))->toContain('new method');
    expect(file_get_contents($expansivePath))->toContain('commit_sha: def456');
    expect(file_get_contents($expansivePath))->toContain('new method details');

    // Cleanup
    @unlink($filePath);
    @unlink($microPath);
    @unlink($standardPath);
    @unlink($expansivePath);
});

it('handles rate limit exceptions by releasing job', function () {
    // Given
    $filePath = base_path('app/Services/RateLimitedService.php');

    // Create file and existing docs
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass RateLimitedService {}");

    $docPath = base_path('docs/source_documents/short/app/Services/RateLimitedService.md');
    @mkdir(dirname($docPath), 0755, true);
    file_put_contents($docPath, 'Existing docs');

    // Mock git diff
    Process::fake([
        'git diff abc123 def456 -- app/Services/RateLimitedService.php' => Process::result('Some diff'),
    ]);

    $job = Mockery::mock(UpdateDocumentationForFileJob::class.'[get_response_from_provider,release]',
        [$filePath, 'abc123', 'def456'])
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andThrow(new ClaudeRateLimitException('Rate limited'));

    $job->shouldReceive('release')
        ->once()
        ->with(60);

    // When
    $job->handle();

    // Then - expectations are set in the mocks
    expect(true)->toBeTrue();

    // Cleanup
    @unlink($filePath);
    @unlink($docPath);
});

it('throws exception for invalid json response', function () {
    // Given
    $filePath = base_path('app/Services/InvalidResponseService.php');

    // Create file and existing docs
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass InvalidResponseService {}");

    $docPath = base_path('docs/source_documents/short/app/Services/InvalidResponseService.md');
    @mkdir(dirname($docPath), 0755, true);
    file_put_contents($docPath, 'Existing docs');

    // Mock git diff
    Process::fake([
        'git diff abc123 def456 -- app/Services/InvalidResponseService.php' => Process::result('Some diff'),
    ]);

    $job = Mockery::mock(UpdateDocumentationForFileJob::class.'[get_response_from_provider]',
        [$filePath, 'abc123', 'def456'])
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn('Invalid JSON');

    // When/Then
    expect(fn () => $job->handle())
        ->toThrow(Exception::class, 'Failed to update documentation: Invalid JSON response from LLM');

    // Cleanup
    @unlink($filePath);
    @unlink($docPath);
});

it('skips updating when AI returns null for all tiers', function () {
    // Given
    $filePath = base_path('app/Services/NoChangeService.php');

    // Create file and existing docs
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass NoChangeService {}");

    $docPath = base_path('docs/source_documents/short/app/Services/NoChangeService.md');
    @mkdir(dirname($docPath), 0755, true);
    $originalContent = 'Original documentation';
    file_put_contents($docPath, $originalContent);

    // Mock git commands
    Process::fake([
        'git diff abc123 def456 -- app/Services/NoChangeService.php' => Process::result('Minor formatting diff'),
        'git log -1 --format=%H -- app/Services/NoChangeService.php' => Process::result("def456\n"),
    ]);

    $job = Mockery::mock(UpdateDocumentationForFileJob::class.'[get_response_from_provider]',
        [$filePath, 'abc123', 'def456'])
        ->shouldAllowMockingProtectedMethods();

    $expectedResponse = json_encode([
        'micro' => null,
        'standard' => null,
        'expansive' => null,
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then - Documentation should not change
    expect(file_get_contents($docPath))->toBe($originalContent);

    // Cleanup
    @unlink($filePath);
    @unlink($docPath);
});

it('updates SHA only in expansive tier when content unchanged', function () {
    // Given
    $filePath = base_path('app/Services/ShaOnlyService.php');

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass ShaOnlyService {}");

    // Create existing expansive documentation
    $expansivePath = base_path('docs/source_documents/full/app/Services/ShaOnlyService.md');
    @mkdir(dirname($expansivePath), 0755, true);
    file_put_contents($expansivePath, '---
commit_sha: old123
---

# ShaOnlyService

Documentation content.');

    // Mock git commands
    Process::fake([
        'git diff abc123 def456 -- app/Services/ShaOnlyService.php' => Process::result('Minor diff'),
        'git log -1 --format=%H -- app/Services/ShaOnlyService.php' => Process::result("def456\n"),
        'git rev-parse HEAD' => Process::result('def456'),
    ]);

    $job = Mockery::mock(UpdateDocumentationForFileJob::class.'[get_response_from_provider]',
        [$filePath, 'abc123', 'def456'])
        ->shouldAllowMockingProtectedMethods();

    // AI returns same content (no changes needed)
    $expectedResponse = json_encode([
        'micro' => null,
        'standard' => null,
        'expansive' => '---
commit_sha: old123
---

# ShaOnlyService

Documentation content.',
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then - SHA should be updated
    $content = file_get_contents($expansivePath);
    expect($content)->toContain('commit_sha: def456');
    expect($content)->toContain('Documentation content.');

    // Cleanup
    @unlink($filePath);
    @unlink($expansivePath);
});

it('creates new documentation tier when AI provides it', function () {
    // Given
    $filePath = base_path('app/Services/NewTierService.php');

    // Create file
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, "<?php\n\nclass NewTierService {}");

    // Create only micro documentation
    $microPath = base_path('docs/source_documents/short/app/Services/NewTierService.md');
    @mkdir(dirname($microPath), 0755, true);
    file_put_contents($microPath, 'Micro documentation');

    // Mock git commands
    Process::fake([
        'git diff abc123 def456 -- app/Services/NewTierService.php' => Process::result('Significant diff'),
        'git log -1 --format=%H -- app/Services/NewTierService.php' => Process::result("def456\n"),
        'git rev-parse HEAD' => Process::result('def456'),
    ]);

    $job = Mockery::mock(UpdateDocumentationForFileJob::class.'[get_response_from_provider]',
        [$filePath, 'abc123', 'def456'])
        ->shouldAllowMockingProtectedMethods();

    // AI adds standard tier
    $expectedResponse = json_encode([
        'micro' => null,
        'standard' => '# NewTierService

## Purpose
New standard documentation',
        'expansive' => null,
    ]);

    $job->shouldReceive('get_response_from_provider')
        ->once()
        ->andReturn($expectedResponse);

    // When
    $job->handle();

    // Then - New standard tier should be created
    $standardPath = base_path('docs/source_documents/medium/app/Services/NewTierService.md');
    expect($standardPath)->toBeFile();
    expect(file_get_contents($standardPath))->toContain('New standard documentation');

    // Cleanup
    @unlink($filePath);
    @unlink($microPath);
    @unlink($standardPath);
});

afterEach(function () {
    // Clean up test directories
    $dirs = [
        base_path('app/Services'),
        base_path('app'),
        base_path('docs/source_documents/short/app/Services'),
        base_path('docs/source_documents/short/app'),
        base_path('docs/source_documents/short'),
        base_path('docs/source_documents/medium/app/Services'),
        base_path('docs/source_documents/medium/app'),
        base_path('docs/source_documents/medium'),
        base_path('docs/source_documents/full/app/Services'),
        base_path('docs/source_documents/full/app'),
        base_path('docs/source_documents/full'),
        base_path('docs/source_documents'),
        base_path('docs/documentation-update-log.json'),
        base_path('docs'),
    ];

    foreach ($dirs as $path) {
        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            $files = glob($path.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($path);
        }
    }

    Mockery::close();
});
