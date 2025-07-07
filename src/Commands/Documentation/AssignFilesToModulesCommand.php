<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentAIService;
use Shawnveltman\LaravelOpenai\Exceptions\ClaudeRateLimitException;
use Shawnveltman\LaravelOpenai\ProviderResponseTrait;

class AssignFilesToModulesCommand extends Command
{
    use ProviderResponseTrait;

    protected $signature = 'documentation:assign-files-to-modules
        {--dry-run : Preview changes without applying them}
        {--auto-create : Automatically create suggested new modules}
        {--confidence= : Minimum confidence threshold for auto-assignment}
        {--interactive : Prompt for confirmation on low-confidence assignments}
        {--limit=0 : Process only N unassigned files (0 = all)}
        {--output-prompt : Output the generated prompt to a file}
        {--model= : The AI model to use for assignment}
        {--force : Apply changes without confirmation}';

    protected $description = 'Assign unassigned documentation files to modules using AI suggestions';

    protected ModuleAssignmentAIService $aiService;

    public function __construct()
    {
        parent::__construct();
        $this->aiService = new ModuleAssignmentAIService;
    }

    public function handle(): int
    {
        $this->info('Starting file-to-module assignment process...');

        // Step 1: Load current state (don't re-analyze if already done)
        $this->info('Loading current module assignments...');
        $analysis = $this->aiService->load_log();

        // If no analysis exists, run it
        if (empty($analysis['last_analysis'])) {
            $this->info('No existing analysis found. Running initial analysis...');
            $analysis = $this->aiService->analyze_module_assignments();
        }

        $unassignedCount = count($analysis['unassigned_files']);

        if ($unassignedCount === 0) {
            $this->info('✓ No unassigned files found! All files are assigned to modules.');

            return 0;
        }

        $this->warn("Found {$unassignedCount} unassigned files.");

        // Step 2: Get unassigned files with documentation
        $this->info('Gathering documentation for unassigned files...');
        $unassignedDocs = $this->aiService->getUnassignedFilesWithDocs();

        // Apply limit if specified
        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $unassignedDocs = $unassignedDocs->take($limit);
            $this->info("Processing only {$limit} files as requested.");
        }

        // Step 3: Extract module summaries
        $this->info('Extracting module summaries...');
        $moduleSummaries = $this->aiService->extractModuleSummaries();
        $this->info("Found {$moduleSummaries->count()} existing modules.");

        // Step 4: Build AI prompt
        $this->info('Building AI prompt...');
        $prompt = $this->aiService->buildModuleAssignmentPrompt($unassignedDocs, $moduleSummaries);

        // Output prompt if requested
        if ($this->option('output-prompt')) {
            $promptPath = base_path('docs/generated-assignment-prompt.md');
            File::put($promptPath, $prompt);
            $this->info("Prompt saved to: {$promptPath}");
        }

        // Step 5: Get AI recommendations
        $this->info('Getting AI recommendations...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - Showing what would be done:');
            $this->showPromptPreview($prompt);

            $this->info("\nIn a real run, this prompt would be sent to the AI service for module assignment suggestions.");
            $this->info('The AI would suggest:');
            $this->line('- Which files should be assigned to existing modules');
            $this->line('- Which files should form new modules');
            $this->line('- Confidence scores for each suggestion');

            return 0;
        }

        // Call the AI service
        $recommendations = $this->getAIRecommendations($prompt, $this->option('model'));

        // Step 6: Process recommendations
        $this->info('Processing AI recommendations...');
        $confidenceThreshold = $this->option('confidence') !== null
            ? (float) $this->option('confidence')
            : config('cascadedocs.modules.default_confidence_threshold');
        $processed = $this->aiService->processAIRecommendations($recommendations, $confidenceThreshold);

        // Display results
        $this->displayRecommendations($processed);

        // Step 7: Apply assignments if not in dry-run mode
        if ($this->shouldApplyChanges($processed)) {
            $this->applyChanges($processed);
        }

        return 0;
    }

    protected function showPromptPreview(string $prompt): void
    {
        $lines = explode("\n", $prompt);
        $preview = implode("\n", array_slice($lines, 0, 50));

        $this->line("\n--- PROMPT PREVIEW (first 50 lines) ---");
        $this->line($preview);
        $this->line('... (truncated)');
        $this->line("--- END PREVIEW ---\n");
    }

    protected function displayRecommendations(array $processed): void
    {
        $this->newLine();
        $this->info('=== AI Recommendations Summary ===');

        // Assignments to existing modules
        if (! empty($processed['assign_to_existing'])) {
            $this->info("\nFiles to assign to existing modules:");

            foreach ($processed['assign_to_existing'] as $assignment) {
                $this->line("  Module: {$assignment['module']} (confidence: {$assignment['confidence']})");

                foreach ($assignment['files'] as $file) {
                    $this->line("    - {$file}");
                }

                if (! empty($assignment['reasoning'])) {
                    $this->line("    Reasoning: {$assignment['reasoning']}");
                }
                $this->newLine();
            }
        }

        // New module suggestions
        if (! empty($processed['create_new_modules'])) {
            $this->info("\nSuggested new modules:");

            foreach ($processed['create_new_modules'] as $module) {
                $this->line("  Module: {$module['name']} ({$module['slug']})");
                $this->line("  Description: {$module['description']}");
                $this->line("  Confidence: {$module['confidence']}");
                $this->line('  Files:');

                foreach ($module['files'] as $file) {
                    $this->line("    - {$file}");
                }

                if (! empty($module['reasoning'])) {
                    $this->line("  Reasoning: {$module['reasoning']}");
                }
                $this->newLine();
            }
        }

        // Low confidence assignments
        if (! empty($processed['low_confidence'])) {
            $this->warn("\nLow confidence assignments (require manual review):");

            foreach ($processed['low_confidence'] as $assignment) {
                $this->line('  Files: '.implode(', ', $assignment['files']));
                $this->line("  Suggested: {$assignment['module']} (confidence: {$assignment['confidence']})");
                $this->newLine();
            }
        }

        // Errors
        if (! empty($processed['errors'])) {
            $this->error("\nErrors encountered:");

            foreach ($processed['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }
    }

    protected function shouldApplyChanges(array $processed): bool
    {
        $hasChanges = ! empty($processed['assign_to_existing']) || ! empty($processed['create_new_modules']);

        if (! $hasChanges) {
            $this->info('No changes to apply.');

            return false;
        }

        if ($this->option('dry-run')) {
            return false;
        }

        // If force or quiet option is set, apply without confirmation
        if ($this->option('force') || $this->option('quiet')) {
            return true;
        }

        // Ask for confirmation with feedback option
        $choice = $this->choice(
            'Do you want to apply these changes?',
            ['yes' => 'Yes, apply changes', 'no' => 'No, cancel', 'feedback' => 'No, but provide feedback'],
            'yes'
        );

        if ($choice === 'feedback') {
            $this->handleFeedbackLoop($processed);

            return false;
        }

        return $choice === 'yes';
    }

    protected function applyChanges(array $processed): void
    {
        $this->info('Applying changes...');

        // Apply assignments to existing modules
        if (! empty($processed['assign_to_existing'])) {
            $this->info('Assigning files to existing modules...');

            $results = $this->aiService->applyModuleAssignments($processed['assign_to_existing']);

            foreach ($results['success'] as $success) {
                $this->info("✓ Added {$success['files_added']} files to {$success['module']}");
            }

            foreach ($results['failed'] as $failure) {
                $this->error("✗ Failed to update {$failure['module']}: {$failure['reason']}");
            }
        }

        // Create new modules if auto-create is enabled
        if (! empty($processed['create_new_modules']) && $this->option('auto-create')) {
            $this->info('Creating new modules...');

            $results = $this->aiService->createNewModules($processed['create_new_modules']);

            foreach ($results['success'] as $success) {
                $this->info("✓ Created module {$success['module']} with {$success['files_added']} files");
            }

            foreach ($results['failed'] as $failure) {
                $this->error("✗ Failed to create {$failure['module']}: {$failure['reason']}");
            }
        } elseif (! empty($processed['create_new_modules'])) {
            $this->warn('New modules suggested but --auto-create not enabled. Run with --auto-create to create them.');
        }

        // Handle interactive mode for low confidence
        if (! empty($processed['low_confidence']) && $this->option('interactive')) {
            $this->handleLowConfidenceInteractive($processed['low_confidence']);
        }

        // Sync module assignments to ensure consistency
        $this->info('Syncing module assignments...');
        $this->call('documentation:sync-module-assignments', ['--quiet' => true]);

        // Update the analysis to reflect the changes we just made
        // This is necessary because we've modified the module structure
        $this->info('Updating module assignment analysis...');
        $this->aiService->analyze_module_assignments();

        $this->info('✓ Module assignments updated successfully!');
    }

    protected function handleLowConfidenceInteractive(array $lowConfidence): void
    {
        $this->info("\nReviewing low confidence assignments...");

        foreach ($lowConfidence as $assignment) {
            $this->info("\nFiles: ".implode(', ', $assignment['files']));
            $this->info("Suggested module: {$assignment['module']} (confidence: {$assignment['confidence']})");

            if ($this->confirm('Accept this assignment?')) {
                // Apply the assignment
                $this->aiService->applyModuleAssignments([
                    [
                        'module' => $assignment['module'],
                        'files' => $assignment['files'],
                        'confidence' => $assignment['confidence'],
                    ],
                ]);

                $this->info('✓ Assignment applied.');
            } else {
                $this->info('✗ Assignment skipped.');
            }
        }
    }

    /**
     * Get AI recommendations for module assignment.
     */
    protected function getAIRecommendations(string $prompt, string $model): array
    {
        $this->info('Calling AI service for recommendations...');

        try {
            // Add specific instructions for JSON response
            $systemPrompt = 'You are a module assignment assistant for a software documentation system. You analyze files and suggest which modules they should belong to based on their functionality and relationships. Always respond with valid JSON only, no markdown code blocks or extra text.';

            $fullPrompt = $systemPrompt."\n\n".$prompt;

            // Call the AI service with JSON mode
            $response = $this->get_response_from_provider(
                $fullPrompt,
                $model,
                json_mode: true
            );

            // Parse the JSON response
            $recommendations = json_decode($response, true);

            if (! $recommendations || ! isset($recommendations['assignments'])) {
                throw new Exception('Invalid response format from AI service');
            }

            return $recommendations;
        } catch (ClaudeRateLimitException $e) {
            $this->error('Rate limit reached. Please try again later.');

            throw $e;
        } catch (Exception $e) {
            $this->error('Failed to get AI recommendations: '.$e->getMessage());

            // Provide fallback recommendations for testing
            $this->warn('Using fallback recommendations for demonstration...');

            return [
                'assignments' => [
                    [
                        'action' => 'assign_to_existing',
                        'files' => [],
                        'module' => 'system-configuration',
                        'confidence' => 0.0,
                        'reasoning' => 'AI service unavailable - manual assignment required',
                    ],
                ],
            ];
        }
    }

    /**
     * Handle feedback loop for AI suggestions.
     */
    protected function handleFeedbackLoop(array $processed): void
    {
        $this->info("\n=== Feedback Loop ===");
        $this->info('Please provide feedback on what should be done differently.');

        $feedback = $this->ask('What would you like the AI to change about these assignments?');

        if (empty($feedback)) {
            $this->warn('No feedback provided. Exiting.');

            return;
        }

        // Save feedback to a file for reference
        $feedbackPath = base_path('docs/module-assignment-feedback.txt');
        $feedbackContent = "\n\n--- Feedback at ".Carbon::now()->toIso8601String()." ---\n";
        $feedbackContent .= "Original suggestions:\n".json_encode($processed, JSON_PRETTY_PRINT)."\n\n";
        $feedbackContent .= 'User feedback: '.$feedback."\n";

        File::append($feedbackPath, $feedbackContent);

        $this->info('Feedback saved. Re-running assignment with your feedback...');

        // Re-run the analysis with feedback
        $this->rerunWithFeedback($feedback);
    }

    /**
     * Re-run the assignment process with user feedback.
     */
    protected function rerunWithFeedback(string $feedback): void
    {
        // Get the original data
        $unassignedDocs = $this->aiService->getUnassignedFilesWithDocs();
        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $unassignedDocs = $unassignedDocs->take($limit);
        }

        $moduleSummaries = $this->aiService->extractModuleSummaries();

        // Build prompt with feedback
        $originalPrompt = $this->aiService->buildModuleAssignmentPrompt($unassignedDocs, $moduleSummaries);

        // Add feedback to the prompt
        $promptWithFeedback = $originalPrompt."\n\n## USER FEEDBACK\n\n";
        $promptWithFeedback .= "The user reviewed your previous suggestions and provided this feedback:\n";
        $promptWithFeedback .= $feedback."\n\n";
        $promptWithFeedback .= 'Please provide new suggestions that address this feedback.';

        // Get new recommendations
        $this->info('Getting updated AI recommendations based on your feedback...');
        $recommendations = $this->getAIRecommendations($promptWithFeedback, $this->option('model'));

        // Process and display new recommendations
        $confidenceThreshold = (float) $this->option('confidence');
        $processed = $this->aiService->processAIRecommendations($recommendations, $confidenceThreshold);

        $this->displayRecommendations($processed);

        // Ask again
        if ($this->shouldApplyChanges($processed)) {
            $this->applyChanges($processed);
        }
    }
}
