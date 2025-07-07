<?php

namespace Lumiio\CascadeDocs;

use Lumiio\CascadeDocs\Services\Documentation\DocumentationParser;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService;

class CascadeDocs
{
    public function __construct(
        protected DocumentationParser $parser,
        protected ModuleMetadataService $metadataService,
        protected ModuleMappingService $mappingService
    ) {}

    /**
     * Get documentation for a specific file
     */
    public function getDocumentation(string $filePath, string $tier = 'medium'): ?string
    {
        $tierMap = config('cascadedocs.tiers');

        $outputPath = config('cascadedocs.paths.output', 'docs/source_documents/');
        $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $filePath);
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        $relativePath = substr($relativePath, 0, -(strlen($fileExtension) + 1));

        $docPath = base_path($outputPath.($tierMap[$tier] ?? $tier).'/'.$relativePath.'.md');

        if (! file_exists($docPath)) {
            return null;
        }

        return file_get_contents($docPath);
    }

    /**
     * Get module information for a file
     */
    public function getFileModule(string $filePath): ?array
    {
        return $this->mappingService->getFileModule($filePath);
    }

    /**
     * Get all modules
     */
    public function getModules(): array
    {
        $modules = [];
        $metadataPath = base_path(config('cascadedocs.paths.modules.metadata', 'docs/source_documents/modules/metadata/'));

        if (! is_dir($metadataPath)) {
            return $modules;
        }

        $files = glob($metadataPath.'/*.json');

        foreach ($files as $file) {
            $moduleSlug = basename($file, '.json');
            $metadata = $this->metadataService->loadMetadata($moduleSlug);

            if ($metadata) {
                $modules[] = [
                    'slug' => $moduleSlug,
                    'name' => $metadata['module_name'],
                    'summary' => $metadata['module_summary'] ?? null,
                    'file_count' => count($metadata['files'] ?? []),
                ];
            }
        }

        return $modules;
    }

    /**
     * Get module documentation
     */
    public function getModuleDocumentation(string $moduleSlug): ?string
    {
        $contentPath = base_path(config('cascadedocs.paths.modules.content', 'docs/source_documents/modules/content/'));
        $filePath = $contentPath.$moduleSlug.'.md';

        if (! file_exists($filePath)) {
            return null;
        }

        return file_get_contents($filePath);
    }

    /**
     * Parse documentation from a string
     */
    public function parseDocumentation(string $content): array
    {
        return $this->parser->parse($content);
    }
}
