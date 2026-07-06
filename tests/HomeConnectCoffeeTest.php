<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectCoffeeTest extends TestCase
{
    public const COFFEE = [
        'OperationState'            => 'Ready',
        'DoorState'                 => 'Closed',
        'PowerState'                => 'An',
        'SelectedProgram'           => 'Caffe Crema',
        'OptionCoffeeTemperature'   => '90°C',
        'OptionBeanAmount'          => 'Stark +',
        'OptionFillQuantity'        => '120 ml',
        'Control'                   => '-',
        'RemoteControlStartAllowed' => 'Yes',
        'LocalControlActive'        => 'No',
        'Event'                     => '',
        'EventDescription'          => ''
    ];

    public const ESPRESSO = [
        'OperationState'            => 'Ready',
        'DoorState'                 => 'Closed',
        'PowerState'                => 'An',
        'SelectedProgram'           => 'Espresso',
        'OptionCoffeeTemperature'   => '92°C',
        'OptionBeanAmount'          => 'Sehr stark',
        'OptionFillQuantity'        => '40 ml',
        'Control'                   => '-',
        'RemoteControlStartAllowed' => 'Yes',
        'LocalControlActive'        => 'No',
        'Event'                     => '',
        'EventDescription'          => ''
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

        parent::setUp();
    }

    public function testBaseFunctionality()
    {
        $cloudId = IPS_GetInstanceListByModuleID('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}')[0];
        $cloudInterface = IPS\InstanceManager::getInstanceInterface($cloudId);
        $cloudInterface->selectedProgram = 'Coffee';
        $coffeMaker = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($coffeMaker);
        IPS_SetProperty($coffeMaker, 'HaID', 'SIEMENS-TI9575X1DE-68A40E251CAD');
        IPS_SetProperty($coffeMaker, 'DeviceType', 'CoffeMaker');
        IPS_ApplyChanges($coffeMaker);
        $intf = IPS\InstanceManager::getInstanceInterface($coffeMaker);
        $this->assertEquals(self::COFFEE, $this->getChildrenValues($coffeMaker));
        $intf->RequestAction('SelectedProgram', 'ConsumerProducts.CoffeeMaker.Program.Beverage.Espresso');
        $this->assertEquals(self::ESPRESSO, $this->getChildrenValues($coffeMaker));
    }

    public function testBaseFunctionalityEvents()
    {
        $cloudInterface = IPS\InstanceManager::getInstanceInterface(IPS_GetInstanceListByModuleID('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}')[0]);
        $cloudInterface->selectedProgram = 'Coffee';
        $coffeMaker = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_SetProperty($coffeMaker, 'HaID', 'SIEMENS-TI9575X1DE-68A40E251CAD');
        IPS_SetProperty($coffeMaker, 'DeviceType', 'CoffeMaker');
        IPS_ApplyChanges($coffeMaker);
        $intf = IPS\InstanceManager::getInstanceInterface($coffeMaker);
        $this->assertEquals(self::COFFEE, $this->getChildrenValues($coffeMaker));
        $cloudInterface->selectedProgram = 'Espresso';
        $intf->ReceiveData(json_encode(
            [
                'Event' => 'NOTIFY',
                'Data'  => '{"items":[{"timestamp":1618480503,"handling":"none","uri":"/api/homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected","key":"BSH.Common.Root.SelectedProgram","value":"ConsumerProducts.CoffeeMaker.Program.Beverage.Espresso","level":"hint"},{"timestamp":1618480503,"handling":"none","uri":"/api/homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected/options/ConsumerProducts.CoffeeMaker.Option.CoffeeTemperature","key":"ConsumerProducts.CoffeeMaker.Option.CoffeeTemperature","value":"ConsumerProducts.CoffeeMaker.EnumType.CoffeeTemperature.92C","level":"hint"},{"timestamp":1618480503,"handling":"none","uri":"/api/homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected/options/ConsumerProducts.CoffeeMaker.Option.FillQuantity","key":"ConsumerProducts.CoffeeMaker.Option.FillQuantity","unit":"ml","value":40,"level":"hint"}],"haId":"SIEMENS-TI9575X1DE-68A40E251CAD"}',
                'ID'    => 'SIEMENS-TI9575X1DE-68A40E251CAD'
            ]
        ));
        //The SelectedProgram lookup is deferred via a one-shot timer -> RequestAction.
        //Under the test stubs RegisterOnceTimer runs the script text synchronously, so the
        //refresh has already completed by the time ReceiveData returns.
        $this->assertEquals(self::ESPRESSO, $this->getChildrenValues($coffeMaker));
    }

    /**
     * Regression for the thread-exhaustion bug: a SelectedProgram event must not run the
     * cloud lookup inline in ReceiveData (that ran on the flow thread while the parent was
     * still delivering the event -> re-entrant deadlock / "Warten auf Skriptresultat
     * fehlgeschlagen"). It is now decoupled via a one-shot timer that triggers the
     * 'RefreshSelectedProgram' action. In production this runs on its own thread; under
     * the stubs RegisterOnceTimer executes the action synchronously, so we assert the
     * refresh happens through that action path (and that the action itself works).
     */
    public function testSelectedProgramEventRefreshesViaRequestAction()
    {
        $cloudInterface = IPS\InstanceManager::getInstanceInterface(IPS_GetInstanceListByModuleID('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}')[0]);
        $cloudInterface->selectedProgram = 'Coffee';
        $coffeMaker = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_SetProperty($coffeMaker, 'HaID', 'SIEMENS-TI9575X1DE-68A40E251CAD');
        IPS_SetProperty($coffeMaker, 'DeviceType', 'CoffeMaker');
        IPS_ApplyChanges($coffeMaker);
        $intf = IPS\InstanceManager::getInstanceInterface($coffeMaker);

        //A SelectedProgram event refreshes the options via the deferred action path.
        $cloudInterface->selectedProgram = 'Espresso';
        HomeConnectCloud::$requestCount = 0;
        $intf->ReceiveData(json_encode([
            'Event' => 'NOTIFY',
            'Data'  => '{"items":[{"handling":"none","key":"BSH.Common.Root.SelectedProgram","value":"ConsumerProducts.CoffeeMaker.Program.Beverage.Espresso","level":"hint"}],"haId":"SIEMENS-TI9575X1DE-68A40E251CAD"}',
            'ID'    => 'SIEMENS-TI9575X1DE-68A40E251CAD'
        ]));
        $this->assertGreaterThan(0, HomeConnectCloud::$requestCount, 'The deferred refresh must perform the cloud lookup');
        $this->assertEquals(self::ESPRESSO, $this->getChildrenValues($coffeMaker), 'Options must be refreshed to the new program');

        //The action handler can also be invoked directly and performs the refresh.
        $cloudInterface->selectedProgram = 'Coffee';
        $intf->RequestAction('RefreshSelectedProgram', '');
        $this->assertEquals(self::COFFEE, $this->getChildrenValues($coffeMaker), 'RefreshSelectedProgram action must refresh the options');
    }

    private function displayChildrenValues($id)
    {
        $children = IPS_GetChildrenIDs($id);
        echo PHP_EOL . count($children) . PHP_EOL;
        foreach ($children as $child) {
            echo IPS_GetName($child) . ' - ' . GetValueFormatted($child) . PHP_EOL;
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
            }
        }

        return strval($profile['Prefix'] . $value . $profile['Suffix']);
    }

    private function buildEvent($event)
    {
        $string = '';
        foreach ($event as $name => $value) {
            $string .= $name . ': ' . $value . "\n";
        }
        return $string;
    }
}
