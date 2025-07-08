<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;

beforeEach(function () {
    $this->service = new DocumentationDiffService;

    // Set up config
    config([
        'cascadedocs.paths.tracking.documentation_update' => 'docs/documentation-update-log.json',
        'cascadedocs.file_types' => ['php', 'js', 'vue', 'jsx', 'ts', 'tsx'],
        'cascadedocs.paths.source' => ['app/', 'resources/js/'],
    ]);
});

covers(DocumentationDiffService::class);

it('gets changed files between commits', function () {
    // Mock git diff output
    Process::fake([
        'git diff --name-only abc123 HEAD' => Process::result(
            "app/Services/UserService.php\nresources/js/components/Button.vue\ntests/TestFile.php\napp/Models/User.php"
        ),
    ]);

    // When
    $files = $this->service->get_changed_files('abc123');

    // Then - Should filter out test files and non-source directories
    expect($files)->toHaveCount(3);
    $filesList = $files->values()->all();
    expect($filesList[0])->toEndWith('app/Services/UserService.php');
    expect($filesList[1])->toEndWith('resources/js/components/Button.vue');
    expect($filesList[2])->toEndWith('app/Models/User.php');
});

it('throws exception when git diff fails', function () {
    // Mock git failure
    Process::fake([
        'git diff --name-only invalid-sha HEAD' => Process::result(
            output: '',
            errorOutput: 'fatal: bad object invalid-sha',
            exitCode: 128
        ),
    ]);

    // When/Then
    expect(fn () => $this->service->get_changed_files('invalid-sha'))
        ->toThrow(Exception::class, 'Failed to get changed files: fatal: bad object invalid-sha');
});

it('filters only documentable files', function () {
    // Mock git diff with various file types
    Process::fake([
        'git diff --name-only abc123 HEAD' => Process::result(
            "app/Services/UserService.php\n".
            "app/config.json\n".
            "resources/js/app.js\n".
            "storage/logs/laravel.log\n".
            "vendor/package/file.php\n".
            'app/Services/TestService.php'
        ),
    ]);

    // When
    $files = $this->service->get_changed_files('abc123');

    // Then - Should include documentable files in source directories
    // Note: TestService.php is included because it's a .php file in app/ directory
    expect($files)->toHaveCount(3);
    $filesList = $files->values()->all();
    expect($filesList[0])->toEndWith('app/Services/UserService.php');
    expect($filesList[1])->toEndWith('resources/js/app.js');
    expect($filesList[2])->toEndWith('app/Services/TestService.php');
});

it('gets file content at specific commit', function () {
    // Mock git show output
    Process::fake([
        'git show abc123:app/Services/UserService.php' => Process::result(
            "<?php\n\nnamespace App\\Services;\n\nclass UserService {}\n"
        ),
    ]);

    // When
    $content = $this->service->get_file_content_at_commit(
        base_path('app/Services/UserService.php'),
        'abc123'
    );

    // Then
    expect($content)->toBe("<?php\n\nnamespace App\\Services;\n\nclass UserService {}\n");
});

it('returns null when file does not exist at commit', function () {
    // Mock git show failure
    Process::fake([
        'git show abc123:app/NonExistent.php' => Process::result(
            output: '',
            errorOutput: 'fatal: path app/NonExistent.php does not exist in abc123',
            exitCode: 128
        ),
    ]);

    // When
    $content = $this->service->get_file_content_at_commit(
        base_path('app/NonExistent.php'),
        'abc123'
    );

    // Then
    expect($content)->toBeNull();
});

it('gets file diff between commits', function () {
    // Mock git diff output
    Process::fake([
        'git diff abc123 HEAD -- app/Services/UserService.php' => Process::result(
            "diff --git a/app/Services/UserService.php b/app/Services/UserService.php\n".
            "index abc123..def456 100644\n".
            "--- a/app/Services/UserService.php\n".
            "+++ b/app/Services/UserService.php\n".
            "@@ -10,6 +10,10 @@ class UserService\n".
            "     {\n".
            "         return User::find(\$id);\n".
            "     }\n".
            "+\n".
            "+    public function updateUser(\$id, \$data)\n".
            "+    {\n".
            "+        return User::find(\$id)->update(\$data);\n".
            "+    }\n".
            ' }'
        ),
    ]);

    // When
    $diff = $this->service->get_file_diff(
        base_path('app/Services/UserService.php'),
        'abc123'
    );

    // Then
    expect($diff)->toContain('public function updateUser');
    expect($diff)->toContain('+    {');
});

it('returns empty string when no changes in file diff', function () {
    // Mock empty diff
    Process::fake([
        'git diff abc123 HEAD -- app/Services/NoChange.php' => Process::result(''),
    ]);

    // When
    $diff = $this->service->get_file_diff(
        base_path('app/Services/NoChange.php'),
        'abc123'
    );

    // Then
    expect($diff)->toBe('');
});

it('gets current commit sha', function () {
    // Mock git command
    Process::fake([
        'git rev-parse HEAD' => Process::result('def456789'),
    ]);

    // When
    $sha = $this->service->get_current_commit_sha();

    // Then
    expect($sha)->toBe('def456789');
});

it('gets file last commit sha', function () {
    // Mock git command
    Process::fake([
        'git log -1 --format=%H -- app/Services/UserService.php' => Process::result("abc123456\n"),
    ]);

    // When
    $sha = $this->service->get_file_last_commit_sha(base_path('app/Services/UserService.php'));

    // Then
    expect($sha)->toBe('abc123456');
});

it('loads update log when exists', function () {
    // Create test log file
    $logPath = base_path('docs/documentation-update-log.json');
    @mkdir(dirname($logPath), 0755, true);
    file_put_contents($logPath, json_encode([
        'last_update' => '2024-01-01',
        'files' => [
            'app/Services/UserService.php' => ['sha' => 'abc123'],
        ],
    ]));

    // When
    $log = $this->service->load_update_log();

    // Then
    expect($log)->toHaveKey('last_update', '2024-01-01');
    expect($log['files'])->toHaveKey('app/Services/UserService.php');

    // Cleanup
    @unlink($logPath);
});

it('returns default structure when update log does not exist', function () {
    // Ensure log doesn't exist
    $logPath = base_path('docs/documentation-update-log.json');
    @unlink($logPath);

    // When
    $log = $this->service->load_update_log();

    // Then
    expect($log)->toBe([
        'last_update_sha' => null,
        'last_update_timestamp' => null,
        'files' => [],
        'modules' => [],
    ]);
});

it('saves update log', function () {
    // Given
    $log = [
        'last_update' => '2024-01-01T00:00:00Z',
        'files' => [
            'app/Services/UserService.php' => ['sha' => 'abc123'],
        ],
    ];

    // Ensure directory exists
    File::ensureDirectoryExists(base_path('docs'));

    // When
    $this->service->save_update_log($log);

    // Then
    $logPath = base_path('docs/documentation-update-log.json');
    expect($logPath)->toBeFile();

    $saved = json_decode(file_get_contents($logPath), true);
    expect($saved)->toBe($log);

    // Cleanup
    @unlink($logPath);
});

it('checks if file needs documentation update', function () {
    // Given
    $updateLog = [
        'files' => [
            'app/Services/UserService.php' => ['sha' => 'old123'],
            'app/Services/OrderService.php' => ['sha' => 'current456'],
        ],
    ];

    // Mock git commands
    Process::fake([
        'git log -1 --format=%H -- app/Services/UserService.php' => Process::result("new789\n"),
        'git log -1 --format=%H -- app/Services/OrderService.php' => Process::result("current456\n"),
        'git log -1 --format=%H -- app/Services/NewService.php' => Process::result(''),
    ]);

    // When/Then - File with different SHA needs update
    expect($this->service->needs_documentation_update(
        base_path('app/Services/UserService.php'),
        $updateLog
    ))->toBeTrue();

    // When/Then - File with same SHA doesn't need update
    expect($this->service->needs_documentation_update(
        base_path('app/Services/OrderService.php'),
        $updateLog
    ))->toBeFalse();

    // When/Then - New file needs update
    expect($this->service->needs_documentation_update(
        base_path('app/Services/NewService.php'),
        $updateLog
    ))->toBeTrue();
});

it('filters out test files and non-source directories', function () {
    // Mock git diff with various paths
    Process::fake([
        'git diff --name-only abc123 HEAD' => Process::result(
            "app/Services/UserService.php\n".
            "app/Services/UserServiceTest.php\n".
            "resources/js/app.js\n".
            "tests/Feature/ExampleTest.php\n".
            "database/migrations/create_users_table.php\n".
            'routes/web.php'
        ),
    ]);

    // When
    $files = $this->service->get_changed_files('abc123');

    // Then
    expect($files)->toHaveCount(2);
    $filesList = $files->values()->all();
    expect($filesList[0])->toEndWith('app/Services/UserService.php');
    expect($filesList[1])->toEndWith('resources/js/app.js');
});

it('gets new files between commits', function () {
    // Mock git diff with status info for new files
    Process::fake([
        'git diff --name-status abc123 HEAD' => Process::result(
            "M\tapp/Services/UserService.php\n".
            "A\tapp/Services/NewService.php\n".
            "D\tapp/Services/OldService.php\n".
            "A\tresources/js/NewComponent.vue\n".
            "A\ttests/NewTest.php"
        ),
    ]);

    // When
    $files = $this->service->get_new_files('abc123');

    // Then - Should only include added files in source directories
    expect($files)->toHaveCount(2);
    $filesList = $files->values()->all();
    expect($filesList[0])->toEndWith('app/Services/NewService.php');
    expect($filesList[1])->toEndWith('resources/js/NewComponent.vue');
});

it('gets deleted files between commits', function () {
    // Mock git diff with status info for deleted files
    Process::fake([
        'git diff --name-status abc123 HEAD' => Process::result(
            "M\tapp/Services/UserService.php\n".
            "A\tapp/Services/NewService.php\n".
            "D\tapp/Services/OldService.php\n".
            "D\tresources/js/OldComponent.vue\n".
            "D\ttests/OldTest.php"
        ),
    ]);

    // When
    $files = $this->service->get_deleted_files('abc123');

    // Then - Should only include deleted files in source directories
    expect($files)->toHaveCount(2);
    $filesList = $files->values()->all();
    expect($filesList[0])->toEndWith('app/Services/OldService.php');
    expect($filesList[1])->toEndWith('resources/js/OldComponent.vue');
});

afterEach(function () {
    // Clean up test files
    @unlink(base_path('docs/documentation-update-log.json'));
    @rmdir(base_path('docs'));
});
