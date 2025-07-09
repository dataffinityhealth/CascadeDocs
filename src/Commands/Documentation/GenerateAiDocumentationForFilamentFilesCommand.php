<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Shawnveltman\LaravelOpenai\ProviderResponseTrait;

class GenerateAiDocumentationForFilamentFilesCommand extends Command
{
    use ProviderResponseTrait;

    protected $signature = 'cascadedocs:generate-ai-documentation-for-filament-files';

    protected $description = 'Generate AI documentation for Filament files in the Livewire directory';

    public function handle(): void
    {
        $this->info('Starting documentation generation for Filament files...');

        // Create documentation directory if it doesn't exist
        $codeDocPath = base_path(config('cascadedocs.paths.code_documentation'));
        if (! File::exists($codeDocPath)) {
            File::makeDirectory($codeDocPath, config('cascadedocs.permissions.directory', 0755), true);
        }

        // Get all files in the Livewire directory
        $livewirePath = base_path(config('cascadedocs.filament.livewire_path'));
        $livewire_files = $this->get_files_recursively($livewirePath);
        $filament_files = $this->filter_filament_files($livewire_files);

        $this->info('Found '.count($filament_files).' Filament files to document.');

        $progress_bar = $this->output->createProgressBar(count($filament_files));
        $progress_bar->start();

        foreach ($filament_files as $file_path) {
            // Generate documentation file path
            $relative_path = Str::after($file_path, app_path());
            $relative_path = Str::beforeLast($relative_path, '.php');
            $doc_path = base_path(config('cascadedocs.paths.code_documentation').$relative_path.'.md');

            // Skip if documentation already exists
            if (File::exists($doc_path)) {
                $this->line(' <info>Skipping</info> '.$relative_path.' (documentation already exists)');
                $progress_bar->advance();

                continue;
            }

            $file_contents = File::get($file_path);
            $prompt = $this->get_prompt($file_contents);

            $model = config('cascadedocs.ai.filament_model');
            $response = $this->get_response_from_provider($prompt, $model, json_mode: false);

            // Create directory structure if it doesn't exist
            $doc_directory = dirname($doc_path);

            if (! File::exists($doc_directory)) {
                File::makeDirectory($doc_directory, config('cascadedocs.permissions.directory', 0755), true);
            }

            // Write documentation to file
            File::put($doc_path, $response);

            $progress_bar->advance();
        }

        $progress_bar->finish();
        $this->newLine();
        $this->info('Documentation generation completed successfully!');
    }

    private function get_files_recursively(string $directory): array
    {
        $files = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function filter_filament_files(array $files): array
    {
        $filament_files = [];

        foreach ($files as $file) {
            $content = File::get($file);

            if (Str::contains($content, config('cascadedocs.filament.namespace_pattern'))) {
                $filament_files[] = $file;
            }
        }

        return $filament_files;
    }

    /**
     * Generate the prompt for the AI.
     */
    private function get_prompt(string $file_contents): string
    {
        $current_date = Carbon::now()->format('Y-m-d');

        return <<<EOT
Please write comprehensive documentation for the following Laravel Livewire class that uses Filament.

```php
{$file_contents}
```

Format your documentation as Markdown with the following headers:

## Date
{$current_date}

## Class Description
[Provide a thorough description of what this file does, including its purpose and functionality. Use both paragraphs and bullet points.]

## Filament Features
[Explicitly outline the Filament features being used in this file, categorized as follows:]

### Table Column Types
[List and describe any table column types used]

### Table Column Features
[Describe any special table column features or configurations]

### Filter Types
[List and describe any filter types used]

### Filter Features
[Describe any special filter features or configurations]

### Form Field Types
[List and describe any form field types used]

### Form Field Features
[Describe any special form field features or configurations]

### Action Types
[List and describe any action types used]

### Action Features
[Describe any special action features or configurations]

If any section doesn't apply to this file, you can indicate that with "None used in this file." Be comprehensive and specific in your documentation.
EOT;
    }
}
