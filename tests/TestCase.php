<?php

namespace Tests;

use App\Support\CurrentStation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
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

    /**
     * The logged queries, with identifier quoting removed.
     *
     * A query-log assertion that spells a table `"settings"` only matches on
     * sqlite: MySQL quotes with backticks, so the filter finds nothing. That
     * fails an assertCount outright and -- worse -- makes an assertEmpty pass
     * for the wrong reason, guarding nothing on the driver production runs.
     *
     * @return array<int, string>
     */
    protected function loggedQueries(): array
    {
        return array_map(
            fn (array $q) => str_replace(['`', '"'], '', (string) $q['query']),
            DB::getQueryLog()
        );
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
