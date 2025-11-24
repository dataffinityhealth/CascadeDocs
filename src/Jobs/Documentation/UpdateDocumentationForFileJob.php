<?php

namespace Lumiio\CascadeDocs\Jobs\Documentation;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;
use Lumiio\CascadeDocs\Support\ResolvesThinkingEffort;
use Shawnveltman\LaravelOpenai\Exceptions\ClaudeRateLimitException;
use Shawnveltman\LaravelOpenai\ProviderResponseTrait;

class UpdateDocumentationForFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use ProviderResponseTrait;
    use Queueable;
    use ResolvesThinkingEffort;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public string $file_path,
        public string $from_sha,
        public string $to_sha,
        public ?string $model = null
    ) {
        $this->tries = config('cascadedocs.queue.retry_attempts', 3);
        $this->timeout = config('cascadedocs.queue.timeout', 300);
        $this->model = $model ?? config('cascadedocs.ai.default_model');
    }

    public function handle(): void
    {
        $diff_service = new DocumentationDiffService;

        // Check if file still exists
        if (! File::exists($this->file_path)) {
            $this->handle_deleted_file();

            return;
        }

        // Get the current content and diff
        $current_content = File::get($this->file_path);
        $git_diff = $diff_service->get_file_diff($this->file_path, $this->from_sha, $this->to_sha);

        // If this is a new file (no diff), use our tracking generation job
        if (! $git_diff) {
            GenerateAndTrackDocumentationJob::dispatch($this->file_path, $this->to_sha, $this->model);

            return;
        }

        // Load existing documentation
        $existing_docs = $this->load_existing_documentation();

        if (empty($existing_docs)) {
            // No existing documentation, use our tracking generation job
            GenerateAndTrackDocumentationJob::dispatch($this->file_path, $this->to_sha, $this->model);

            return;
        }

        // Generate update prompt
        $prompt = $this->get_update_prompt(
            $current_content,
            $git_diff,
            $existing_docs
        );

        try {
            $response = $this->get_response_from_provider(
                $prompt,
                $this->model,
                json_mode: true,
                thinking_effort: $this->resolveThinkingEffort()
            );
            $updated_docs = json_decode($response, true);

            if (! $updated_docs || ! is_array($updated_docs)) {
                throw new Exception('Invalid JSON response from LLM');
            }

            // Save updated documentation only for tiers that changed
            $changes_made = $this->save_updated_documentation($updated_docs, $existing_docs);

            // Always update the log when we process a file, even if only SHA was updated
            $this->update_file_in_log($this->file_path);
        } catch (ClaudeRateLimitException $e) {
            // Let the job retry automatically
            $this->release(config('cascadedocs.queue.rate_limit_delay', 60)); // Release back to queue

            return;
        } catch (Exception $e) {
            throw new Exception('Failed to update documentation: '.$e->getMessage());
        }
    }

    protected function load_existing_documentation(): array
    {
        $docs = [];
        $tiers = config('cascadedocs.tiers');

        foreach ($tiers as $tier => $folder) {
            $doc_path = $this->get_tier_document_path($this->file_path, $tier);

            if (File::exists($doc_path)) {
                $docs[$tier] = File::get($doc_path);
            }
        }

        return $docs;
    }

    protected function save_updated_documentation(array $updated_docs, array $existing_docs): bool
    {
        $tiers = ['micro' => 'short', 'standard' => 'medium', 'expansive' => 'full'];
        $changes_made = false;
        $current_sha = (new DocumentationDiffService)->get_current_commit_sha();

        foreach ($tiers as $tier => $folder) {
            $doc_path = $this->get_tier_document_path($this->file_path, $tier);

            // Check if this tier has existing documentation
            if (isset($existing_docs[$tier])) {
                // If the tier was updated with new content
                if (isset($updated_docs[$tier]) && $updated_docs[$tier] !== null) {
                    // Skip if content is identical to existing
                    if ($updated_docs[$tier] === $existing_docs[$tier]) {
                        // For expansive tier, still update SHA even if content is unchanged
                        if ($tier === 'expansive') {
                            $this->update_sha_only($doc_path, $current_sha);
                            $changes_made = true;
                        }

                        continue;
                    }

                    // Content changed, write the new documentation
                    $this->write_documentation_file($doc_path, $updated_docs[$tier]);
                    $changes_made = true;
                } else {
                    // No update from AI, but for expansive tier we should still update SHA
                    if ($tier === 'expansive') {
                        $this->update_sha_only($doc_path, $current_sha);
                        $changes_made = true;
                    }
                }
            } elseif (isset($updated_docs[$tier]) && $updated_docs[$tier] !== null) {
                // New documentation for a tier that didn't exist before
                $this->write_documentation_file($doc_path, $updated_docs[$tier]);
                $changes_made = true;
            }
        }

        return $changes_made;
    }

    protected function get_tier_document_path(string $source_file_path, string $tier): string
    {
        $tier_map = config('cascadedocs.tiers');

        $relative_path = Str::after($source_file_path, base_path().DIRECTORY_SEPARATOR);
        $file_extension = pathinfo($source_file_path, PATHINFO_EXTENSION);
        $relative_path = Str::beforeLast($relative_path, '.'.$file_extension);

        $output_path = config('cascadedocs.paths.output');

        return base_path("{$output_path}{$tier_map[$tier]}/{$relative_path}.md");
    }

    protected function write_documentation_file(string $doc_path, string $content): void
    {
        $doc_directory = dirname($doc_path);

        if (! File::exists($doc_directory)) {
            File::makeDirectory($doc_directory, config('cascadedocs.permissions.directory', 0755), true);
        }

        // Update commit SHA in content if it's the expansive tier
        if (Str::contains($doc_path, '/full/')) {
            $current_sha = (new DocumentationDiffService)->get_current_commit_sha();
            $content = preg_replace('/commit_sha:\s*\w+/', "commit_sha: {$current_sha}", $content);
        }

        File::put($doc_path, $content);
    }

    protected function update_sha_only(string $doc_path, string $current_sha): void
    {
        if (! File::exists($doc_path)) {
            return;
        }

        $content = File::get($doc_path);
        $updated_content = preg_replace('/commit_sha:\s*\w+/', "commit_sha: {$current_sha}", $content);

        // Only write if SHA actually changed
        if ($content !== $updated_content) {
            File::put($doc_path, $updated_content);
        }
    }

    protected function handle_deleted_file(): void
    {
        // Remove documentation files for deleted source files
        $tiers = config('cascadedocs.tiers');

        foreach ($tiers as $tier => $folder) {
            $doc_path = $this->get_tier_document_path($this->file_path, $tier);

            if (File::exists($doc_path)) {
                File::delete($doc_path);
            }
        }

        // Update the log to remove this file
        $this->remove_file_from_log($this->file_path);
    }

    protected function update_file_in_log(string $file_path): void
    {
        $diff_service = new DocumentationDiffService;
        $log = $diff_service->load_update_log();

        $relative_path = Str::after($file_path, base_path().DIRECTORY_SEPARATOR);
        $current_sha = $diff_service->get_file_last_commit_sha($file_path);

        $log['files'][$relative_path] = [
            'sha' => $current_sha,
            'last_updated' => Carbon::now()->toIso8601String(),
        ];

        $diff_service->save_update_log($log);
    }

    protected function remove_file_from_log(string $file_path): void
    {
        $diff_service = new DocumentationDiffService;
        $log = $diff_service->load_update_log();

        $relative_path = Str::after($file_path, base_path().DIRECTORY_SEPARATOR);

        unset($log['files'][$relative_path]);

        $diff_service->save_update_log($log);
    }

    protected function get_update_prompt(
        string $current_content,
        string $git_diff,
        array $existing_docs
    ): string {
        $file_extension = pathinfo($this->file_path, PATHINFO_EXTENSION);
        $language_code_block = $file_extension === 'php' ? 'php' : 'javascript';
        $class_name = basename($this->file_path, '.'.$file_extension);

        $existing_docs_section = '';

        foreach ($existing_docs as $tier => $content) {
            $existing_docs_section .= "\n### Current {$tier} documentation:\n{$content}\n";
        }

        return <<<EOT
You are updating documentation for a file that has changed. Your task is to update the existing documentation to reflect the changes while maintaining the same format and structure.

# File Information
File: {$this->file_path}
Class/Component: {$class_name}

# Current File Content
```{$language_code_block}
{$current_content}
```

# Git Diff (showing what changed)
```diff
{$git_diff}
```

# Existing Documentation
{$existing_docs_section}

# Task
Analyze the git diff carefully and determine if documentation needs updating.

**DO NOT UPDATE DOCUMENTATION FOR:**
- Code style changes (formatting, spacing, indentation)
- Variable renaming that doesn't change functionality
- Minor refactoring without behavior changes
- Adding/removing comments
- Import reordering
- Whitespace changes

**ONLY UPDATE DOCUMENTATION FOR:**
- New methods, properties, or functionality added
- Methods, properties, or functionality removed
- Changes in behavior or business logic
- Changes in parameters, return types, or data structures
- New dependencies or integrations
- Breaking changes

IMPORTANT RULES:
1. Return `null` for any tier that doesn't need updating
2. Prefer NOT changing documentation unless absolutely necessary
3. Short (micro) and medium (standard) tiers should rarely change unless there are major functional changes
4. The expansive tier may need updates more often, but still only for meaningful changes
5. Always update the commit SHA in the expansive documentation metadata when making any change to that tier

Return ONLY a valid JSON object with this structure:

{
  "micro": null,  // or updated content ONLY if needed
  "standard": null,  // or updated content ONLY if needed
  "expansive": "Updated content with new commit_sha"  // or null if no changes needed
}

Example response for minor changes:
{
  "micro": null,
  "standard": null,
  "expansive": null
}
EOT;
    }
}
