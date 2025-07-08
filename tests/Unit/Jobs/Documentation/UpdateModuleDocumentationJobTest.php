<?php

namespace Lumiio\CascadeDocs\Tests\Unit\Jobs\Documentation;

use Illuminate\Support\Facades\Config;
use Lumiio\CascadeDocs\Jobs\Documentation\UpdateModuleDocumentationJob;
use Lumiio\CascadeDocs\Tests\TestCase;

class UpdateModuleDocumentationJobTest extends TestCase
{
    public function test_it_initializes_with_correct_configuration(): void
    {
        Config::set('cascadedocs.ai.default_model', 'gpt-4');
        
        $job = new UpdateModuleDocumentationJob(
            'test-module',
            'abc123'
        );

        $this->assertEquals('test-module', $job->module_slug);
        $this->assertEquals('abc123', $job->to_sha);
        $this->assertEquals('gpt-4', $job->model);
    }

    public function test_it_accepts_custom_model(): void
    {
        $job = new UpdateModuleDocumentationJob(
            'test-module',
            'abc123',
            'claude-3'
        );

        $this->assertEquals('claude-3', $job->model);
    }
}