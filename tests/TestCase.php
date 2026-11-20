<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // the panel theme is a Vite asset; tests must not depend on `npm run build`
        $this->withoutVite();
    }
}
