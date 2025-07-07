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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shawnveltman\LaravelOpenai\Exceptions\ClaudeRateLimitException;
use Shawnveltman\LaravelOpenai\ProviderResponseTrait;

class GenerateAiDocumentationForFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use ProviderResponseTrait;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public string $file_path,
        public string $tier = 'all',
        public ?string $model = null
    ) {
        $this->tries = config('cascadedocs.queue.retry_attempts', 3);
        $this->timeout = config('cascadedocs.queue.timeout', 300);
        $this->model = $model ?? config('cascadedocs.ai.default_model', 'o3');
    }

    public function handle(): void
    {
        // Log the start of documentation generation
        Log::info('CascadeDocs: Starting documentation generation', [
            'file' => $this->file_path,
            'tier' => $this->tier,
            'model' => $this->model,
        ]);

        // Check if documentation already exists for the requested tiers
        $tiers_to_check = $this->tier === 'all' ? config('cascadedocs.tier_names') : [$this->tier];
        $existing_tiers = [];

        foreach ($tiers_to_check as $tier_to_check) {
            $doc_path = $this->get_tier_document_path($this->file_path, $tier_to_check);

            if (File::exists($doc_path)) {
                $existing_tiers[] = $tier_to_check;
            }
        }

        // Skip if all requested tiers already exist
        if (count($existing_tiers) === count($tiers_to_check)) {
            Log::info('CascadeDocs: Skipping - documentation already exists', [
                'file' => $this->file_path,
                'existing_tiers' => $existing_tiers,
            ]);

            return;
        }

        $class_name = basename($this->file_path, '.php');
        $commit_sha = trim(exec('git rev-parse HEAD'));
        $file_contents = File::get($this->file_path);
        $file_extension = pathinfo($this->file_path, PATHINFO_EXTENSION);
        $relative_path = Str::after($this->file_path, base_path().DIRECTORY_SEPARATOR);

        // Get all three tiers in one LLM call
        $prompt = $this->get_unified_prompt($file_contents, $file_extension, $class_name, $relative_path, $commit_sha);

        try {
            Log::info('CascadeDocs: Sending request to AI provider', [
                'file' => $this->file_path,
                'prompt_length' => strlen($prompt),
            ]);

            $response = $this->get_response_from_provider($prompt, $this->model, json_mode: true);
            $documentation = json_decode($response, true);

            if (! $documentation || ! is_array($documentation)) {
                throw new Exception('Invalid JSON response from LLM');
            }

            // Validate response for truncation - now just logs warnings
            $this->validateResponseForTruncation($documentation);
        } catch (ClaudeRateLimitException $e) {
            // Let the job retry automatically
            $this->release(config('cascadedocs.queue.rate_limit_delay', 60)); // Release back to queue

            return;
        } catch (Exception $e) {
            throw new Exception('Failed to generate documentation: '.$e->getMessage());
        }

        $tiers_to_save = $this->tier === 'all' ? config('cascadedocs.tier_names') : [$this->tier];
        $written_files = [];

        try {
            foreach ($tiers_to_save as $current_tier) {
                if (isset($documentation[$current_tier])) {
                    $doc_path = $this->get_tier_document_path($this->file_path, $current_tier);
                    $this->write_documentation_file($doc_path, $documentation[$current_tier]);
                    $written_files[] = $doc_path;
                }
            }

            // Log successful completion
            Log::info('CascadeDocs: Documentation generation completed successfully', [
                'file' => $this->file_path,
                'tiers_saved' => $tiers_to_save,
                'files_written' => count($written_files),
            ]);
        } catch (Exception $e) {
            // Rollback any files that were written
            foreach ($written_files as $file_path) {
                if (File::exists($file_path)) {
                    File::delete($file_path);
                }
            }

            Log::error('CascadeDocs: Failed to write documentation files', [
                'file' => $this->file_path,
                'error' => $e->getMessage(),
                'files_rolled_back' => count($written_files),
            ]);

            throw $e;
        }
    }

    private function get_tier_document_path(string $source_file_path, string $tier): string
    {
        $tier_map = config('cascadedocs.tiers');

        $relative_path = Str::after($source_file_path, base_path().DIRECTORY_SEPARATOR);
        $file_extension = pathinfo($source_file_path, PATHINFO_EXTENSION);
        $relative_path = Str::beforeLast($relative_path, '.'.$file_extension);

        $outputPath = config('cascadedocs.paths.output');

        return base_path("{$outputPath}{$tier_map[$tier]}/{$relative_path}.md");
    }

    private function write_documentation_file(string $doc_path, string $content): void
    {
        $doc_directory = dirname($doc_path);

        if (! File::exists($doc_directory)) {
            File::makeDirectory($doc_directory, config('cascadedocs.permissions.directory', 0755), true);
        }

        File::put($doc_path, $content);
    }

    private function validateResponseForTruncation(array $documentation): void
    {
        $truncationPatterns = [
            '/\.\.\.$/',
            '/\[truncated\]/i',
            '/\[.*to be added.*\]/i',
            '/\[.*details here.*\]/i',
            '/\[.*insert.*\]/i',
            '/\[.*placeholder.*\]/i',
            '/\[.*TODO.*\]/i',
        ];

        $minLengths = [
            'micro' => 30,      // Reduced from 50
            'standard' => 100,  // Reduced from 200
            'expansive' => 300, // Reduced from 500
        ];

        $warnings = [];

        foreach ($documentation as $tier => $content) {
            if (! in_array($tier, config('cascadedocs.tier_names'))) {
                continue;
            }

            // Check for truncation patterns
            foreach ($truncationPatterns as $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    $warnings[] = "Tier {$tier} contains potential placeholder: {$matches[0]}";
                    Log::warning('CascadeDocs: Potential placeholder detected', [
                        'file' => $this->file_path,
                        'tier' => $tier,
                        'pattern' => $pattern,
                        'match' => $matches[0],
                        'content_preview' => substr($content, max(0, strpos($content, $matches[0]) - 50), 200),
                    ]);
                }
            }

            // Check minimum content length
            if (strlen($content) < ($minLengths[$tier] ?? 30)) {
                $warnings[] = "Tier {$tier} seems short (length: ".strlen($content).')';
                Log::warning('CascadeDocs: Documentation seems short', [
                    'file' => $this->file_path,
                    'tier' => $tier,
                    'length' => strlen($content),
                    'expected_min' => $minLengths[$tier] ?? 30,
                    'content_preview' => substr($content, 0, 100),
                ]);
            }

            // Only check for obvious truncation indicators
            $trimmedContent = trim($content);

            // Check if it ends with incomplete markdown or code block
            if (preg_match('/```[^`]*$/', $trimmedContent)) {
                $warnings[] = "Tier {$tier} ends with incomplete code block";
                Log::warning('CascadeDocs: Potential truncation - incomplete code block', [
                    'file' => $this->file_path,
                    'tier' => $tier,
                    'ending' => substr($trimmedContent, -100),
                ]);
            } elseif (preg_match('/`[^`]$/', $trimmedContent)) {
                $warnings[] = "Tier {$tier} ends with incomplete inline code";
                Log::warning('CascadeDocs: Potential truncation - incomplete inline code', [
                    'file' => $this->file_path,
                    'tier' => $tier,
                    'ending' => substr($trimmedContent, -50),
                ]);
            } elseif (preg_match('/\|\s*$/', $trimmedContent)) {
                $warnings[] = "Tier {$tier} ends with incomplete table";
                Log::warning('CascadeDocs: Potential truncation - incomplete table', [
                    'file' => $this->file_path,
                    'tier' => $tier,
                    'ending' => substr($trimmedContent, -100),
                ]);
            }
        }

        // Log a summary if there were any warnings
        if (! empty($warnings)) {
            Log::info('CascadeDocs: Documentation validation warnings', [
                'file' => $this->file_path,
                'warnings' => $warnings,
                'note' => 'Documentation was still saved despite warnings',
            ]);
        } else {
            Log::info('CascadeDocs: Documentation validation passed', [
                'file' => $this->file_path,
            ]);
        }
    }

    private function get_unified_prompt(string $file_contents, string $file_extension, string $class_name, string $source_path, string $commit_sha): string
    {
        $language_code_block = $file_extension === 'php' ? 'php' : 'javascript';
        $current_date = Carbon::now()->format('Y-m-d');

        return <<<EOT
You are an expert technical writer specializing in PHP/Laravel documentation.

Your task is to produce documentation at THREE different levels for the following code. Return your response as a valid JSON object with three keys: "micro", "standard", and "expansive".

# Context
File path: **{$source_path}**
Current commit: **{$commit_sha}**
Generated on: **{$current_date}**

```{$language_code_block}
{$file_contents}
```

# Output Format
Return ONLY a valid JSON object (no markdown code blocks, no extra text) with this exact structure:

{
  "micro": "Your micro-blurb content here",
  "standard": "Your standard summary content here", 
  "expansive": "Your expansive documentation content here"
}

# Tier 1: Micro-blurb (key: "micro")
Write a **single brief paragraph (≤ 120 words)** that tells a developer what this class does and why it matters, without listing code, parameters, or implementation details.

Rules:
- Plain English (avoid jargon)
- No code snippets
- Maximum 120 words
- Format as: "## {$class_name} · Micro-blurb\n\n[Your paragraph]"

# Tier 2: Standard Summary (key: "standard")
Write a concise **Standard summary (≤ 500 words)** explaining WHAT the class does, HOW it behaves, and WHEN to use it.

Format exactly as:
```yaml
doc_tier: standard
doc_version: 1
```

# {$class_name}

## Purpose
[One or two sentence purpose]

## Behaviour & Flow
[Step-by-step description of how it works]

## Responsibilities
- [Responsibility 1]
- [Responsibility 2]
- [Responsibility 3]

## Inputs / Outputs
- **Inputs** – [key data the class consumes]
- **Side-effects** – [queues, DB writes, external calls, etc.]

## Usage Context
[A short, code-free example scenario]

## Constraints & Error Conditions
- [Any constraints or error cases]

# Tier 3: Expansive Documentation (key: "expansive")
Create a **comprehensive Markdown document** with no word limit.

Format exactly as:
```yaml
doc_version: 1
doc_tier: expansive
source_path: {$source_path}
commit_sha: {$commit_sha}
tags: [comma, separated, relevant, tags]
references:
  - [Related class or component 1]
  - [Related class or component 2]
```

# {$class_name}

## File Purpose
[Comprehensive overview paragraph]

## Public API
```{$language_code_block}
// Only method signatures, no bodies
```

## Behaviour Flow
1. [Step 1]
2. [Step 2]
...

## Key Properties & State
| Property | Type | Role | Nullable |
|----------|------|------|----------|
| \$example | string | Description | no |

## Internal Details
[Describe algorithms, helpers, and private methods]

## Security Considerations
- [Security note 1]
- [Security note 2]

## Performance Notes
- [Performance consideration 1]
- [Performance consideration 2]

## Troubleshooting / Common Pitfalls
| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| ... | ... | ... |

## Example Usage
```{$language_code_block}
// Practical example showing how to use this class
```

## Related Components
- **[Component A]** – [how it interacts]
- **[Component B]** – [why it's relevant]

Remember: Return ONLY the JSON object with all three tiers.

# CRITICAL REQUIREMENTS:
- You MUST provide COMPLETE documentation for all three tiers
- DO NOT truncate, summarize, or use ellipsis (...) in any tier
- DO NOT use placeholders like [details here], [to be added], or [insert X]
- Each tier must be fully written according to its requirements
- If content seems long, that's expected - provide the full documentation
- Return a complete, valid JSON object with all content
EOT;
    }
}
