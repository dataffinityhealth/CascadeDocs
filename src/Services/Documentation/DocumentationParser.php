<?php

namespace Lumiio\CascadeDocs\Services\Documentation;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class DocumentationParser
{
    /**
     * Extract text between second "---" and "## How This Module Works"
     * This gets the module overview section.
     */
    public function extractModuleSummary(string $modulePath): string
    {
        if (! File::exists($modulePath)) {
            throw new Exception("Module file not found: {$modulePath}");
        }

        $content = File::get($modulePath);

        // Find all occurrences of "---"
        $lines = explode("\n", $content);
        $dashPositions = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '---') {
                $dashPositions[] = $index;
            }
        }

        if (count($dashPositions) < 2) {
            throw new Exception("Invalid module format - requires at least 2 '---' markers");
        }

        // Find the line containing "## How This Module Works"
        $startLine = $dashPositions[1] + 1;
        $endLine = null;

        for ($i = $startLine; $i < count($lines); $i++) {
            if (str_starts_with(trim($lines[$i]), '## How This Module Works')) {
                $endLine = $i;

                break;
            }
        }

        if ($endLine === null) {
            throw new Exception("Could not find '## How This Module Works' section");
        }

        // Extract the summary lines
        $summaryLines = array_slice($lines, $startLine, $endLine - $startLine);

        // Clean up and return
        return trim(implode("\n", $summaryLines));
    }

    /**
     * Extract summaries from all module files.
     */
    public function extractAllModuleSummaries(): Collection
    {
        $contentPath = base_path(config('cascadedocs.paths.modules.content'));

        if (! File::exists($contentPath)) {
            return collect();
        }

        return collect(File::files($contentPath))
            ->filter(fn ($file) => $file->getExtension() === 'md')
            ->mapWithKeys(function ($file) {
                try {
                    $slug = $file->getFilenameWithoutExtension();
                    $content = File::get($file->getPathname());

                    // Extract overview section from content
                    $summary = $this->extractOverviewFromContent($content);

                    return [$slug => $summary];
                } catch (Exception $e) {
                    // Log error and skip this module
                    logger()->warning('Failed to extract module summary', [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage(),
                    ]);

                    return [];
                }
            });
    }

    /**
     * Get short documentation for a specific file.
     */
    public function getShortDocumentation(string $filePath): ?string
    {
        $shortDocPath = $this->convertToShortDocPath($filePath);

        if (! File::exists($shortDocPath)) {
            return null;
        }

        return trim(File::get($shortDocPath));
    }

    /**
     * Get short documentation for multiple files (batch processing).
     */
    public function getShortDocumentationBatch(array $filePaths): Collection
    {
        return collect($filePaths)->mapWithKeys(function ($filePath) {
            $shortDoc = $this->getShortDocumentation($filePath);

            return [$filePath => $shortDoc];
        })->filter(); // Remove null values
    }

    /**
     * Convert source file path to short documentation path.
     */
    private function convertToShortDocPath(string $filePath): string
    {
        // Remove base path if present
        $relativePath = str_replace(base_path().'/', '', $filePath);

        // Replace file extensions with .md
        $docPath = preg_replace('/\.(php|js|vue|jsx|ts|tsx)$/', '.md', $relativePath);

        $outputPath = config('cascadedocs.paths.output');
        $tierDir = config('cascadedocs.tiers.micro', 'short');

        return base_path("{$outputPath}{$tierDir}/{$docPath}");
    }

    /**
     * Check if a file has short documentation.
     */
    public function hasShortDocumentation(string $filePath): bool
    {
        $shortDocPath = $this->convertToShortDocPath($filePath);

        return File::exists($shortDocPath);
    }

    /**
     * Get module metadata from front matter.
     */
    public function extractModuleMetadata(string $modulePath): array
    {
        if (! File::exists($modulePath)) {
            return [];
        }

        $content = File::get($modulePath);

        // Extract front matter between first two "---"
        if (preg_match('/^---\n(.*?)\n---/s', $content, $matches)) {
            $frontMatter = $matches[1];
            $metadata = [];

            foreach (explode("\n", $frontMatter) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $metadata[trim($key)] = trim($value);
                }
            }

            return $metadata;
        }

        return [];
    }

    /**
     * Extract overview section from module content.
     */
    protected function extractOverviewFromContent(string $content): string
    {
        // Look for ## Overview section
        if (preg_match('/^## Overview\s*\n\n(.+?)(?=\n##|\z)/sm', $content, $matches)) {
            return trim($matches[1]);
        }

        // If no overview section, return first paragraph after title
        if (preg_match('/^# .+?\n\n(.+?)(?=\n##|\z)/sm', $content, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}
