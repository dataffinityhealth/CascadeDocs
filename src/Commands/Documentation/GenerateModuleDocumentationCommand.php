<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateModuleDocumentationCommand extends Command
{
    protected $signature = 'cascadedocs:generate-module-docs 
        {--model= : The AI model to use for generation}
        {--fresh : Force fresh analysis even if module-assignment-log.json exists}';

    protected $description = 'Generate module documentation by organizing files and creating module overviews';

    public function handle()
    {
        $this->info('Starting module documentation generation...');

        $model = $this->option('model') ?? config('cascadedocs.ai.default_model');

        // Step 1: Analyze module assignments using AI (only if not already done)
        $moduleAssignmentLogPath = base_path(config('cascadedocs.paths.tracking.module_assignment'));

        if ($this->option('fresh') || ! File::exists($moduleAssignmentLogPath)) {
            $this->info('Step 1: Analyzing module assignments using AI...');
            if ($this->option('fresh')) {
                $this->warn('Running fresh analysis as requested...');
            }
            $this->call('documentation:analyze-modules', ['--update' => true]);
        } else {
            $this->info('Step 1: Module assignment analysis already exists, skipping...');
            $log = json_decode(File::get($moduleAssignmentLogPath), true);
            $assignedCount = array_sum(array_map('count', $log['assigned_files'] ?? []));
            $unassignedCount = count($log['unassigned_files'] ?? []);
            $this->info("Found {$assignedCount} assigned files and {$unassignedCount} unassigned files.");
        }

        // Step 2: Assign files to modules
        $this->info("\nStep 2: Assigning files to modules...");
        $this->call('documentation:assign-files-to-modules', [
            '--force' => true,
            '--model' => $model,
            '--auto-create' => true,
        ]);

        // Step 3: Sync module assignments
        $this->info("\nStep 3: Syncing module assignments...");
        $this->call('documentation:sync-module-assignments');

        // Step 4: Update all module documentation
        $this->info("\nStep 4: Generating module documentation...");
        $this->call('documentation:update-all-modules', [
            '--model' => $model,
            '--force' => true, // Skip confirmation in automated flow
        ]);

        // Step 5: Generate module index
        $this->info("\nStep 5: Generating module index...");
        $this->call('cascadedocs:generate-module-index');

        // Step 6: Show module status
        $this->info("\nStep 6: Module documentation status:");
        $this->call('documentation:module-status');

        $this->newLine();
        $this->info('✓ Module documentation generation complete!');

        return 0;
    }
}
