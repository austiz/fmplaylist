<?php

namespace Tests;

use App\Support\CurrentStation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The current station is process state, and the suite reuses one process, so a
     * station resolved by one test's request would otherwise still be current in the
     * next -- whose database no longer contains it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CurrentStation::forget();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
