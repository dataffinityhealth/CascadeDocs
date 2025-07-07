<?php

namespace Lumiio\CascadeDocs\Services\Documentation;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ModuleMetadataService
{
    protected string $metadataPath;
    protected string $contentPath;

    public function __construct()
    {
        $this->metadataPath = base_path('docs/source_documents/modules/metadata');
        $this->contentPath  = base_path('docs/source_documents/modules/content');
    }

    /**
     * Load module metadata from JSON file.
     */
    public function loadMetadata(string $moduleSlug): ?array
    {
        $metadataFile = "{$this->metadataPath}/{$moduleSlug}.json";

        if (! File::exists($metadataFile))
        {
            return null;
        }

        return json_decode(File::get($metadataFile), true);
    }

    /**
     * Save module metadata to JSON file.
     */
    public function saveMetadata(string $moduleSlug, array $metadata): void
    {
        $metadataFile = "{$this->metadataPath}/{$moduleSlug}.json";

        // Ensure statistics are up to date
        $metadata['statistics']   = $this->calculateStatistics($metadata);
        $metadata['last_updated'] = Carbon::now()->toIso8601String();

        File::put($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Add files to a module.
     */
    public function addFiles(string $moduleSlug, array $files, bool $documented = false): array
    {
        $metadata = $this->loadMetadata($moduleSlug);

        if (! $metadata)
        {
            throw new Exception("Module not found: {$moduleSlug}");
        }

        // Get existing file paths
        $existingPaths = collect($metadata['files'])->pluck('path')->toArray();

        foreach ($files as $file)
        {
            // Skip if file already exists
            if (in_array($file, $existingPaths))
            {
                continue;
            }

            // Add to appropriate list
            if ($documented)
            {
                $metadata['files'][] = [
                    'path'               => $file,
                    'documented'         => true,
                    'documentation_tier' => $this->getDocumentationTier($file),
                    'added_date'         => Carbon::now()->toIso8601String(),
                ];
            } else
            {
                // Add to undocumented files if not already there
                if (! in_array($file, $metadata['undocumented_files']))
                {
                    $metadata['undocumented_files'][] = $file;
                }
            }
        }

        $this->saveMetadata($moduleSlug, $metadata);

        return $metadata;
    }

    /**
     * Remove files from a module.
     */
    public function removeFiles(string $moduleSlug, array $files): array
    {
        $metadata = $this->loadMetadata($moduleSlug);

        if (! $metadata)
        {
            throw new Exception("Module not found: {$moduleSlug}");
        }

        // Remove from documented files
        $metadata['files'] = collect($metadata['files'])
            ->reject(fn ($file) => in_array($file['path'], $files))
            ->values()
            ->toArray();

        // Remove from undocumented files
        $metadata['undocumented_files'] = array_values(
            array_diff($metadata['undocumented_files'], $files)
        );

        $this->saveMetadata($moduleSlug, $metadata);

        return $metadata;
    }

    /**
     * Mark files as documented.
     */
    public function markFilesAsDocumented(string $moduleSlug, array $files): array
    {
        $metadata = $this->loadMetadata($moduleSlug);

        if (! $metadata)
        {
            throw new Exception("Module not found: {$moduleSlug}");
        }

        foreach ($files as $file)
        {
            // Remove from undocumented list
            $metadata['undocumented_files'] = array_values(
                array_diff($metadata['undocumented_files'], [$file])
            );

            // Check if already in documented files
            $exists = collect($metadata['files'])->firstWhere('path', $file);

            if (! $exists)
            {
                // Add to documented files
                $metadata['files'][] = [
                    'path'               => $file,
                    'documented'         => true,
                    'documentation_tier' => $this->getDocumentationTier($file),
                    'added_date'         => Carbon::now()->toIso8601String(),
                ];
            }
        }

        $this->saveMetadata($moduleSlug, $metadata);

        return $metadata;
    }

    /**
     * Update module summary.
     */
    public function updateModuleSummary(string $moduleSlug, string $summary): void
    {
        $metadata = $this->loadMetadata($moduleSlug);

        if (! $metadata)
        {
            throw new Exception("Module not found: {$moduleSlug}");
        }

        $metadata['module_summary'] = $summary;

        $this->saveMetadata($moduleSlug, $metadata);
    }

    /**
     * Move a file from documented to undocumented status.
     * This is used when a file is modified and needs module re-documentation.
     */
    public function moveFileToUndocumented(string $moduleSlug, string $filePath): void
    {
        $metadata = $this->loadMetadata($moduleSlug);

        if (! $metadata)
        {
            throw new Exception("Module not found: {$moduleSlug}");
        }

        // Find and remove from documented files
        $fileIndex = null;

        foreach ($metadata['files'] as $index => $file)
        {
            if ($file['path'] === $filePath)
            {
                $fileIndex = $index;

                break;
            }
        }

        if ($fileIndex !== null)
        {
            // Remove from documented files
            array_splice($metadata['files'], $fileIndex, 1);

            // Add to undocumented files if not already there
            if (! in_array($filePath, $metadata['undocumented_files']))
            {
                $metadata['undocumented_files'][] = $filePath;
            }

            $this->saveMetadata($moduleSlug, $metadata);
        }
    }

    /**
     * Create new module metadata.
     */
    public function createModule(array $moduleData): void
    {
        $slug = $moduleData['slug'];

        $metadata = [
            'module_name'        => $moduleData['name'],
            'module_slug'        => $slug,
            'module_summary'     => '', // Empty until documentation is generated
            'doc_version'        => '1.0',
            'generated_at'       => Carbon::now()->toIso8601String(),
            'last_updated'       => Carbon::now()->toIso8601String(),
            'git_commit_sha'     => $this->getCurrentGitCommit(),
            'files'              => [],
            'undocumented_files' => $moduleData['files'] ?? [],
            'statistics'         => [
                'total_files'        => count($moduleData['files'] ?? []),
                'documented_files'   => 0,
                'undocumented_files' => count($moduleData['files'] ?? []),
            ],
        ];

        $this->saveMetadata($slug, $metadata);

        // Create empty content file
        $contentFile = "{$this->contentPath}/{$slug}.md";
        $content     = "# {$moduleData['name']} Module\n\n## Overview\n\n{$moduleData['description']}\n\n## How This Module Works\n\n[To be documented]\n";

        File::put($contentFile, $content);
    }

    /**
     * Get all module slugs.
     */
    public function getAllModuleSlugs(): Collection
    {
        return collect(File::files($this->metadataPath))
            ->filter(fn ($file) => $file->getExtension() === 'json')
            ->map(fn ($file) => $file->getBasename('.json'))
            ->sort()
            ->values();
    }

    /**
     * Get all files from a module (documented and undocumented).
     */
    public function getAllModuleFiles(string $moduleSlug): array
    {
        $metadata = $this->loadMetadata($moduleSlug);

        if (! $metadata)
        {
            return [];
        }

        $documentedFiles   = collect($metadata['files'])->pluck('path')->toArray();
        $undocumentedFiles = $metadata['undocumented_files'] ?? [];

        return array_unique(array_merge($documentedFiles, $undocumentedFiles));
    }

    /**
     * Check if module exists.
     */
    public function moduleExists(string $slug): bool
    {
        return File::exists("{$this->metadataPath}/{$slug}.json");
    }

    /**
     * Calculate statistics for a module.
     */
    protected function calculateStatistics(array $metadata): array
    {
        $documentedCount   = count($metadata['files']);
        $undocumentedCount = count($metadata['undocumented_files'] ?? []);

        return [
            'total_files'        => $documentedCount + $undocumentedCount,
            'documented_files'   => $documentedCount,
            'undocumented_files' => $undocumentedCount,
        ];
    }

    /**
     * Get documentation tier for a file.
     */
    protected function getDocumentationTier(string $file): string
    {
        $tiers = ['full', 'medium', 'short'];

        // Remove extension and add .md
        $docFile = preg_replace('/\.(php|js|blade\.php)$/', '', $file) . '.md';

        foreach ($tiers as $tier)
        {
            $docPath = base_path("docs/source_documents/{$tier}/{$docFile}");

            if (File::exists($docPath))
            {
                return $tier;
            }
        }

        return 'unknown';
    }

    /**
     * Get current git commit SHA.
     */
    protected function getCurrentGitCommit(): string
    {
        $commit = trim(shell_exec('git rev-parse HEAD') ?? 'unknown');

        return $commit ?: 'unknown';
    }
}
