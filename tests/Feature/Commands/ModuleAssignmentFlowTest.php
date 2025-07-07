<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentAIService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleFileUpdater;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;

describe('Module Assignment Flow', function () {
    beforeEach(function () {
        // Set up config paths
        Config::set('cascadedocs.paths.tracking.module_assignment', 'docs/module-assignment-log.json');
        Config::set('cascadedocs.paths.modules.metadata', 'docs/module_metadata/');
        Config::set('cascadedocs.paths.modules.content', 'docs/source_documents/modules/');
        Config::set('cascadedocs.paths.output', 'docs/source_documents/');
        Config::set('cascadedocs.tier_directories', ['full', 'medium', 'short']);
        Config::set('cascadedocs.ai.default_model', 'gpt-4o');
        Config::set('cascadedocs.ai.default_provider', 'openai');
        
        // Create test directories
        File::makeDirectory(base_path('docs'), 0755, true, true);
        File::makeDirectory(base_path('docs/source_documents/modules'), 0755, true, true);
        File::makeDirectory(base_path('docs/module_metadata'), 0755, true, true);

        // Clean up any existing files
        if (File::exists(base_path('docs/module-assignment-log.json'))) {
            File::delete(base_path('docs/module-assignment-log.json'));
        }
    });

    afterEach(function () {
        // Clean up
        File::deleteDirectory(base_path('docs'));
    });

    it('creates initial module structure with no existing modules', function () {
        // Mock the HTTP response for OpenAI
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'modules' => [
                                    [
                                        'module_name' => 'Authentication',
                                        'module_slug' => 'authentication',
                                        'description' => 'Handles user authentication and authorization',
                                        'files' => [
                                            'app/Http/Controllers/Auth/LoginController.php',
                                            'app/Http/Controllers/Auth/RegisterController.php',
                                            'app/Http/Middleware/Authenticate.php',
                                        ],
                                    ],
                                    [
                                        'module_name' => 'User Management',
                                        'module_slug' => 'user-management',
                                        'description' => 'Manages user data and profiles',
                                        'files' => [
                                            'app/Models/User.php',
                                        ],
                                    ],
                                ],
                                'unassigned_files' => [],
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'total_tokens' => 150,
                ],
            ], 200),
        ]);
        
        // Create a real instance first, then mock it
        $realService = new ModuleAssignmentAIService();
        $service = Mockery::mock($realService)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        
        // Mock filesystem-related methods
        $service->shouldReceive('get_all_documented_files')
            ->andReturn(collect([
                'app/Http/Controllers/Auth/LoginController.php',
                'app/Http/Controllers/Auth/RegisterController.php',
                'app/Models/User.php',
                'app/Http/Middleware/Authenticate.php',
            ]));
            
        $service->shouldReceive('getAllFilesWithDocs')
            ->andReturnUsing(function ($files) {
                return $files->mapWithKeys(function ($file) {
                    return [$file => [
                        'path' => $file,
                        'has_short_doc' => true,
                        'short_doc' => 'Test documentation for '.basename($file),
                    ]];
                });
            });
            
        // Mock module file creation - make it optional since it depends on the response
        $service->shouldReceive('createModuleFiles')
            ->zeroOrMoreTimes()
            ->andReturn(null);

        $result = $service->analyze_module_assignments();

        expect($result)
            ->toHaveKey('assigned_files')
            ->toHaveKey('ai_created_modules')
            ->and($result['assigned_files'])
            ->toHaveKey('authentication')
            ->toHaveKey('user-management')
            ->and($result['ai_created_modules'])
            ->toHaveCount(2);
            
        // Verify no healthcare terms in saved data
        expect($result['ai_created_modules'][0]['description'])
            ->not->toContain('healthcare')
            ->not->toContain('patient')
            ->not->toContain('clinical');
    });

    it('assigns files to existing modules', function () {
        // Create existing module assignment log
        $existingLog = [
            'last_analysis' => now()->toIso8601String(),
            'assigned_files' => [
                'authentication' => [
                    'app/Http/Controllers/Auth/LoginController.php',
                ],
                'user-management' => [
                    'app/Models/User.php',
                ],
            ],
            'unassigned_files' => [
                'app/Http/Controllers/Auth/RegisterController.php',
                'app/Http/Middleware/Authenticate.php',
            ],
            'do_not_document' => [],
            'potential_modules' => [],
            'module_suggestions' => [],
        ];

        File::put(
            base_path('docs/module-assignment-log.json'),
            json_encode($existingLog, JSON_PRETTY_PRINT)
        );

        $service = new ModuleAssignmentAIService();

        // Use reflection to test buildModuleAssignmentPrompt
        $reflection = new ReflectionMethod($service, 'buildModuleAssignmentPrompt');
        $reflection->setAccessible(true);

        $unassignedDocs = collect([
            'app/Http/Controllers/Auth/RegisterController.php' => [
                'path' => 'app/Http/Controllers/Auth/RegisterController.php',
                'short_doc' => 'Handles user registration',
                'has_short_doc' => true,
                'related_files' => [],
            ],
        ]);

        $moduleSummaries = collect([
            'authentication' => 'Authentication module',
            'user-management' => 'User management module',
        ]);

        $prompt = $reflection->invoke($service, $unassignedDocs, $moduleSummaries);

        expect($prompt)
            ->toContain('Existing module slugs you should use for \'assign_to_existing\':')
            ->toContain('- authentication')
            ->toContain('- user-management')
            ->not->toContain('patient')
            ->not->toContain('clinical')
            ->not->toContain('healthcare');
    });
});

describe('Edge Cases', function () {
    it('handles special characters in module names', function () {
        $service = new ModuleAssignmentAIService();

        $reflection = new ReflectionMethod($service, 'getAssignmentInstructions');
        $reflection->setAccessible(true);

        $moduleSummaries = collect([
            'e-commerce' => 'E-commerce module',
            'api-v2' => 'API version 2',
            'third-party-integrations' => 'Third party integrations',
        ]);

        $instructions = $reflection->invoke($service, $moduleSummaries);

        expect($instructions)
            ->toContain('- e-commerce')
            ->toContain('- api-v2')
            ->toContain('- third-party-integrations');
    });
});