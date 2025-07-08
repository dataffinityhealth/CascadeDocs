<?php

namespace Lumiio\CascadeDocs\Tests\Unit\Facades;

use Lumiio\CascadeDocs\CascadeDocs;
use Lumiio\CascadeDocs\Facades\CascadeDocs as CascadeDocsFacade;
use Lumiio\CascadeDocs\Tests\TestCase;

class CascadeDocsTest extends TestCase
{
    public function test_facade_resolves_correctly(): void
    {
        $this->assertInstanceOf(CascadeDocs::class, CascadeDocsFacade::getFacadeRoot());
    }

    public function test_facade_accessor_returns_correct_class(): void
    {
        $reflection = new \ReflectionClass(CascadeDocsFacade::class);
        $method = $reflection->getMethod('getFacadeAccessor');
        $method->setAccessible(true);

        $this->assertEquals(CascadeDocs::class, $method->invoke(new CascadeDocsFacade));
    }
}
