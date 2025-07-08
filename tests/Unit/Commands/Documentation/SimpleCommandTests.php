<?php

namespace Lumiio\CascadeDocs\Tests\Unit\Commands\Documentation;

use Lumiio\CascadeDocs\Commands\Documentation\AnalyzeModuleAssignmentsCommand;
use Lumiio\CascadeDocs\Commands\Documentation\AssignFilesToModulesCommand;
use Lumiio\CascadeDocs\Commands\Documentation\CreateModuleCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForAllFilesCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForFilamentFilesCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateArchitectureDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateClassDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateModuleDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\ModuleStatusCommand;
use Lumiio\CascadeDocs\Commands\Documentation\SyncModuleAssignmentsCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateAllModuleDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationAfterMergeCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationForChangedFilesCommand;
use Lumiio\CascadeDocs\Tests\TestCase;

class SimpleCommandTests extends TestCase
{
    public function test_analyze_module_assignments_command_can_be_instantiated(): void
    {
        $command = new AnalyzeModuleAssignmentsCommand();
        $this->assertInstanceOf(AnalyzeModuleAssignmentsCommand::class, $command);
    }

    public function test_assign_files_to_modules_command_can_be_instantiated(): void
    {
        $command = new AssignFilesToModulesCommand();
        $this->assertInstanceOf(AssignFilesToModulesCommand::class, $command);
    }

    public function test_create_module_command_can_be_instantiated(): void
    {
        $command = new CreateModuleCommand();
        $this->assertInstanceOf(CreateModuleCommand::class, $command);
    }

    public function test_generate_ai_documentation_for_all_files_command_can_be_instantiated(): void
    {
        $command = new GenerateAiDocumentationForAllFilesCommand();
        $this->assertInstanceOf(GenerateAiDocumentationForAllFilesCommand::class, $command);
    }

    public function test_generate_ai_documentation_for_filament_files_command_can_be_instantiated(): void
    {
        $command = new GenerateAiDocumentationForFilamentFilesCommand();
        $this->assertInstanceOf(GenerateAiDocumentationForFilamentFilesCommand::class, $command);
    }

    public function test_generate_architecture_documentation_command_can_be_instantiated(): void
    {
        $command = new GenerateArchitectureDocumentationCommand();
        $this->assertInstanceOf(GenerateArchitectureDocumentationCommand::class, $command);
    }

    public function test_generate_class_documentation_command_can_be_instantiated(): void
    {
        $command = new GenerateClassDocumentationCommand();
        $this->assertInstanceOf(GenerateClassDocumentationCommand::class, $command);
    }

    public function test_generate_module_documentation_command_can_be_instantiated(): void
    {
        $command = new GenerateModuleDocumentationCommand();
        $this->assertInstanceOf(GenerateModuleDocumentationCommand::class, $command);
    }

    public function test_module_status_command_can_be_instantiated(): void
    {
        $command = new ModuleStatusCommand();
        $this->assertInstanceOf(ModuleStatusCommand::class, $command);
    }

    public function test_sync_module_assignments_command_can_be_instantiated(): void
    {
        $command = new SyncModuleAssignmentsCommand();
        $this->assertInstanceOf(SyncModuleAssignmentsCommand::class, $command);
    }

    public function test_update_all_module_documentation_command_can_be_instantiated(): void
    {
        $command = new UpdateAllModuleDocumentationCommand();
        $this->assertInstanceOf(UpdateAllModuleDocumentationCommand::class, $command);
    }

    public function test_update_changed_documentation_command_can_be_instantiated(): void
    {
        $command = new UpdateChangedDocumentationCommand();
        $this->assertInstanceOf(UpdateChangedDocumentationCommand::class, $command);
    }

    public function test_update_documentation_after_merge_command_can_be_instantiated(): void
    {
        $command = new UpdateDocumentationAfterMergeCommand();
        $this->assertInstanceOf(UpdateDocumentationAfterMergeCommand::class, $command);
    }

    public function test_update_documentation_for_changed_files_command_can_be_instantiated(): void
    {
        $command = new UpdateDocumentationForChangedFilesCommand();
        $this->assertInstanceOf(UpdateDocumentationForChangedFilesCommand::class, $command);
    }
}