<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentAIService;

class AnalyzeModuleAssignmentsCommand extends Command
{
    protected $signature = 'documentation:analyze-modules 
        {--report : Show detailed report}
        {--suggest : Show module suggestions}
        {--update : Update the analysis log}';

    protected $description = 'Analyze module assignments using AI to create logical module groupings';

    public function handle(): int
    {
        $service = new ModuleAssignmentAIService;

        if ($this->option('update')) {
            $this->info('Analyzing module assignments using AI...');
            $this->warn('This may take a few minutes as the AI analyzes your codebase...');

            $analysis = $service->analyze_module_assignments();
            $this->info('Analysis complete and saved to module-assignment-log.json');

            if (isset($analysis['ai_created_modules'])) {
                $this->info('Created '.count($analysis['ai_created_modules']).' modules using AI analysis.');
            }
        } else {
            $analysis = $service->load_log();

            if (! $analysis['last_analysis']) {
                $this->warn('No analysis found. Run with --update to generate analysis.');

                return 1;
            }
        }

        // Show summary
        $this->newLine();
        $this->info('Module Assignment Summary');
        $this->info('========================');

        $total_assigned = array_sum(array_map('count', $analysis['assigned_files']));
        $total_unassigned = count($analysis['unassigned_files']);
        $total_files = $total_assigned + $total_unassigned;

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total documented files', $total_files],
                ['Files assigned to modules', $total_assigned],
                ['Files without modules', $total_unassigned],
                ['Existing modules', count($analysis['assigned_files'])],
                ['Potential new modules', count($analysis['potential_modules'])],
            ]
        );

        if ($this->option('report')) {
            $this->show_detailed_report($analysis);
        }

        if ($this->option('suggest')) {
            $this->show_module_suggestions($analysis);
        }

        if ($total_unassigned > 0 && ! $this->option('report') && ! $this->option('suggest')) {
            $this->newLine();
            $this->warn("Found {$total_unassigned} files without module assignments.");
            $this->info('Run with --report to see details or --suggest to see module suggestions.');
        }

        return 0;
    }

    protected function show_detailed_report(array $analysis): void
    {
        $this->newLine();
        $this->info('Files by Module');
        $this->info('===============');

        foreach ($analysis['assigned_files'] as $module => $files) {
            $this->info("\n{$module} (".count($files).' files):');

            foreach ($files as $file) {
                $this->line("  - {$file}");
            }
        }

        if (! empty($analysis['unassigned_files'])) {
            $this->newLine();
            $this->warn('Unassigned Files');
            $this->warn('================');

            // Group by directory for easier reading
            $by_directory = collect($analysis['unassigned_files'])->groupBy(function ($file) {
                return dirname($file);
            });

            foreach ($by_directory as $directory => $files) {
                $this->info("\n{$directory}:");

                foreach ($files as $file) {
                    $this->line('  - '.basename($file));
                }
            }
        }
    }

    protected function show_module_suggestions(array $analysis): void
    {
        if (empty($analysis['module_suggestions'])) {
            $this->info('No module suggestions available.');

            return;
        }

        $this->newLine();
        $this->info('Module Creation Suggestions');
        $this->info('===========================');

        foreach ($analysis['module_suggestions'] as $index => $suggestion) {
            $this->newLine();
            $this->info(($index + 1).'. Suggested module: '.$suggestion['suggested_name']);
            $this->line('   Files: '.$suggestion['file_count']);
            $this->line('   Confidence: '.round($suggestion['confidence'] * 100).'%');
            $this->line('   Reason: '.$suggestion['reason']);

            // Show files if high confidence
            if ($suggestion['confidence'] > 0.7 && isset($analysis['potential_modules'])) {
                foreach ($analysis['potential_modules'] as $key => $module_info) {
                    if ($module_info['suggested_name'] === $suggestion['suggested_name']) {
                        $this->line('   Files to include:');

                        foreach (array_slice($module_info['files'], 0, 5) as $file) {
                            $this->line('     - '.$file);
                        }

                        if (count($module_info['files']) > 5) {
                            $this->line('     ... and '.(count($module_info['files']) - 5).' more');
                        }

                        break;
                    }
                }
            }
        }

        $this->newLine();
        $this->info('To assign additional files to modules:');
        $this->line('1. Run php artisan documentation:assign-files-to-modules');
        $this->line('2. Or manually edit the module metadata files');
        $this->line('3. Run php artisan documentation:update-all-modules to regenerate documentation');
    }
}
