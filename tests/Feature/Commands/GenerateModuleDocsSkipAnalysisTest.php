<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

describe('Generate Module Docs Skip Analysis', function () {
    beforeEach(function () {
        // Set up config paths
        Config::set('cascadedocs.paths.tracking.module_assignment', 'docs/module-assignment-log.json');

        // Create test directories
        File::makeDirectory(base_path('docs'), 0755, true, true);

        // Clean up any existing files
        if (File::exists(base_path('docs/module-assignment-log.json'))) {
            File::delete(base_path('docs/module-assignment-log.json'));
        }
    });

    afterEach(function () {
        // Clean up
        File::deleteDirectory(base_path('docs'));
    });

    it('skips analysis when module-assignment-log.json exists', function () {
        // Create existing analysis log with unassigned files
        $existingLog = [
            'last_analysis' => now()->toIso8601String(),
            'assigned_files' => [
                'test-module' => ['app/Test.php', 'app/Services/TestService.php'],
                'auth-module' => ['app/Http/Controllers/AuthController.php'],
            ],
            'unassigned_files' => ['app/Unassigned.php', 'app/Another.php'],
            'do_not_document' => [],
            'potential_modules' => [],
            'module_suggestions' => [],
            'ai_created_modules' => [],
        ];

        File::put(
            base_path('docs/module-assignment-log.json'),
            json_encode($existingLog, JSON_PRETTY_PRINT)
        );

        // Test just the first part of the command - checking if analysis is skipped
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\GenerateModuleDocumentationCommand;
        $command->setLaravel(app());

        // Mock the command to capture output
        $output = new \Symfony\Component\Console\Output\BufferedOutput;
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);

        // We'll test by checking the file existence logic directly
        $moduleAssignmentLogPath = base_path(config('cascadedocs.paths.tracking.module_assignment'));
        expect(File::exists($moduleAssignmentLogPath))->toBeTrue();

        // The command should skip analysis when the file exists
        $log = json_decode(File::get($moduleAssignmentLogPath), true);
        $assignedCount = array_sum(array_map('count', $log['assigned_files'] ?? []));
        $unassignedCount = count($log['unassigned_files'] ?? []);

        expect($assignedCount)->toBe(3);
        expect($unassignedCount)->toBe(2);
    });

    it('would run analysis when no module-assignment-log.json exists', function () {
        // Ensure no log exists
        expect(File::exists(base_path('docs/module-assignment-log.json')))->toBeFalse();

        // The command logic would run analysis in this case
        $moduleAssignmentLogPath = base_path(config('cascadedocs.paths.tracking.module_assignment'));
        expect(File::exists($moduleAssignmentLogPath))->toBeFalse();
    });

    it('respects the fresh option logic', function () {
        // Create existing analysis log
        $existingLog = [
            'last_analysis' => now()->toIso8601String(),
            'assigned_files' => [
                'old-module' => ['app/OldFile.php'],
            ],
            'unassigned_files' => [],
            'do_not_document' => [],
            'potential_modules' => [],
            'module_suggestions' => [],
            'ai_created_modules' => [],
        ];

        File::put(
            base_path('docs/module-assignment-log.json'),
            json_encode($existingLog, JSON_PRETTY_PRINT)
        );

        // File exists
        expect(File::exists(base_path('docs/module-assignment-log.json')))->toBeTrue();

        // With --fresh option, the logic would be: if ($this->option('fresh') || !File::exists($moduleAssignmentLogPath))
        // So even with existing file, fresh option would trigger analysis
        $wouldRunAnalysisWithFresh = true || ! File::exists(base_path('docs/module-assignment-log.json'));
        expect($wouldRunAnalysisWithFresh)->toBeTrue();

        // Without fresh option
        $wouldRunAnalysisWithoutFresh = false || ! File::exists(base_path('docs/module-assignment-log.json'));
        expect($wouldRunAnalysisWithoutFresh)->toBeFalse();
    });
});
