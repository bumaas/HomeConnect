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

    private function generateActiveProgramEvent($value)
    {
        $data = [
            'Event' => 'NOTIFY',
            'Data'  => json_encode([
                'items' => [
                    0 => [
                        'timestamp' => 1753731205,
                        'handling'  => 'none',
                        'uri'       => '/api/homeappliances/SIEMENS-LD88WMM66-XYZ/programs/active',
                        'key'       => 'BSH.Common.Root.ActiveProgram',
                        'value'     => $value,
                        'level'     => 'hint',
                    ],
                ],
                'haId' => 'SIEMENS-LD88WMM66-XYZ',
            ]),
            'id' => 'SIEMENS-LD88WMM66-XYZ',
        ];
        return json_encode($data);
    }
}
