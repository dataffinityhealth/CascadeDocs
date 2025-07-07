<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;

class GenerateModuleDocumentationCommand extends Command
{
    protected $signature = 'cascadedocs:generate-module-docs 
        {--model=o3 : The AI model to use for generation}';
    
    protected $description = 'Generate module documentation by organizing files and creating module overviews';

    public function handle()
    {
        $this->info('Starting module documentation generation...');
        
        $model = $this->option('model');
        
        // Step 1: Create modules if they don't exist
        $this->info('Step 1: Checking for existing modules...');
        $this->call('documentation:module:create', ['--auto' => true]);
        
        // Step 2: Assign files to modules
        $this->info("\nStep 2: Assigning files to modules...");
        $this->call('documentation:module:assign', [
            '--force' => true,
            '--model' => $model
        ]);
        
        // Step 3: Sync module assignments
        $this->info("\nStep 3: Syncing module assignments...");
        $this->call('documentation:module:sync');
        
        // Step 4: Update all module documentation
        $this->info("\nStep 4: Generating module documentation...");
        $this->call('documentation:module:update-all', [
            '--model' => $model
        ]);
        
        // Step 5: Show module status
        $this->info("\nStep 5: Module documentation status:");
        $this->call('documentation:module:status');
        
        $this->newLine();
        $this->info('✓ Module documentation generation complete!');
        
        return 0;
    }
}