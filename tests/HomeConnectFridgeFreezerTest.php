<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectFridgeFreezerTest extends TestCase
{
    private const DEVICE_GUID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';
    private const FRIDGE_HAID = 'BOSCH-KAD92HBFP-68A40E25B8F3';

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

        $this->ConfiguratorID = IPS_CreateInstance('{CA0E667D-8F28-8DF1-2750-5CF587ECA85A}');

        //Reset the request counter so each test starts from a known state
        HomeConnectCloud::$requestCount = 0;

        parent::setUp();
    }

    /**
     * Regression test for the rate-limit retry loop.
     *
     * A FridgeFreezer has no OperationState in /status and /programs returns an
     * UnsupportedOperation error. With the old code needsInitialization() returned
     * true on every IM_CHANGESTATUS (because the OperationState variable never
     * existed), so each parent status flap re-ran a full InitializeDevice() and
     * burned through the API quota.
     *
     * A burst of identical parent status flaps must produce NO extra API calls at all:
     * the transition guard drops repeated same-status notifications, and the refresh
     * throttle suppresses the remaining one because a refresh already ran during setup.
     */
    public function testNoReInitLoopWhenOperationStateMissing()
    {
        $fridge = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($fridge)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($fridge, 'HaID', self::FRIDGE_HAID);
        IPS_SetProperty($fridge, 'DeviceType', 'FridgeFreezer');
        IPS_ApplyChanges($fridge);

        //The first initialization ran and created variables, even though /programs failed.
        $this->assertGreaterThan(0, HomeConnectCloud::$requestCount, 'Initial setup should perform API calls');
        $this->assertNotFalse(@IPS_GetObjectIDByIdent('DoorState', $fridge), 'DoorState should be created from /status');
        //A FridgeFreezer has no OperationState - this is exactly what triggered the old loop.
        $this->assertFalse(@IPS_GetObjectIDByIdent('OperationState', $fridge), 'FridgeFreezer has no OperationState');
        $this->assertEquals(IS_ACTIVE, IPS_GetInstance($fridge)['InstanceStatus']);

        //Now simulate repeated parent status flaps (as seen during reconnect / rate limiting).
        HomeConnectCloud::$requestCount = 0;
        $intf = IPS\InstanceManager::getInstanceInterface($fridge);
        for ($i = 0; $i < 5; $i++) {
            $intf->MessageSink(0, $parent, IM_CHANGESTATUS, [IS_ACTIVE]);
        }

        //No API call at all: repeated same-status flaps are dropped by the transition
        //guard, and the single genuine transition is suppressed by the refresh throttle
        //(a refresh already ran during setup, well within REFRESH_MIN_INTERVAL).
        $this->assertSame(0, HomeConnectCloud::$requestCount, 'A burst of identical status flaps must not trigger any refresh');

        //The structure is untouched - still no OperationState, DoorState still present, still active.
        $this->assertFalse(@IPS_GetObjectIDByIdent('OperationState', $fridge), 'Value refresh must not create OperationState');
        $this->assertNotFalse(@IPS_GetObjectIDByIdent('DoorState', $fridge), 'DoorState remains present');
        $this->assertEquals(IS_ACTIVE, IPS_GetInstance($fridge)['InstanceStatus']);
    }

    /**
     * Re-applying without a config change immediately after setup must not perform any
     * API calls: it is neither a re-init (signature unchanged) nor an allowed refresh
     * (throttled, since setup just refreshed within REFRESH_MIN_INTERVAL).
     */
    public function testReInitializesOnceAfterSignatureChange()
    {
        $fridge = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($fridge)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($fridge, 'HaID', self::FRIDGE_HAID);
        IPS_SetProperty($fridge, 'DeviceType', 'FridgeFreezer');
        IPS_ApplyChanges($fridge);

        $this->assertGreaterThan(0, HomeConnectCloud::$requestCount);

        //Re-applying right away is throttled and does not re-initialize.
        HomeConnectCloud::$requestCount = 0;
        IPS_ApplyChanges($fridge);
        $this->assertSame(0, HomeConnectCloud::$requestCount, 'ApplyChanges right after setup must be throttled (no API calls)');
    }

    /**
     * The lightweight value refresh itself (invoked directly, bypassing the throttle)
     * fetches only /status and /settings and updates existing variables - it must not
     * re-create structure or hit /programs.
     */
    public function testValueRefreshFetchesStatusAndSettingsOnly()
    {
        $fridge = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($fridge)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($fridge, 'HaID', self::FRIDGE_HAID);
        IPS_SetProperty($fridge, 'DeviceType', 'FridgeFreezer');
        IPS_ApplyChanges($fridge);

        $intf = IPS\InstanceManager::getInstanceInterface($fridge);
        HomeConnectCloud::$requestCount = 0;
        $method = new ReflectionMethod($intf, 'refreshDeviceValues');
        $method->setAccessible(true);
        $method->invoke($intf);

        //GET /status + GET /settings = 2 calls; no /programs, no per-setting constraint fetch.
        $this->assertSame(2, HomeConnectCloud::$requestCount, 'Value refresh must only fetch /status and /settings');
        $this->assertFalse(@IPS_GetObjectIDByIdent('OperationState', $fridge), 'Value refresh must not create OperationState');
        $this->assertNotFalse(@IPS_GetObjectIDByIdent('DoorState', $fridge), 'DoorState remains present');
    }

    /**
     * The API documents that fridge freezers have no programs at all ("There are no
     * programs available for Fridge Freezers"), so createPrograms() must not spend a
     * request on the guaranteed SDK.Error.UnsupportedOperation from /programs.
     */
    public function testCreateProgramsSkipsRequestForProgramlessType()
    {
        $fridge = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($fridge)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($fridge, 'HaID', self::FRIDGE_HAID);
        IPS_SetProperty($fridge, 'DeviceType', 'FridgeFreezer');
        IPS_ApplyChanges($fridge);

        $intf = IPS\InstanceManager::getInstanceInterface($fridge);
        HomeConnectCloud::$requestCount = 0;
        $method = new ReflectionMethod($intf, 'createPrograms');
        $method->setAccessible(true);
        $method->invoke($intf);

        $this->assertSame(0, HomeConnectCloud::$requestCount, 'createPrograms must not request /programs for a programless appliance type');
        $this->assertFalse(@IPS_GetObjectIDByIdent('SelectedProgram', $fridge), 'No program selection variable for a programless appliance type');
    }

    /**
     * A CONNECTED event must not refresh synchronously on the event thread (that blocks
     * ReceiveData -> "Warten auf Skriptresultat fehlgeschlagen"). It arms a one-shot timer
     * that runs the refresh via the 'RefreshDeviceState' action. Under the stubs
     * RegisterOnceTimer runs synchronously, so the refresh completes here (throttle cleared
     * first so it actually runs).
     */
    public function testConnectedEventRefreshesViaDeferredAction()
    {
        $fridge = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($fridge)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($fridge, 'HaID', self::FRIDGE_HAID);
        IPS_SetProperty($fridge, 'DeviceType', 'FridgeFreezer');
        IPS_ApplyChanges($fridge);

        $intf = IPS\InstanceManager::getInstanceInterface($fridge);
        //Clear the 30s refresh throttle so the deferred refresh runs.
        $setBuffer = new ReflectionMethod($intf, 'SetBuffer');
        $setBuffer->setAccessible(true);
        $setBuffer->invoke($intf, 'LastRefresh', '0');

        HomeConnectCloud::$requestCount = 0;
        $intf->ReceiveData(json_encode(['Event' => 'CONNECTED', 'Data' => '', 'ID' => self::FRIDGE_HAID]));

        //Deferred action performed the lightweight refresh (GET /status + GET /settings).
        $this->assertSame(2, HomeConnectCloud::$requestCount, 'CONNECTED must refresh via the deferred RefreshDeviceState action');
        $this->assertEquals(IS_ACTIVE, IPS_GetInstance($fridge)['InstanceStatus']);
    }
}
