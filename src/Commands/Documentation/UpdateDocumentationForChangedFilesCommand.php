<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateDocumentationForFileJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;

class UpdateDocumentationForChangedFilesCommand extends Command
{
    protected $signature = 'update:documentation 
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

        // Load the update log
        $update_log = $this->diff_service->load_update_log();

        // Determine the starting SHA
        $from_sha = $this->option('since') ?? $update_log['last_update_sha'];

        if (! $from_sha) {
            $this->error('No previous update SHA found in documentation-update-log.json');
            $this->error('The log file should be initialized with a last_update_sha.');
            $this->error('You can also use --since=<commit-sha> to specify a starting point.');

            return 1;
        }

        $current_sha = $this->diff_service->get_current_commit_sha();

        if ($from_sha === $current_sha) {
            $this->info('Documentation is already up to date with commit: '.$current_sha);

            return 0;
        }

        $this->info("Checking for changes from {$from_sha} to {$current_sha}");

        // Get all changed files
        $changed_files = $this->diff_service->get_changed_files($from_sha, $current_sha);
        $new_files = $this->diff_service->get_new_files($from_sha, $current_sha);
        $deleted_files = $this->diff_service->get_deleted_files($from_sha, $current_sha);

        if ($changed_files->isEmpty() && $new_files->isEmpty() && $deleted_files->isEmpty()) {
            $this->info('No documentable files have changed.');

            return 0;
        }

        // Analyze changes
        $all_changed = $changed_files->merge($new_files)->unique();
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

            foreach ($all_changed as $file) {
                $this->line('  - '.$this->diff_service->get_relative_path($file));
            }

            return 0;
        }

        // Process each changed file
        $this->info("\nDispatching documentation update jobs...");

        $files_to_update = collect();
        $modules_to_update = collect();

        foreach ($all_changed as $file) {
            if ($this->diff_service->needs_documentation_update($file, $update_log)) {
                $files_to_update->push($file);

                // Track which modules need updating
                $module = $this->module_service->get_module_for_file($file);

                if ($module && ! $modules_to_update->contains($module)) {
                    $modules_to_update->push($module);
                }
            }
        }

        // Dispatch file update jobs
        $bar = $this->output->createProgressBar($files_to_update->count());
        $bar->setFormat('Dispatching file jobs: %current%/%max% [%bar%] %percent:3s%%');

        foreach ($files_to_update as $file) {
            UpdateDocumentationForFileJob::dispatch(
                $file,
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

        // Update the log with the new SHA and timestamp
        $update_log['last_update_sha'] = $current_sha;
        $update_log['last_update_timestamp'] = Carbon::now()->toIso8601String();
        $this->diff_service->save_update_log($update_log);

        $this->info("\nDocumentation update jobs have been queued.");
        $this->info("Updated log with SHA: {$current_sha}");
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
                $this->info('Run php artisan documentation:analyze-modules --suggest to see suggestions.');
            }
        }

        return 0;
    }
}
