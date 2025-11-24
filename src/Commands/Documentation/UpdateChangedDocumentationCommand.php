<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;

class UpdateChangedDocumentationCommand extends Command
{
    protected $signature = 'cascadedocs:update-changed 
        {--model= : The AI model to use for generation}
        {--dry-run : Show what would be updated without making changes}
        {--auto-commit : Automatically commit documentation changes}
        {--from-sha= : Git SHA to compare from (defaults to last documented SHA)}
        {--to-sha=HEAD : Git SHA to compare to}';

    protected $description = 'Update documentation for all changed files, modules, and architecture';

    public function handle()
    {
        $this->info('CascadeDocs - Intelligent Documentation Update System');
        $this->info('='.str_repeat('=', 50));

        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');
        $dryRun = $this->option('dry-run');

        $logPath = base_path(
            config('cascadedocs.paths.logs', 'docs/').'documentation-update-log.json'
        );

        $fromSha = $this->resolveFromSha($logPath);
        $toSha = $this->option('to-sha') ?? 'HEAD';

        $this->newLine();
        $this->info('Step 1: Detecting changes...');
        $this->info('Comparing from: '.$fromSha);
        $this->info('Comparing to: '.$toSha);

        $diffResult = Process::run("git diff --name-only {$fromSha} {$toSha}");

        if (! $diffResult->successful()) {
            $this->error('Failed to detect changes: '.$diffResult->errorOutput());

            return 1;
        }

        $changedPaths = collect(explode("\n", trim($diffResult->output())))->filter();

        if ($changedPaths->isEmpty()) {
            $this->info('No changes detected.');

            return 0;
        }

        // Step 2: Detect files needing documentation updates
        $this->newLine();
        $this->info('Step 2: Detecting files needing documentation updates...');

        $diffService = new DocumentationDiffService;
        $filesToUpdate = $diffService->get_files_needing_update();

        if ($filesToUpdate->isEmpty()) {
            $this->info('✓ All documentation is up to date!');

            return 0;
        }

        $this->info('Found '.$filesToUpdate->count().' files needing documentation updates:');

        // Group files by update type for better display
        $newFiles = $filesToUpdate->filter(fn ($info) => is_null($info['documented_sha']));
        $changedFiles = $filesToUpdate->filter(fn ($info) => ! is_null($info['documented_sha']));

        if ($newFiles->isNotEmpty()) {
            $this->info("\nNew files without documentation ({$newFiles->count()}):");
            foreach ($newFiles as $fileInfo) {
                $this->line('  - '.$fileInfo['relative_path']);
            }
        }

        if ($changedFiles->isNotEmpty()) {
            $this->info("\nFiles with outdated documentation ({$changedFiles->count()}):");
            foreach ($changedFiles as $fileInfo) {
                $this->line(sprintf(
                    '  - %s (current: %s, documented: %s)',
                    $fileInfo['relative_path'],
                    substr($fileInfo['current_sha'], 0, 8),
                    substr($fileInfo['documented_sha'], 0, 8)
                ));
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run mode - no changes will be made.');

            return 0;
        }

        // Step 3: Update file documentation
        $this->newLine();
        $this->info('Step 3: Updating file documentation...');

        // Get the current commit SHA for tracking
        $currentSha = $diffService->get_current_commit_sha();

        // Use the earliest documented SHA as the "since" point, or current if none exist
        $earliestDocumentedSha = $filesToUpdate
            ->filter(fn ($info) => ! is_null($info['documented_sha']))
            ->pluck('documented_sha')
            ->unique()
            ->sort()
            ->first() ?? $currentSha;

        $this->call('cascadedocs:update-documentation', [
            '--since' => $earliestDocumentedSha,
            '--model' => $model,
        ]);

        // Step 4: Update module documentation
        $this->newLine();
        $this->info('Step 4: Updating module documentation...');

        // First, assign any new files to modules
        $this->call('cascadedocs:assign-files-to-modules', [
            '--model' => $model,
            '--force' => true,
        ]);

        // Then update module documentation
        $this->call('cascadedocs:update-all-modules', [
            '--model' => $model,
        ]);

        // Step 5: Update architecture documentation if significant changes
        $this->newLine();
        $this->info('Step 5: Checking if architecture update is needed...');

        // Check if any new modules were created or if many files changed
        $significantChange = $filesToUpdate->count() > 10 || $this->hasNewModules();

        if ($significantChange) {
            $this->info('Significant changes detected. Updating architecture documentation...');
            $this->call('cascadedocs:generate-architecture-docs', [
                '--model' => $model,
            ]);
        } else {
            $this->info('No significant architectural changes detected.');
        }

        // Step 6: Show summary
        $this->newLine();
        $this->info('Step 6: Documentation Update Summary');
        $this->info('='.str_repeat('=', 40));

        $this->call('cascadedocs:module-status');

        // Step 7: Optionally commit changes
        if ($this->option('auto-commit')) {
            $this->newLine();
            $this->info('Step 7: Committing documentation changes...');

            $result = Process::run('git add docs/');
            if ($result->successful()) {
                $fileCount = $filesToUpdate->count();
                $commitMessage = "docs: Update documentation for {$fileCount} changed files";
                $result = Process::run(['git', 'commit', '-m', $commitMessage]);

                if ($result->successful()) {
                    $this->info('✓ Documentation changes committed.');
                } else {
                    $this->warn('No documentation changes to commit.');
                }
            }
        }

        $this->newLine();
        $this->info('✓ Documentation update complete!');

        return 0;
    }

    protected function resolveFromSha(string $logPath): string
    {
        if ($from = $this->option('from-sha')) {
            return $from;
        }

        if (file_exists($logPath)) {
            $logContents = json_decode(file_get_contents($logPath), true);

            if (is_array($logContents)) {
                if (! empty($logContents['last_update_sha'])) {
                    return $logContents['last_update_sha'];
                }

                if (! empty($logContents['last_git_sha'])) {
                    return $logContents['last_git_sha'];
                }
            }
        }

        $previousCommit = Process::run('git rev-parse HEAD~1');

        if ($previousCommit->successful()) {
            return trim($previousCommit->output());
        }

        return 'HEAD~1';
    }

    protected function hasNewModules(): bool
    {
        // Check if any modules were created in the last run
        $logPath = base_path(config('cascadedocs.paths.logs', 'docs/').'module-assignment-log.json');

        if (! file_exists($logPath)) {
            return false;
        }

        $log = json_decode(file_get_contents($logPath), true);

        // Check if any modules have very recent creation dates
        $recentThreshold = now()->subMinutes(10);

        foreach ($log['modules'] ?? [] as $module) {
            if (isset($module['created_at']) && $module['created_at'] > $recentThreshold) {
                return true;
            }
        }

        return false;
    }
}
