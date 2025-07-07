<?php

namespace Lumiio\CascadeDocs\Commands\Documentation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;
use Shawnveltman\LaravelOpenai\ProviderResponseTrait;

class GenerateArchitectureDocumentationCommand extends Command
{
    use ProviderResponseTrait;

    protected $signature = 'cascadedocs:generate-architecture-docs 
        {--model= : The AI model to use for generation}';
    
    protected $description = 'Generate high-level architecture documentation from module summaries';

    public function handle()
    {
        $this->info('Starting architecture documentation generation...');
        
        $model = $this->option('model') ?: config('cascadedocs.ai.default_model');
        $metadataService = new ModuleMetadataService();
        
        // Collect all module summaries
        $this->info('Collecting module summaries...');
        $modules = $this->collectModuleSummaries($metadataService);
        
        if (empty($modules)) {
            $this->error('No modules found. Please generate module documentation first.');
            return 1;
        }
        
        $this->info('Found ' . count($modules) . ' modules to analyze.');
        
        // Generate architecture documentation
        $this->info('Generating architecture documentation...');
        
        try {
            $architectureDoc = $this->generateArchitectureDocumentation($modules, $model);
            
            // Save the documentation
            $outputPath = config('cascadedocs.paths.output', 'docs/source_documents/');
            $architecturePath = base_path($outputPath . 'architecture/');
            
            if (!File::exists($architecturePath)) {
                File::makeDirectory($architecturePath, config('cascadedocs.permissions.directory', 0755), true);
            }
            
            $filePath = $architecturePath . config('cascadedocs.paths.architecture.main');
            File::put($filePath, $architectureDoc);
            
            $this->info('✓ Architecture documentation saved to: ' . $filePath);
            
            // Also create a high-level summary
            $summaryDoc = $this->generateArchitectureSummary($modules, $model);
            $summaryPath = $architecturePath . config('cascadedocs.paths.architecture.summary');
            File::put($summaryPath, $summaryDoc);
            
            $this->info('✓ Architecture summary saved to: ' . $summaryPath);
            
        } catch (\Exception $e) {
            $this->error('Failed to generate architecture documentation: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
    
    protected function collectModuleSummaries(ModuleMetadataService $metadataService): array
    {
        $modules = [];
        $modulesPath = base_path(config('cascadedocs.paths.modules.metadata', 'docs/source_documents/modules/metadata/'));
        
        if (!File::exists($modulesPath)) {
            return $modules;
        }
        
        $metadataFiles = File::files($modulesPath);
        
        foreach ($metadataFiles as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            
            $moduleSlug = $file->getFilenameWithoutExtension();
            $metadata = $metadataService->loadMetadata($moduleSlug);
            
            if ($metadata && isset($metadata['module_summary'])) {
                $modules[] = [
                    'name' => $metadata['module_name'],
                    'slug' => $moduleSlug,
                    'summary' => $metadata['module_summary'],
                    'file_count' => count($metadata['files'] ?? []),
                ];
            }
        }
        
        // Sort modules alphabetically
        usort($modules, fn($a, $b) => strcmp($a['name'], $b['name']));
        
        return $modules;
    }
    
    protected function generateArchitectureDocumentation(array $modules, string $model): string
    {
        $moduleSummaries = '';
        foreach ($modules as $module) {
            $moduleSummaries .= "\n\n## {$module['name']} Module\n";
            $moduleSummaries .= "Files: {$module['file_count']}\n\n";
            $moduleSummaries .= $module['summary'];
        }
        
        $prompt = <<<EOT
You are creating comprehensive system architecture documentation based on module summaries.

# Module Summaries
The following modules exist in the system:
{$moduleSummaries}

# Task
Create a comprehensive architecture document that:

1. **System Overview** (200-300 words)
   - Describe the overall system purpose and architecture
   - Identify the architectural patterns used (MVC, DDD, etc.)
   - Explain how modules work together

2. **Core Architecture Components**
   - Group related modules into logical layers (Presentation, Business Logic, Data, Infrastructure, etc.)
   - Explain the role of each layer
   - Show module dependencies and interactions

3. **Data Flow**
   - Describe how data flows through the system
   - Identify key integration points
   - Explain request/response lifecycles

4. **Key Design Decisions**
   - Identify architectural patterns and why they're used
   - Explain module boundaries and responsibilities
   - Discuss scalability and maintainability considerations

5. **Module Interactions**
   - Create a dependency map showing which modules depend on others
   - Identify shared services or utilities
   - Explain communication patterns between modules

6. **Technology Stack**
   - List key technologies and frameworks identified from modules
   - Explain their roles in the architecture

Format the output as a well-structured markdown document with clear sections and subsections.
Do not use placeholders - provide specific, detailed content based on the module information provided.
EOT;

        return $this->get_response_from_provider($prompt, $model);
    }
    
    protected function generateArchitectureSummary(array $modules, string $model): string
    {
        $moduleList = implode("\n", array_map(fn($m) => "- {$m['name']} ({$m['file_count']} files)", $modules));
        
        $prompt = <<<EOT
Create a concise architecture summary document (1-2 pages) based on these modules:

{$moduleList}

The summary should include:
1. **Executive Summary** (100-150 words) - High-level system overview
2. **Architecture Diagram** (ASCII or description) - Visual representation of system layers
3. **Key Components** - Bullet list of major system components
4. **Technology Choices** - Main technologies and their purposes
5. **Deployment Considerations** - Brief notes on deployment architecture

Keep it concise and focused on giving readers a quick understanding of the system architecture.
Format as markdown with clear headings.
EOT;

        return $this->get_response_from_provider($prompt, $model);
    }
}