<?php

namespace Lumiio\CascadeDocs\Jobs\Documentation;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;
use Shawnveltman\LaravelOpenai\Exceptions\ClaudeRateLimitException;
use Shawnveltman\LaravelOpenai\Exceptions\OpenAiApiException;
use Shawnveltman\LaravelOpenai\ProviderResponseTrait;

class UpdateModuleDocumentationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use ProviderResponseTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes for module updates

    public function __construct(
        public string $module_slug,
        public string $to_sha,
        public ?string $model = null
    ) {
        $this->model = $model ?? config('cascadedocs.ai.default_model');
    }

    public function handle(): void
    {
        $metadata_service = new ModuleMetadataService;

        // Log start of job
        logger()->info('UpdateModuleDocumentationJob started', [
            'module_slug' => $this->module_slug,
            'to_sha' => $this->to_sha,
            'model' => $this->model,
        ]);

        // Check if module exists
        if (! $metadata_service->moduleExists($this->module_slug)) {
            throw new Exception("Module not found: {$this->module_slug}");
        }

        // Get metadata first - make a working copy
        $metadata = $metadata_service->loadMetadata($this->module_slug);
        $original_metadata = $metadata; // Keep original in case we need to revert

        // Load current module content or create placeholder
        $modulesContentPath = config('cascadedocs.paths.modules.content');
        $content_file_path = base_path("{$modulesContentPath}{$this->module_slug}.md");
        $original_content = null; // Store original content for rollback

        if (! File::exists($content_file_path)) {
            // Create placeholder content
            $module_name = $metadata['module_name'];
            $current_module_content = "# {$module_name} Module\n\n## Overview\n\n[To be documented]\n\n## How This Module Works\n\n[To be documented]\n";

            // Ensure directory exists
            $contentDir = dirname($content_file_path);

            if (! File::exists($contentDir)) {
                File::makeDirectory($contentDir, 0755, true);
            }

            // Create the file
            File::put($content_file_path, $current_module_content);
        } else {
            $original_content = File::get($content_file_path);
            $current_module_content = $original_content;
        }

        // Check for undocumented files
        $undocumented_files = $metadata['undocumented_files'] ?? [];

        if (empty($undocumented_files)) {
            // No undocumented files, no update needed
            logger()->info('No undocumented files for module, skipping update', [
                'module_slug' => $this->module_slug,
            ]);

            return;
        }

        logger()->info('Found undocumented files for module', [
            'module_slug' => $this->module_slug,
            'undocumented_count' => count($undocumented_files),
            'files' => $undocumented_files,
        ]);

        // Collect full documentation for undocumented files
        $files_documentation = $this->collect_files_documentation($undocumented_files);

        if ($files_documentation->isEmpty()) {
            return;
        }

        // Generate update prompt
        $prompt = $this->get_module_update_prompt(
            $this->module_slug,
            $metadata['module_name'],
            $current_module_content,
            $files_documentation
        );

        logger()->info('Generated module update prompt', [
            'module_slug' => $this->module_slug,
            'prompt_length' => strlen($prompt),
            'files_count' => $files_documentation->count(),
        ]);

        try {
            logger()->info('Calling AI provider for module update', [
                'module_slug' => $this->module_slug,
                'model' => $this->model,
            ]);

            $response = $this->get_response_from_provider($prompt, $this->model, json_mode: false);

            // Validate response doesn't contain placeholders
            $placeholder_patterns = [
                '/\[insert[^\]]*\]/i',
                '/\[to be[^\]]*\]/i',
                '/\[describe[^\]]*\]/i',
                '/\[add[^\]]*\]/i',
                '/\[your[^\]]*\]/i',
                '/\[TODO[^\]]*\]/i',
                '/\[PLACEHOLDER[^\]]*\]/i',
            ];

            foreach ($placeholder_patterns as $pattern) {
                if (preg_match($pattern, $response)) {
                    throw new Exception('AI response contains placeholder text. Retrying with clearer instructions.');
                }
            }

            // Extract module summary before saving anything
            $module_summary = $this->extract_module_summary($response);

            // Now perform all updates in a safe order
            // 1. Save the updated content file first (this is most likely to fail)
            File::put($content_file_path, $response);

            // 2. Update metadata in memory only
            if ($module_summary) {
                $metadata['module_summary'] = $module_summary;
            }

            // 3. Mark files as documented in memory
            foreach ($undocumented_files as $file) {
                // Remove from undocumented list
                $metadata['undocumented_files'] = array_values(
                    array_diff($metadata['undocumented_files'], [$file])
                );

                // Check if already in documented files
                $exists = collect($metadata['files'])->firstWhere('path', $file);

                if (! $exists) {
                    // Add to documented files
                    $metadata['files'][] = [
                        'path' => $file,
                        'documented' => true,
                        'documentation_tier' => $this->getDocumentationTier($file),
                        'added_date' => Carbon::now()->toIso8601String(),
                    ];
                }
            }

            // 4. Update the git commit SHA in metadata
            $metadata['git_commit_sha'] = $this->to_sha;

            // 5. Save the metadata as the last step
            $metadata_service->saveMetadata($this->module_slug, $metadata);

            // 6. Update the log (this is less critical)
            $this->update_module_in_log($this->module_slug);

            logger()->info('Successfully updated module documentation', [
                'module_slug' => $this->module_slug,
                'files_documented' => count($undocumented_files),
                'content_length' => strlen($response),
            ]);
        } catch (ClaudeRateLimitException $e) {
            logger()->warning('Claude rate limit hit for module update', [
                'module_slug' => $this->module_slug,
                'error' => $e->getMessage(),
            ]);

            // Restore original content if it was modified
            if ($original_content !== null && File::exists($content_file_path)) {
                File::put($content_file_path, $original_content);
            }

            // Let the job retry automatically
            $this->release(120); // Release back to queue after 2 minutes

            return;
        } catch (OpenAiApiException $e) {
            logger()->error('OpenAI API error for module update', [
                'module_slug' => $this->module_slug,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'prompt_length' => strlen($prompt ?? ''),
                'model' => $this->model,
            ]);

            // Restore original content if it was modified
            if ($original_content !== null && File::exists($content_file_path)) {
                File::put($content_file_path, $original_content);
            }

            // For token limit errors, don't retry
            if (str_contains($e->getMessage(), 'maximum context length') ||
                str_contains($e->getMessage(), 'token')) {
                logger()->error('Token limit exceeded, failing job permanently', [
                    'module_slug' => $this->module_slug,
                ]);
                $this->fail($e);

                return;
            }

            throw $e;
        } catch (Exception $e) {
            logger()->error('Failed to update module documentation', [
                'module_slug' => $this->module_slug,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'prompt_length' => strlen($prompt ?? ''),
                'model' => $this->model,
            ]);

            // Restore original content if it was modified
            if ($original_content !== null && File::exists($content_file_path)) {
                File::put($content_file_path, $original_content);
            }

            throw new Exception("Failed to update module documentation for {$this->module_slug}: ".$e->getMessage());
        }
    }

    protected function collect_files_documentation(array $files): Collection
    {
        $documentation = collect();

        foreach ($files as $file) {
            // Try to find full documentation first
            $doc_path = $this->get_full_documentation_path($file);

            if (! File::exists($doc_path)) {
                // Try medium tier
                $doc_path = $this->get_medium_documentation_path($file);
            }

            if (File::exists($doc_path)) {
                $doc_content = File::get($doc_path);

                $documentation->push([
                    'path' => $file,
                    'documentation' => $doc_content,
                ]);
            }
        }

        return $documentation;
    }

    protected function get_full_documentation_path(string $file): string
    {
        $doc_file = preg_replace('/\.(php|js|blade\.php)$/', '', $file).'.md';
        $outputPath = config('cascadedocs.paths.output');
        $tierDir = config('cascadedocs.tiers.expansive', 'full');

        return base_path("{$outputPath}{$tierDir}/{$doc_file}");
    }

    protected function get_medium_documentation_path(string $file): string
    {
        $doc_file = preg_replace('/\.(php|js|blade\.php)$/', '', $file).'.md';
        $outputPath = config('cascadedocs.paths.output');
        $tierDir = config('cascadedocs.tiers.standard', 'medium');

        return base_path("{$outputPath}{$tierDir}/{$doc_file}");
    }

    protected function update_module_in_log(string $module_slug): void
    {
        $diff_service = new DocumentationDiffService;
        $log = $diff_service->load_update_log();

        $log['modules'][$module_slug] = [
            'sha' => $this->to_sha,
            'last_updated' => Carbon::now()->toIso8601String(),
        ];

        $diff_service->save_update_log($log);
    }

    protected function extract_module_summary(string $content): ?string
    {
        // Look for ## Overview section
        if (preg_match('/^## Overview\s*\n\n(.+?)(?=\n##|\z)/sm', $content, $matches)) {
            $overview = trim($matches[1]);

            // Truncate to approximately 200 words
            $words = str_word_count($overview, 1);

            if (count($words) > 200) {
                $truncated = implode(' ', array_slice($words, 0, 200));

                // Try to end on a complete sentence
                if (preg_match('/^(.+[.!?])\s/s', $truncated, $sentence_match)) {
                    return trim($sentence_match[1]);
                }

                return $truncated.'...';
            }

            return $overview;
        }

        // If no overview section, try to get first paragraph after title
        if (preg_match('/^# .+?\n\n(.+?)(?=\n##|\z)/sm', $content, $matches)) {
            $first_paragraph = trim($matches[1]);

            // Truncate to approximately 200 words
            $words = str_word_count($first_paragraph, 1);

            if (count($words) > 200) {
                $truncated = implode(' ', array_slice($words, 0, 200));

                // Try to end on a complete sentence
                if (preg_match('/^(.+[.!?])\s/s', $truncated, $sentence_match)) {
                    return trim($sentence_match[1]);
                }

                return $truncated.'...';
            }

            return $first_paragraph;
        }

        return null;
    }

    protected function getDocumentationTier(string $file): string
    {
        $tiers = config('cascadedocs.tier_directories', ['full', 'medium', 'short']);

        // Remove extension and add .md
        $doc_file = preg_replace('/\.(php|js|blade\.php)$/', '', $file).'.md';
        $outputPath = config('cascadedocs.paths.output');

        foreach ($tiers as $tier) {
            $doc_path = base_path("{$outputPath}{$tier}/{$doc_file}");

            if (File::exists($doc_path)) {
                return $tier;
            }
        }

        return 'unknown';
    }

    protected function get_module_update_prompt(
        string $module_slug,
        string $module_name,
        string $current_module_content,
        Collection $files_documentation
    ): string {
        $files_section = '';

        foreach ($files_documentation as $file_info) {
            $files_section .= "\n\n## File: {$file_info['path']}\n\n{$file_info['documentation']}";
        }

        return <<<EOT
You are updating module documentation to incorporate new files that have been added to the module.

# Module Information
Module Name: {$module_name}
Module Slug: {$module_slug}

# Current Module Documentation
{$current_module_content}

# New Files to Incorporate
The following files have been added to this module and need to be incorporated into the documentation:
{$files_section}

# Task
Update the module documentation to incorporate these new files. Your response should be the COMPLETE updated module documentation in markdown format.

## Critical Requirements:

1. **NO PLACEHOLDERS**: Do not use placeholder text like "[insert details here]", "[to be documented]", "[describe...]", or any similar brackets. Write complete, informative content.

2. **CONCRETE DETAILS**: Provide specific, factual information based on the code you've been shown. If you see a class, describe what it does. If you see methods, explain their purpose.

3. **MAINTAIN STRUCTURE**: Keep the same section structure as the existing documentation but expand and update content as needed.

4. **INTEGRATION**: Seamlessly integrate information about new files into existing sections. Don't just append - weave the new content throughout.

5. **OVERVIEW SECTION**: The overview must be 150-200 words and clearly explain:
   - What the module does
   - Its primary purpose in the system
   - Key capabilities and features
   - Main components it provides

6. **COMPLETE CONTENT**: Return the ENTIRE module documentation, not just changes. Include all sections with full content.

## Expected Output Format:

Your response should be a complete markdown document starting with:
# {$module_name} Module

Followed by all necessary sections with detailed, specific content based on the actual code and functionality shown in the file documentation.

Remember: Write as if you're explaining to a new developer who needs to understand this module. Be specific, be complete, no placeholders.
- Complete "How This Module Works" section with integrated file descriptions
- Complete workflows section (updated if needed)
- All other sections in their entirety
- Related documentation section

DO NOT return placeholders, summaries, or partial content. Return the FULL documentation with the new files properly integrated.
EOT;
    }
}
