<?php

namespace Tests\ApiTests;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;

abstract class BaseApiTest extends TestCase
{
    // Removing DatabaseTransactions to allow state persistence between tests if needed, 
    // or we can use a shared database and clean up at the end. 
    // The prompt implies a sequential flow where IDs are stored for next steps.

    protected $startTime;
    protected static $sharedStatePath = 'tests/ApiTests/state.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->startTime = microtime(true);
    }

    protected function report($name, $status, $module = 'N/A', $metadata = [])
    {
        $duration = microtime(true) - $this->startTime;
        TestReporter::logResult($name, $status, $duration, $module, $metadata);
    }

    protected function saveState($key, $value)
    {
        $state = $this->loadAllState();
        $state[$key] = $value;
        File::put(base_path(self::$sharedStatePath), json_encode($state, JSON_PRETTY_PRINT));
    }

    protected function getState($key, $default = null)
    {
        $state = $this->loadAllState();
        return $state[$key] ?? $default;
    }

    private function loadAllState()
    {
        if (!File::exists(base_path(self::$sharedStatePath))) {
            return [];
        }
        return json_decode(File::get(base_path(self::$sharedStatePath)), true) ?: [];
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        // Optionally print summary if this is the last test class
    }
}
