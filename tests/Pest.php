<?php

use Illuminate\Support\Facades\Http;
use Lumiio\CascadeDocs\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Prevent any stray HTTP requests in all tests
beforeEach(function () {
    Http::preventStrayRequests();
});