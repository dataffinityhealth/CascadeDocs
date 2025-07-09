<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;

class CreateModuleCommand extends Command
{
    protected $signature = 'cascadedocs:create-module 
        {name : The module slug name}
        {--files=* : Specific files to include}
        {--from-suggestion : Use files from a suggested module}
        {--title= : Module title (defaults to formatted name)}
        {--description= : Module description}';

    protected $description = 'Create a new documentation module';

    public function handle(): int
    {
        $module_slug = Str::slug($this->argument('name'));
        $module_path = base_path("docs/source_documents/modules/{$module_slug}.md");

        if (File::exists($module_path)) {
            $this->error("Module {$module_slug} already exists!");

            return 1;
        }

        $files = collect($this->option('files'));

        // If using suggestion, get files from the module assignment log
        if ($this->option('from-suggestion')) {
            $assignment_service = new ModuleAssignmentService;
            $analysis = $assignment_service->load_log();

            $suggested_files = $this->get_files_from_suggestion($module_slug, $analysis);

            if ($suggested_files) {
                $files = $files->merge($suggested_files);
            }
        }

        if ($files->isEmpty()) {
            $this->warn('No files specified for the module.');

            if (! $this->confirm('Create empty module?')) {
                return 0;
            }
        }

        $title = $this->option('title') ?? $this->format_title($module_slug);
        $description = $this->option('description') ?? $this->ask('Enter module description');

        // Create the module file
        $content = $this->generate_module_content($module_slug, $title, $description, $files);

        File::put($module_path, $content);

        $this->info("Module created at: {$module_path}");
        $this->info("Added {$files->count()} files to the module.");

        // Update module assignment analysis
        $this->info('Updating module assignments...');
        $assignment_service = new ModuleAssignmentService;
        $assignment_service->analyze_module_assignments();

        $this->newLine();
        $this->info('Next steps:');
        $this->line('1. Review and edit the module file as needed');
        $this->line('2. Run php artisan cascadedocs:update-documentation to generate module documentation');

        return 0;
    }

    protected function get_files_from_suggestion(string $module_slug, array $analysis): ?array
    {
        foreach ($analysis['potential_modules'] as $key => $module_info) {
            if ($module_info['suggested_name'] === $module_slug) {
                $this->info("Using files from suggested module: {$module_slug}");

                return $module_info['files'];
            }
        }

        $this->warn("No suggestion found for module: {$module_slug}");

        return null;
    }

    protected function format_title(string $slug): string
    {
        $words = explode('-', $slug);
        $words = array_map('ucfirst', $words);

        return implode(' ', $words);
    }

    protected function generate_module_content(string $slug, string $title, string $description, $files): string
    {
        $current_sha = trim(exec('git rev-parse HEAD'));
        $timestamp = Carbon::now()->toIso8601String();
        $file_count = $files->count();

        $content = <<<EOT
---
doc_version: 1.0
doc_tier: module
module_name: {$title}
module_slug: {$slug}
generated_at: {$timestamp}
git_commit_sha: {$current_sha}
total_files: {$file_count}
---

# {$title} Module

## Overview

{$description}

## How This Module Works

### Core Components

[Describe the main components and their roles]

EOT;

        // Group files by directory for better organization
        $by_directory = $files->groupBy(function ($file) {
            return dirname($file);
        });

        foreach ($by_directory as $directory => $dir_files) {
            $content .= "\n### ".$this->format_directory_name($directory)."\n\n";

            foreach ($dir_files as $file) {
                $basename = basename($file);
                $content .= "- **`{$file}`** - [Description needed]. This [component/service/job] ...\n\n";
            }
        }

        $content .= <<<'EOT'

## Key Workflows Explained

### [Workflow Name]

[Describe the workflow]

1. **Step 1**: Description
2. **Step 2**: Description
3. **Step 3**: Description

## Dependencies & Integration Points

- **Internal Dependencies**: [List key dependencies within the system]
- **External Services**: [List any external services or APIs]
- **Database Tables**: [List primary database tables used]

## Security Considerations

[Describe any security considerations for this module]

## Performance Notes

[Describe any performance considerations]

## Testing Approach

[Describe how this module should be tested]
EOT;

        return $content;
    }

    protected function format_directory_name(string $directory): string
    {
        $parts = explode('/', $directory);
        $meaningful_parts = [];

        foreach ($parts as $part) {
            if (! in_array($part, ['app', 'resources', 'js', 'src'])) {
                $meaningful_parts[] = ucfirst($part);
            }
        }

        return implode(' / ', $meaningful_parts) ?: 'Core Files';
    }
}
