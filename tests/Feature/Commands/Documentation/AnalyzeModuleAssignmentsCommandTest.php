<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Tests\TestCase;

class AnalyzeModuleAssignmentsCommandTest extends TestCase
{
    protected string $testLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testLogPath = 'tests/fixtures/analyze-command';
        Config::set('cascadedocs.paths.tracking.module_assignment', $this->testLogPath.'/assignment.json');
        Config::set('cascadedocs.paths.modules.metadata', $this->testLogPath.'/metadata');

        // Create test directories
        File::ensureDirectoryExists(base_path($this->testLogPath));
        File::ensureDirectoryExists(base_path($this->testLogPath.'/metadata'));
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testLogPath))) {
            File::deleteDirectory(base_path($this->testLogPath));
        }
        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:analyze-modules --help')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_no_analysis_exists(): void
    {
        $this->artisan('cascadedocs:analyze-modules')
            ->expectsOutput('No analysis found. Run with --update to generate analysis.')
            ->assertExitCode(1);
    }

    public function test_it_shows_summary_when_analysis_exists(): void
    {
        // Create a test analysis log
        $analysisLog = [
            'last_analysis' => '2024-01-01T12:00:00+00:00',
            'assigned_files' => [
                'authentication' => ['app/Models/User.php', 'app/Controllers/AuthController.php'],
                'blog' => ['app/Models/Post.php'],
            ],
            'unassigned_files' => ['app/Helpers/StringHelper.php'],
            'potential_modules' => [],
            'module_suggestions' => [],
        ];

        File::put(
            base_path($this->testLogPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:analyze-modules')
            ->expectsOutput('Module Assignment Summary')
            ->expectsOutput('========================')
            ->assertExitCode(0);
    }

    public function test_it_shows_detailed_report(): void
    {
        // Create a test analysis log
        $analysisLog = [
            'last_analysis' => '2024-01-01T12:00:00+00:00',
            'assigned_files' => [
                'authentication' => ['app/Models/User.php', 'app/Controllers/AuthController.php'],
            ],
            'unassigned_files' => ['app/Helpers/StringHelper.php'],
            'potential_modules' => [],
            'module_suggestions' => [],
        ];

        File::put(
            base_path($this->testLogPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:analyze-modules --report')
            ->expectsOutput('Files by Module')
            ->expectsOutput('===============')
            ->expectsOutput('Unassigned Files')
            ->expectsOutput('================')
            ->assertExitCode(0);
    }

    public function test_it_shows_module_suggestions(): void
    {
        // Create a test analysis log with suggestions
        $analysisLog = [
            'last_analysis' => '2024-01-01T12:00:00+00:00',
            'assigned_files' => [],
            'unassigned_files' => [],
            'potential_modules' => [
                'payment-processing' => [
                    'suggested_name' => 'payment-processing',
                    'files' => ['app/Models/Payment.php', 'app/Services/PaymentService.php'],
                ],
            ],
            'module_suggestions' => [
                [
                    'suggested_name' => 'payment-processing',
                    'file_count' => 2,
                    'confidence' => 0.85,
                    'reason' => 'Files related to payment processing functionality',
                ],
            ],
        ];

        File::put(
            base_path($this->testLogPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:analyze-modules --suggest')
            ->expectsOutput('Module Creation Suggestions')
            ->expectsOutput('===========================')
            ->expectsOutput('1. Suggested module: payment-processing')
            ->expectsOutputToContain('Confidence:')
            ->expectsOutputToContain('Files to include:')
            ->assertExitCode(0);
    }

    public function test_it_shows_no_suggestions_message(): void
    {
        // Create a test analysis log without suggestions
        $analysisLog = [
            'last_analysis' => '2024-01-01T12:00:00+00:00',
            'assigned_files' => [],
            'unassigned_files' => [],
            'potential_modules' => [],
            'module_suggestions' => [],
        ];

        File::put(
            base_path($this->testLogPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:analyze-modules --suggest')
            ->expectsOutput('No module suggestions available.')
            ->assertExitCode(0);
    }

    public function test_it_warns_about_unassigned_files(): void
    {
        // Create a test analysis log with unassigned files
        $analysisLog = [
            'last_analysis' => '2024-01-01T12:00:00+00:00',
            'assigned_files' => [],
            'unassigned_files' => ['app/Helpers/StringHelper.php', 'app/Utils/ArrayHelper.php'],
            'potential_modules' => [],
            'module_suggestions' => [],
        ];

        File::put(
            base_path($this->testLogPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:analyze-modules')
            ->expectsOutput('Found 2 files without module assignments.')
            ->expectsOutput('Run with --report to see details or --suggest to see module suggestions.')
            ->assertExitCode(0);
    }

    public function test_it_displays_summary_table(): void
    {
        // Create a test analysis log
        $analysisLog = [
            'last_analysis' => '2024-01-01T12:00:00+00:00',
            'assigned_files' => [
                'auth' => ['app/Models/User.php'],
                'blog' => ['app/Models/Post.php', 'app/Controllers/PostController.php'],
            ],
            'unassigned_files' => ['app/Helpers/StringHelper.php'],
            'potential_modules' => [
                'potential1' => ['files' => []],
                'potential2' => ['files' => []],
            ],
            'module_suggestions' => [],
        ];

        File::put(
            base_path($this->testLogPath.'/assignment.json'),
            json_encode($analysisLog)
        );

        $this->artisan('cascadedocs:analyze-modules')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Total documented files', 4],
                    ['Files assigned to modules', 3],
                    ['Files without modules', 1],
                    ['Existing modules', 2],
                    ['Potential new modules', 2],
                ]
            )
            ->assertExitCode(0);
    }
}
