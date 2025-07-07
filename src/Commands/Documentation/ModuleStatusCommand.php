<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Lumiio\CascadeDocs\Services\Documentation\DocumentationParser;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ModuleStatusCommand extends Command
{
    protected $signature = 'documentation:module-status
        {--module= : Show status for specific module}
        {--unassigned : Show only unassigned files}
        {--suggestions : Show module suggestions}
        {--summary : Show summary statistics only}';
    protected $description = 'Display current module assignment status and statistics';
    protected ModuleAssignmentService $assignmentService;
    protected DocumentationParser $parser;

    public function __construct()
    {
        parent::__construct();
        $this->assignmentService = new ModuleAssignmentService();
        $this->parser            = new DocumentationParser();
    }

    public function handle(): int
    {
        if ($this->option('module'))
        {
            return $this->showModuleDetails($this->option('module'));
        }

        if ($this->option('unassigned'))
        {
            return $this->showUnassignedFiles();
        }

        if ($this->option('suggestions'))
        {
            return $this->showModuleSuggestions();
        }

        return $this->showOverallStatus();
    }

    protected function showOverallStatus(): int
    {
        $this->info('Module Assignment Status');
        $this->info('========================');

        $log = $this->assignmentService->load_log();

        // Overall statistics
        $totalAssigned   = collect($log['assigned_files'])->flatten()->count();
        $totalUnassigned = count($log['unassigned_files']);
        $totalFiles      = $totalAssigned + $totalUnassigned;
        $moduleCount     = count($log['assigned_files']);

        $this->newLine();
        $this->line("Total documented files: {$totalFiles}");
        $this->line("Assigned to modules: {$totalAssigned} (" . $this->percentage($totalAssigned, $totalFiles) . '%)');
        $this->line("Unassigned files: {$totalUnassigned} (" . $this->percentage($totalUnassigned, $totalFiles) . '%)');
        $this->line("Total modules: {$moduleCount}");

        if (! empty($log['last_analysis']))
        {
            $this->line("Last analysis: {$log['last_analysis']}");
        }

        if (! $this->option('summary'))
        {
            // Module breakdown
            $this->newLine();
            $this->info('Module File Counts:');
            $this->table(
                ['Module', 'Files', 'Percentage'],
                $this->getModuleStats($log['assigned_files'], $totalFiles)
            );

            // Unassigned breakdown by directory
            if ($totalUnassigned > 0)
            {
                $this->newLine();
                $this->warn('Unassigned Files by Directory:');
                $byDirectory = collect($log['unassigned_files'])->groupBy(function ($file)
                {
                    return dirname($file);
                })->map->count()->sortDesc();

                $this->table(
                    ['Directory', 'Count'],
                    $byDirectory->map(function ($count, $dir)
                    {
                        return [$dir, $count];
                    })->values()
                );
            }

            // Show potential modules
            if (! empty($log['potential_modules']))
            {
                $this->newLine();
                $this->info('Potential New Modules:');

                foreach ($log['potential_modules'] as $key => $module)
                {
                    $this->line("- {$module['suggested_name']} ({$module['file_count']} files)");
                }
            }
        }

        return 0;
    }

    protected function showModuleDetails(string $moduleSlug): int
    {
        $modulePath = base_path("docs/source_documents/modules/{$moduleSlug}.md");

        if (! File::exists($modulePath))
        {
            $this->error("Module '{$moduleSlug}' not found.");

            return 1;
        }

        $this->info("Module: {$moduleSlug}");
        $this->info(str_repeat('=', strlen("Module: {$moduleSlug}")));

        // Get module metadata
        $metadata = $this->parser->extractModuleMetadata($modulePath);

        if (! empty($metadata))
        {
            $this->newLine();
            $this->info('Metadata:');

            foreach ($metadata as $key => $value)
            {
                $this->line("  {$key}: {$value}");
            }
        }

        // Get assigned files
        $log           = $this->assignmentService->load_log();
        $assignedFiles = $log['assigned_files'][$moduleSlug] ?? [];

        $this->newLine();
        $this->info("Assigned Files ({count({$assignedFiles})}):");

        foreach ($assignedFiles as $file)
        {
            $hasDoc = $this->parser->hasShortDocumentation($file) ? '✓' : '✗';
            $this->line("  [{$hasDoc}] {$file}");
        }

        // Show module summary
        try
        {
            $summary = $this->parser->extractModuleSummary($modulePath);

            if ($summary)
            {
                $this->newLine();
                $this->info('Module Overview:');
                $this->line($summary);
            }
        } catch (Exception $e)
        {
            $this->warn('Could not extract module overview: ' . $e->getMessage());
        }

        return 0;
    }

    protected function showUnassignedFiles(): int
    {
        $log             = $this->assignmentService->load_log();
        $unassignedFiles = collect($log['unassigned_files']);

        if ($unassignedFiles->isEmpty())
        {
            $this->info('✓ No unassigned files found!');

            return 0;
        }

        $this->warn("Unassigned Files ({$unassignedFiles->count()})");
        $this->warn(str_repeat('=', 20));

        // Group by directory
        $byDirectory = $unassignedFiles->groupBy(function ($file)
        {
            return dirname($file);
        });

        foreach ($byDirectory as $directory => $files)
        {
            $this->newLine();
            $this->info("Directory: {$directory}");

            foreach ($files as $file)
            {
                $hasDoc = $this->parser->hasShortDocumentation($file) ? '✓' : '✗';
                $this->line("  [{$hasDoc}] " . basename($file));
            }
        }

        $this->newLine();
        $this->line('Legend: [✓] Has short documentation, [✗] Missing short documentation');

        return 0;
    }

    protected function showModuleSuggestions(): int
    {
        $log = $this->assignmentService->load_log();

        if (empty($log['module_suggestions']))
        {
            $this->info('No module suggestions available. Run analyze-modules first.');

            return 0;
        }

        $this->info('Module Suggestions');
        $this->info('==================');

        foreach ($log['module_suggestions'] as $suggestion)
        {
            $this->newLine();
            $this->line("Suggested Module: {$suggestion['suggested_name']}");
            $this->line("File Count: {$suggestion['file_count']}");
            $this->line('Confidence: ' . ($suggestion['confidence'] * 100) . '%');
            $this->line("Reason: {$suggestion['reason']}");
        }

        $this->newLine();
        $this->info('To create a suggested module, run:');
        $this->line('php artisan documentation:create-module <name> --from-suggestion');

        return 0;
    }

    protected function getModuleStats(array $assignedFiles, int $totalFiles): array
    {
        $stats = [];

        foreach ($assignedFiles as $module => $files)
        {
            $count   = count($files);
            $stats[] = [
                $module,
                $count,
                $this->percentage($count, $totalFiles) . '%',
            ];
        }

        // Sort by file count descending
        usort($stats, function ($a, $b)
        {
            return $b[1] <=> $a[1];
        });

        return $stats;
    }

    protected function percentage(int $part, int $total): string
    {
        if ($total === 0)
        {
            return '0';
        }

        return number_format(($part / $total) * 100, 1);
    }
}
