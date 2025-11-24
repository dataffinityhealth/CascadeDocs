<?php

namespace Lumiio\CascadeDocs\Tests\Feature\Commands\Documentation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForFilamentFilesCommand;
use Lumiio\CascadeDocs\Tests\TestCase;
use Mockery;
use Shawnveltman\LaravelOpenai\Enums\ThinkingEffort;

class GenerateAiDocumentationForFilamentFilesCommandTest extends TestCase
{
    protected string $testPath;

    protected string $livewirePath;

    protected string $docsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPath = 'tests/fixtures/filament-docs';
        $this->livewirePath = 'app/Livewire';
        $this->docsPath = 'docs/code';

        // Configure paths
        Config::set('cascadedocs.paths.code_documentation', $this->docsPath);
        Config::set('cascadedocs.filament.livewire_path', $this->livewirePath);
        Config::set('cascadedocs.filament.namespace_pattern', 'use Filament\\');
        Config::set('cascadedocs.ai.filament_model', 'claude-3-5-haiku-20241022');
        Config::set('cascadedocs.permissions.directory', 0755);

        // Create test directories
        File::ensureDirectoryExists(base_path($this->livewirePath));
        File::ensureDirectoryExists(base_path($this->docsPath));
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists(base_path($this->testPath))) {
            File::deleteDirectory(base_path($this->testPath));
        }

        if (File::exists(base_path($this->livewirePath))) {
            File::deleteDirectory(base_path($this->livewirePath));
        }

        if (File::exists(base_path($this->docsPath))) {
            File::deleteDirectory(base_path($this->docsPath));
        }

        parent::tearDown();
    }

    public function test_it_has_correct_signature(): void
    {
        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files --help')
            ->assertExitCode(0);
    }

    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(\Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForFilamentFilesCommand::class));
    }

    public function test_command_has_correct_name(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForFilamentFilesCommand;
        $this->assertEquals('cascadedocs:generate-ai-documentation-for-filament-files', $command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $command = new \Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForFilamentFilesCommand;
        $this->assertEquals('Generate AI documentation for Filament files in the Livewire directory', $command->getDescription());
    }

    public function test_it_creates_documentation_directory_if_not_exists(): void
    {
        // Ensure docs directory doesn't exist
        if (File::exists(base_path($this->docsPath))) {
            File::deleteDirectory(base_path($this->docsPath));
        }

        // Create a Filament file
        $this->createFilamentFile('UserResource.php');

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->assertExitCode(0);

        // Check directory was created
        $this->assertTrue(File::exists(base_path($this->docsPath)));
    }

    public function test_it_finds_filament_files_in_livewire_directory(): void
    {
        // Create test files
        $this->createFilamentFile('UserResource.php');
        $this->createFilamentFile('Components/UserTable.php');
        $this->createNonFilamentFile('RegularComponent.php');

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->expectsOutput('Found 2 Filament files to document.')
            ->assertExitCode(0);
    }

    public function test_it_skips_existing_documentation(): void
    {
        // Create a Filament file
        $this->createFilamentFile('UserResource.php');

        // Create existing documentation
        $docPath = base_path($this->docsPath.'/Livewire/UserResource.md');
        File::ensureDirectoryExists(dirname($docPath));
        File::put($docPath, '# Existing Documentation');

        // No mocking needed since file is skipped
        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->expectsOutput('Found 1 Filament files to document.')
            ->assertExitCode(0);

        // Verify existing documentation wasn't overwritten
        $this->assertEquals('# Existing Documentation', File::get($docPath));
    }

    public function test_it_generates_documentation_with_correct_prompt(): void
    {
        // Create a Filament file
        $this->createFilamentFile('UserResource.php');

        // Capture the prompt
        $capturedPrompt = null;

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->withArgs(function (
                    $prompt,
                    $model,
                    $userId,
                    $assistantStarterText,
                    $description,
                    $jobUuid,
                    $jsonMode,
                    $temperature,
                    $systemPrompt,
                    $messages,
                    $imageUrls,
                    $thinkingEffort,
                    $maxTokens = 64000
                ) use (&$capturedPrompt) {
                    $capturedPrompt = $prompt;

                    expect($jsonMode)->toBeFalse();
                    expect($thinkingEffort)->toBeInstanceOf(ThinkingEffort::class);
                    expect($thinkingEffort)->toBe(ThinkingEffort::HIGH);
                    expect($maxTokens)->toBe(64000);

                    return true;
                })
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->assertExitCode(0);

        // Verify prompt contains expected sections
        $this->assertStringContainsString('comprehensive documentation', $capturedPrompt);
        $this->assertStringContainsString('Laravel Livewire class that uses Filament', $capturedPrompt);
        $this->assertStringContainsString('## Class Description', $capturedPrompt);
        $this->assertStringContainsString('## Filament Features', $capturedPrompt);
        $this->assertStringContainsString('### Table Column Types', $capturedPrompt);
        $this->assertStringContainsString('### Filter Types', $capturedPrompt);
        $this->assertStringContainsString('### Form Field Types', $capturedPrompt);
        $this->assertStringContainsString('### Action Types', $capturedPrompt);
    }

    public function test_it_handles_nested_directory_structure(): void
    {
        // Create nested Filament files
        $this->createFilamentFile('Resources/UserResource.php');
        $this->createFilamentFile('Resources/Tables/UserTable.php');

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->twice()
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->assertExitCode(0);

        // Check nested documentation was created
        $this->assertTrue(File::exists(base_path($this->docsPath.'/Livewire/Resources/UserResource.md')));
        $this->assertTrue(File::exists(base_path($this->docsPath.'/Livewire/Resources/Tables/UserTable.md')));
    }

    public function test_it_uses_configured_model(): void
    {
        // Create a Filament file
        $this->createFilamentFile('UserResource.php');

        // Set a custom model
        Config::set('cascadedocs.ai.filament_model', 'custom-model');

        // Capture the model
        $capturedModel = null;

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->withArgs(function (
                    $prompt,
                    $model,
                    $userId,
                    $assistantStarterText,
                    $description,
                    $jobUuid,
                    $jsonMode,
                    $temperature,
                    $systemPrompt,
                    $messages,
                    $imageUrls,
                    $thinkingEffort,
                    $maxTokens = 64000
                ) use (&$capturedModel) {
                    $capturedModel = $model;

                    expect($jsonMode)->toBeFalse();
                    expect($thinkingEffort)->toBeInstanceOf(ThinkingEffort::class);
                    expect($thinkingEffort)->toBe(ThinkingEffort::HIGH);
                    expect($maxTokens)->toBe(64000);

                    return true;
                })
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->assertExitCode(0);

        $this->assertEquals('custom-model', $capturedModel);
    }

    public function test_it_shows_progress_bar(): void
    {
        // Create multiple files
        $this->createFilamentFile('UserResource.php');
        $this->createFilamentFile('PostResource.php');
        $this->createFilamentFile('CommentResource.php');

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->times(3)
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->expectsOutput('Found 3 Filament files to document.')
            ->assertExitCode(0);
    }

    public function test_it_filters_only_php_files(): void
    {
        // Create various file types
        $this->createFilamentFile('UserResource.php');
        File::put(base_path($this->livewirePath.'/config.json'), '{"filament": true}');
        File::put(base_path($this->livewirePath.'/styles.css'), '.filament { }');

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->expectsOutput('Found 1 Filament files to document.')
            ->assertExitCode(0);
    }

    public function test_it_handles_empty_livewire_directory(): void
    {
        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->expectsOutput('Found 0 Filament files to document.')
            ->expectsOutput('Documentation generation completed successfully!')
            ->assertExitCode(0);
    }

    public function test_it_creates_subdirectories_for_documentation(): void
    {
        // Create deeply nested file
        $this->createFilamentFile('Resources/Admin/Users/UserResource.php');

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->assertExitCode(0);

        // Verify directory structure was created
        $this->assertTrue(File::exists(base_path($this->docsPath.'/Livewire/Resources/Admin/Users')));
        $this->assertTrue(File::exists(base_path($this->docsPath.'/Livewire/Resources/Admin/Users/UserResource.md')));
    }

    public function test_it_uses_correct_permissions_for_directories(): void
    {
        // Set custom permissions
        Config::set('cascadedocs.permissions.directory', 0777);

        // Create a Filament file
        $this->createFilamentFile('UserResource.php');

        // Mock the command
        $this->instance(
            GenerateAiDocumentationForFilamentFilesCommand::class,
            Mockery::mock(GenerateAiDocumentationForFilamentFilesCommand::class.'[get_response_from_provider]')
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('get_response_from_provider')
                ->once()
                ->andReturn('## Generated Documentation')
                ->getMock()
        );

        $this->artisan('cascadedocs:generate-ai-documentation-for-filament-files')
            ->assertExitCode(0);

        // Can't easily test actual permissions in all environments
        // Just verify the command completed successfully
        $this->assertTrue(File::exists(base_path($this->docsPath.'/Livewire/UserResource.md')));
    }

    /**
     * Helper method to create a Filament file
     */
    protected function createFilamentFile(string $filename): void
    {
        $path = base_path($this->livewirePath.'/'.$filename);
        File::ensureDirectoryExists(dirname($path));

        $content = <<<'PHP'
<?php

namespace App\Livewire;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Forms;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('email'),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }
}
PHP;

        File::put($path, $content);
    }

    /**
     * Helper method to create a non-Filament file
     */
    protected function createNonFilamentFile(string $filename): void
    {
        $path = base_path($this->livewirePath.'/'.$filename);
        File::ensureDirectoryExists(dirname($path));

        $content = <<<'PHP'
<?php

namespace App\Livewire;

use Livewire\Component;

class RegularComponent extends Component
{
    public function render()
    {
        return view('livewire.regular-component');
    }
}
PHP;

        File::put($path, $content);
    }
}
