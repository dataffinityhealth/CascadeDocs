<?php

namespace Lumiio\CascadeDocs\Services\Documentation;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Support\ResolvesThinkingEffort;
use Shawnveltman\LaravelOpenai\ProviderResponseTrait;

class ModuleAssignmentAIService extends ModuleAssignmentService
{
    use ProviderResponseTrait;
    use ResolvesThinkingEffort;

    protected DocumentationParser $parser;

    public function __construct()
    {
        parent::__construct();
        $this->parser = new DocumentationParser;
    }

    /**
     * AI-powered analysis of module assignments for ALL files.
     * This creates the initial module structure using LLM analysis.
     */
    public function analyze_module_assignments(): array
    {
        $all_documented_files = $this->get_all_documented_files();

        // Load existing do_not_document files
        $existing_log = $this->load_log();
        $do_not_document_files = $existing_log['do_not_document'] ?? [];

        // Filter out files that are in do_not_document
        $filtered_files = $all_documented_files->filter(function ($file) use ($do_not_document_files) {
            return ! in_array($file, $do_not_document_files);
        });

        // Get all files with their documentation
        $filesWithDocs = $this->getAllFilesWithDocs($filtered_files);

        // Build prompt for initial module creation
        $prompt = $this->buildInitialModuleCreationPrompt($filesWithDocs);

        // Get AI recommendations for module structure
        $aiResponse = $this->getAIModuleRecommendations($prompt);

        // Process the AI response to create module structure
        $moduleStructure = $this->processInitialModuleStructure($aiResponse);

        // Save the log with the new structure
        $log = [
            'last_analysis' => \Carbon\Carbon::now()->toIso8601String(),
            'assigned_files' => $moduleStructure['assigned_files'],
            'unassigned_files' => $moduleStructure['unassigned_files'],
            'do_not_document' => $do_not_document_files,
            'potential_modules' => [], // No longer needed with AI approach
            'module_suggestions' => [], // No longer needed with AI approach
            'ai_created_modules' => $moduleStructure['created_modules'],
        ];

        $this->save_log($log);

        // Actually create the module files that don't exist yet
        if (! empty($moduleStructure['created_modules'])) {
            $this->createModuleFiles($moduleStructure['created_modules']);
        }

        return $log;
    }

    /**
     * Get all files with their short documentation.
     */
    protected function getAllFilesWithDocs(Collection $files): Collection
    {
        return $files->mapWithKeys(function ($file) {
            return [
                $file => [
                    'path' => $file,
                    'has_short_doc' => $this->parser->hasShortDocumentation($file),
                    'short_doc' => $this->parser->getShortDocumentation($file),
                ],
            ];
        });
    }

    /**
     * Get unassigned files with their short documentation.
     */
    public function getUnassignedFilesWithDocs(): Collection
    {
        $log = $this->load_log();
        $unassignedFiles = collect($log['unassigned_files'] ?? []);

        return $unassignedFiles->mapWithKeys(function ($file) {
            return [
                $file => [
                    'path' => $file,
                    'has_short_doc' => $this->parser->hasShortDocumentation($file),
                    'short_doc' => $this->parser->getShortDocumentation($file),
                    'related_files' => $this->findRelatedFiles($file),
                ],
            ];
        });
    }

    /**
     * Extract summaries from all module files.
     */
    public function extractModuleSummaries(): Collection
    {
        return $this->parser->extractAllModuleSummaries();
    }

    /**
     * Build the prompt for AI module assignment.
     */
    public function buildModuleAssignmentPrompt(Collection $unassignedDocs, Collection $moduleSummaries): string
    {
        // Build the context sections
        $existingModulesSection = $this->formatExistingModules($moduleSummaries);
        $unassignedFilesSection = $this->formatUnassignedFiles($unassignedDocs);

        // Build the complete prompt
        $prompt = "# Module Assignment Task\n\n";
        $prompt .= "You are tasked with organizing files into modules for a software documentation system.\n\n";
        $prompt .= "## EXISTING MODULES\n\n";
        $prompt .= $existingModulesSection."\n\n";
        $prompt .= '## UNASSIGNED FILES ('.$unassignedDocs->count()." files)\n\n";
        $prompt .= $unassignedFilesSection."\n\n";
        $prompt .= $this->getAssignmentInstructions($moduleSummaries);

        return $prompt;
    }

    /**
     * Process AI recommendations and prepare for application.
     */
    public function processAIRecommendations(array $recommendations, float $confidenceThreshold = 0.7): array
    {
        $processed = [
            'assign_to_existing' => [],
            'create_new_modules' => [],
            'low_confidence' => [],
            'errors' => [],
        ];

        foreach ($recommendations['assignments'] ?? [] as $assignment) {
            $confidence = $assignment['confidence'] ?? 0;

            if ($confidence < $confidenceThreshold) {
                $processed['low_confidence'][] = $assignment;

                continue;
            }

            if ($assignment['action'] === 'assign_to_existing') {
                // Validate the module exists
                if ($this->moduleExists($assignment['module'])) {
                    $processed['assign_to_existing'][] = [
                        'files' => $assignment['files'],
                        'module' => $assignment['module'],
                        'confidence' => $confidence,
                        'reasoning' => $assignment['reasoning'] ?? '',
                    ];
                } else {
                    $processed['errors'][] = "Module not found: {$assignment['module']}";
                }
            } elseif ($assignment['action'] === 'create_new_module') {
                // Validate new module data
                if ($this->validateNewModuleData($assignment)) {
                    $processed['create_new_modules'][] = [
                        'name' => $assignment['module_name'],
                        'slug' => $assignment['module_slug'],
                        'description' => $assignment['description'],
                        'files' => $assignment['files'],
                        'confidence' => $confidence,
                        'reasoning' => $assignment['reasoning'] ?? '',
                    ];
                } else {
                    $processed['errors'][] = "Invalid module data for: {$assignment['module_name']}";
                }
            }
        }

        return $processed;
    }

    /**
     * Apply module assignments to existing modules.
     */
    public function applyModuleAssignments(array $assignments): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        $metadataService = new ModuleMetadataService;

        foreach ($assignments as $assignment) {
            if (! $metadataService->moduleExists($assignment['module'])) {
                $results['failed'][] = [
                    'module' => $assignment['module'],
                    'reason' => 'Module not found',
                ];

                continue;
            }

            try {
                // Add files to module as undocumented (will be documented later)
                $metadataService->addFiles($assignment['module'], $assignment['files'], false);

                $results['success'][] = [
                    'module' => $assignment['module'],
                    'files_added' => count($assignment['files']),
                    'files' => $assignment['files'],
                ];
            } catch (Exception $e) {
                $results['failed'][] = [
                    'module' => $assignment['module'],
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // Update the module assignment log
        $this->updateAssignmentLog($results['success']);

        return $results;
    }

    /**
     * Create new modules based on AI recommendations.
     */
    public function createNewModules(array $newModules): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        $metadataService = new ModuleMetadataService;
        $updater = new ModuleFileUpdater;

        foreach ($newModules as $moduleData) {
            try {
                if ($metadataService->moduleExists($moduleData['slug'])) {
                    $results['failed'][] = [
                        'module' => $moduleData['slug'],
                        'reason' => 'Module already exists',
                    ];

                    continue;
                }

                // Create the module with metadata and placeholder content
                $updater->createModule([
                    'slug' => $moduleData['slug'],
                    'name' => $moduleData['name'],
                    'description' => $moduleData['description'],
                    'files' => $moduleData['files'],
                ]);

                $results['success'][] = [
                    'module' => $moduleData['slug'],
                    'files_added' => count($moduleData['files']),
                    'files' => $moduleData['files'],
                ];
            } catch (Exception $e) {
                $results['failed'][] = [
                    'module' => $moduleData['slug'] ?? 'unknown',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // Update the module assignment log for successful creations
        if (! empty($results['success'])) {
            $this->updateAssignmentLog($results['success']);
        }

        return $results;
    }

    /**
     * Generate content for a new module.
     */
    protected function generateNewModuleContent(array $moduleData): string
    {
        $timestamp = now()->toIso8601String();
        $fileCount = count($moduleData['files']);
        $gitCommit = trim(shell_exec('git rev-parse HEAD') ?? 'unknown');

        $content = <<<EOT
---
doc_version: 1.0
doc_tier: module
module_name: {$moduleData['name']}
module_slug: {$moduleData['slug']}
generated_at: {$timestamp}
git_commit_sha: {$gitCommit}
total_files: {$fileCount}
last_updated: {$timestamp}
---

# {$moduleData['name']} Module

## Overview

{$moduleData['description']}

## How This Module Works

### Core Components

EOT;

        // Group files by directory
        $filesByDirectory = [];

        foreach ($moduleData['files'] as $file) {
            $dir = dirname($file);

            if (! isset($filesByDirectory[$dir])) {
                $filesByDirectory[$dir] = [];
            }
            $filesByDirectory[$dir][] = $file;
        }

        ksort($filesByDirectory);

        // Add grouped files
        foreach ($filesByDirectory as $dir => $files) {
            $dirTitle = $this->formatDirectoryTitle($dir);
            $content .= "\n### {$dirTitle}\n\n";

            sort($files);

            foreach ($files as $file) {
                $fileName = basename($file);
                $content .= "- **`{$file}`** - {$fileName}\n";
            }
        }

        $content .= <<<'EOT'

## Module Files

See the files listed above organized by directory.

## Key Workflows Explained

[To be documented]

## Integration Points

### Dependencies (This module requires)

[To be documented]

### Dependents (Modules that require this)

[To be documented]

## Security Considerations

[To be documented]

## Future Considerations

[To be documented]

## Related Documentation

[To be documented]
EOT;

        return $content;
    }

    /**
     * Format directory name for display.
     */
    protected function formatDirectoryTitle(string $directory): string
    {
        // Convert path to title case and replace slashes with arrows
        $parts = explode('/', $directory);
        $formatted = array_map(function ($part) {
            return ucwords(str_replace(['-', '_'], ' ', $part));
        }, $parts);

        return implode(' → ', $formatted);
    }

    /**
     * Find files related to a given file.
     */
    protected function findRelatedFiles(string $file): array
    {
        $related = [];
        $directory = dirname($file);
        $basename = basename($file, '.'.pathinfo($file, PATHINFO_EXTENSION));

        // Look for files in the same directory
        $allFiles = $this->get_all_documented_files();

        foreach ($allFiles as $otherFile) {
            if ($otherFile === $file) {
                continue;
            }

            // Same directory
            if (dirname($otherFile) === $directory) {
                $related[] = $otherFile;

                continue;
            }

            // Similar name pattern
            $otherBasename = basename($otherFile, '.'.pathinfo($otherFile, PATHINFO_EXTENSION));
            similar_text($basename, $otherBasename, $similarity);

            if ($similarity > 70) {
                $related[] = $otherFile;
            }
        }

        return array_slice($related, 0, 5); // Limit to 5 related files
    }

    /**
     * Format existing modules for the prompt.
     */
    protected function formatExistingModules(Collection $moduleSummaries): string
    {
        $metadataService = new ModuleMetadataService;
        $output = '';

        foreach ($moduleSummaries as $slug => $summary) {
            $metadata = $metadataService->loadMetadata($slug);

            if ($metadata) {
                $output .= "### Module: {$slug}\n";
                $output .= "**Name:** {$metadata['module_name']}\n";

                // Use module_summary from metadata if available and not empty
                $summaryText = (! empty($metadata['module_summary'])) ? $metadata['module_summary'] : $summary;

                // Only show summary if we have one
                if (! empty($summaryText)) {
                    $output .= "**Summary:** {$summaryText}\n";
                } else {
                    $output .= "**Summary:** *Documentation not yet generated for this module*\n";
                }

                // Add file count for context
                $totalFiles = $metadata['statistics']['total_files'] ?? 0;
                $output .= "**Files:** {$totalFiles} files\n\n";
            } else {
                // Fallback if metadata not found
                $output .= "### Module: {$slug}\n\n{$summary}\n\n";
            }

            $output .= "---\n\n";
        }

        return rtrim($output, "\n---\n\n");
    }

    /**
     * Format unassigned files for the prompt.
     */
    protected function formatUnassignedFiles(Collection $unassignedDocs): string
    {
        return $unassignedDocs->map(function ($fileData, $path) {
            $output = "### File: {$path}\n";

            // Extract directory and filename for clarity
            $directory = dirname($path);
            $filename = basename($path);
            $output .= "**Directory:** {$directory}\n";
            $output .= "**Filename:** {$filename}\n\n";

            if ($fileData['short_doc']) {
                $output .= "**Documentation:**\n{$fileData['short_doc']}";
            } else {
                $output .= '**Documentation:** *No short documentation available*';
            }

            if (! empty($fileData['related_files'])) {
                $output .= "\n\n**Related files:** ".implode(', ', $fileData['related_files']);
            }

            return $output;
        })->implode("\n\n---\n\n");
    }

    /**
     * Get assignment instructions for the AI.
     */
    protected function getAssignmentInstructions(Collection $moduleSummaries): string
    {
        // Extract module slugs from the existing modules
        $moduleSlugsList = '';
        if ($moduleSummaries->isNotEmpty()) {
            $slugs = $moduleSummaries->keys()->map(function ($slug) {
                return "- {$slug}";
            })->implode("\n");

            $moduleSlugsList = "\nExisting module slugs you should use for 'assign_to_existing':\n{$slugs}\n";
        }

        return <<<EOT
## INSTRUCTIONS

Analyze ALL unassigned files and organize them in two phases:

PHASE 1 - Assign to existing modules:
- Review each unassigned file and check if it naturally belongs to any EXISTING module
- **CRITICAL: Consider BOTH the file path AND the documentation content when making assignments**
- File paths often contain keywords that indicate module membership (e.g., files in `app/Livewire/DataRequests/` likely belong to a data-requests module)
- Look for module names or related concepts in the file path structure
- Only assign files that are clearly related to the module's purpose
- Use high confidence scores (0.8+) for obvious matches

PHASE 2 - Create new module suggestions:
- From the remaining unassigned files, identify natural groupings that could form NEW modules
- Look for files that share common functionality, purpose, or domain
- **Use directory structure as a strong hint for grouping** (e.g., all files in `app/Livewire/Products/` likely form a cohesive module)
- Group related components (e.g., Livewire components, controllers, services, models)
- Each new module must have at least 3 related files

IMPORTANT: Process ALL files in the batch - either assign them to existing modules OR group them into new module suggestions. Aim to minimize unprocessed files.

Example path analysis:
- File path: `app/Livewire/Products/Create.php` → Should be assigned to "products" or similar module
- File path: `app/Http/Controllers/Auth/LoginController.php` → Should be assigned to "authentication" module
- File path: `app/Models/Order.php` → Should be assigned to "orders" module (note: "Order" in filename)

Return your response as a JSON object with the following structure:

{
    "assignments": [
        {
            "action": "assign_to_existing",
            "files": ["path/to/file1.php", "path/to/file2.php"],
            "module": "existing-module-slug",
            "confidence": 0.85,
            "reasoning": "These files handle similar functionality..."
        },
        {
            "action": "create_new_module",
            "files": ["path/to/file3.php", "path/to/file4.php", "path/to/file5.php"],
            "module_name": "New Module Name",
            "module_slug": "new-module-slug",
            "description": "This module handles [specific functionality]. It includes [key components] and provides [main features].",
            "confidence": 0.9,
            "reasoning": "These files form a cohesive unit for..."
        }
    ]
}

Guidelines:
- For "assign_to_existing": ONLY use module slugs that appear in the EXISTING MODULES section
- For "create_new_module": Provide all required fields (module_name, module_slug, description, files)
- Only suggest assignments with confidence >= 0.7
- New modules must have at least 3 related files
- Module slugs should be lowercase with hyphens (e.g., "user-management")
- Descriptions should be comprehensive (2-3 sentences minimum)
- **ALWAYS analyze the file path first** - it often contains the clearest indication of module membership
- Match path keywords to module names (e.g., "DataRequest" in path → data-request module)
- Consider both directory structure AND file purpose when grouping
- Files in the same logical directory often belong together
{$moduleSlugsList}
IMPORTANT: Return ONLY valid JSON. Do not include markdown code blocks or any other text.

EOT;
    }

    /**
     * Check if a module exists.
     */
    protected function moduleExists(string $slug): bool
    {
        $metadataService = new ModuleMetadataService;

        return $metadataService->moduleExists($slug);
    }

    /**
     * Validate new module data.
     */
    protected function validateNewModuleData(array $data): bool
    {
        $required = ['module_name', 'module_slug', 'description', 'files'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }

        // Validate slug format
        if (! preg_match('/^[a-z0-9-]+$/', $data['module_slug'])) {
            return false;
        }

        // Validate minimum files
        return ! (count($data['files']) < 3);
    }

    /**
     * Update the assignment log after successful assignments.
     */
    protected function updateAssignmentLog(array $successfulAssignments): void
    {
        $log = $this->load_log();

        // Remove assigned files from unassigned list
        $assignedFiles = collect($successfulAssignments)
            ->pluck('files')
            ->flatten()
            ->toArray();

        $log['unassigned_files'] = array_values(
            array_diff($log['unassigned_files'], $assignedFiles)
        );

        // Update assigned files section
        foreach ($successfulAssignments as $assignment) {
            if (! isset($log['assigned_files'][$assignment['module']])) {
                $log['assigned_files'][$assignment['module']] = [];
            }

            $log['assigned_files'][$assignment['module']] = array_merge(
                $log['assigned_files'][$assignment['module']],
                $assignment['files']
            );
        }

        $log['last_ai_assignment'] = now()->toIso8601String();

        $this->save_log($log);
    }

    /**
     * Build prompt for initial module creation.
     */
    protected function buildInitialModuleCreationPrompt(Collection $filesWithDocs): string
    {
        $prompt = "# Initial Module Creation Task\n\n";
        $prompt .= 'You are tasked with creating an initial module structure for a software application. ';
        $prompt .= "Analyze ALL the following files and create a comprehensive module structure that organizes them logically.\n\n";
        $prompt .= "IMPORTANT: Group files into coherent modules based on functionality. It's okay to leave files unassigned if they don't clearly belong to any module.\n\n";
        $prompt .= '## FILES TO ORGANIZE ('.$filesWithDocs->count()." files)\n\n";

        foreach ($filesWithDocs as $path => $fileData) {
            $prompt .= "### File: {$path}\n";

            // Extract directory and filename for clarity
            $directory = dirname($path);
            $filename = basename($path);
            $prompt .= "**Directory:** {$directory}\n";
            $prompt .= "**Filename:** {$filename}\n\n";

            if ($fileData['short_doc']) {
                $prompt .= "**Documentation:**\n{$fileData['short_doc']}";
            } else {
                $prompt .= '**Documentation:** *No short documentation available*';
            }
            $prompt .= "\n\n---\n\n";
        }

        $prompt .= $this->getInitialModuleCreationInstructions();

        return $prompt;
    }

    /**
     * Get instructions for initial module creation.
     */
    protected function getInitialModuleCreationInstructions(): string
    {
        return <<<'EOT'
## INSTRUCTIONS

Create a comprehensive module structure that organizes files into logical modules based on functionality. Follow these guidelines:

1. **Analyze file paths and documentation** to understand the purpose of each file
2. **Group files by functionality** - files that work together should be in the same module
3. **Consider directory structure** as a strong hint for module organization
4. **Create modules that make semantic sense** for the application domain
5. **Leave files unassigned** if they don't clearly fit into any logical module

Return your response as a JSON object with the following structure:

{
    "modules": [
        {
            "module_name": "User Authentication",
            "module_slug": "authentication",
            "description": "Handles user authentication, login, registration, and password management. Includes authentication middleware, controllers, and related Livewire components.",
            "files": [
                "app/Http/Controllers/Auth/LoginController.php",
                "app/Http/Controllers/Auth/RegisterController.php",
                "app/Http/Middleware/Authenticate.php",
                "app/Livewire/Auth/Login.php",
                "app/Livewire/Auth/Register.php"
            ]
        },
        {
            "module_name": "User Management",
            "module_slug": "user-management",
            "description": "Manages user profiles, settings, and administrative functions. Includes user models, profile management, and administrative interfaces.",
            "files": [
                "app/Models/User.php",
                "app/Http/Controllers/UserController.php",
                "app/Livewire/Users/Index.php",
                "app/Livewire/Users/Edit.php",
                "app/Policies/UserPolicy.php",
                "app/Notifications/UserNotification.php"
            ]
        }
    ],
    "unassigned_files": [
        "app/Helpers/ViteHelper.php",
        "resources/js/bootstrap.js"
    ]
}

Guidelines:
- **Group files that clearly work together** into modules
- **Leave files unassigned** if they are:
  - Utility files that don't belong to a specific feature
  - Configuration or bootstrap files
  - Standalone helpers without clear module affinity
- Module names should be clear and descriptive
- Module slugs should be lowercase with hyphens (e.g., "user-management")
- Descriptions should be comprehensive (2-3 sentences minimum)
- Group related components together (models, controllers, Livewire components, etc.)
- Consider both file paths AND functionality when grouping
- Create modules that represent coherent features or subsystems
- Each module should have at least 3 files (don't force small groups)

IMPORTANT: Return ONLY valid JSON. Do not include markdown code blocks or any other text.

EOT;
    }

    /**
     * Get AI module recommendations.
     */
    protected function getAIModuleRecommendations(string $prompt): array
    {
        $model = config('cascadedocs.ai.default_model', 'gpt-4o');

        try {
            // Add system prompt for better JSON response
            $systemPrompt = 'You are a module organization assistant for a software documentation system. You analyze files and create logical module groupings based on their functionality and relationships. Always respond with valid JSON only, no markdown code blocks or extra text.';

            $fullPrompt = $systemPrompt."\n\n".$prompt;

            // Use the trait method to get response
            $response = $this->get_response_from_provider(
                $fullPrompt,
                $model,
                json_mode: true,
                thinking_effort: $this->resolveThinkingEffort()
            );

            $result = json_decode($response, true);

            if (! $result || ! isset($result['modules'])) {
                throw new \Exception('Invalid response format from AI service');
            }

            return $result;
        } catch (\Exception $e) {
            throw new \Exception('Failed to get AI module recommendations: '.$e->getMessage());
        }
    }

    /**
     * Process initial module structure from AI response.
     */
    protected function processInitialModuleStructure(array $aiResponse): array
    {
        $assignedFiles = [];
        $createdModules = [];

        foreach ($aiResponse['modules'] ?? [] as $module) {
            $slug = $module['module_slug'];

            // Track assigned files
            $assignedFiles[$slug] = $module['files'];

            // Track modules to create
            $createdModules[] = [
                'slug' => $slug,
                'name' => $module['module_name'],
                'description' => $module['description'],
                'files' => $module['files'],
            ];
        }

        // Use the unassigned files from the AI response
        $unassignedFiles = $aiResponse['unassigned_files'] ?? [];

        return [
            'assigned_files' => $assignedFiles,
            'unassigned_files' => $unassignedFiles,
            'created_modules' => $createdModules,
        ];
    }

    /**
     * Create module files for newly created modules.
     */
    protected function createModuleFiles(array $modules): void
    {
        $metadataService = new ModuleMetadataService;
        $fileUpdater = new ModuleFileUpdater;

        foreach ($modules as $module) {
            if (! $metadataService->moduleExists($module['slug'])) {
                $fileUpdater->createModule($module);
            }
        }
    }
}
