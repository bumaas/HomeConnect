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

    private function cloud()
    {
        return IPS\InstanceManager::getInstanceInterface(IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0]);
    }

    private function invoke($object, string $method, ...$args)
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }
}
