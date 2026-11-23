<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs DatabaseSeeder once per test run (not per test — see the
     * RefreshDatabase migrate-once-for-sqlite-":memory:" optimization
     * noted in tests/Pest.php), so every test gets the real seeded
     * card catalog without re-seeding 200+ rows on every single test.
     */
    protected $seed = true;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
