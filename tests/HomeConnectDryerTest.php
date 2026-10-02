<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectDryerTest extends TestCase
{
    public const COTTON = [
        'DoorState'                 => 'Closed',
        'OperationState'            => 'Ready',
        'PowerState'                => 'An',
        'SelectedProgram'           => 'Baumwolle',
        'OptionDryingTarget'        => 'Schranktrocken',
        'Control'                   => '-',
        'LocalControlActive'        => 'No',
        'RemoteControlActive'       => 'Yes',
        'RemoteControlStartAllowed' => 'Yes',
        'Event'                     => '-',
        'EventDescription'          => '',
        'Connected'                 => 'Yes'
    ];

    public const TIME_COLD = [
        'OperationState'            => 'Ready',
        'DoorState'                 => 'Closed',
        'PowerState'                => 'An',
        'SelectedProgram'           => 'Zeitprogramm kalt',
        'UseDuration'               => 'No',
        'OptionDuration'            => '1200 seconds',
        'Control'                   => '-',
        'LocalControlActive'        => 'No',
        'RemoteControlActive'       => 'Yes',
        'RemoteControlStartAllowed' => 'Yes',
        'Event'                     => '-',
        'EventDescription'          => '',
        'Connected'                 => 'Yes'
    ];

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
        $this->CloudID = IPS_CreateInstance('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}');
        IPS_ConnectInstance($this->ConfiguratorID, $this->CloudID);
        IPS\InstanceManager::setStatus($this->ConfiguratorID, 102);
        IPS\InstanceManager::setStatus($this->CloudID, 102);

        parent::setUp();
    }

    public function testBaseFunctionality()
    {
        $cloudInterface = IPS\InstanceManager::getInstanceInterface($this->CloudID);
        $cloudInterface->selectedProgram = 'Cotton';
        $dryer = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_ConnectInstance($dryer, $this->ConfiguratorID);
        $this->assertTrue(true);
        $dryer = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_ConnectInstance($dryer, $this->ConfiguratorID);
        IPS_SetProperty($dryer, 'HaID', 'BOSCH-WTX87E90-68A40E44C6B9');
        IPS_SetProperty($dryer, 'DeviceType', 'Dryer');
        IPS_ApplyChanges($dryer);
        $intf = IPS\InstanceManager::getInstanceInterface($dryer);
        $this->assertEquals(self::COTTON, $this->getChildrenValues($dryer));
        $intf->RequestAction('SelectedProgram', 'LaundryCare.Dryer.Program.TimeCold');
        $this->assertEquals(self::TIME_COLD, $this->getChildrenValues($dryer));
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
            if (IPS_GetObject($child)['ObjectIsHidden']) {
                continue;
            }
            $result[IPS_GetObject($child)['ObjectIdent']] = $this->formatValue($child);
        }
        return $result;
    }

    private function formatValue(int $variableID): string
    {
        $presentation = IPS_GetVariablePresentation($variableID);
        if (!empty($presentation)) {
            return GetValueFormatted($variableID);
        }

        $variable = IPS_GetVariable($variableID);
        $profileName = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];
        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return strval($variable['VariableValue']);
        }

        $profile = IPS_GetVariableProfile($profileName);
        $value = $variable['VariableValue'];
        if (count($profile['Associations']) > 0) {
            switch ($profile['ProfileType']) {
                case VARIABLETYPE_BOOLEAN:
                    return $value ? $profile['Associations'][1]['Name'] : $profile['Associations'][0]['Name'];
                case VARIABLETYPE_STRING:
                    for ($i = count($profile['Associations']) - 1; $i >= 0; $i--) {
                        if ($value == $profile['Associations'][$i]['Value']) {
                            return $profile['Prefix'] . sprintf($profile['Associations'][$i]['Name'], $value) . $profile['Suffix'];
                        }
                    }
                    return '-';
                case VARIABLETYPE_INTEGER:
                    return strval($profile['Prefix'] . $value . $profile['Suffix']);
            }
        }

        return strval($profile['Prefix'] . $value . $profile['Suffix']);
    }
}
