<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

/**
 * The device instance exposes a "Connected" variable, so a visualization can tell an
 * appliance that is merely switched off (OperationState Inactive, still connected) from
 * one Home Connect reports as offline.
 *
 * Fixtures are real captures: the dryer (BOSCH-WTX87E90) was online, the washer
 * (BOSCH-WAV28G43) was offline when /status was requested on 02.10.2026 - Home Connect
 * then answers with SDK.Error.HomeAppliance.Connection.Initialization.Failed.
 */
class HomeConnectConnectedTest extends TestCase
{
    private const DEVICE_GUID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';
    private const ONLINE_HAID = 'BOSCH-WTX87E90-68A40E44C6B9';
    private const OFFLINE_HAID = 'BOSCH-WAV28G43-68A40E970546';

    protected function setUp(): void
    {
        //Reset
        IPS\Kernel::reset();

        //Register our core stubs for testing
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');

        //Register io stubs for testing - sse client
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/IOStubs/library.json');

        //Register our library we need for testing
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        HomeConnectCloud::$requestCount = 0;

        parent::setUp();
    }

    public function testOnlineDeviceIsConnected()
    {
        $device = $this->createDevice(self::ONLINE_HAID, 'Dryer');

        $connectedID = @IPS_GetObjectIDByIdent('Connected', $device);
        $this->assertNotFalse($connectedID, 'Every device gets a Connected variable');
        $this->assertSame(VARIABLETYPE_BOOLEAN, IPS_GetVariable($connectedID)['VariableType']);
        $this->assertTrue(GetValue($connectedID), 'A device answering /status is connected');
    }

    public function testOfflineDeviceIsNotConnected()
    {
        $device = $this->createDevice(self::OFFLINE_HAID, 'Washer');

        $connectedID = @IPS_GetObjectIDByIdent('Connected', $device);
        $this->assertNotFalse($connectedID, 'The Connected variable also exists for a device that is offline at setup');
        $this->assertFalse(GetValue($connectedID), 'A device reported as offline by /status is not connected');
    }

    public function testDisconnectedAndConnectedEventsToggleConnected()
    {
        $device = $this->createDevice(self::ONLINE_HAID, 'Dryer');
        $intf = IPS\InstanceManager::getInstanceInterface($device);
        $connectedID = IPS_GetObjectIDByIdent('Connected', $device);

        $intf->ReceiveData(json_encode(['Event' => 'DISCONNECTED', 'Data' => '', 'ID' => self::ONLINE_HAID]));
        $this->assertFalse(GetValue($connectedID), 'DISCONNECTED clears Connected');
        //Unchanged behavior: OperationState still falls back to Inactive.
        $this->assertSame('BSH.Common.EnumType.OperationState.Inactive', GetValue(IPS_GetObjectIDByIdent('OperationState', $device)));

        //The refresh throttle is still active here; Connected must follow the event anyway.
        $intf->ReceiveData(json_encode(['Event' => 'CONNECTED', 'Data' => '', 'ID' => self::ONLINE_HAID]));
        $this->assertTrue(GetValue($connectedID), 'CONNECTED sets Connected again');
    }

    public function testConnectedDoesNotChangeInstanceStatus()
    {
        $device = $this->createDevice(self::OFFLINE_HAID, 'Washer');
        $this->assertEquals(IS_ACTIVE, IPS_GetInstance($device)['InstanceStatus'], 'An offline appliance is no instance error');

        $intf = IPS\InstanceManager::getInstanceInterface($device);
        $intf->ReceiveData(json_encode(['Event' => 'DISCONNECTED', 'Data' => '', 'ID' => self::OFFLINE_HAID]));
        $this->assertEquals(IS_ACTIVE, IPS_GetInstance($device)['InstanceStatus']);
    }

    /**
     * Review finding 4: an appliance that is offline when its instance is set up keeps
     * Initialized = false. When it comes online within REFRESH_MIN_INTERVAL, the CONNECTED
     * refresh is throttled - and nothing ever retried the pending initialization, so the
     * instance stayed without its variables. A throttled init must schedule a retry.
     */
    public function testThrottledInitializationIsRetried()
    {
        $device = $this->createDevice(self::OFFLINE_HAID, 'Washer');
        $intf = IPS\InstanceManager::getInstanceInterface($device);
        $this->assertFalse($this->invoke($intf, 'ReadAttributeBoolean', 'Initialized'), 'Setup of an offline appliance does not initialize');

        //The appliance comes online right after the setup.
        $intf->ReceiveData(json_encode(['Event' => 'CONNECTED', 'Data' => '', 'ID' => self::OFFLINE_HAID]));

        $retry = $this->invoke($intf, 'GetTimerInterval', 'RetryRefresh');
        $this->assertGreaterThan(0, $retry, 'A throttled initialization must be retried');
        $this->assertLessThanOrEqual(31000, $retry, 'The retry follows once the throttle window has passed');
    }

    /**
     * Counterpart to finding 4: a throttled value refresh of an initialized appliance needs
     * no retry - live STATUS/NOTIFY events keep its values current.
     */
    public function testThrottledValueRefreshIsNotRetried()
    {
        $device = $this->createDevice(self::ONLINE_HAID, 'Dryer');
        $intf = IPS\InstanceManager::getInstanceInterface($device);

        $intf->ReceiveData(json_encode(['Event' => 'CONNECTED', 'Data' => '', 'ID' => self::ONLINE_HAID]));

        //The stub reports a stopped timer as 0 minus the elapsed time.
        $this->assertLessThanOrEqual(0, $this->invoke($intf, 'GetTimerInterval', 'RetryRefresh'), 'No retry for a value refresh');
    }

    /**
     * Upstream review of PR #20: refreshDeviceState() cleared the retry timer on every
     * call, so a throttled value refresh in the same window dropped a pending
     * initialization retry without re-arming it. The retry must survive.
     */
    public function testThrottledValueRefreshKeepsPendingRetry()
    {
        $device = $this->createDevice(self::OFFLINE_HAID, 'Washer');
        $intf = IPS\InstanceManager::getInstanceInterface($device);
        $intf->ReceiveData(json_encode(['Event' => 'CONNECTED', 'Data' => '', 'ID' => self::OFFLINE_HAID]));
        $this->assertGreaterThan(0, $this->invoke($intf, 'GetTimerInterval', 'RetryRefresh'), 'Precondition: a retry is pending');

        //A value refresh within the throttle window (e.g. a parent status flap).
        $this->invoke($intf, 'refreshDeviceState', false, 'test');

        $this->assertGreaterThan(0, $this->invoke($intf, 'GetTimerInterval', 'RetryRefresh'), 'The pending retry must not be lost');
    }

    /**
     * Upstream review of PR #20: the retry ran through the CONNECTED action and showed up
     * in the debug log as "Event:CONNECTED (deferred)". It must name its own trigger.
     */
    public function testRetryRunsUnderItsOwnLabel()
    {
        $device = $this->createDevice(self::OFFLINE_HAID, 'Washer');
        $intf = IPS\InstanceManager::getInstanceInterface($device);
        $intf->ReceiveData(json_encode(['Event' => 'CONNECTED', 'Data' => '', 'ID' => self::OFFLINE_HAID]));

        $before = count(IPS\DebugServer::getDebugMessages($device));
        //What the RetryRefresh timer runs.
        $intf->RequestAction('RetryRefreshDeviceState', '');

        $traces = [];
        foreach (array_slice(IPS\DebugServer::getDebugMessages($device), $before) as $message) {
            if ($message['Message'] === 'refreshDeviceState' && strpos($message['Data'], 'trigger: ') === 0) {
                $traces[] = $message['Data'];
            }
        }
        $this->assertCount(1, $traces, 'The retry runs exactly one refresh');
        $this->assertStringStartsWith('trigger: RetryRefresh,', $traces[0], 'The retry must be traced under its own trigger');
    }

    /**
     * Follow-up from the review of PR #20: RetryRefresh is a repeating timer. If the cloud
     * goes inactive while a retry is pending (e.g. a rate-limit block), it kept firing every
     * 1-30 s until the cloud was active again: no API request, but a script run and a debug
     * line each time. An inactive parent must stop the timer; the status change back to
     * IS_ACTIVE starts the initialization again anyway.
     */
    public function testInactiveParentStopsPendingRetry()
    {
        $device = $this->createDevice(self::OFFLINE_HAID, 'Washer');
        $intf = IPS\InstanceManager::getInstanceInterface($device);
        $intf->ReceiveData(json_encode(['Event' => 'CONNECTED', 'Data' => '', 'ID' => self::OFFLINE_HAID]));
        $this->assertGreaterThan(0, $this->invoke($intf, 'GetTimerInterval', 'RetryRefresh'), 'Precondition: a retry is pending');

        $parent = IPS_GetInstance($device)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, 201);
        $intf->MessageSink(0, $parent, IM_CHANGESTATUS, [201]);

        $this->assertLessThanOrEqual(0, $this->invoke($intf, 'GetTimerInterval', 'RetryRefresh'), 'The retry must stop while the parent is inactive');
        $this->assertSame(IS_INACTIVE, IPS_GetInstance($device)['InstanceStatus']);
    }

    private function invoke($object, string $method, ...$args)
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }

    private function createDevice(string $haID, string $deviceType): int
    {
        $device = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($device)['ConnectionID'];
        IPS\InstanceManager::getInstanceInterface($parent)->selectedProgram = 'Cotton';
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($device, 'HaID', $haID);
        IPS_SetProperty($device, 'DeviceType', $deviceType);
        IPS_ApplyChanges($device);

        return $device;
    }
}
