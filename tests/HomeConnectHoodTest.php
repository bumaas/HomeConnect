<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectHoodTest extends TestCase
{
    private const HA_ID = 'SIEMENS-LD88WMM66-XYZ';

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
        $cloudID = IPS_GetInstanceListByModuleID('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}')[0];
        IPS\InstanceManager::setStatus($this->ConfiguratorID, 102);
        IPS\InstanceManager::setStatus($cloudID, 102);

        parent::setUp();
    }

    public function testActiveProgramEventCreatesAndFillsVariable()
    {
        $hood = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($hood);

        // Fan run-on reports the running program only via ActiveProgram.
        $intf->ReceiveData($this->generateActiveProgramEvent('Cooking.Common.Program.Hood.DelayedShutOff'));

        $variableID = IPS_GetObjectIDByIdent('ActiveProgram', $hood);
        $this->assertNotFalse($variableID);
        $this->assertEquals(VARIABLETYPE_STRING, IPS_GetVariable($variableID)['VariableType']);
        $this->assertEquals('Cooking.Common.Program.Hood.DelayedShutOff', GetValue($variableID));
    }

    public function testActiveProgramNullClearsVariable()
    {
        $hood = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($hood);

        $intf->ReceiveData($this->generateActiveProgramEvent('Cooking.Common.Program.Hood.DelayedShutOff'));
        $intf->ReceiveData($this->generateActiveProgramEvent(null));

        $variableID = IPS_GetObjectIDByIdent('ActiveProgram', $hood);
        $this->assertNotFalse($variableID);
        $this->assertEquals('', GetValue($variableID));
    }

    public function testActiveProgramNullWithoutVariableDoesNotCreateIt()
    {
        $hood = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($hood);

        // A null value (nothing running) must not create the variable.
        $intf->ReceiveData($this->generateActiveProgramEvent(null));

        $this->assertFalse(@IPS_GetObjectIDByIdent('ActiveProgram', $hood));
    }

    /**
     * A hood operated at the appliance never sends SelectedProgram, so the option
     * variables have to be created from the ActiveProgram event: one lookup of the
     * available-program metadata provides the profiles, the option events themselves
     * provide the values.
     */
    public function testActiveProgramCreatesOptionVariables()
    {
        $hood = $this->createInitializedHood();
        $intf = IPS\InstanceManager::getInstanceInterface($hood);

        // Idle after init: no program related option variables yet.
        $this->assertFalse(@IPS_GetObjectIDByIdent('OptionVentingLevel', $hood));

        HomeConnectCloud::$requestCount = 0;
        // Local program start as captured from a real device: ActiveProgram plus the
        // current option values arrive in one NOTIFY batch.
        $intf->ReceiveData($this->generateNotifyEvent([
            $this->item('BSH.Common.Setting.PowerState', 'BSH.Common.EnumType.PowerState.On', 'settings/BSH.Common.Setting.PowerState'),
            $this->item('BSH.Common.Root.ActiveProgram', 'Cooking.Common.Program.Hood.Automatic', 'programs/active'),
            $this->item('Cooking.Common.Option.Hood.VentingLevel', 'Cooking.Hood.EnumType.Stage.FanOff', 'programs/selected/options/Cooking.Common.Option.Hood.VentingLevel'),
            $this->item('Cooking.Common.Option.Hood.IntensiveLevel', 'Cooking.Hood.EnumType.IntensiveStage.IntensiveStageOff', 'programs/selected/options/Cooking.Common.Option.Hood.IntensiveLevel'),
        ]));

        $this->assertEquals(1, HomeConnectCloud::$requestCount, 'Creating the option variables must cost exactly one request');
        $ventingID = IPS_GetObjectIDByIdent('OptionVentingLevel', $hood);
        $this->assertNotFalse($ventingID);
        $this->assertEquals('Cooking.Hood.EnumType.Stage.FanOff', GetValue($ventingID));
        $this->assertEquals('Cooking.Hood.EnumType.IntensiveStage.IntensiveStageOff', GetValue(IPS_GetObjectIDByIdent('OptionIntensiveLevel', $hood)));

        // The variable must carry the enum profile built from the constraints.
        $profileName = IPS_GetVariable($ventingID)['VariableProfile'];
        $this->assertEquals('HomeConnect.Hood.Option.VentingLevel', $profileName);
        $associations = [];
        foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
            $associations[$association['Value']] = $association['Name'];
        }
        $this->assertEquals('Lüfterstufe 1', $associations['Cooking.Hood.EnumType.Stage.FanStage01']);

        // The program itself must not pollute the (empty) program selection.
        $this->assertEquals('', GetValue(IPS_GetObjectIDByIdent('SelectedProgram', $hood)));

        // Subsequent option events update the value without any further request.
        $intf->ReceiveData($this->generateNotifyEvent([
            $this->item('Cooking.Common.Option.Hood.VentingLevel', 'Cooking.Hood.EnumType.Stage.FanStage02', 'programs/selected/options/Cooking.Common.Option.Hood.VentingLevel'),
        ]));
        $this->assertEquals('Cooking.Hood.EnumType.Stage.FanStage02', GetValue($ventingID));
        $this->assertEquals(1, HomeConnectCloud::$requestCount, 'Option value updates must not cost requests');

        // A repeated ActiveProgram event for the same program must not refetch.
        $intf->ReceiveData($this->generateNotifyEvent([
            $this->item('BSH.Common.Root.ActiveProgram', 'Cooking.Common.Program.Hood.Automatic', 'programs/active'),
        ]));
        $this->assertEquals(1, HomeConnectCloud::$requestCount, 'An unchanged active program must not refetch the options');
    }

    /**
     * An option value that arrives before the option variable exists is buffered and
     * applied right after the deferred refresh created the variable.
     */
    public function testActiveProgramAppliesBufferedOptionValue()
    {
        $hood = $this->createInitializedHood();
        $intf = IPS\InstanceManager::getInstanceInterface($hood);

        $intf->ReceiveData($this->generateNotifyEvent([
            $this->item('Cooking.Common.Option.Hood.VentingLevel', 'Cooking.Hood.EnumType.Stage.FanStage02', 'programs/selected/options/Cooking.Common.Option.Hood.VentingLevel'),
        ]));
        $this->assertFalse(@IPS_GetObjectIDByIdent('OptionVentingLevel', $hood));

        $intf->ReceiveData($this->generateNotifyEvent([
            $this->item('BSH.Common.Root.ActiveProgram', 'Cooking.Common.Program.Hood.Automatic', 'programs/active'),
        ]));

        $ventingID = IPS_GetObjectIDByIdent('OptionVentingLevel', $hood);
        $this->assertNotFalse($ventingID);
        $this->assertEquals('Cooking.Hood.EnumType.Stage.FanStage02', GetValue($ventingID));
    }

    /**
     * Regression: a program that is not listed under programs/available (undocumented
     * runtime programs like the fan run-on) must neither create variables nor clear
     * the program selection - the selected-program failure path used to wipe it.
     */
    public function testActiveProgramUnsupportedProgramLeavesSelectionUntouched()
    {
        $hood = $this->createInitializedHood();
        $intf = IPS\InstanceManager::getInstanceInterface($hood);

        $selectedID = IPS_GetObjectIDByIdent('SelectedProgram', $hood);
        SetValue($selectedID, 'Cooking.Common.Program.Hood.Venting');

        $intf->ReceiveData($this->generateNotifyEvent([
            $this->item('BSH.Common.Root.ActiveProgram', 'Cooking.Common.Program.Hood.DelayedShutOff', 'programs/active'),
        ]));

        $this->assertEquals('Cooking.Common.Program.Hood.DelayedShutOff', GetValue(IPS_GetObjectIDByIdent('ActiveProgram', $hood)));
        $this->assertFalse(@IPS_GetObjectIDByIdent('OptionVentingLevel', $hood));
        $this->assertEquals('Cooking.Common.Program.Hood.Venting', GetValue($selectedID), 'A failed program lookup must not clear the program selection');
    }

    private function createInitializedHood()
    {
        $hood = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_SetProperty($hood, 'HaID', self::HA_ID);
        IPS_SetProperty($hood, 'DeviceType', 'Hood');
        IPS_ApplyChanges($hood);
        return $hood;
    }

    private function item($key, $value, $uriPath)
    {
        return [
            'timestamp' => 1753731205,
            'handling'  => 'none',
            'uri'       => '/api/homeappliances/' . self::HA_ID . '/' . $uriPath,
            'key'       => $key,
            'value'     => $value,
            'level'     => 'hint',
        ];
    }

    private function generateNotifyEvent(array $items)
    {
        return json_encode([
            'Event' => 'NOTIFY',
            'Data'  => json_encode([
                'items' => $items,
                'haId'  => self::HA_ID,
            ]),
            'id' => self::HA_ID,
        ]);
    }

    private function generateActiveProgramEvent($value)
    {
        return $this->generateNotifyEvent([
            $this->item('BSH.Common.Root.ActiveProgram', $value, 'programs/active'),
        ]);
    }
}
