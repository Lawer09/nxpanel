<?php

namespace Tests\Feature;

use App\Providers\OctaneServiceProvider;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class OctaneServiceProviderTest extends TestCase
{
    public function test_reset_scheduler_database_state_rolls_back_and_reconnects(): void
    {
        DB::shouldReceive('transactionLevel')
            ->times(3)
            ->andReturn(2, 1, 0);
        DB::shouldReceive('rollBack')->times(2);
        DB::shouldReceive('purge')->once();
        DB::shouldReceive('reconnect')->once();

        $provider = new OctaneServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'resetSchedulerDatabaseState');
        $method->setAccessible(true);
        $method->invoke($provider);

        $this->assertTrue(true);
    }
}
