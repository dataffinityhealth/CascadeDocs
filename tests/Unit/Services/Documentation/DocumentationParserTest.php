<?php

namespace Lumiio\CascadeDocs\Tests\Unit\Services\Documentation;

use Exception;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationParser;
use Lumiio\CascadeDocs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DocumentationParserTest extends TestCase
{
    protected DocumentationParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DocumentationParser;
    }

    #[Test]
    public function it_extracts_module_summary_correctly(): void
    {
        // Given - A module file with proper structure
        $moduleContent = <<<'EOT'
---
doc_version: 1.0
doc_tier: module
module_name: Test Module
module_slug: test-module
generated_at: 2025-06-27T12:00:00Z
git_commit_sha: abcdef123
total_files: 5
---

# Test Module

## Overview

This is the overview section that should be extracted.
It contains multiple lines of content.

Including this line.

## How This Module Works

This section should not be included.
EOT;

        // Create a temporary file
        $tempFile = storage_path('app/test-module.md');
        File::put($tempFile, $moduleContent);

        // When - Extract the module summary
        $summary = $this->parser->extractModuleSummary($tempFile);

        // Then - Only the overview section is extracted
        $expectedSummary = <<<'EOT'
# Test Module

## Overview

This is the overview section that should be extracted.
It contains multiple lines of content.

Including this line.
EOT;

        $this->assertEquals($expectedSummary, $summary);

        // Cleanup
        File::delete($tempFile);
    }

    #[Test]
    public function it_throws_exception_for_invalid_module_format(): void
    {
        // Given - A module file without proper markers
        $invalidContent = <<<'EOT'
# Module without proper format

This file doesn't have the required structure.
EOT;

        $tempFile = storage_path('app/invalid-module.md');
        File::put($tempFile, $invalidContent);

        // When/Then - Expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid module format');

        $this->parser->extractModuleSummary($tempFile);

        // Cleanup
        File::delete($tempFile);
    }

    #[Test]
    public function it_checks_if_file_has_short_documentation(): void
    {
        // Given - Mock file system responses
        File::shouldReceive('exists')
            ->once()
            ->with(base_path('docs/source_documents/short/app/Services/TestService.md'))
            ->andReturn(true);

        // When - Check if file has documentation
        $hasDoc = $this->parser->hasShortDocumentation('app/Services/TestService.php');

        // Then - Should return true
        $this->assertTrue($hasDoc);
    }

    #[Test]
    public function it_returns_null_for_missing_short_documentation(): void
    {
        // Given - A file without short documentation
        File::shouldReceive('exists')
            ->once()
            ->with(base_path('docs/source_documents/short/app/Services/MissingDoc.md'))
            ->andReturn(false);

        // When - Try to get short documentation
        $doc = $this->parser->getShortDocumentation('app/Services/MissingDoc.php');

        // Then - Should return null
        $this->assertNull($doc);
    }

    #[Test]
    public function it_extracts_module_metadata_correctly(): void
    {
        // Given - A module file with metadata
        $moduleContent = <<<'EOT'
---
doc_version: 1.0
doc_tier: module
module_name: Test Module
module_slug: test-module
generated_at: 2025-06-27T12:00:00Z
git_commit_sha: abcdef123
total_files: 5
---

# Content after metadata
EOT;

        $tempFile = storage_path('app/metadata-test.md');
        File::put($tempFile, $moduleContent);

        // When - Extract metadata
        $metadata = $this->parser->extractModuleMetadata($tempFile);

        // Then - Metadata is parsed correctly
        $this->assertEquals('1.0', $metadata['doc_version']);
        $this->assertEquals('module', $metadata['doc_tier']);
        $this->assertEquals('Test Module', $metadata['module_name']);
        $this->assertEquals('test-module', $metadata['module_slug']);
        $this->assertEquals('5', $metadata['total_files']);

        // Cleanup
        File::delete($tempFile);
    }

    #[Test]
    public function it_handles_batch_documentation_retrieval(): void
    {
        // Given - Multiple files
        $files = [
            'app/Services/Service1.php',
            'app/Services/Service2.php',
            'app/Services/Service3.php',
        ];

        // Mock file existence
        File::shouldReceive('exists')
            ->with(base_path('docs/source_documents/short/app/Services/Service1.md'))
            ->andReturn(true);
        File::shouldReceive('get')
            ->with(base_path('docs/source_documents/short/app/Services/Service1.md'))
            ->andReturn('Documentation for Service1');

        File::shouldReceive('exists')
            ->with(base_path('docs/source_documents/short/app/Services/Service2.md'))
            ->andReturn(false);

        File::shouldReceive('exists')
            ->with(base_path('docs/source_documents/short/app/Services/Service3.md'))
            ->andReturn(true);
        File::shouldReceive('get')
            ->with(base_path('docs/source_documents/short/app/Services/Service3.md'))
            ->andReturn('Documentation for Service3');

        // When - Get batch documentation
        $docs = $this->parser->getShortDocumentationBatch($files);

        // Then - Only files with documentation are returned
        $this->assertCount(2, $docs);
        $this->assertEquals('Documentation for Service1', $docs['app/Services/Service1.php']);
        $this->assertEquals('Documentation for Service3', $docs['app/Services/Service3.php']);
        $this->assertArrayNotHasKey('app/Services/Service2.php', $docs->toArray());
    }
}
