<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Answer the email rule's DNS lookups without the network, so the suite
     * gives the same result offline as on. Any well-formed domain is taken to
     * exist; reserved ones (.test, .invalid…) are still refused before any
     * lookup, which is how a test proves the check is in force.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Validator::fakeDnsLookups();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
