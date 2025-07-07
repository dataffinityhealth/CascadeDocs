<?php

namespace Lumiio\CascadeDocs\Services\Documentation;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DocumentationDiffService
{
    public function get_changed_files(string $from_sha, ?string $to_sha = null): Collection
    {
        $to_sha ??= 'HEAD';

        $result = Process::run("git diff --name-only {$from_sha} {$to_sha}");

        if (! $result->successful()) {
            throw new Exception('Failed to get changed files: '.$result->errorOutput());
        }

        $files = collect(explode("\n", trim($result->output())))
            ->filter()
            ->filter(function ($file) {
                return $this->is_documentable_file($file);
            })
            ->map(function ($file) {
                return base_path($file);
            });

        return $files;
    }

    public function get_new_files(string $from_sha, ?string $to_sha = null): Collection
    {
        $to_sha ??= 'HEAD';

        $result = Process::run("git diff --name-status {$from_sha} {$to_sha}");

        if (! $result->successful()) {
            throw new Exception('Failed to get file status: '.$result->errorOutput());
        }

        $files = collect(explode("\n", trim($result->output())))
            ->filter()
            ->filter(function ($line) {
                return Str::startsWith($line, 'A');
            })
            ->map(function ($line) {
                return trim(Str::after($line, 'A'));
            })
            ->filter(function ($file) {
                return $this->is_documentable_file($file);
            })
            ->map(function ($file) {
                return base_path($file);
            });

        return $files;
    }

    public function get_deleted_files(string $from_sha, ?string $to_sha = null): Collection
    {
        $to_sha ??= 'HEAD';

        $result = Process::run("git diff --name-status {$from_sha} {$to_sha}");

        if (! $result->successful()) {
            throw new Exception('Failed to get file status: '.$result->errorOutput());
        }

        $files = collect(explode("\n", trim($result->output())))
            ->filter()
            ->filter(function ($line) {
                return Str::startsWith($line, 'D');
            })
            ->map(function ($line) {
                return trim(Str::after($line, 'D'));
            })
            ->filter(function ($file) {
                return $this->is_documentable_file($file);
            })
            ->map(function ($file) {
                return base_path($file);
            });

        return $files;
    }

    public function get_file_content_at_commit(string $file_path, string $commit_sha): ?string
    {
        $relative_path = $this->get_relative_path($file_path);

        $result = Process::run("git show {$commit_sha}:{$relative_path}");

        if (! $result->successful()) {
            // File might not exist at that commit
            return null;
        }

        return $result->output();
    }

    public function get_file_diff(string $file_path, string $from_sha, ?string $to_sha = null): ?string
    {
        $to_sha ??= 'HEAD';
        $relative_path = $this->get_relative_path($file_path);

        $result = Process::run("git diff {$from_sha} {$to_sha} -- {$relative_path}");

        if (! $result->successful()) {
            return null;
        }

        return $result->output();
    }

    public function get_current_commit_sha(): string
    {
        $result = Process::run('git rev-parse HEAD');

        if (! $result->successful()) {
            throw new Exception('Failed to get current commit SHA: '.$result->errorOutput());
        }

        return trim($result->output());
    }

    public function get_file_last_commit_sha(string $file_path): ?string
    {
        $relative_path = $this->get_relative_path($file_path);

        $result = Process::run("git log -1 --format=%H -- {$relative_path}");

        if (! $result->successful() || empty(trim($result->output()))) {
            return null;
        }

        return trim($result->output());
    }

    public function load_update_log(): array
    {
        $log_path = base_path(config('cascadedocs.paths.tracking.documentation_update'));

        if (! File::exists($log_path)) {
            return [
                'last_update_sha' => null,
                'last_update_timestamp' => null,
                'files' => [],
                'modules' => [],
            ];
        }

        $content = File::get($log_path);

        return json_decode($content, true);
    }

    public function save_update_log(array $log): void
    {
        $log_path = base_path(config('cascadedocs.paths.tracking.documentation_update'));

        File::put($log_path, json_encode($log, JSON_PRETTY_PRINT));
    }

    public function needs_documentation_update(string $file_path, array $update_log): bool
    {
        $relative_path = $this->get_relative_path($file_path);

        if (! isset($update_log['files'][$relative_path])) {
            return true;
        }

        $current_sha = $this->get_file_last_commit_sha($file_path);
        $documented_sha = $update_log['files'][$relative_path]['sha'] ?? null;

        return $current_sha !== $documented_sha;
    }

    protected function is_documentable_file(string $file_path): bool
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $documentable_extensions = config('cascadedocs.file_types');

        if (! in_array($extension, $documentable_extensions)) {
            return false;
        }

        // Only document files in configured source directories
        $sourceDirs = config('cascadedocs.paths.source');
        $inSourceDir = false;
        foreach ($sourceDirs as $dir) {
            if (Str::startsWith($file_path, $dir)) {
                $inSourceDir = true;
                break;
            }
        }
        if (! $inSourceDir) {
            return false;
        }

        // Skip test files
        return ! (Str::contains($file_path, ['tests/', 'test.', 'Test.']));
    }

    public function get_relative_path(string $file_path): string
    {
        $base_path = base_path();

        if (Str::startsWith($file_path, $base_path)) {
            return Str::after($file_path, $base_path.DIRECTORY_SEPARATOR);
        }

        return $file_path;
    }

    public function analyze_changes_for_summary(Collection $changed_files): array
    {
        $summary = [
            'total_files' => $changed_files->count(),
            'by_type' => [],
            'by_directory' => [],
            'affected_modules' => [],
        ];

        $module_service = new ModuleMappingService;

        foreach ($changed_files as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $directory = dirname($this->get_relative_path($file));

            // Count by type
            if (! isset($summary['by_type'][$extension])) {
                $summary['by_type'][$extension] = 0;
            }
            $summary['by_type'][$extension]++;

            // Count by directory
            if (! isset($summary['by_directory'][$directory])) {
                $summary['by_directory'][$directory] = 0;
            }
            $summary['by_directory'][$directory]++;

            // Track affected modules
            $module = $module_service->get_module_for_file($file);

            if ($module && ! in_array($module, $summary['affected_modules'])) {
                $summary['affected_modules'][] = $module;
            }
        }

        return $summary;
    }
}
