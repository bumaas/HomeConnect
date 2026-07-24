<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectCloudTest extends TestCase
{
    private const CLOUD_GUID = '{CE76810D-B685-9BE0-CC04-38B204DEAD5E}';

    //A 429 as it arrives through the SSE event stream (see cbeham's dump.txt).
    private const RATE_LIMIT_PAYLOAD = '{"error":{"key":"429","description":"The rate limit \"1000 calls in 1 day\" was reached. Requests are blocked during the remaining period of 18113 seconds."}}';

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/IOStubs/library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        $this->ConfiguratorID = IPS_CreateInstance('{CA0E667D-8F28-8DF1-2750-5CF587ECA85A}');

        // The upstream SSE-Client stub only registers 'Open'; give the parent IO the
        // 'Active'/'URL'/'Headers' properties the module toggles (as the real IO has),
        // so the rate-limit tests run against unmodified SymconStubs.
        $this->prepareParentIo(IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0]);

        parent::setUp();
    }

    /**
     * A 429 carried by the event stream must activate the shared rate-limit state,
     * even though no REST call (getData/putData) was involved.
     */
    public function testReceiveDataActivatesRateLimitOn429()
    {
        $cloud = $this->cloud();
        $cloud->ReceiveData(self::RATE_LIMIT_PAYLOAD);

        $this->assertTrue($this->invoke($cloud, 'isRateLimitActive'), 'A 429 from the stream must activate the rate limit');

        $until = $this->invoke($cloud, 'ReadAttributeInteger', 'RateLimitUntil');
        $this->assertGreaterThan(time() + 18000, $until, 'RateLimitUntil should reflect the ~18113s retry-after');

        $this->assertNotSame('', $this->invoke($cloud, 'ReadAttributeString', 'RateError'), 'RateError should be set');
    }

    /**
     * Core of the fix: while rate limited, RegisterServerEvents must NOT reconnect
     * the event stream (which would hit /events again) but defer via the Reconnect
     * timer. With the old code it would fall through and try to talk to the parent IO.
     */
    public function testRegisterServerEventsDefersWhileRateLimited()
    {
        $cloud = $this->cloud();
        $cloud->ReceiveData(self::RATE_LIMIT_PAYLOAD);

        //Must not throw and must not touch the parent IO.
        $cloud->RegisterServerEvents();

        $reconnect = $this->invoke($cloud, 'GetTimerInterval', 'Reconnect');
        $this->assertGreaterThan(0, $reconnect, 'Reconnect must be deferred until the limit expires');
    }

    /**
     * The 60s keep-alive check must not trigger a reconnect while rate limited -
     * otherwise it hammers /events every minute (~1440 calls/day).
     */
    public function testCheckServerEventsSkipsWhileRateLimited()
    {
        $cloud = $this->cloud();
        $cloud->ReceiveData(self::RATE_LIMIT_PAYLOAD);

        //Would throw (parent IO has no URL/Active) if it tried to reconnect.
        $cloud->CheckServerEvents();

        $this->assertTrue($this->invoke($cloud, 'isRateLimitActive'), 'Still rate limited, no reconnect attempted');
    }

    /**
     * A normal keep-alive event must still be processed (and not be mistaken for a
     * rate-limit payload).
     */
    public function testKeepAliveStillProcessedWhenNotLimited()
    {
        $cloud = $this->cloud();
        $cloud->ReceiveData('{"Event":"KEEP-ALIVE"}');

        $this->assertFalse($this->invoke($cloud, 'isRateLimitActive'), 'A keep-alive must not activate the rate limit');
    }

    /**
     * #4/#5: A 429 must stop the event-stream IO (so it no longer hammers /events)
     * and mark the instance with the honest rate-limit status instead of IS_ACTIVE.
     */
    public function testRateLimitStopsEventStreamAndSetsStatus()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);

        //Simulate a running event stream.
        IPS_SetProperty($parent, 'Active', true);
        IPS_ApplyChanges($parent);
        $this->assertTrue(IPS_GetProperty($parent, 'Active'));

        $cloud->ReceiveData(self::RATE_LIMIT_PAYLOAD);

        $this->assertFalse(IPS_GetProperty($parent, 'Active'), 'Event-stream IO must be deactivated while blocked');
        //201 == STATUS_RATE_LIMITED (>= IS_EBASE), so children go inactive during the block.
        $this->assertSame(201, IPS_GetInstance($cloudID)['InstanceStatus'], 'Instance must report the rate-limit status, not active');
    }

    /**
     * #4: When the block is over, ResetRateLimit must re-activate the IO and resume
     * the stream (with a fresh token) and return the instance to IS_ACTIVE.
     */
    public function testResetRateLimitRestartsEventStream()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);

        //Seed a valid access token so RegisterServerEvents does not attempt an OAuth refresh.
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));

        $cloud->ReceiveData(self::RATE_LIMIT_PAYLOAD);
        $this->assertTrue($this->invoke($cloud, 'isRateLimitActive'));

        $cloud->ResetRateLimit();

        $this->assertFalse($this->invoke($cloud, 'isRateLimitActive'), 'Reset must clear the rate limit');
        $this->assertTrue(IPS_GetProperty($parent, 'Active'), 'Event-stream IO must be re-activated on reset');
        $this->assertSame(IS_ACTIVE, IPS_GetInstance($cloudID)['InstanceStatus'], 'Instance must be active again after reset');
    }

    /**
     * #5: A 401 "invalid_token" carried by the stream (the access token expired) must
     * trigger a reconnect with a fresh token instead of letting the IO loop on 401.
     * It must not be mistaken for a rate limit, and the IO stays active.
     */
    public function testInvalidTokenReconnectsEventStream()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);

        //Seed a valid access token so the reconnect reuses it instead of hitting OAuth.
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));

        $cloud->ReceiveData('{"error":{"key":"invalid_token","description":"The access token expired"}}');

        $this->assertFalse($this->invoke($cloud, 'isRateLimitActive'), 'invalid_token must not be treated as a rate limit');
        $this->assertTrue(IPS_GetProperty($parent, 'Active'), 'Stream must be re-registered (IO active) after invalid_token');
        $this->assertStringContainsString('homeappliances/events', IPS_GetProperty($parent, 'URL'), 'Reconnect must re-arm the /events request');
    }

    /**
     * Reconnect fix: a stale keep-alive must trigger a reconnect even when the parent
     * IO has dropped to a non-active status. The old code gated this behind
     * HasActiveParent(), so a dead parent never recovered (keep-alives stopped for good).
     */
    public function testCheckServerEventsReconnectsWhenParentInactive()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);

        //IO is configured active, but its runtime status has dropped (stream died).
        IPS_SetProperty($parent, 'Active', true);
        IPS_ApplyChanges($parent);
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));
        //Last keep-alive is well over 60s old -> the stream is considered dead.
        $this->invoke($cloud, 'SetBuffer', 'KeepAlive', (string) (time() - 120));
        IPS\InstanceManager::setStatus($parent, IS_INACTIVE);

        $cloud->CheckServerEvents();

        $this->assertStringContainsString('homeappliances/events', IPS_GetProperty($parent, 'URL'), 'A stale keep-alive must reconnect even when the parent is not active');
    }

    /**
     * Reconnect fix: the error backoff must be capped at 3 minutes so a dropped stream
     * recovers quickly. Previously it grew quadratically up to 1 hour.
     */
    public function testReconnectBackoffCappedAt3Minutes()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = IPS_GetInstance($cloudID)['ConnectionID'];

        //Simulate many consecutive parent error status changes (retries grow to 20).
        for ($i = 0; $i < 20; $i++) {
            $cloud->MessageSink(0, $parent, IM_CHANGESTATUS, [IS_EBASE]);
        }

        //retries^2 would be 400s; must be capped at 180s (180000 ms).
        $this->assertSame(180000, $this->invoke($cloud, 'GetTimerInterval', 'Reconnect'), 'Reconnect backoff must be capped at 3 minutes');
    }

    /**
     * A successful request must NOT reconnect the event stream when no rate limit was
     * pending. Otherwise every getData()/putData() would re-register the IO (ApplyChanges)
     * -> connection close/reopen on every operation and Event-Control status flapping.
     */
    public function testSuccessDoesNotReconnectWhenNoRateLimitPending()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);

        //No rate limit pending (RateLimitUntil defaults to 0).
        $this->invoke($cloud, 'clearRateLimitAfterSuccess');

        $this->assertSame('', IPS_GetProperty($parent, 'URL'), 'A normal successful request must not re-register the event stream');
    }

    /**
     * When a rate-limit block was pending, a successful request clears it and resumes the
     * event stream (this is the wanted resume path).
     */
    public function testSuccessClearsPendingRateLimitAndResumes()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));

        //Simulate a pending block.
        $this->invoke($cloud, 'WriteAttributeInteger', 'RateLimitUntil', time() + 3600);

        $this->invoke($cloud, 'clearRateLimitAfterSuccess');

        $this->assertFalse($this->invoke($cloud, 'isRateLimitActive'), 'Pending rate limit must be cleared');
        $this->assertStringContainsString('homeappliances/events', IPS_GetProperty($parent, 'URL'), 'Stream must resume after the block clears');
    }

    private function cloud()
    {
        return IPS\InstanceManager::getInstanceInterface(IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0]);
    }

    /**
     * The upstream SSE-Client stub only registers the 'Open' property, but the module
     * toggles the parent IO's 'Active'/'URL'/'Headers' (as the real SSE Client IO has).
     * Register them on the parent instance so these tests run against unmodified
     * SymconStubs without patching the submodule. Returns the parent instance ID.
     */
    private function prepareParentIo(int $cloudID): int
    {
        $parent = IPS_GetInstance($cloudID)['ConnectionID'];
        $module = IPS\InstanceManager::getInstanceInterface($parent);
        $this->invoke($module, 'RegisterPropertyBoolean', 'Active', false);
        $this->invoke($module, 'RegisterPropertyString', 'URL', '');
        $this->invoke($module, 'RegisterPropertyString', 'Headers', '');
        return $parent;
    }

    private function invoke($object, string $method, ...$args)
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }
}
