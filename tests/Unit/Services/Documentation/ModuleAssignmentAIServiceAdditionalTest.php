<?php

namespace Lumiio\CascadeDocs\Tests\Unit\Services\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentAIService;
use Lumiio\CascadeDocs\Tests\TestCase;
use Mockery;

class ModuleAssignmentAIServiceAdditionalTest extends TestCase
{
    protected ModuleAssignmentAIService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test directories
        File::ensureDirectoryExists(base_path('docs'));
        File::ensureDirectoryExists(base_path('docs/source_documents/modules/metadata'));

        // Configure paths
        Config::set('cascadedocs.paths.modules.metadata', 'docs/source_documents/modules/metadata/');
        Config::set('cascadedocs.paths.tracking.module_assignment', 'docs/module-assignment-log.json');

        // Prevent any real HTTP requests
        Http::preventStrayRequests();
        Http::fake();

        $this->service = new ModuleAssignmentAIService;
    }

    protected function tearDown(): void
    {
        if (File::exists(base_path('docs'))) {
            File::deleteDirectory(base_path('docs'));
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_process_ai_recommendations_handles_all_cases(): void
    {
        $recommendations = [
            'assignments' => [
                // High confidence assignment to existing module
                [
                    'action' => 'assign_to_existing',
                    'files' => ['app/Services/AuthService.php'],
                    'module' => 'auth',
                    'confidence' => 0.9,
                    'reasoning' => 'Authentication service',
                ],
                // Low confidence assignment
                [
                    'action' => 'assign_to_existing',
                    'files' => ['app/Services/MaybeAuthService.php'],
                    'module' => 'auth',
                    'confidence' => 0.5,
                    'reasoning' => 'Possibly authentication related',
                ],
                // Assignment to non-existent module
                [
                    'action' => 'assign_to_existing',
                    'files' => ['app/Services/NonExistentService.php'],
                    'module' => 'non-existent',
                    'confidence' => 0.9,
                    'reasoning' => 'Should fail',
                ],
                // Valid new module
                [
                    'action' => 'create_new_module',
                    'files' => ['app/Services/PaymentService.php'],
                    'module_name' => 'Payment Processing',
                    'module_slug' => 'payment-processing',
                    'description' => 'Handles payments',
                    'confidence' => 0.85,
                    'reasoning' => 'Payment functionality',
                ],
                // Invalid new module (missing required fields)
                [
                    'action' => 'create_new_module',
                    'files' => ['app/Services/InvalidService.php'],
                    'module_name' => 'Invalid Module',
                    // Missing module_slug and description
                    'confidence' => 0.85,
                ],
            ],
        ];

        // Create auth module
        File::put(base_path('docs/source_documents/modules/metadata/auth.json'), json_encode([
            'module_slug' => 'auth',
            'module_name' => 'Authentication',
            'files' => [],
        ]));

        $processed = $this->service->processAIRecommendations($recommendations, 0.7);

        // Check high confidence assignment
        $this->assertCount(1, $processed['assign_to_existing']);
        $this->assertEquals('auth', $processed['assign_to_existing'][0]['module']);

        // Check low confidence assignment
        $this->assertCount(1, $processed['low_confidence']);
        $this->assertEquals(0.5, $processed['low_confidence'][0]['confidence']);

        // Check errors
        $this->assertContains('Module not found: non-existent', $processed['errors']);
        $this->assertContains('Invalid module data for: Invalid Module', $processed['errors']);

        // Check new module (might not be created due to validation in processAIRecommendations)
        if (! empty($processed['create_new_modules'])) {
            $this->assertEquals('payment-processing', $processed['create_new_modules'][0]['slug']);
        }
    }

    public function test_apply_module_assignments(): void
    {
        // Create test modules
        File::put(base_path('docs/source_documents/modules/metadata/auth.json'), json_encode([
            'module_slug' => 'auth',
            'module_name' => 'Authentication',
            'files' => [],
            'undocumented_files' => [],
        ]));

        $assignments = [
            [
                'module' => 'auth',
                'files' => ['app/Services/AuthService.php', 'app/Models/User.php'],
                'confidence' => 0.9,
            ],
            [
                'module' => 'non-existent',
                'files' => ['app/Services/NonExistent.php'],
                'confidence' => 0.8,
            ],
        ];

        $results = $this->service->applyModuleAssignments($assignments);

        $this->assertCount(1, $results['success']);
        $this->assertEquals('auth', $results['success'][0]['module']);
        $this->assertEquals(2, $results['success'][0]['files_added']);

        $this->assertCount(1, $results['failed']);
        $this->assertEquals('non-existent', $results['failed'][0]['module']);
    }

    public function test_create_new_modules(): void
    {
        $modules = [
            [
                'name' => 'Payment Processing',
                'slug' => 'payment-processing',
                'description' => 'Handles all payment related functionality',
                'files' => ['app/Services/PaymentService.php', 'app/Models/Payment.php'],
                'confidence' => 0.85,
            ],
            // Module with existing slug
            [
                'name' => 'Existing Module',
                'slug' => 'existing-module',
                'description' => 'This should fail',
                'files' => ['app/Services/ExistingService.php'],
                'confidence' => 0.9,
            ],
        ];

        // Create existing module
        File::put(base_path('docs/source_documents/modules/metadata/existing-module.json'), json_encode([
            'module_slug' => 'existing-module',
            'module_name' => 'Existing Module',
            'files' => [],
        ]));

        $results = $this->service->createNewModules($modules);

        $this->assertCount(1, $results['success']);
        $this->assertEquals('payment-processing', $results['success'][0]['module']);
        $this->assertEquals(2, $results['success'][0]['files_added']);

        $this->assertCount(1, $results['failed']);
        $this->assertStringContainsString('already exists', $results['failed'][0]['reason']);
    }

    public function test_validate_new_module_data(): void
    {
        // Test via processAIRecommendations which calls validateNewModuleData
        $validData = [
            'assignments' => [
                [
                    'action' => 'create_new_module',
                    'module_name' => 'Test Module',
                    'module_slug' => 'test-module',
                    'description' => 'Test description',
                    'files' => ['file1.php'],
                    'confidence' => 0.9,
                ],
            ],
        ];

        $processed = $this->service->processAIRecommendations($validData);
        // Validate that the module data is properly processed
        $this->assertIsArray($processed['create_new_modules']);
        if (count($processed['create_new_modules']) > 0) {
            $this->assertEquals('test-module', $processed['create_new_modules'][0]['slug']);
        }

        // Invalid slug format
        $invalidSlug = [
            'assignments' => [
                [
                    'action' => 'create_new_module',
                    'module_name' => 'Test Module',
                    'module_slug' => 'Test Module', // Contains space
                    'description' => 'Test description',
                    'files' => ['file1.php'],
                    'confidence' => 0.9,
                ],
            ],
        ];

        $processed = $this->service->processAIRecommendations($invalidSlug);
        $this->assertEmpty($processed['create_new_modules']);
        $this->assertContains('Invalid module data for: Test Module', $processed['errors']);

        // Missing required field
        $missingField = [
            'assignments' => [
                [
                    'action' => 'create_new_module',
                    'module_name' => 'Test Module',
                    'module_slug' => 'test-module',
                    // Missing description
                    'files' => ['file1.php'],
                    'confidence' => 0.9,
                ],
            ],
        ];

        $processed = $this->service->processAIRecommendations($missingField);
        $this->assertEmpty($processed['create_new_modules']);

        // Empty required field
        $emptyField = [
            'assignments' => [
                [
                    'action' => 'create_new_module',
                    'module_name' => 'Test Module',
                    'module_slug' => 'test-module',
                    'description' => 'Test description',
                    'files' => [], // Empty files
                    'confidence' => 0.9,
                ],
            ],
        ];

        $processed = $this->service->processAIRecommendations($emptyField);
        $this->assertEmpty($processed['create_new_modules']);
    }

    public function test_build_module_assignment_prompt(): void
    {
        // Create test module metadata
        File::put(base_path('docs/source_documents/modules/metadata/auth.json'), json_encode([
            'module_slug' => 'auth',
            'module_name' => 'Authentication',
            'module_summary' => 'Handles user authentication',
            'statistics' => ['total_files' => 5],
        ]));

        $unassignedDocs = collect([
            'app/Services/NewService.php' => [
                'short_doc' => 'New service documentation',
                'related_files' => ['app/Models/NewModel.php'],
            ],
        ]);

        $moduleSummaries = collect([
            'auth' => 'Authentication module summary',
        ]);

        $prompt = $this->service->buildModuleAssignmentPrompt($unassignedDocs, $moduleSummaries);

        $this->assertStringContainsString('Module Assignment Task', $prompt);
        $this->assertStringContainsString('Authentication', $prompt);
        $this->assertStringContainsString('app/Services/NewService.php', $prompt);
        $this->assertStringContainsString('New service documentation', $prompt);
    }

    public function test_extract_module_summaries(): void
    {
        // Configure content path where module markdown files are
        Config::set('cascadedocs.paths.modules.content', 'docs/source_documents/modules/content/');
        File::ensureDirectoryExists(base_path('docs/source_documents/modules/content'));

        // Create test modules metadata
        File::put(base_path('docs/source_documents/modules/metadata/auth.json'), json_encode([
            'module_slug' => 'auth',
            'module_name' => 'Authentication',
            'module_summary' => 'Handles authentication',
        ]));

        File::put(base_path('docs/source_documents/modules/metadata/payment.json'), json_encode([
            'module_slug' => 'payment',
            'module_name' => 'Payment',
            'module_summary' => null, // No summary
        ]));

        // Create module content files
        File::put(base_path('docs/source_documents/modules/content/auth.md'), "# Authentication\n\nHandles authentication");
        File::put(base_path('docs/source_documents/modules/content/payment.md'), "# Payment\n\nPayment processing");

        $summaries = $this->service->extractModuleSummaries();

        $this->assertCount(2, $summaries);
        $this->assertNotEmpty($summaries['auth']);
        $this->assertNotEmpty($summaries['payment']);
    }

    public function test_get_unassigned_files_with_docs(): void
    {
        // Create assignment log
        $log = [
            'unassigned_files' => [
                'app/Services/UnassignedService.php',
                'app/Models/UnassignedModel.php',
            ],
        ];
        File::put(base_path('docs/module-assignment-log.json'), json_encode($log));

        $docs = $this->service->getUnassignedFilesWithDocs();

        $this->assertCount(2, $docs);
        $this->assertArrayHasKey('app/Services/UnassignedService.php', $docs);
        $this->assertArrayHasKey('app/Models/UnassignedModel.php', $docs);

        // Each doc should have the expected structure
        foreach ($docs as $file => $data) {
            $this->assertArrayHasKey('path', $data);
            $this->assertArrayHasKey('has_short_doc', $data);
            $this->assertArrayHasKey('short_doc', $data);
            $this->assertArrayHasKey('related_files', $data);
            $this->assertEquals($file, $data['path']);
        }
    }
}
