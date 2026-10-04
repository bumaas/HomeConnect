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
        //The stream was running.
        IPS_SetProperty($parent, 'Active', true);
        IPS_ApplyChanges($parent);

        //A cached access token the server has just rejected. Without a refresh token the
        //refresh fails before any network access - which proves a new token was requested.
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));
        $before = count(IPS\DebugServer::getDebugMessages($cloudID));

        ob_start();
        $cloud->ReceiveData('{"error":{"key":"invalid_token","description":"The access token expired"}}');
        $output = ob_get_clean();

        $messages = array_column(array_slice(IPS\DebugServer::getDebugMessages($cloudID), $before), 'Message');
        $this->assertFalse($this->invoke($cloud, 'isRateLimitActive'), 'invalid_token must not be treated as a rate limit');
        $this->assertTrue(IPS_GetProperty($parent, 'Active'), 'The IO stays active after invalid_token');
        $this->assertContains('RegisterServerEventsError', $messages, 'Reconnect must request a fresh access token');
        $this->assertSame('', $output, 'ReceiveData must not echo - its output ends up in the log');
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
        //A registered cloud has a refresh token.
        $this->invoke($cloud, 'WriteAttributeString', 'Token', 'refresh');
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

    /**
     * Watchdog backoff: on a dead stream the keep-alive watchdog must not re-register
     * every 2 minutes forever (~700 GET /events per day, keeping the "1000 calls in
     * 1 day" quota exhausted for good). Attempts must back off exponentially.
     */
    public function testCheckServerEventsBacksOffWhileStreamStaysDead()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);
        IPS_SetProperty($parent, 'Active', true);
        IPS_ApplyChanges($parent);
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));
        //A registered cloud has a refresh token.
        $this->invoke($cloud, 'WriteAttributeString', 'Token', 'refresh');

        //First failure: reconnects immediately and schedules the next attempt in 120s.
        $this->invoke($cloud, 'SetBuffer', 'KeepAlive', (string) (time() - 120));
        $cloud->CheckServerEvents();
        $this->assertStringContainsString('homeappliances/events', IPS_GetProperty($parent, 'URL'), 'First failure must reconnect');
        $this->assertSame('1', (string) $this->invoke($cloud, 'GetBuffer', 'WatchdogRetries'));

        //Still within the backoff window: no further reconnect.
        IPS_SetProperty($parent, 'URL', '');
        IPS_ApplyChanges($parent);
        $this->invoke($cloud, 'SetBuffer', 'KeepAlive', (string) (time() - 120));
        $cloud->CheckServerEvents();
        $this->assertSame('', IPS_GetProperty($parent, 'URL'), 'Within the backoff window the watchdog must not reconnect');

        //Backoff window elapsed: reconnects again and doubles the delay (120s -> 240s).
        $this->invoke($cloud, 'SetBuffer', 'WatchdogNextRetry', (string) (time() - 1));
        $cloud->CheckServerEvents();
        $this->assertStringContainsString('homeappliances/events', IPS_GetProperty($parent, 'URL'), 'After the backoff window the watchdog must reconnect');
        $this->assertSame('2', (string) $this->invoke($cloud, 'GetBuffer', 'WatchdogRetries'));
        $nextRetry = intval($this->invoke($cloud, 'GetBuffer', 'WatchdogNextRetry'));
        $this->assertGreaterThan(time() + 200, $nextRetry, 'Second attempt must schedule the next one ~240s ahead');
    }

    /**
     * The watchdog backoff must be capped at 1 hour so a dead stream is still probed
     * regularly (~30 requests/day) without ever burning the daily quota.
     */
    public function testWatchdogBackoffCappedAtOneHour()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);
        IPS_SetProperty($parent, 'Active', true);
        IPS_ApplyChanges($parent);
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));
        //A registered cloud has a refresh token.
        $this->invoke($cloud, 'WriteAttributeString', 'Token', 'refresh');

        $this->invoke($cloud, 'SetBuffer', 'KeepAlive', (string) (time() - 120));
        $this->invoke($cloud, 'SetBuffer', 'WatchdogRetries', '10');
        $cloud->CheckServerEvents();

        $nextRetry = intval($this->invoke($cloud, 'GetBuffer', 'WatchdogNextRetry'));
        $this->assertLessThanOrEqual(time() + 3600, $nextRetry, 'Backoff must be capped at 1 hour');
        $this->assertGreaterThan(time() + 3500, $nextRetry, 'Capped backoff must still be ~1 hour');
    }

    /**
     * The first keep-alive after a recovery proves the stream is alive again - it must
     * reset the watchdog backoff so a future drop reconnects quickly again.
     */
    public function testKeepAliveResetsWatchdogBackoff()
    {
        $cloud = $this->cloud();
        $this->invoke($cloud, 'SetBuffer', 'WatchdogRetries', '5');
        $this->invoke($cloud, 'SetBuffer', 'WatchdogNextRetry', (string) (time() + 3600));

        $cloud->ReceiveData('{"Event":"KEEP-ALIVE"}');

        $this->assertSame('', (string) $this->invoke($cloud, 'GetBuffer', 'WatchdogRetries'), 'A keep-alive must reset the watchdog backoff');
        $this->assertSame('', (string) $this->invoke($cloud, 'GetBuffer', 'WatchdogNextRetry'), 'A keep-alive must clear the pending backoff window');
    }

    /**
     * Review finding 1: a Symcon restart during a block resets the RateLimit timer to 0
     * (timers are runtime state), while RateLimitUntil and the deactivated IO persist.
     * Once the block has expired, the keep-alive watchdog must lift the block and
     * re-activate the IO - otherwise RegisterServerEvents only reports "IO instance is
     * not active" and the event stream stays dead until the user intervenes.
     */
    public function testWatchdogLiftsExpiredBlockAfterRestart()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));

        //State after the restart: block expired, IO still switched off by applyRateLimit,
        //RateLimit timer not running, keep-alive stale.
        $this->invoke($cloud, 'WriteAttributeInteger', 'RateLimitUntil', time() - 10);
        $this->assertFalse(IPS_GetProperty($parent, 'Active'));
        $this->invoke($cloud, 'SetBuffer', 'KeepAlive', (string) (time() - 120));

        ob_start();
        $cloud->CheckServerEvents();
        $output = ob_get_clean();

        $this->assertSame('', $output, 'The watchdog must not end up at "IO instance is not active"');
        $this->assertSame(0, $this->invoke($cloud, 'ReadAttributeInteger', 'RateLimitUntil'), 'The expired block must be cleared');
        $this->assertTrue(IPS_GetProperty($parent, 'Active'), 'The IO stopped for the block must be re-activated');
        $this->assertStringContainsString('homeappliances/events', IPS_GetProperty($parent, 'URL'), 'The event stream must be re-registered');
    }

    /**
     * Counterpart to finding 1: an IO the user switched off on purpose (no block pending)
     * must stay off - the watchdog only re-activates an IO the module stopped itself.
     */
    public function testWatchdogLeavesIntentionallyInactiveIoAlone()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));
        $this->invoke($cloud, 'WriteAttributeString', 'Token', 'refresh');
        $this->invoke($cloud, 'SetBuffer', 'KeepAlive', (string) (time() - 120));

        ob_start();
        $cloud->CheckServerEvents();
        $output = ob_get_clean();

        $this->assertFalse(IPS_GetProperty($parent, 'Active'), 'An IO switched off by the user must not be re-activated');
        //Review finding 10: output of a timer lands in the log as a warning - every attempt.
        $this->assertSame('', $output, 'The watchdog must not echo while the IO is switched off on purpose');
    }

    /**
     * Review finding 10: without a login (no refresh token) every watchdog attempt
     * echoed "login is missing" from the timer, i.e. a log warning each time.
     */
    public function testWatchdogSilentWithoutLogin()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);
        IPS_SetProperty($parent, 'Active', true);
        IPS_ApplyChanges($parent);
        $this->invoke($cloud, 'SetBuffer', 'KeepAlive', (string) (time() - 120));

        ob_start();
        $cloud->CheckServerEvents();
        $output = ob_get_clean();

        $this->assertSame('', $output, 'The watchdog must not echo while the login is missing');
    }

    /**
     * Review finding 6: ForceRegisterServerEvents activated the IO (first ApplyChanges,
     * with the old URL/header) before it fetched a token. Without a usable token it must
     * not switch the IO on at all - it would only collect 401s from /events.
     */
    public function testForceRegisterDoesNotActivateIoWithoutToken()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);

        ob_start();
        $cloud->ForceRegisterServerEvents();
        $output = ob_get_clean();

        $this->assertStringContainsString('login is missing', $output, 'A manual registration still reports the missing login');
        $this->assertFalse(IPS_GetProperty($parent, 'Active'), 'The IO must not be activated without a token');
    }

    /**
     * Counterpart to finding 6: if the stream cannot be resumed when the block ends, the
     * expired block must stay pending, so the watchdog retries the reset later instead of
     * leaving the IO switched off with nobody responsible for it.
     */
    public function testResetRateLimitKeepsBlockPendingWhenStreamCannotResume()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);
        IPS_SetProperty($parent, 'Active', true);
        IPS_ApplyChanges($parent);

        $cloud->ReceiveData(self::RATE_LIMIT_PAYLOAD);
        //The RateLimit timer fires when the block has just expired.
        $this->invoke($cloud, 'WriteAttributeInteger', 'RateLimitUntil', time() - 1);
        ob_start();
        $cloud->ResetRateLimit();
        ob_end_clean();

        $this->assertNotSame(0, $this->invoke($cloud, 'ReadAttributeInteger', 'RateLimitUntil'), 'The block must stay pending until the stream runs again');
        $this->assertFalse(IPS_GetProperty($parent, 'Active'), 'The IO stays off while no token is available');
    }

    /**
     * Review finding 2: a 401 "invalid_token" from the stream means the server rejected
     * the cached access token. The reconnect must not re-send that same token (it is
     * still "valid" by its local expiry) - each such attempt costs a GET /events.
     */
    public function testInvalidTokenDropsCachedAccessToken()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $parent = $this->prepareParentIo($cloudID);

        //Locally still valid for an hour, but the server has rejected it.
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'rejected', 'Expires' => time() + 3600]));
        //Without a refresh token the refresh fails before any network access.
        $this->invoke($cloud, 'WriteAttributeString', 'Token', '');

        ob_start();
        $cloud->ReceiveData('{"error":{"key":"invalid_token","description":"The access token expired"}}');
        ob_end_clean();

        $this->assertStringNotContainsString('Bearer rejected', (string) IPS_GetProperty($parent, 'Headers'), 'The rejected token must not be sent again');
        $this->assertStringNotContainsString('rejected', (string) $this->invoke($cloud, 'GetBuffer', 'AccessToken'), 'The rejected token must be dropped from the cache');
    }

    /**
     * Review finding 3: ResetRateLimit must re-activate the IO before the instance reports
     * IS_ACTIVE. Children react to that status change with HasActiveParent(), which walks
     * up to the IO; seeing it still inactive they go inactive and - because of their
     * LastParentStatus guard - never retry. The stubs deliver no messages, so the order is
     * checked on the debug trace: the stream registration ('url') must precede the status.
     */
    public function testResetRateLimitActivatesIoBeforeStatus()
    {
        $cloudID = IPS_GetInstanceListByModuleID(self::CLOUD_GUID)[0];
        $cloud = IPS\InstanceManager::getInstanceInterface($cloudID);
        $this->prepareParentIo($cloudID);
        $this->invoke($cloud, 'SetBuffer', 'AccessToken', json_encode(['Token' => 'test', 'Expires' => time() + 3600]));

        $cloud->ReceiveData(self::RATE_LIMIT_PAYLOAD);
        //The debug log is not reset between tests - only look at what ResetRateLimit writes.
        $before = count(IPS\DebugServer::getDebugMessages($cloudID));
        $cloud->ResetRateLimit();

        $messages = array_column(array_slice(IPS\DebugServer::getDebugMessages($cloudID), $before), 'Message');
        $registered = array_search('url', $messages, true);
        $activated = array_search('ResetRateLimit', $messages, true);
        $this->assertNotFalse($registered, 'ResetRateLimit must re-register the event stream');
        $this->assertNotFalse($activated, 'ResetRateLimit must trace when the instance becomes active');
        $this->assertLessThan($activated, $registered, 'The IO must be active before the instance reports IS_ACTIVE');
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
