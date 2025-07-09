<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class UpdateChangedDocumentationCommand extends Command
{
    protected $signature = 'cascadedocs:update-changed 
        {--from-sha= : Git SHA to compare from (defaults to last documented SHA)}
        {--to-sha=HEAD : Git SHA to compare to}
        {--model= : The AI model to use for generation}
        {--auto-commit : Automatically commit documentation changes}';

    protected $description = 'Update documentation for all changed files, modules, and architecture';

    public function handle()
    {
        $this->info('CascadeDocs - Intelligent Documentation Update System');
        $this->info('='.str_repeat('=', 50));

        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');
        $fromSha = $this->option('from-sha');
        $toSha = $this->option('to-sha') ?? 'HEAD';

        // Step 1: Detect changes
        $this->newLine();
        $this->info('Step 1: Detecting changes...');

        if (! $fromSha) {
            // Try to get the last documented SHA from the update log
            $logPath = base_path(config('cascadedocs.paths.logs', 'docs/').'documentation-update-log.json');
            if (file_exists($logPath)) {
                $log = json_decode(file_get_contents($logPath), true);
                $fromSha = $log['last_git_sha'] ?? null;
            }

            if (! $fromSha) {
                // Get the last commit SHA
                $result = Process::run('git rev-parse HEAD~1');
                $fromSha = trim($result->output());
            }
        }

        $this->info("Comparing from: {$fromSha}");
        $this->info("Comparing to: {$toSha}");

        // Get changed files
        $result = Process::run("git diff --name-only {$fromSha} {$toSha}");
        $changedFiles = array_filter(explode("\n", trim($result->output())));

        if (empty($changedFiles)) {
            $this->info('No changes detected.');

            return 0;
        }

        $this->info('Found '.count($changedFiles).' changed files.');

        // Step 2: Update file documentation
        $this->newLine();
        $this->info('Step 2: Updating file documentation...');

        $this->call('cascadedocs:update-documentation', [
            '--from-sha' => $fromSha,
            '--to-sha' => $toSha,
            '--model' => $model,
        ]);

        // Step 3: Update module documentation
        $this->newLine();
        $this->info('Step 3: Updating module documentation...');

        // First, assign any new files to modules
        $this->call('cascadedocs:assign-files-to-modules', [
            '--model' => $model,
            '--force' => true,
        ]);

        // Then update module documentation
        $this->call('cascadedocs:update-all-modules', [
            '--model' => $model,
        ]);

        // Step 4: Update architecture documentation if significant changes
        $this->newLine();
        $this->info('Step 4: Checking if architecture update is needed...');

        // Check if any new modules were created or if many files changed
        $significantChange = count($changedFiles) > 10 || $this->hasNewModules();

        if ($significantChange) {
            $this->info('Significant changes detected. Updating architecture documentation...');
            $this->call('cascadedocs:generate-architecture-docs', [
                '--model' => $model,
            ]);
        } else {
            $this->info('No significant architectural changes detected.');
        }

        // Step 5: Show summary
        $this->newLine();
        $this->info('Step 5: Documentation Update Summary');
        $this->info('='.str_repeat('=', 40));

        $this->call('cascadedocs:module-status');

        // Step 6: Optionally commit changes
        if ($this->option('auto-commit')) {
            $this->newLine();
            $this->info('Step 6: Committing documentation changes...');

            $result = Process::run('git add docs/');
            if ($result->successful()) {
                $commitMessage = "docs: Update documentation for changes from {$fromSha} to {$toSha}";
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
