<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Jobs\Documentation;

use Lumiio\CascadeDocs\Jobs\Documentation\GenerateAiDocumentationForFileJob;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Lumiio\CascadeDocs\Tests\TestCase;

class GenerateAiDocumentationForFileJobTest extends TestCase
{
    protected string $test_file_path;
    protected string $test_file_content;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test file
        $this->test_file_path    = base_path('app/TestClass.php');
        $this->test_file_content = <<<'PHP'
<?php

namespace App;

class TestClass
{
    public function test_method()
    {
        return 'test';
    }
}
PHP;

        File::put($this->test_file_path, $this->test_file_content);

        // Clean up any existing documentation
        $this->cleanup_documentation_files();
    }

    protected function tearDown(): void
    {
        // Clean up test file
        if (File::exists($this->test_file_path))
        {
            File::delete($this->test_file_path);
        }

        // Clean up documentation files
        $this->cleanup_documentation_files();

        parent::tearDown();
    }

    #[Test]
    public function it_generates_documentation_for_a_file(): void
    {
        // Given we have a mocked OpenAI response
        $this->mock_openai_response();

        // When we dispatch the job
        $job = new GenerateAiDocumentationForFileJob($this->test_file_path, 'all', 'o3');
        $job->handle();

        // Then documentation files should be created
        $this->assertFileExists(base_path('docs/source_documents/short/app/TestClass.md'));
        $this->assertFileExists(base_path('docs/source_documents/medium/app/TestClass.md'));
        $this->assertFileExists(base_path('docs/source_documents/full/app/TestClass.md'));

        // And they should contain the expected content
        $short_content = File::get(base_path('docs/source_documents/short/app/TestClass.md'));
        $this->assertStringContainsString('TestClass · Micro-blurb', $short_content);

        $medium_content = File::get(base_path('docs/source_documents/medium/app/TestClass.md'));
        $this->assertStringContainsString('doc_tier: standard', $medium_content);

        $full_content = File::get(base_path('docs/source_documents/full/app/TestClass.md'));
        $this->assertStringContainsString('doc_tier: expansive', $full_content);
    }

    #[Test]
    public function it_skips_processing_when_documentation_already_exists(): void
    {
        // Given we have existing documentation files
        $this->create_existing_documentation();

        // And we mock the OpenAI API (it should not be called)
        Http::fake([
            '*' => Http::response('Should not be called', 500),
        ]);

        // When we dispatch the job
        $job = new GenerateAiDocumentationForFileJob($this->test_file_path, 'all', 'o3');
        $job->handle();

        // Then no HTTP requests should have been made
        Http::assertNothingSent();
    }

    #[Test]
    public function it_generates_only_requested_tier(): void
    {
        // Given we have a mocked OpenAI response
        $this->mock_openai_response();

        // When we dispatch the job for only 'micro' tier
        $job = new GenerateAiDocumentationForFileJob($this->test_file_path, 'micro', 'o3');
        $job->handle();

        // Then only the micro documentation should be created
        $this->assertFileExists(base_path('docs/source_documents/short/app/TestClass.md'));
        $this->assertFileDoesNotExist(base_path('docs/source_documents/medium/app/TestClass.md'));
        $this->assertFileDoesNotExist(base_path('docs/source_documents/full/app/TestClass.md'));
    }

    #[Test]
    public function it_handles_rate_limit_exceptions(): void
    {
        // Given we have a rate limit response
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Rate limit exceeded',
                    'type'    => 'rate_limit_error',
                ],
            ], 429),
        ]);

        // When we dispatch the job
        $job = new GenerateAiDocumentationForFileJob($this->test_file_path, 'micro', 'o3');

        // The job should handle the error
        try
        {
            $job->handle();
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e)
        {
            // The job should throw an exception for the rate limit
            $this->assertStringContainsString('Failed to generate documentation', $e->getMessage());
        }
    }

    #[Test]
    public function it_creates_necessary_directories(): void
    {
        // Given we have a file in a nested directory
        $nested_file_path = base_path('app/Services/Nested/TestService.php');
        $nested_content   = <<<'PHP'
<?php

namespace App\Services\Nested;

class TestService
{
    public function serve()
    {
        return 'service';
    }
}
PHP;

        // Create the nested file
        File::ensureDirectoryExists(dirname($nested_file_path));
        File::put($nested_file_path, $nested_content);

        // Mock OpenAI response
        $this->mock_openai_response();

        // When we dispatch the job
        $job = new GenerateAiDocumentationForFileJob($nested_file_path, 'micro', 'o3');
        $job->handle();

        // Then the nested directory structure should be created
        $this->assertDirectoryExists(base_path('docs/source_documents/short/app/Services/Nested'));
        $this->assertFileExists(base_path('docs/source_documents/short/app/Services/Nested/TestService.md'));

        // Clean up
        File::delete($nested_file_path);
        File::deleteDirectory(base_path('app/Services/Nested'));

        // Only delete the specific nested test documentation
        if (File::exists(base_path('docs/source_documents/short/app/Services/Nested/TestService.md')))
        {
            File::delete(base_path('docs/source_documents/short/app/Services/Nested/TestService.md'));
        }
        // Remove the directory only if it's empty
        $nested_doc_dir = base_path('docs/source_documents/short/app/Services/Nested');

        if (File::isDirectory($nested_doc_dir) && count(File::files($nested_doc_dir)) === 0)
        {
            File::deleteDirectory($nested_doc_dir);
        }
    }

    #[Test]
    public function it_handles_javascript_files(): void
    {
        // Given we have a JavaScript file
        $js_file_path = base_path('resources/js/test.js');
        $js_content   = <<<'JS'
export function testFunction() {
    return 'test';
}
JS;

        File::ensureDirectoryExists(dirname($js_file_path));
        File::put($js_file_path, $js_content);

        // Mock OpenAI response
        $this->mock_openai_response();

        // When we dispatch the job
        $job = new GenerateAiDocumentationForFileJob($js_file_path, 'micro', 'o3');
        $job->handle();

        // Then documentation should be created
        $this->assertFileExists(base_path('docs/source_documents/short/resources/js/test.md'));

        // Clean up
        File::delete($js_file_path);

        // Only delete the specific test documentation file
        if (File::exists(base_path('docs/source_documents/short/resources/js/test.md')))
        {
            File::delete(base_path('docs/source_documents/short/resources/js/test.md'));
        }
    }

    #[Test]
    public function it_uses_custom_model_when_specified(): void
    {
        // Given we have a mocked OpenAI response
        $this->mock_openai_response();

        // When we dispatch the job with a custom model
        $job = new GenerateAiDocumentationForFileJob($this->test_file_path, 'micro', 'gpt-4');
        $job->handle();

        // Then documentation should be created (model is used internally)
        $this->assertFileExists(base_path('docs/source_documents/short/app/TestClass.md'));
    }

    private function mock_openai_response(): void
    {
        Http::fake([
            '*' => Http::response($this->get_successful_openai_response(), 200),
        ]);
    }

    private function get_successful_openai_response(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'micro'     => "## TestClass · Micro-blurb\n\nThis is a test class that demonstrates basic functionality.",
                            'standard'  => "```yaml\ndoc_tier: standard\ndoc_version: 1\n```\n\n# TestClass\n\n## Purpose\nTest class for documentation generation.",
                            'expansive' => "```yaml\ndoc_tier: expansive\ndoc_version: 1\n```\n\n# TestClass\n\n## File Purpose\nComprehensive test class documentation.",
                        ]),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    private function create_existing_documentation(): void
    {
        $tiers = [
            'short'  => "## TestClass · Micro-blurb\n\nExisting documentation.",
            'medium' => "# TestClass\n\nExisting standard documentation.",
            'full'   => "# TestClass\n\nExisting expansive documentation.",
        ];

        foreach ($tiers as $tier => $content)
        {
            $path = base_path("docs/source_documents/{$tier}/app/TestClass.md");
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $content);
        }
    }

    private function cleanup_documentation_files(): void
    {
        $paths = [
            base_path('docs/source_documents/short/app/TestClass.md'),
            base_path('docs/source_documents/medium/app/TestClass.md'),
            base_path('docs/source_documents/full/app/TestClass.md'),
        ];

        foreach ($paths as $path)
        {
            if (File::exists($path))
            {
                File::delete($path);
            }
        }
    }
}
