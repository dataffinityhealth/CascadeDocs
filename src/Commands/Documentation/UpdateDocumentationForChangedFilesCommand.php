<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateDocumentationForFileJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;

class UpdateDocumentationForChangedFilesCommand extends Command
{
    protected $signature = 'cascadedocs:update-documentation 
        {--since= : Specific commit SHA to update from}
        {--dry-run : Show what would be updated without making changes}
        {--model= : The AI model to use for generation}';

    protected $description = 'Update documentation for files that have changed since the last update';

    protected DocumentationDiffService $diff_service;

    protected ModuleMappingService $module_service;

    public function __construct()
    {
        parent::__construct();
        $this->diff_service = new DocumentationDiffService;
        $this->module_service = new ModuleMappingService;
    }

    public function handle(): int
    {
        $this->info('Starting documentation update process...');
        $dry_run = $this->option('dry-run');
        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');

        // Use smart detection to find files needing updates
        $this->info('Scanning for files needing documentation updates...');
        $files_to_update = $this->diff_service->get_files_needing_update();

        if ($files_to_update->isEmpty()) {
            $this->info('✓ All documentation is up to date!');

            return 0;
        }

        // Get current SHA for tracking
        $current_sha = $this->diff_service->get_current_commit_sha();

        // Analyze changes
        $all_changed = $files_to_update->pluck('path');
        $summary = $this->diff_service->analyze_changes_for_summary($all_changed);

        $this->info("Found {$summary['total_files']} changed files:");
        $this->table(['Type', 'Count'], collect($summary['by_type'])->map(function ($count, $type) {
            return [$type, $count];
        })->values());

        if (! empty($summary['affected_modules'])) {
            $this->info('Affected modules: '.implode(', ', $summary['affected_modules']));
        }

        if ($dry_run) {
            $this->info('Dry run mode - no changes will be made.');
            $this->info("\nFiles that would be updated:");

            foreach ($files_to_update as $fileInfo) {
                $status = $fileInfo['documented_sha'] ? 'outdated' : 'new';
                $this->line('  - '.$fileInfo['relative_path'].' ('.$status.')');
            }

            return 0;
        }

        // Process each changed file
        $this->info("\nDispatching documentation update jobs...");

        $modules_to_update = collect();

        // Track which modules need updating
        foreach ($files_to_update as $fileInfo) {
            $module = $this->module_service->get_module_for_file($fileInfo['path']);

            if ($module && ! $modules_to_update->contains($module)) {
                $modules_to_update->push($module);
            }
        }

        // Dispatch file update jobs
        $bar = $this->output->createProgressBar($files_to_update->count());
        $bar->setFormat('Dispatching file jobs: %current%/%max% [%bar%] %percent:3s%%');

        foreach ($files_to_update as $fileInfo) {
            // Pass the file's previous SHA if it exists
            $from_sha = $fileInfo['documented_sha'] ?? $current_sha;

            UpdateDocumentationForFileJob::dispatch(
                $fileInfo['path'],
                $from_sha,
                $current_sha,
                $model
            );
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Dispatch module update jobs (with delay to ensure file updates complete first)
        if ($modules_to_update->isNotEmpty()) {
            $this->info("Dispatching module update jobs for {$modules_to_update->count()} modules...");

            foreach ($modules_to_update as $module) {
                if (config('queue.default') !== 'sync') {
                    UpdateModuleDocumentationJob::dispatch($module, $current_sha, $model)->delay(now()->addMinutes(5));
                } else {
                    UpdateModuleDocumentationJob::dispatch($module, $current_sha, $model);
                }
            }
        }

        $this->info("\nDocumentation update jobs have been queued.");
        $this->info('Check your queue worker for processing status.');

        // Run module assignment analysis if we processed files
        if ($files_to_update->isNotEmpty() && ! $dry_run) {
            $this->newLine();
            $this->info('Analyzing module assignments...');

            $assignment_service = new ModuleAssignmentService;
            $analysis = $assignment_service->analyze_module_assignments();

            $total_unassigned = count($analysis['unassigned_files']);

            if ($total_unassigned > 0) {
                $this->warn("Found {$total_unassigned} files without module assignments.");
                $this->info('Run php artisan cascadedocs:analyze-modules --suggest to see suggestions.');
            }
        }

        return 0;
    }
}
