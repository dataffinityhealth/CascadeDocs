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

    protected function is_documentable_file(string $file_path): bool
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $documentable_extensions = config('cascadedocs.file_types');

        if (! in_array($extension, $documentable_extensions)) {
            return false;
        }

        // Only document files in configured source directories
        $sourceDirs = config('cascadedocs.paths.source');
        $relativePath = $this->get_relative_path($file_path);

        $inSourceDir = false;
        foreach ($sourceDirs as $dir) {
            // Check both against the full path and relative path
            if (Str::startsWith($file_path, $dir) || Str::startsWith($relativePath, $dir)) {
                $inSourceDir = true;
                break;
            }
        }
        if (! $inSourceDir) {
            return false;
        }

        // Skip test files
        return ! Str::contains($file_path, ['tests/', 'test.', 'Test.']);
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

    public function get_files_needing_update(): Collection
    {
        $filesNeedingUpdate = collect();

        // Get the full documentation path
        $fullDocPath = base_path('docs/source_documents/full');

        if (! File::isDirectory($fullDocPath)) {
            // No documentation exists yet, all files need documenting
            return $this->get_all_undocumented_files();
        }

        // Scan all documentation files recursively
        $docFiles = collect(File::allFiles($fullDocPath))
            ->filter(fn ($file) => $file->getExtension() === 'md');

        // Process each documentation file
        foreach ($docFiles as $docFile) {
            $docPath = $docFile->getPathname();
            $docInfo = $this->extract_doc_metadata($docPath);

            if (! $docInfo) {
                continue;
            }

            if (! isset($docInfo['source_path']) || ! isset($docInfo['commit_sha'])) {
                continue;
            }

            // Get the full source path
            $sourcePath = base_path($docInfo['source_path']);

            // Check if source file still exists
            if (! File::exists($sourcePath)) {
                continue; // File was deleted, skip
            }

            // Check if it's still a documentable file
            if (! $this->is_documentable_file($sourcePath)) {
                continue;
            }

            // Get current SHA of the source file
            $currentSha = $this->get_file_last_commit_sha($sourcePath);

            // Compare SHAs
            if ($currentSha !== $docInfo['commit_sha']) {
                $filesNeedingUpdate->push([
                    'path' => $sourcePath,
                    'relative_path' => $docInfo['source_path'],
                    'current_sha' => $currentSha,
                    'documented_sha' => $docInfo['commit_sha'],
                    'needs_update' => true,
                    'doc_path' => $docPath,
                ]);
            }
        }

        // Also check for new files that don't have documentation yet
        $documentedPaths = $filesNeedingUpdate->pluck('relative_path')
            ->merge($this->get_all_documented_source_paths());

        $undocumentedFiles = $this->get_all_undocumented_files($documentedPaths);

        return $filesNeedingUpdate->merge($undocumentedFiles);
    }

    public function extract_sha_from_documentation(string $docPath): ?string
    {
        if (! File::exists($docPath)) {
            return null;
        }

        $content = File::get($docPath);

        // Extract YAML frontmatter - try both --- and ``` formats
        $yaml = null;
        if (preg_match('/^---\n(.*?)\n---/s', $content, $matches)) {
            $yaml = $matches[1];
        } elseif (preg_match('/^```yaml\n(.*?)\n```/s', $content, $matches)) {
            $yaml = $matches[1];
        }

        if ($yaml && preg_match('/^commit_sha:\s*([a-f0-9]{40})/m', $yaml, $shaMatch)) {
            return $shaMatch[1];
        }

        return null;
    }

    public function extract_doc_metadata(string $docPath): ?array
    {
        if (! File::exists($docPath)) {
            return null;
        }

        $content = File::get($docPath);

        // Extract YAML frontmatter - try both --- and ``` formats
        $yamlPattern1 = '/^---\n(.*?)\n---/s';
        $yamlPattern2 = '/^```yaml\n(.*?)\n```/s';

        $yaml = null;
        if (preg_match($yamlPattern1, $content, $matches)) {
            $yaml = $matches[1];
        } elseif (preg_match($yamlPattern2, $content, $matches)) {
            $yaml = $matches[1];
        }

        if ($yaml) {
            $metadata = [];

            // Extract source_path
            if (preg_match('/^source_path:\s*(.+)$/m', $yaml, $pathMatch)) {
                $metadata['source_path'] = trim($pathMatch[1]);
            }

            // Extract commit_sha
            if (preg_match('/^commit_sha:\s*([a-f0-9]{40})/m', $yaml, $shaMatch)) {
                $metadata['commit_sha'] = $shaMatch[1];
            }

            // Extract doc_tier
            if (preg_match('/^doc_tier:\s*(.+)$/m', $yaml, $tierMatch)) {
                $metadata['doc_tier'] = trim($tierMatch[1]);
            }

            return $metadata;
        }

        return null;
    }

    private function get_all_undocumented_files(?Collection $documentedPaths = null): Collection
    {
        $files = collect();
        $sourcePaths = config('cascadedocs.paths.source');

        // Get all documentable files
        foreach ($sourcePaths as $sourcePath) {
            $fullPath = base_path($sourcePath);
            if (File::isDirectory($fullPath)) {
                $this->scanDirectory($fullPath, $files);
            }
        }

        // If we have documented paths, filter them out
        if ($documentedPaths !== null && $documentedPaths->isNotEmpty()) {
            $files = $files->filter(function ($filePath) use ($documentedPaths) {
                $relativePath = $this->get_relative_path($filePath);

                return ! $documentedPaths->contains($relativePath);
            });
        }

        // Return in the same format as documented files
        return $files->map(function ($filePath) {
            return [
                'path' => $filePath,
                'relative_path' => $this->get_relative_path($filePath),
                'current_sha' => $this->get_file_last_commit_sha($filePath),
                'documented_sha' => null,
                'needs_update' => true,
                'doc_path' => null,
            ];
        });
    }

    private function get_all_documented_source_paths(): Collection
    {
        $fullDocPath = base_path('docs/source_documents/full');

        if (! File::isDirectory($fullDocPath)) {
            return collect();
        }

        $docFiles = collect(File::allFiles($fullDocPath))
            ->filter(fn ($file) => $file->getExtension() === 'md');

        $paths = collect();

        foreach ($docFiles as $docFile) {
            $docInfo = $this->extract_doc_metadata($docFile->getPathname());
            if ($docInfo && isset($docInfo['source_path'])) {
                $paths->push($docInfo['source_path']);
            }
        }

        return $paths->unique();
    }

    private function getDocumentationPath(string $filePath): string
    {
        $relativePath = $this->get_relative_path($filePath);
        // Remove leading slash if present
        $relativePath = ltrim($relativePath, '/');
        // Replace directory separators with underscores and append .md
        $docName = str_replace('/', '_', $relativePath).'.md';

        return base_path('docs/source_documents/full/'.$docName);
    }

    private function scanDirectory(string $directory, Collection &$files): void
    {
        $excludedDirs = config('cascadedocs.exclude.directories', []);
        $excludedPatterns = config('cascadedocs.exclude.patterns', []);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getPathname();
                $relativePath = $this->get_relative_path($filePath);

                // Skip excluded directories
                $skip = false;
                foreach ($excludedDirs as $excludedDir) {
                    if (Str::contains($relativePath, $excludedDir.'/')) {
                        $skip = true;
                        break;
                    }
                }

                if ($skip) {
                    continue;
                }

                // Skip excluded patterns
                foreach ($excludedPatterns as $pattern) {
                    if (fnmatch($pattern, basename($filePath))) {
                        $skip = true;
                        break;
                    }
                }

                if ($skip) {
                    continue;
                }

                // Check if it's a documentable file
                if ($this->is_documentable_file($filePath)) {
                    $files->push($filePath);
                }
            }
        }
    }
}
