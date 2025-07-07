<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;

class GenerateClassDocumentationCommand extends Command
{
    protected $signature = 'cascadedocs:generate-class-docs 
        {--tier=all : micro|standard|expansive|all}
        {--model= : The AI model to use for generation}
        {--force : Regenerate documentation even if it exists}';

    protected $description = 'Generate documentation for all classes in your application';

    public function handle()
    {
        $this->info('Starting class documentation generation...');

        $tier = $this->option('tier');
        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');
        $force = $this->option('force');

        // Validate tier
        if (! in_array($tier, ['micro', 'standard', 'expansive', 'all'])) {
            $this->error('Invalid tier option. Must be one of: micro, standard, expansive, all');

            return 1;
        }

        // Get source paths from config
        $paths = config('cascadedocs.paths.source', ['app/', 'resources/js/']);
        $fileTypes = config('cascadedocs.file_types', ['php', 'js', 'vue', 'jsx', 'ts', 'tsx']);

        // Create output directories
        $this->createOutputDirectories();

        // Collect all files
        $files = $this->collectFiles($paths, $fileTypes);

        if (empty($files)) {
            $this->warn('No files found to document.');

            return 0;
        }

        $this->info('Found '.count($files).' files to process.');

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $jobsDispatched = 0;
        $skippedFiles = 0;

        foreach ($files as $file) {
            if (! $force && $this->documentationExists($file, $tier)) {
                $skippedFiles++;
                $bar->advance();

                continue;
            }

            GenerateAiDocumentationForFileJob::dispatch($file, $tier, $model)
                ->onQueue(config('cascadedocs.queue.name', 'default'));

            $jobsDispatched++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("✓ Dispatched {$jobsDispatched} documentation generation jobs.");

        if ($skippedFiles > 0) {
            $this->info("✓ Skipped {$skippedFiles} files (documentation already exists).");
        }

        $this->info('All jobs have been queued. Check your queue worker for processing status.');

        return 0;
    }

    protected function createOutputDirectories(): void
    {
        $outputPath = config('cascadedocs.paths.output', 'docs/source_documents/');

        $directories = [
            base_path($outputPath.'short'),
            base_path($outputPath.'medium'),
            base_path($outputPath.'full'),
        ];

        foreach ($directories as $dir) {
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }
    }

    protected function collectFiles(array $paths, array $fileTypes): array
    {
        $files = [];
        $excludeDirs = config('cascadedocs.exclude.directories', []);
        $excludePatterns = config('cascadedocs.exclude.patterns', []);

        foreach ($paths as $path) {
            $fullPath = base_path($path);

            if (! File::exists($fullPath)) {
                $this->warn("Path not found: {$path}");

                continue;
            }

            $allFiles = File::allFiles($fullPath);

            foreach ($allFiles as $file) {
                // Check if in excluded directory
                $relativePath = str_replace(base_path().'/', '', $file->getPathname());
                $shouldExclude = false;

                foreach ($excludeDirs as $excludeDir) {
                    if (str_starts_with($relativePath, $excludeDir.'/')) {
                        $shouldExclude = true;
                        break;
                    }
                }

                if ($shouldExclude) {
                    continue;
                }

                // Check file type
                if (! in_array($file->getExtension(), $fileTypes)) {
                    continue;
                }

                // Check exclude patterns
                $filename = $file->getFilename();
                foreach ($excludePatterns as $pattern) {
                    if (fnmatch($pattern, $filename)) {
                        $shouldExclude = true;
                        break;
                    }
                }

                if (! $shouldExclude) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    protected function documentationExists(string $filePath, string $tier): bool
    {
        $tiersToCheck = $tier === 'all' ? ['micro', 'standard', 'expansive'] : [$tier];

        foreach ($tiersToCheck as $tierToCheck) {
            $docPath = $this->getDocumentationPath($filePath, $tierToCheck);

            if (! File::exists($docPath)) {
                return false;
            }
        }

        return true;
    }

    protected function getDocumentationPath(string $sourceFilePath, string $tier): string
    {
        $tierMap = [
            'micro' => 'short',
            'standard' => 'medium',
            'expansive' => 'full',
        ];

        $outputPath = config('cascadedocs.paths.output', 'docs/source_documents/');
        $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $sourceFilePath);
        $fileExtension = pathinfo($sourceFilePath, PATHINFO_EXTENSION);
        $relativePath = substr($relativePath, 0, -(strlen($fileExtension) + 1));

        return base_path($outputPath.$tierMap[$tier].'/'.$relativePath.'.md');
    }
}
