<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;

class GenerateAiDocumentationForAllFilesCommand extends Command
{
    protected $signature = 'generate:ai-documentation 
        {--paths=*} 
        {--default-paths}
        {--tier=all : micro|standard|expansive|all}
        {--model=o3 : The AI model to use for generation}';

    protected $description = 'Generate multi-tier AI documentation for PHP files in specified directories';

    public function handle()
    {
        $this->info('Starting multi-tier documentation generation...');

        // Validate tier option
        $tier = $this->option('tier');

        if (! in_array($tier, ['micro', 'standard', 'expansive', 'all'])) {
            $this->error('Invalid tier option. Must be one of: micro, standard, expansive, all');

            return;
        }

        // Get the model option
        $model = $this->option('model');

        // Create documentation directories if they don't exist
        $tier_directories = [
            'short' => base_path('docs/source_documents/short'),
            'medium' => base_path('docs/source_documents/medium'),
            'full' => base_path('docs/source_documents/full'),
        ];

        foreach ($tier_directories as $dir) {
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }

        // Get paths from command options or use defaults
        $paths = $this->option('paths');

        // If --default-paths is used or no paths provided, use these defaults
        if ($this->option('default-paths') || empty($paths)) {
            $paths = [
                'app',
                'resources/js',
            ];
        }

        $this->info('Scanning the following directories: '.implode(', ', $paths));

        // Collect all PHP files from all specified paths
        $all_files = [];

        foreach ($paths as $path) {
            $full_path = base_path($path);

            if (File::exists($full_path)) {
                $files = $this->get_files_recursively($full_path);
                $all_files = array_merge($all_files, $files);
                $this->info('Found '.count($files)." PHP files in {$path}");
            } else {
                $this->warn("Path not found: {$path}");
            }
        }

        $this->info('Found '.count($all_files).' total files to process.');

        $jobs_dispatched = 0;
        $skipped_files = 0;

        foreach ($all_files as $file_path) {
            // Check if documentation already exists for all requested tiers
            if ($this->documentation_exists($file_path, $tier)) {
                $skipped_files++;

                continue;
            }

            // Dispatch job for this file
            GenerateAiDocumentationForFileJob::dispatch($file_path, $tier, $model);
            $jobs_dispatched++;
        }

        $this->info("Dispatched {$jobs_dispatched} documentation generation jobs.");

        if ($skipped_files > 0) {
            $this->info("Skipped {$skipped_files} files (documentation already exists).");
        }

        $this->info('All jobs have been queued. Check your queue worker for processing status.');
    }

    private function get_files_recursively(string $directory): array
    {
        $files = [];

        foreach (File::allFiles($directory) as $file) {
            $extension = $file->getExtension();

            if ($extension === 'php' || $extension === 'js' || $extension === 'vue' || $extension === 'jsx' || $extension === 'ts' || $extension === 'tsx') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function documentation_exists(string $file_path, string $tier): bool
    {
        $tiers_to_check = $tier === 'all' ? ['micro', 'standard', 'expansive'] : [$tier];

        foreach ($tiers_to_check as $tier_to_check) {
            $doc_path = $this->get_tier_document_path($file_path, $tier_to_check);

            if (! File::exists($doc_path)) {
                return false;
            }
        }

        return true;
    }

    private function get_tier_document_path(string $source_file_path, string $tier): string
    {
        $tier_map = [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full',
        ];

        $relative_path = Str::after($source_file_path, base_path().DIRECTORY_SEPARATOR);
        $file_extension = pathinfo($source_file_path, PATHINFO_EXTENSION);
        $relative_path = Str::beforeLast($relative_path, '.'.$file_extension);

        return base_path("docs/source_documents/{$tier_map[$tier]}/{$relative_path}.md");
    }
}
