<?php

namespace Lumiio\CascadeDocs\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Lumiio\CascadeDocs\CascadeDocs
 */
class CascadeDocs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Lumiio\CascadeDocs\CascadeDocs::class;
    }
}
