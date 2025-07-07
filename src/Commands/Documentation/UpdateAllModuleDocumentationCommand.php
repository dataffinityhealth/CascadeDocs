<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;

class UpdateAllModuleDocumentationCommand extends Command
{
    protected $signature = 'documentation:update-all-modules 
                            {--module= : Update a specific module}
                            {--model= : The AI model to use for generation}
                            {--dry-run : Show what would be updated without making changes}
                            {--limit=0 : Maximum number of modules to process (0 = all)}';

    protected $description = 'Update documentation for all modules that have undocumented files';

    protected ModuleMetadataService $metadataService;

    public function __construct()
    {
        parent::__construct();
        $this->metadataService = new ModuleMetadataService;
    }

    public function handle(): int
    {
        $moduleSlug = $this->option('module');
        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');
        $dryRun = $this->option('dry-run');

        $this->info('Starting module documentation update process...');

        if ($moduleSlug) {
            // Update specific module
            $result = $this->processModule($moduleSlug, $model, $dryRun);

            if (! $result) {
                $this->error("Module not found: {$moduleSlug}");

                return 1;
            }

            return 0;
        }

        // Update all modules with undocumented files
        $modules = $this->metadataService->getAllModuleSlugs();
        $this->info("Found {$modules->count()} total modules");

        $modulesToUpdate = collect();

        // Check which modules have undocumented files
        foreach ($modules as $slug) {
            $metadata = $this->metadataService->loadMetadata($slug);

            if ($metadata && ! empty($metadata['undocumented_files'])) {
                $modulesToUpdate->push([
                    'slug' => $slug,
                    'name' => $metadata['module_name'],
                    'undocumented_count' => count($metadata['undocumented_files']),
                ]);
            }
        }

        if ($modulesToUpdate->isEmpty()) {
            $this->info('No modules have undocumented files. All documentation is up to date!');

            return 0;
        }

        // Apply limit if specified
        $limit = (int) $this->option('limit');

        if ($limit > 0 && $modulesToUpdate->count() > $limit) {
            $modulesToUpdate = $modulesToUpdate->take($limit);
            $this->info("Limiting to {$limit} modules as requested.");
        }

        $this->info("Found {$modulesToUpdate->count()} modules with undocumented files:");
        $this->table(
            ['Module', 'Name', 'Undocumented Files'],
            $modulesToUpdate->map(function ($module) {
                return [
                    $module['slug'],
                    $module['name'],
                    $module['undocumented_count'],
                ];
            })
        );

        if ($dryRun) {
            $this->info("\nDry run mode - no changes will be made.");

            return 0;
        }

        if (! $this->confirm('Do you want to update documentation for these modules?')) {
            $this->info('Update cancelled.');

            return 0;
        }

        // Get current git SHA for tracking
        $gitSha = trim(shell_exec('git rev-parse HEAD') ?? 'unknown');

        $this->info("\nDispatching module documentation update jobs...");
        $bar = $this->output->createProgressBar($modulesToUpdate->count());
        $bar->start();

        foreach ($modulesToUpdate as $module) {
            UpdateModuleDocumentationJob::dispatch(
                $module['slug'],
                $gitSha,
                $model
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Successfully queued {$modulesToUpdate->count()} module update jobs.");
        $this->info('Check your queue worker for processing status.');
        $this->info('Run: php artisan queue:work --queue=module_updates');

        return 0;
    }

    protected function processModule(string $moduleSlug, string $model, bool $dryRun): bool
    {
        $metadata = $this->metadataService->loadMetadata($moduleSlug);

        if (! $metadata) {
            return false;
        }

        $undocumentedCount = count($metadata['undocumented_files'] ?? []);

        if ($undocumentedCount === 0) {
            $this->info("Module '{$moduleSlug}' has no undocumented files.");

            return true;
        }

        $this->info("Module: {$metadata['module_name']}");
        $this->info("Undocumented files: {$undocumentedCount}");

        if ($dryRun) {
            $this->info("Would update documentation for module: {$moduleSlug}");
            $this->info('Files to document:');

            foreach ($metadata['undocumented_files'] as $file) {
                $this->line("  - {$file}");
            }

            return true;
        }

        // Get current git SHA
        $gitSha = trim(shell_exec('git rev-parse HEAD') ?? 'unknown');

        UpdateModuleDocumentationJob::dispatch(
            $moduleSlug,
            $gitSha,
            $model
        );

        $this->info("Module documentation update job queued for: {$moduleSlug}");

        return true;
    }
}
