<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateDocumentationForFileJob;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;
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

        $dryRun = $this->option('dry-run');
        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');
        $logPath = config('cascadedocs.paths.tracking.documentation_update', 'docs/documentation-update-log.json');
        $logFilename = basename($logPath);

        $updateLog = $this->diff_service->load_update_log();
        $fromSha = $this->option('since') ?? $updateLog['last_update_sha'];

        if (! $fromSha) {
            $this->info("No previous update SHA found in {$logFilename}");
            $this->info('The log file should be initialized with a last_update_sha.');
            $this->info('You can also use --since=<commit-sha> to specify a starting point.');

            return 1;
        }

        $currentSha = $this->diff_service->get_current_commit_sha();

        if ($fromSha === $currentSha) {
            $this->info("Documentation is already up to date with commit: {$currentSha}");

            return 0;
        }

        $this->info("Checking for changes from {$fromSha} to {$currentSha}");

        $changedFiles = $this->diff_service->get_changed_files($fromSha, $currentSha);
        $newFiles = $this->diff_service->get_new_files($fromSha, $currentSha);
        $deletedFiles = $this->diff_service->get_deleted_files($fromSha, $currentSha);

        $documentableFiles = $changedFiles->merge($newFiles)->unique();

        if ($documentableFiles->isEmpty()) {
            $this->info('No documentable files have changed.');

            return 0;
        }

        $summary = $this->diff_service->analyze_changes_for_summary($documentableFiles);

        $this->info("Found {$summary['total_files']} changed files:");
        $this->table(
            ['Type', 'Count'],
            collect($summary['by_type'])->map(fn ($count, $type) => [$type, $count])->values()
        );

        if ($dryRun) {
            $this->info('Dry run mode - no changes will be made.');
            $this->newLine();
            $this->info('Files that would be updated:');

            foreach ($documentableFiles as $file) {
                $this->line(' - '.$this->diff_service->get_relative_path($file));
            }

            return 0;
        }

        $this->info('Dispatching documentation update jobs...');

        $modulesToUpdate = collect();

        $this->dispatchFileJobs($documentableFiles, $fromSha, $currentSha, $model, $modulesToUpdate);

        if ($deletedFiles->isNotEmpty()) {
            $this->removeDeletedFilesFromLog($deletedFiles, $updateLog);
        }

        if ($modulesToUpdate->isNotEmpty()) {
            $this->dispatchModuleJobs($modulesToUpdate, $currentSha, $model);
        }

        $updateLog['last_update_sha'] = $currentSha;
        $updateLog['last_update_timestamp'] = Carbon::now()->toIso8601String();

        $this->diff_service->save_update_log($updateLog);

        $this->info('Documentation update jobs have been queued.');
        $this->info('Updated log with SHA: '.$currentSha);

        return 0;
    }

    protected function dispatchFileJobs(Collection $files, string $fromSha, string $currentSha, string $model, Collection $modulesToUpdate): void
    {
        foreach ($files as $file) {
            UpdateDocumentationForFileJob::dispatch(
                $file,
                $fromSha,
                $currentSha,
                $model
            );

            $module = $this->module_service->get_module_for_file($file);

            if ($module && ! $modulesToUpdate->contains($module)) {
                $modulesToUpdate->push($module);
            }
        }
    }

    protected function dispatchModuleJobs(Collection $modules, string $currentSha, string $model): void
    {
        $this->info("Dispatching module update jobs for {$modules->count()} modules...");

        foreach ($modules as $module) {
            if (config('queue.default') !== 'sync') {
                UpdateModuleDocumentationJob::dispatch($module, $currentSha, $model)->delay(now()->addMinutes(5));
            } else {
                UpdateModuleDocumentationJob::dispatch($module, $currentSha, $model);
            }
        }
    }

    protected function removeDeletedFilesFromLog(Collection $deletedFiles, array &$updateLog): void
    {
        foreach ($deletedFiles as $file) {
            $relativePath = $this->diff_service->get_relative_path($file);
            unset($updateLog['files'][$relativePath]);
        }
    }
}
