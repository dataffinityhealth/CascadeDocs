<?php

use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;

beforeEach(function () {
    $this->service = new DocumentationDiffService;
});

test('extract_sha_from_documentation extracts SHA correctly', function () {
    $docContent = <<<'MD'
---
doc_version: 1
doc_tier: expansive
source_path: resources/js/app.js
commit_sha: f71bbb46441bad47dd98a555baf8b980b5175ad1
tags: [javascript, entrypoint, laravel, vite, mix, bootstrap, axios]
references:
  - resources/js/bootstrap.js
  - vite.config.js
---

# App.js Documentation

This is the main entry point.
MD;

    // Create a temporary file
    $tempFile = sys_get_temp_dir().'/test_doc.md';
    File::put($tempFile, $docContent);

    $sha = $this->service->extract_sha_from_documentation($tempFile);

    expect($sha)->toBe('f71bbb46441bad47dd98a555baf8b980b5175ad1');

    // Clean up
    File::delete($tempFile);
});

test('extract_sha_from_documentation returns null for missing SHA', function () {
    $docContent = <<<'MD'
---
doc_version: 1
doc_tier: expansive
source_path: resources/js/app.js
tags: [javascript, entrypoint]
---

# App.js Documentation
MD;

    $tempFile = sys_get_temp_dir().'/test_doc_no_sha.md';
    File::put($tempFile, $docContent);

    $sha = $this->service->extract_sha_from_documentation($tempFile);

    expect($sha)->toBeNull();

    File::delete($tempFile);
});

test('extract_sha_from_documentation returns null for non-existent file', function () {
    $sha = $this->service->extract_sha_from_documentation('/non/existent/file.md');

    expect($sha)->toBeNull();
});
