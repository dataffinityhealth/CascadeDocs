<?php

namespace Lumiio\CascadeDocs\Services\Documentation;

use Illuminate\Support\Facades\File;

class ModuleFileUpdater
{
    protected ModuleMetadataService $metadataService;
    protected string $contentPath;
    protected ?string $currentSlug = null;

    public function __construct()
    {
        $this->metadataService = new ModuleMetadataService();
        $this->contentPath     = base_path('docs/source_documents/modules/content');
    }

    /**
     * Add files to a module (updates metadata only).
     */
    public function addFiles(string $moduleSlug, array $files, bool $documented = false): void
    {
        $this->metadataService->addFiles($moduleSlug, $files, $documented);
    }

    /**
     * Remove files from a module.
     */
    public function removeFiles(string $moduleSlug, array $files): void
    {
        $this->metadataService->removeFiles($moduleSlug, $files);
    }

    /**
     * Mark files as documented after content generation.
     */
    public function markFilesAsDocumented(string $moduleSlug, array $files): void
    {
        $this->metadataService->markFilesAsDocumented($moduleSlug, $files);
    }

    /**
     * Load module content.
     */
    public function loadContent(string $moduleSlug): ?string
    {
        $contentFile = "{$this->contentPath}/{$moduleSlug}.md";

        if (! File::exists($contentFile))
        {
            return null;
        }

        return File::get($contentFile);
    }

    /**
     * Save module content.
     */
    public function saveContent(string $moduleSlug, string $content): void
    {
        $contentFile = "{$this->contentPath}/{$moduleSlug}.md";
        File::put($contentFile, $content);
    }

    /**
     * Create a new module.
     */
    public function createModule(array $moduleData): void
    {
        $this->metadataService->createModule($moduleData);
    }

    /**
     * Check if module exists.
     */
    public function moduleExists(string $slug): bool
    {
        return $this->metadataService->moduleExists($slug);
    }

    /**
     * Get module metadata.
     */
    public function getMetadata(string $moduleSlug): ?array
    {
        return $this->metadataService->loadMetadata($moduleSlug);
    }

    /**
     * Get all files in a module.
     */
    public function getAllFiles(string $moduleSlug): array
    {
        return $this->metadataService->getAllModuleFiles($moduleSlug);
    }

    /**
     * Legacy support - load module using old path structure.
     * This method is deprecated and will be removed after migration.
     */
    public function loadModule(string $modulePath): self
    {
        // Extract slug from path
        $slug = basename($modulePath, '.md');

        // If it's an old-style path, just return self for compatibility
        // The actual operations will use the new metadata service
        $this->currentSlug = $slug;

        return $this;
    }

    /**
     * Legacy support - save using old path structure.
     * This method is deprecated and will be removed after migration.
     */
    public function save(string $modulePath): void
    {
        // Extract slug from path
        $slug = basename($modulePath, '.md');

        // This is a no-op for now as we're using the new structure
        // Files are already saved through the metadata service
    }

    /**
     * Get current file count.
     */
    public function getFileCount(): int
    {
        if (! isset($this->currentSlug))
        {
            return 0;
        }

        $metadata = $this->metadataService->loadMetadata($this->currentSlug);

        return $metadata ? $metadata['statistics']['total_files'] : 0;
    }
}
