<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Screens render without compiled front-end assets (no `npm run build` needed).
        $this->withoutVite();
    }
}
