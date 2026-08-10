<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_sunat_queues_are_declared_for_beta_and_production(): void
    {
        $this->assertSame('sunat-beta', config('facturador.sunat.queues.beta'));
        $this->assertSame('sunat-production', config('facturador.sunat.queues.production'));
    }

    public function test_horizon_snapshot_is_not_scheduled_when_using_database_queue(): void
    {
        config()->set('queue.default', 'database');

        Artisan::call('schedule:list');

        $this->assertStringNotContainsString('horizon:snapshot', Artisan::output());
    }
}
