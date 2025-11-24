<?php

use Illuminate\Support\Facades\Config;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentAIService;
use Shawnveltman\LaravelOpenai\Enums\ThinkingEffort;

beforeEach(function () {
    Config::set('cascadedocs.ai.default_model', 'gpt-4o');
});

describe('ModuleAssignmentAIService', function () {
    describe('getAssignmentInstructions', function () {
        it('includes existing module slugs when modules exist', function () {
            $service = new ModuleAssignmentAIService;

            // Use reflection to access protected method
            $reflection = new ReflectionMethod($service, 'getAssignmentInstructions');
            $reflection->setAccessible(true);

            $moduleSummaries = collect([
                'authentication' => 'Authentication module summary',
                'user-management' => 'User management module summary',
                'api-endpoints' => 'API endpoints module summary',
            ]);

            $instructions = $reflection->invoke($service, $moduleSummaries);

            expect($instructions)
                ->toContain('Existing module slugs you should use for \'assign_to_existing\':')
                ->toContain('- authentication')
                ->toContain('- user-management')
                ->toContain('- api-endpoints')
                ->not->toContain('healthcare')
                ->not->toContain('patient')
                ->not->toContain('clinical');
        });

        it('handles empty module list gracefully', function () {
            $service = new ModuleAssignmentAIService;

            $reflection = new ReflectionMethod($service, 'getAssignmentInstructions');
            $reflection->setAccessible(true);

            $moduleSummaries = collect([]);

            $instructions = $reflection->invoke($service, $moduleSummaries);

            expect($instructions)
                ->not->toContain('Existing module slugs you should use')
                ->toContain('PHASE 1 - Assign to existing modules:')
                ->toContain('PHASE 2 - Create new module suggestions:');
        });
    });

    describe('buildModuleAssignmentPrompt', function () {
        it('builds prompt with dynamic module list', function () {
            $service = new ModuleAssignmentAIService;

            $unassignedDocs = collect([
                'app/Services/PaymentService.php' => [
                    'path' => 'app/Services/PaymentService.php',
                    'short_doc' => 'Handles payment processing',
                    'has_short_doc' => true,
                    'related_files' => [],
                ],
            ]);

            $moduleSummaries = collect([
                'payments' => 'Payment processing module',
                'orders' => 'Order management module',
            ]);

            $prompt = $service->buildModuleAssignmentPrompt($unassignedDocs, $moduleSummaries);

            expect($prompt)
                ->toContain('software documentation system')
                ->not->toContain('healthcare platform')
                ->toContain('## EXISTING MODULES')
                ->toContain('payments')
                ->toContain('orders');
        });
    });

    describe('buildInitialModuleCreationPrompt', function () {
        it('uses generic language instead of healthcare-specific', function () {
            $service = new ModuleAssignmentAIService;

            $reflection = new ReflectionMethod($service, 'buildInitialModuleCreationPrompt');
            $reflection->setAccessible(true);

            $filesWithDocs = collect([
                'app/Http/Controllers/ProductController.php' => [
                    'path' => 'app/Http/Controllers/ProductController.php',
                    'short_doc' => 'Manages product CRUD operations',
                    'has_short_doc' => true,
                ],
            ]);

            $prompt = $reflection->invoke($service, $filesWithDocs);

            expect($prompt)
                ->toContain('software application')
                ->not->toContain('healthcare')
                ->not->toContain('medical')
                ->not->toContain('patient')
                ->toContain('User Authentication') // Generic example
                ->toContain('User Management'); // Generic example
        });
    });

    describe('getAIModuleRecommendations', function () {
        it('uses generic system prompt', function () {
            $service = new ModuleAssignmentAIService;

            // Mock the trait method
            $service = Mockery::mock(ModuleAssignmentAIService::class)
                ->makePartial()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->withArgs(function (
                    $prompt,
                    $model,
                    $userId,
                    $assistantStarterText,
                    $description,
                    $jobUuid,
                    $jsonMode,
                    $temperature,
                    $systemPrompt,
                    $messages,
                    $imageUrls,
                    $thinkingEffort,
                    $maxTokens = 64000
                ) {
                    expect($prompt)
                        ->toContain('software documentation system')
                        ->toContain('functionality and relationships')
                        ->not->toContain('healthcare')
                        ->not->toContain('medical');

                    expect($jsonMode)->toBeTrue();
                    expect($thinkingEffort)->toBeInstanceOf(ThinkingEffort::class);
                    expect($thinkingEffort)->toBe(ThinkingEffort::HIGH);
                    expect($maxTokens)->toBe(64000);

                    return true;
                })
                ->andReturn(json_encode([
                    'modules' => [],
                ]))
                ->getMock();

            $reflection = new ReflectionMethod($service, 'getAIModuleRecommendations');
            $reflection->setAccessible(true);

            $reflection->invoke($service, 'Test prompt');
        });
    });
});

describe('No hardcoded healthcare references', function () {
    it('contains no healthcare-specific module names in prompts', function () {
        $service = new ModuleAssignmentAIService;

        // Check that the file content doesn't contain healthcare-specific terms
        $fileContent = file_get_contents(__DIR__.'/../../../../src/Services/Documentation/ModuleAssignmentAIService.php');

        $healthcareTerms = [
            'patient-',
            'clinical-',
            'medical-',
            'hipaa',
            'health-diary',
            'consent-forms',
            'trial-matching',
            'genetic-testing',
            'clinician',
            'assent-handling',
            'age-of-majority',
            'redcap',
            'wearable-integration',
        ];

        foreach ($healthcareTerms as $term) {
            expect($fileContent)->not->toContain($term);
        }
    });
});
