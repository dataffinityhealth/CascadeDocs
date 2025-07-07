<?php

namespace Lumiio\CascadeDocs\Services\Documentation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModuleMappingService
{
    protected Collection $module_mappings;
    protected array $module_metadata = [];

    public function __construct()
    {
        $this->module_mappings = collect();
        $this->load_module_mappings();
    }

    public function get_module_for_file(string $file_path): ?string
    {
        $relative_path = $this->get_relative_path($file_path);

        foreach ($this->module_mappings as $module_slug => $files)
        {
            if ($files->contains($relative_path))
            {
                return $module_slug;
            }
        }

        return null;
    }

    public function get_files_for_module(string $module_slug): Collection
    {
        return $this->module_mappings->get($module_slug, collect());
    }

    public function get_all_modules(): array
    {
        return $this->module_mappings->keys()->toArray();
    }

    public function get_module_metadata(string $module_slug): ?array
    {
        return $this->module_metadata[$module_slug] ?? null;
    }

    public function suggest_module_for_new_file(string $file_path): ?string
    {
        $relative_path = $this->get_relative_path($file_path);
        $path_parts    = explode('/', $relative_path);

        // Don't assign documentation system files to regular modules
        if (Str::contains($relative_path, ['Documentation/', 'documentation']))
        {
            return null;
        }

        // First, try to find modules with files in the same directory
        $directory           = dirname($relative_path);
        $best_match          = null;
        $highest_match_count = 0;

        foreach ($this->module_mappings as $module_slug => $files)
        {
            $files_in_same_dir = $files->filter(function ($file) use ($directory)
            {
                return Str::startsWith($file, $directory . '/');
            });

            if ($files_in_same_dir->count() > $highest_match_count)
            {
                $highest_match_count = $files_in_same_dir->count();
                $best_match          = $module_slug;
            }
        }

        // Only suggest a module if there are at least 2 files in the same directory
        // This prevents false positives from generic directories
        if ($best_match && $highest_match_count >= 2)
        {
            return $best_match;
        }

        // If no direct match, try to match based on path patterns
        $namespace_parts = $this->extract_namespace_parts($relative_path);

        foreach ($this->module_mappings as $module_slug => $files)
        {
            foreach ($namespace_parts as $part)
            {
                if (Str::contains($module_slug, Str::slug($part)))
                {
                    return $module_slug;
                }
            }
        }

        return null;
    }

    protected function load_module_mappings(): void
    {
        $metadata_path = base_path('docs/source_documents/modules/metadata');

        if (! File::exists($metadata_path))
        {
            return;
        }

        $metadata_files = File::files($metadata_path);

        foreach ($metadata_files as $file)
        {
            if ($file->getExtension() !== 'json')
            {
                continue;
            }

            $metadata = json_decode(File::get($file->getPathname()), true);

            if ($metadata)
            {
                $module_slug = $metadata['module_slug'];
                $files       = collect();

                // Add documented files
                foreach ($metadata['files'] ?? [] as $fileInfo)
                {
                    $files->push($fileInfo['path']);
                }

                // Add undocumented files
                foreach ($metadata['undocumented_files'] ?? [] as $file)
                {
                    $files->push($file);
                }

                $this->module_mappings[$module_slug] = $files->unique()->values();
                $this->module_metadata[$module_slug] = $metadata;
            }
        }
    }

    /**
     * @deprecated This method is no longer used as we've migrated to JSON metadata files
     */
    protected function parse_module_file(string $content): ?array
    {
        $lines       = explode("\n", $content);
        $metadata    = [];
        $files       = [];
        $in_metadata = false;
        $module_slug = null;

        foreach ($lines as $line)
        {
            // Parse YAML front matter
            if (trim($line) === '---')
            {
                $in_metadata = ! $in_metadata;

                continue;
            }

            if ($in_metadata)
            {
                if (preg_match('/^module_slug:\s*(.+)$/', $line, $matches))
                {
                    $module_slug = trim($matches[1]);
                } elseif (preg_match('/^(\w+):\s*(.+)$/', $line, $matches))
                {
                    $metadata[trim($matches[1])] = trim($matches[2]);
                }

                continue;
            }

            // Parse file paths from bullet points
            if (preg_match('/^\s*-\s*\*\*`([^`]+)`\*\*/', $line, $matches))
            {
                $files[] = trim($matches[1]);
            }
        }

        if (! $module_slug)
        {
            return null;
        }

        return [
            'slug'     => $module_slug,
            'files'    => $files,
            'metadata' => $metadata,
        ];
    }

    protected function get_relative_path(string $file_path): string
    {
        $base_path = base_path();

        if (Str::startsWith($file_path, $base_path))
        {
            return Str::after($file_path, $base_path . DIRECTORY_SEPARATOR);
        }

        return $file_path;
    }

    protected function extract_namespace_parts(string $relative_path): array
    {
        $parts           = explode('/', $relative_path);
        $namespace_parts = [];

        // Extract meaningful parts from the path
        foreach ($parts as $part)
        {
            if (in_array($part, ['app', 'resources', 'js', 'php', 'src']))
            {
                continue;
            }

            // Convert camelCase and PascalCase to words
            $words           = preg_split('/(?=[A-Z])/', $part);
            $namespace_parts = array_merge($namespace_parts, array_filter($words));
        }

        return array_unique($namespace_parts);
    }

    public function refresh_mappings(): void
    {
        $this->module_mappings = collect();
        $this->module_metadata = [];
        $this->load_module_mappings();
    }
}
