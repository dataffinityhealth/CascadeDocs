<?php

namespace Lumiio\CascadeDocs\Tests\Unit\Services\Documentation;

use Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentAIService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleFileUpdater;
use Lumiio\CascadeDocs\Tests\TestCase;

class SimpleServiceTests extends TestCase
{
    public function test_module_assignment_ai_service_can_be_instantiated(): void
    {
        $service = new ModuleAssignmentAIService();
        $this->assertInstanceOf(ModuleAssignmentAIService::class, $service);
    }

    public function test_module_file_updater_can_be_instantiated(): void
    {
        $service = new ModuleFileUpdater();
        $this->assertInstanceOf(ModuleFileUpdater::class, $service);
    }
}