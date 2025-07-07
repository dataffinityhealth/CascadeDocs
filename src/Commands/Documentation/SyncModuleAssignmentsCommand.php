<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

class SyncModuleAssignmentsCommand extends Command
{
    protected $signature = 'documentation:sync-module-assignments 
        {--dry-run : Show what would be updated without making changes}
        {--detailed : Show detailed parsing information}';
    protected $description = 'Sync module assignments from both module documentation and metadata files';

    public function handle(): int
    {
        $this->info('Syncing module assignments from module metadata and documentation files...');

        $dry_run = $this->option('dry-run');
        $verbose = $this->option('detailed');

        // Parse all module files
        $module_assignments = $this->parse_all_modules($verbose);

        if ($module_assignments->isEmpty())
        {
            $this->warn('No module assignments found.');

            return 1;
        }

        // Show what we found
        $this->display_findings($module_assignments);

        if ($dry_run)
        {
            $this->info("\nDry run mode - no changes made.");

            return 0;
        }

        // Update the module assignment log
        $this->update_assignment_log($module_assignments);

        $this->info("\nModule assignments have been synced successfully!");
        $this->info('Run php artisan documentation:analyze-modules --report to see the updated assignments.');

        return 0;
    }

    protected function parse_all_modules(bool $verbose): Collection
    {
        $module_assignments = collect();

        // Step 1: Parse metadata JSON files
        $metadata_path = base_path('docs/source_documents/modules/metadata');

        if (File::exists($metadata_path))
        {
            $metadata_files = File::files($metadata_path);

            foreach ($metadata_files as $file)
            {
                if ($file->getExtension() !== 'json')
                {
                    continue;
                }

                $module_slug = Str::beforeLast($file->getFilename(), '.json');

                if ($verbose)
                {
                    $this->info("\nParsing module metadata: {$module_slug}");
                }

                $metadata = json_decode(File::get($file->getPathname()), true);

                // Collect all files from metadata (both documented and undocumented)
                $files = collect();

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

                if ($files->isNotEmpty())
                {
                    $module_assignments[$module_slug] = $files->unique()->values();

                    if ($verbose)
                    {
                        $this->line("  Found {$files->count()} file references in metadata");
                    }
                }
            }
        }

        // Step 2: Parse markdown documentation files and merge with metadata
        $content_path = base_path('docs/source_documents/modules/content');

        if (File::exists($content_path))
        {
            $content_files = File::files($content_path);

            foreach ($content_files as $file)
            {
                if ($file->getExtension() !== 'md')
                {
                    continue;
                }

                $module_slug = Str::beforeLast($file->getFilename(), '.md');

                if ($verbose)
                {
                    $this->info("\nParsing module documentation: {$module_slug}");
                }

                $content   = File::get($file->getPathname());
                $doc_files = $this->extract_file_references($content, $verbose);

                if ($doc_files->isNotEmpty())
                {
                    // Merge with existing assignments from metadata
                    $existing                         = $module_assignments->get($module_slug, collect());
                    $merged                           = $existing->merge($doc_files)->unique()->values();
                    $module_assignments[$module_slug] = $merged;

                    if ($verbose)
                    {
                        $this->line("  Found {$doc_files->count()} file references in documentation");
                        $this->line("  Total unique files for module: {$merged->count()}");
                    }
                }
            }
        }

        return $module_assignments;
    }

    protected function extract_file_references(string $content, bool $verbose): Collection
    {
        $files = collect();

        // Pattern 1: Markdown code blocks with file paths like `app/...` or `resources/js/...`
        // Matches: `app/Services/MyService.php`, `resources/js/components/MyComponent.vue`
        preg_match_all('/`((?:app|resources\/js)\/[^`]+\.\w+)`/', $content, $matches);

        foreach ($matches[1] as $file_path)
        {
            $clean_path = $this->clean_file_path($file_path);

            if ($clean_path && $this->is_valid_file_path($clean_path))
            {
                $files->push($clean_path);

                if ($verbose)
                {
                    $this->line("    - Found: {$clean_path}");
                }
            }
        }

        // Pattern 2: Bullet points with file paths
        // Matches: - **`app/Services/MyService.php`**
        preg_match_all('/^\s*[-*]\s*\*?\*?`((?:app|resources\/js)\/[^`]+\.\w+)`\*?\*?/m', $content, $matches);

        foreach ($matches[1] as $file_path)
        {
            $clean_path = $this->clean_file_path($file_path);

            if ($clean_path && $this->is_valid_file_path($clean_path) && ! $files->contains($clean_path))
            {
                $files->push($clean_path);

                if ($verbose)
                {
                    $this->line("    - Found: {$clean_path}");
                }
            }
        }

        // Pattern 3: File paths in parentheses
        // Matches: (app/Services/MyService.php)
        preg_match_all('/\(((?:app|resources\/js)\/[^)]+\.\w+)\)/', $content, $matches);

        foreach ($matches[1] as $file_path)
        {
            $clean_path = $this->clean_file_path($file_path);

            if ($clean_path && $this->is_valid_file_path($clean_path) && ! $files->contains($clean_path))
            {
                $files->push($clean_path);

                if ($verbose)
                {
                    $this->line("    - Found: {$clean_path}");
                }
            }
        }

        return $files->unique()->sort()->values();
    }

    protected function clean_file_path(string $path): string
    {
        // Remove any markdown formatting
        $path = strip_tags($path);
        $path = trim($path);

        // Remove any trailing punctuation
        $path = rtrim($path, '.,;:!?');

        // Ensure it starts with app/ or resources/js/
        if (! Str::startsWith($path, ['app/', 'resources/js/']))
        {
            return '';
        }

        return $path;
    }

    protected function is_valid_file_path(string $path): bool
    {
        // Must have an extension
        if (! preg_match('/\.\w+$/', $path))
        {
            return false;
        }

        // Should not contain spaces or special characters (except for path separators)
        if (preg_match('/[\s<>"|?*]/', $path))
        {
            return false;
        }

        // Validate extension
        $extension        = pathinfo($path, PATHINFO_EXTENSION);
        $valid_extensions = ['php', 'js', 'vue', 'jsx', 'ts', 'tsx'];

        return in_array($extension, $valid_extensions);
    }

    protected function display_findings(Collection $module_assignments): void
    {
        $this->newLine();
        $this->info('Module File References Found');
        $this->info('============================');

        $total_files = 0;

        foreach ($module_assignments as $module => $files)
        {
            $count = $files->count();
            $total_files += $count;
            $this->line("{$module}: {$count} files");
        }

        $this->newLine();
        $this->info('Total modules: ' . $module_assignments->count());
        $this->info("Total file references: {$total_files}");
    }

    protected function update_assignment_log(Collection $module_assignments): void
    {
        $this->info("\nUpdating module assignment log...");

        // Load existing log
        $assignment_service = new ModuleAssignmentService();
        $existing_log       = $assignment_service->load_log();

        // Build new assigned files structure
        $assigned_files = [];

        foreach ($module_assignments as $module => $files)
        {
            $assigned_files[$module] = $files->toArray();
        }

        // Get all documented files to identify unassigned ones
        $all_documented     = collect();
        $assignment_service = new ModuleAssignmentService();

        // Use reflection to call the protected method
        $reflection = new ReflectionClass($assignment_service);
        $method     = $reflection->getMethod('get_all_documented_files');
        $method->setAccessible(true);
        $all_documented = $method->invoke($assignment_service);

        // Find unassigned files
        $all_assigned = collect($assigned_files)->flatten()->unique();

        // Get do_not_document files from existing log
        $do_not_document_files = collect($existing_log['do_not_document'] ?? []);

        // Filter out do_not_document files from all documented files
        $documented_minus_do_not_document = $all_documented->diff($do_not_document_files);

        // Find unassigned files (excluding do_not_document)
        $unassigned = $documented_minus_do_not_document->diff($all_assigned)->values();

        // Update the log, preserving do_not_document
        $new_log = [
            'last_analysis'      => now()->toIso8601String(),
            'assigned_files'     => $assigned_files,
            'unassigned_files'   => $unassigned->toArray(),
            'do_not_document'    => $do_not_document_files->toArray(),
            'potential_modules'  => $existing_log['potential_modules']  ?? [],
            'module_suggestions' => $existing_log['module_suggestions'] ?? [],
        ];

        // Save the updated log
        $log_path = base_path('docs/module-assignment-log.json');
        File::put($log_path, json_encode($new_log, JSON_PRETTY_PRINT));

        $this->info("Updated {$all_assigned->count()} file assignments across " . count($assigned_files) . ' modules.');
        $this->info("Found {$unassigned->count()} unassigned files.");
    }
}
