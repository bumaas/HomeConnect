<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectOvenTest extends TestCase
{
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

        parent::setUp();
    }

    /**
     * Upstream review of PR #20: an option profile that already exists as INTEGER while the
     * API reports a Double made the variable an INTEGER too (build 30), so 4.5 would be cut
     * to 4. The lossy direction gets a float profile of its own (".f", as createStates does)
     * and a float variable. The option is the setpoint of the oven HM778GMB1 (real capture
     * under tests/homeappliances/385110390226001436-001); only the stale profile is staged.
     */
    public function testFloatOptionAgainstIntegerProfileGetsFloatProfile()
    {
        $profile = 'HomeConnect.Oven.Option.SetpointTemperature';
        IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);

        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($oven);
        $program = json_decode(file_get_contents(__DIR__ . '/homeappliances/385110390226001436-001/programs/available/Cooking.Oven.Program.HeatingMode.HotAir/response.json'), true);
        $option = $program['data']['options'][0];
        $this->assertSame('Cooking.Oven.Option.SetpointTemperature', $option['key']);
        $this->assertSame('Double', $option['type']);

        $create = new ReflectionMethod($intf, 'createVariableFromConstraints');
        $create->setAccessible(true);
        $create->invoke($intf, $profile, $option, 'Option', 10);

        $variableID = @IPS_GetObjectIDByIdent('OptionSetpointTemperature', $oven);
        $this->assertNotFalse($variableID, 'The option variable must be created');
        $this->assertSame(VARIABLETYPE_FLOAT, IPS_GetVariable($variableID)['VariableType'], 'A Double option must not be cut to an integer');
        $this->assertSame($profile . '.f', IPS_GetVariable($variableID)['VariableProfile'], 'The float variable gets the float profile');
        $floatProfile = IPS_GetVariableProfile($profile . '.f');
        $this->assertSame(VARIABLETYPE_FLOAT, $floatProfile['ProfileType']);
        $this->assertEquals(30, $floatProfile['MinValue']);
        $this->assertEquals(275, $floatProfile['MaxValue']);
        $this->assertSame(' °C', $floatProfile['Suffix']);
        $this->assertSame(VARIABLETYPE_INTEGER, IPS_GetVariableProfile($profile)['ProfileType'], 'The existing profile is left alone');
    }

    public function testBaseFunctionality()
    {
        $cloudInterface = IPS\InstanceManager::getInstanceInterface(IPS_GetInstanceListByModuleID('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}')[0]);
        // $cloudInterface->selectedProgram = 'Cotton';
        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $this->assertTrue(true);
        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_SetProperty($oven, 'HaID', 'SIEMENS-HB676G5S6-68A40E2F702D');
        IPS_SetProperty($oven, 'DeviceType', 'Oven');
        IPS_ApplyChanges($oven);

        $intf = IPS\InstanceManager::getInstanceInterface($oven);
        $intf->ReceiveData($this->generateTestData(52));
        $initialVar = IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven);
        $this->assertEquals(VARIABLETYPE_INTEGER, $this->getValueType($oven));

        $intf->ReceiveData($this->generateTestData(50.99));
        $newFloat = IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven);
        $this->assertNotEquals($newFloat, $initialVar);
        $this->assertEquals(VARIABLETYPE_FLOAT, $this->getValueType($oven));

        $intf->ReceiveData($this->generateTestData(54));
        $this->assertEquals($newFloat, IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven));
        $this->assertEquals(VARIABLETYPE_FLOAT, $this->getValueType($oven));

        $intf->ReceiveData($this->generateTestData(54.00000000000001));
        $this->assertEquals($newFloat, IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven));
        $this->assertEquals(VARIABLETYPE_FLOAT, $this->getValueType($oven));
    }

    public function testReactivatesAfterParentStatusChange()
    {
        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $parent = IPS_GetInstance($oven)['ConnectionID'];

        IPS\InstanceManager::setStatus($parent, IS_INACTIVE);

        IPS_SetProperty($oven, 'HaID', 'SIEMENS-HB676G5S6-68A40E2F702D');
        IPS_SetProperty($oven, 'DeviceType', 'Oven');
        IPS_ApplyChanges($oven);

        $this->assertEquals(IS_INACTIVE, IPS_GetInstance($oven)['InstanceStatus']);

        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);
        $intf = IPS\InstanceManager::getInstanceInterface($oven);
        $intf->MessageSink(0, $parent, IM_CHANGESTATUS, [IS_ACTIVE]);

        $this->assertEquals(IS_ACTIVE, IPS_GetInstance($oven)['InstanceStatus']);
    }

    public function testStringSettingWithoutAllowedValuesDoesNotCrash()
    {
        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($oven);

        $method = new ReflectionMethod($intf, 'createVariableFromConstraints');
        $method->setAccessible(true);
        $method->invoke(
            $intf,
            'HomeConnect.Test.StringSetting',
            [
                'key'         => 'Dishcare.Dishwasher.Setting.ProgramInfo',
                'name'        => 'Program Info',
                'type'        => 'String',
                'constraints' => [
                    'access' => 'readWrite'
                ]
            ],
            'Setting',
            1
        );

        $variableID = IPS_GetObjectIDByIdent('ProgramInfo', $oven);
        $this->assertNotFalse($variableID);
        $this->assertEquals(VARIABLETYPE_STRING, IPS_GetVariable($variableID)['VariableType']);
        $this->assertCount(0, IPS_GetVariableProfile('HomeConnect.Test.StringSetting')['Associations']);
    }

    public function testSettingWithoutTypeFallsBackToValueType()
    {
        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($oven);

        $method = new ReflectionMethod($intf, 'createVariableFromConstraints');
        $method->setAccessible(true);
        $method->invoke(
            $intf,
            'HomeConnect.Test.BooleanSetting',
            [
                'key'         => 'BSH.Common.Setting.ChildLock',
                'name'        => 'Child Lock',
                'value'       => false,
                'constraints' => [
                    'access' => 'readWrite'
                ]
            ],
            'Setting',
            1
        );

        $variableID = IPS_GetObjectIDByIdent('ChildLock', $oven);
        $this->assertNotFalse($variableID);
        $this->assertEquals(VARIABLETYPE_BOOLEAN, IPS_GetVariable($variableID)['VariableType']);
        $this->assertEquals('HomeConnect.YesNo', IPS_GetVariable($variableID)['VariableProfile']);
    }

    private function generateTestData($tempValue)
    {
        $data = [
            'Event' => 'STATUS',
            'Data'  => json_encode([
                'items' => [
                    0 => [
                        'timestamp' => 1621510867,
                        'handling'  => 'none',
                        'uri'       => '/api/homeappliances/SIEMENS-HM676G0S6-68A40E544465/status/Cooking.Oven.Status.CurrentCavityTemperature',
                        'key'       => 'Cooking.Oven.Status.CurrentCavityTemperature',
                        'unit'      => '°C',
                        'value'     => $tempValue,
                        'level'     => 'hint',
                    ],
                ],
                'haId' => 'SIEMENS-HM676G0S6-68A40E544465',
            ]),
            'id' => 'SIEMENS-HM676G0S6-68A40E544465',
        ];
        return json_encode($data);
    }

    private function getValueType($id)
    {
        $variableId = @IPS_GetObjectIDByIdent('CurrentCavityTemperature', $id);
        if ($variableId === false) {
            return -1;
        }
        return IPS_GetVariable($variableId)['VariableType'];
    }

    private function displayTemperature($id)
    {
        $variableId = @IPS_GetObjectIDByIdent('CurrentCavityTemperature', $id);
        if ($variableId === false) {
            echo PHP_EOL . 'CurrentCavityTemperature not found' . PHP_EOL;
            return;
        }
        $type = IPS_GetVariable($variableId)['VariableType'];
        echo PHP_EOL . $variableId . ' - ' . $type . PHP_EOL;
    }

    private function displayChildrenValues($id)
    {
        $children = IPS_GetChildrenIDs($id);
        echo PHP_EOL . count($children) . PHP_EOL;
        foreach ($children as $child) {
            if (!IPS_GetObject($child)['ObjectIsHidden']) {
                echo IPS_GetName($child) . ' - ' . GetValueFormatted($child) . PHP_EOL;
            }
        }
    }

    private function getChildrenValues($id)
    {
        $children = IPS_GetChildrenIDs($id);
        $result = [];
        foreach ($children as $child) {
            if (!IPS_GetObject($child)['ObjectIsHidden']) {
                $result[IPS_GetObject($child)['ObjectIdent']] = GetValueFormatted($child);
            }
        }
        return $result;
    }
}
