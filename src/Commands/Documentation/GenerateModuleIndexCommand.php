<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;

class GenerateModuleIndexCommand extends Command
{
    protected $signature = 'cascadedocs:generate-module-index
                            {--output= : Custom output path for the index file}';

    protected $description = 'Generate an index.md file listing all modules with their summaries';

    public function handle(): int
    {
        $this->info('Generating module index...');

        $metadata_service = new ModuleMetadataService();
        $modules = collect();

        // Get all module metadata files
        $metadata_dir = $metadata_service->getMetadataDirectory();
        
        if (!File::exists($metadata_dir)) {
            $this->error("No module metadata found. Please run 'php artisan cascadedocs:generate-module-docs' first.");
            return 1;
        }

        $metadata_files = File::files($metadata_dir);
        
        if (empty($metadata_files)) {
            $this->error("No module metadata files found.");
            return 1;
        }

        // Load metadata for each module
        foreach ($metadata_files as $file) {
            if ($file->getExtension() === 'json') {
                $module_slug = $file->getFilenameWithoutExtension();
                $metadata = $metadata_service->loadMetadata($module_slug);
                
                if ($metadata) {
                    $modules->push([
                        'name' => $metadata['name'],
                        'slug' => $metadata['slug'],
                        'summary' => $metadata['module_summary'] ?? 'No summary available',
                        'file_count' => count($metadata['files'] ?? []),
                        'undocumented_count' => count($metadata['undocumented_files'] ?? []),
                    ]);
                }
            }
        }

        if ($modules->isEmpty()) {
            $this->error("No valid module metadata found.");
            return 1;
        }

        // Sort modules alphabetically by name
        $modules = $modules->sortBy('name');

        // Generate the markdown content
        $markdown = $this->generateMarkdown($modules);

        // Determine output path
        $output_path = $this->option('output') 
            ?? base_path(config('cascadedocs.output_directory', 'docs/source_documents')) . '/modules/index.md';

        // Ensure directory exists
        $output_dir = dirname($output_path);
        if (!File::exists($output_dir)) {
            File::makeDirectory($output_dir, 0755, true);
        }

        // Write the index file
        File::put($output_path, $markdown);

        $this->info("Module index generated successfully at: {$output_path}");
        $this->info("Total modules indexed: " . $modules->count());

        return 0;
    }

    protected function generateMarkdown($modules): string
    {
        $markdown = "# Module Index\n\n";
        $markdown .= "This index provides an overview of all modules in the codebase, their summaries, and links to their documentation.\n\n";
        $markdown .= "**Total Modules:** " . $modules->count() . "\n\n";
        $markdown .= "**Generated:** " . now()->format('Y-m-d H:i:s') . "\n\n";
        
        // Add table of contents
        $markdown .= "## Table of Contents\n\n";
        foreach ($modules as $module) {
            $markdown .= "- [{$module['name']}](#{$module['slug']})\n";
        }
        $markdown .= "\n";
        
        // Add module summary table
        $markdown .= "## Module Summary\n\n";
        $markdown .= "| Module | Files | Summary |\n";
        $markdown .= "|--------|-------|----------|\n";
        
        foreach ($modules as $module) {
            $module_link = "[{$module['name']}](content/{$module['slug']}.md)";
            $file_stats = $module['file_count'];
            if ($module['undocumented_count'] > 0) {
                $file_stats .= " ({$module['undocumented_count']} undocumented)";
            }
            $summary = $this->truncateSummary($module['summary']);
            $markdown .= "| {$module_link} | {$file_stats} | {$summary} |\n";
        }
        
        $markdown .= "\n## Module Details\n\n";
        
        // Add detailed section for each module
        foreach ($modules as $module) {
            $markdown .= "### <a name=\"{$module['slug']}\"></a>{$module['name']}\n\n";
            $markdown .= "**Documentation:** [View Module Documentation](content/{$module['slug']}.md)\n\n";
            $markdown .= "**Files:** {$module['file_count']}";
            if ($module['undocumented_count'] > 0) {
                $markdown .= " ({$module['undocumented_count']} files pending documentation)";
            }
            $markdown .= "\n\n";
            $markdown .= "**Summary:**\n\n";
            $markdown .= $module['summary'] . "\n\n";
            $markdown .= "---\n\n";
        }
        
        return $markdown;
    }

    protected function truncateSummary(string $summary, int $max_length = 150): string
    {
        // Remove newlines and extra spaces for table display
        $summary = preg_replace('/\s+/', ' ', trim($summary));
        
        if (strlen($summary) <= $max_length) {
            return $summary;
        }
        
        // Truncate to max length and add ellipsis
        $truncated = substr($summary, 0, $max_length);
        
        // Try to break at the last complete word
        $last_space = strrpos($truncated, ' ');
        if ($last_space !== false && $last_space > $max_length * 0.8) {
            $truncated = substr($truncated, 0, $last_space);
        }
        
        return $truncated . '...';
    }
}