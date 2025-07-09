<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateDocumentationForFileJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;

class UpdateDocumentationAfterMergeCommand extends Command
{
    protected $signature = 'cascadedocs:update-after-merge 
                            {--since= : Override the last update SHA}
                            {--model= : AI model to use}
                            {--dry-run : Show what would be updated}
                            {--limit=400 : Maximum number of unassigned files to process}';

    protected $description = 'Complete documentation update workflow after merging a branch';

    protected DocumentationDiffService $diffService;

    protected ModuleMappingService $moduleService;

    protected ModuleMetadataService $metadataService;

    protected ModuleAssignmentService $assignmentService;

    public function __construct()
    {
        parent::__construct();
        $this->diffService = new DocumentationDiffService;
        $this->moduleService = new ModuleMappingService;
        $this->metadataService = new ModuleMetadataService;
        $this->assignmentService = new ModuleAssignmentService;
    }

    public function handle(): int
    {
        $this->info('Starting complete documentation update workflow...');

        $dryRun = $this->option('dry-run');
        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');

        // Step 1: Load update log and determine SHAs
        $updateLog = $this->diffService->load_update_log();
        $fromSha = $this->option('since') ?? $updateLog['last_update_sha'];

        if (! $fromSha) {
            $this->error('No previous update SHA found. Please run with --since=<commit-sha>');

            return 1;
        }

        $currentSha = $this->diffService->get_current_commit_sha();

        if ($fromSha === $currentSha) {
            $this->info('Documentation is already up to date.');

            return 0;
        }

        $this->info("Analyzing changes from {$fromSha} to {$currentSha}");

        // Step 2: Get all file changes
        $changedFiles = $this->diffService->get_changed_files($fromSha, $currentSha);
        $newFiles = $this->diffService->get_new_files($fromSha, $currentSha);
        $deletedFiles = $this->diffService->get_deleted_files($fromSha, $currentSha);

        $allChanged = $changedFiles->merge($newFiles)->unique();

        if ($allChanged->isEmpty() && $deletedFiles->isEmpty()) {
            $this->info('No documentable files have changed.');

            return 0;
        }

        // Analyze changes
        $summary = $this->diffService->analyze_changes_for_summary($allChanged);
        $this->displayChangeSummary($summary, $dryRun);

        if ($dryRun) {
            $this->displayDryRunDetails($allChanged, $deletedFiles);

            return 0;
        }

        // Step 3: Process new files (generate all tiers of documentation)
        $this->processNewFiles($newFiles, $model);

        // Step 4: Process changed files (update documentation and mark as undocumented in modules)
        $affectedModules = $this->processChangedFiles($changedFiles, $fromSha, $currentSha, $model);

        // Step 5: Handle deleted files
        $this->processDeletedFiles($deletedFiles);

        // Step 6: Analyze and assign untracked files
        $this->processUnassignedFiles($model);

        // Step 7: Update all affected modules
        $this->updateAffectedModules($affectedModules, $currentSha, $model);

        // Step 8: Update the log
        $updateLog['last_update_sha'] = $currentSha;
        $updateLog['last_update_timestamp'] = Carbon::now()->toIso8601String();
        $this->diffService->save_update_log($updateLog);

        $this->info("\n✅ Documentation update complete!");
        $this->info("Updated to SHA: {$currentSha}");

        return 0;
    }

    protected function displayChangeSummary(array $summary, bool $dryRun): void
    {
        $this->info("Found {$summary['total_files']} changed files:");

        $this->table(
            ['Type', 'Count'],
            collect($summary['by_type'])->map(fn ($count, $type) => [$type, $count])->values()
        );

        if (! empty($summary['affected_modules'])) {
            $this->info('Affected modules: '.implode(', ', $summary['affected_modules']));
        }
    }

    protected function displayDryRunDetails(Collection $changedFiles, Collection $deletedFiles): void
    {
        $this->info("\nDry run mode - no changes will be made.");

        if ($changedFiles->isNotEmpty()) {
            $this->info("\nFiles that would be updated:");

            foreach ($changedFiles as $file) {
                $this->line('  - '.$this->diffService->get_relative_path($file));
            }
        }

        if ($deletedFiles->isNotEmpty()) {
            $this->info("\nFiles that would be removed:");

            foreach ($deletedFiles as $file) {
                $this->line('  - '.$this->diffService->get_relative_path($file));
            }
        }
    }

    protected function processNewFiles(Collection $newFiles, string $model): void
    {
        if ($newFiles->isEmpty()) {
            return;
        }

        $this->info("\n📄 Processing {$newFiles->count()} new files...");
        $bar = $this->output->createProgressBar($newFiles->count());

        foreach ($newFiles as $file) {
            // Queue documentation generation for all tiers
            GenerateAiDocumentationForFileJob::dispatch($file, 'all', $model);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    protected function processChangedFiles(Collection $changedFiles, string $fromSha, string $currentSha, string $model): Collection
    {
        if ($changedFiles->isEmpty()) {
            return collect();
        }

        $this->info("\n🔄 Processing {$changedFiles->count()} changed files...");
        $bar = $this->output->createProgressBar($changedFiles->count());

        $affectedModules = collect();

        foreach ($changedFiles as $file) {
            // Update documentation
            UpdateDocumentationForFileJob::dispatch($file, $fromSha, $currentSha, $model);

            // Find module and mark file as undocumented
            $module = $this->moduleService->get_module_for_file($file);

            if ($module) {
                $this->metadataService->moveFileToUndocumented($module, $file);
                $affectedModules->push($module);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $affectedModules->unique();
    }

    protected function processDeletedFiles(Collection $deletedFiles): void
    {
        if ($deletedFiles->isEmpty()) {
            return;
        }

        $this->info("\n🗑️  Processing {$deletedFiles->count()} deleted files...");

        foreach ($deletedFiles as $file) {
            $module = $this->moduleService->get_module_for_file($file);

            if ($module) {
                $this->metadataService->removeFiles($module, [$file]);
            }
        }
    }

    protected function processUnassignedFiles(string $model): void
    {
        $this->info("\n🔍 Checking for unassigned files...");

        $analysis = $this->assignmentService->analyze_module_assignments();
        $unassignedCount = count($analysis['unassigned_files']);

        if ($unassignedCount === 0) {
            $this->info('All files are assigned to modules.');

            return;
        }

        $this->info("Found {$unassignedCount} unassigned files.");

        $limit = (int) $this->option('limit');

        if ($limit > 0 && $unassignedCount > $limit) {
            $this->info("Limiting assignment to {$limit} files.");
        }

        // Run the assignment command with auto-create and force
        $this->call('cascadedocs:assign-files-to-modules', [
            '--model' => $model,
            '--limit' => $limit,
            '--auto-create' => true,
            '--force' => true,  // Skip confirmation prompt
        ]);
    }

    protected function updateAffectedModules(Collection $affectedModules, string $currentSha, string $model): void
    {
        // Get all modules with undocumented files
        $modulesToUpdate = collect();

        $allModules = $this->metadataService->getAllModuleSlugs();

        foreach ($allModules as $slug) {
            $metadata = $this->metadataService->loadMetadata($slug);

            if ($metadata && ! empty($metadata['undocumented_files'])) {
                $modulesToUpdate->push($slug);
            }
        }

        // Include explicitly affected modules
        $modulesToUpdate = $modulesToUpdate->merge($affectedModules)->unique();

        if ($modulesToUpdate->isEmpty()) {
            $this->info("\n✅ No module documentation updates needed.");

            return;
        }

        $this->info("\n📚 Updating {$modulesToUpdate->count()} module documentation...");
        $bar = $this->output->createProgressBar($modulesToUpdate->count());

        foreach ($modulesToUpdate as $module) {
            UpdateModuleDocumentationJob::dispatch($module, $currentSha, $model)
                ->onQueue('module_updates');
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }
}
