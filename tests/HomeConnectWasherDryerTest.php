<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

/**
 * Washer dryer WNC254A40 (forum t/124612 #570/#572). The fixtures are taken unchanged
 * from a user's debug dump: the API lists only three selectable programs, but programs
 * chosen at the appliance (about 25 according to #572) are reported via events. The
 * forum post shows only the last key snippets, so the full keys below assume the
 * namespace of the listed programs (LaundryCare.WasherDryer.Program.*).
 */
class HomeConnectWasherDryerTest extends TestCase
{
    private const HA_ID = 'BOSCH-WNC254A40-XYZ';
    private const PROFILE = 'HomeConnect.WasherDryer.Programs';

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

    public function testProgramProfileContainsTheListedPrograms()
    {
        $this->createWasherDryer();

        $this->assertEquals([
            'LaundryCare.WasherDryer.Program.Cotton.Eco4060' => 'Eco 40-60',
            'LaundryCare.WasherDryer.Program.Cotton'         => 'Baumwolle',
            'LaundryCare.WasherDryer.Program.Mix'            => 'Schnell/Mix',
        ], $this->associations());
    }

    /**
     * A program selected at the appliance is reported via SelectedProgram although the
     * API does not list it. Without an association the variable shows the raw key.
     */
    public function testSelectedProgramEventAddsReadableAssociation()
    {
        $washerDryer = $this->createWasherDryer();
        $intf = IPS\InstanceManager::getInstanceInterface($washerDryer);

        $intf->ReceiveData($this->generateNotifyEvent('BSH.Common.Root.SelectedProgram', 'LaundryCare.WasherDryer.Program.PlushToy', 'programs/selected'));

        $this->assertEquals('LaundryCare.WasherDryer.Program.PlushToy', GetValue(IPS_GetObjectIDByIdent('SelectedProgram', $washerDryer)));
        $this->assertSame('Plush Toy', $this->associations()['LaundryCare.WasherDryer.Program.PlushToy'] ?? null);
    }

    /**
     * ApplyChanges (e.g. on every kernel start) rebuilds the profile from the API list.
     * The currently selected program must keep its association.
     */
    public function testApplyChangesKeepsAssociationOfSelectedProgram()
    {
        $washerDryer = $this->createWasherDryer();
        $intf = IPS\InstanceManager::getInstanceInterface($washerDryer);
        $intf->ReceiveData($this->generateNotifyEvent('BSH.Common.Root.SelectedProgram', 'LaundryCare.WasherDryer.Program.ShirtsBlouses', 'programs/selected'));

        IPS_ApplyChanges($washerDryer);

        $this->assertEquals('LaundryCare.WasherDryer.Program.ShirtsBlouses', GetValue(IPS_GetObjectIDByIdent('SelectedProgram', $washerDryer)));
        $this->assertSame('Shirts Blouses', $this->associations()['LaundryCare.WasherDryer.Program.ShirtsBlouses'] ?? null);
        $this->assertSame('Schnell/Mix', $this->associations()['LaundryCare.WasherDryer.Program.Mix'] ?? null);
    }

    public function testActiveProgramEventGetsReadableName()
    {
        $washerDryer = $this->createWasherDryer();
        $intf = IPS\InstanceManager::getInstanceInterface($washerDryer);

        $intf->ReceiveData($this->generateNotifyEvent('BSH.Common.Root.ActiveProgram', 'LaundryCare.WasherDryer.Program.DelicatesSilk', 'programs/active'));

        $this->assertSame('Delicates Silk', $this->associations()['LaundryCare.WasherDryer.Program.DelicatesSilk'] ?? null);
    }

    /**
     * Upstream review of PR #20: a digit followed by a capital split oddly, "HotAir3D"
     * became "Hot Air3 D". Digits form their own word, a capital after a digit does not.
     */
    public function testReadableNameKeepsDigitGroupsTogether()
    {
        $intf = IPS\InstanceManager::getInstanceInterface($this->createWasherDryer());
        $readableName = new ReflectionMethod($intf, 'getReadableName');
        $readableName->setAccessible(true);

        //Keys of the washer WAV28G43 and the dishwasher (tests/homeappliances), example from the review.
        $this->assertSame('IDos 1 Base Level', $readableName->invoke($intf, 'LaundryCare.Washer.Setting.IDos1BaseLevel'));
        $this->assertSame('Eco 50', $readableName->invoke($intf, 'Dishcare.Dishwasher.Program.Eco50'));
        $this->assertSame('Hot Air 3D', $readableName->invoke($intf, 'Cooking.Oven.Program.HeatingMode.HotAir3D'));
    }

    private function createWasherDryer()
    {
        $washerDryer = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_ConnectInstance($washerDryer, $this->ConfiguratorID);
        IPS_SetProperty($washerDryer, 'HaID', self::HA_ID);
        IPS_SetProperty($washerDryer, 'DeviceType', 'WasherDryer');
        IPS_ApplyChanges($washerDryer);
        return $washerDryer;
    }

    private function associations()
    {
        $associations = [];
        foreach (IPS_GetVariableProfile(self::PROFILE)['Associations'] as $association) {
            $associations[$association['Value']] = $association['Name'];
        }
        return $associations;
    }

    private function generateNotifyEvent($key, $value, $uriPath)
    {
        return json_encode([
            'Event' => 'NOTIFY',
            'Data'  => json_encode([
                'items' => [[
                    'timestamp' => 1759561437,
                    'handling'  => 'none',
                    'uri'       => '/api/homeappliances/' . self::HA_ID . '/' . $uriPath,
                    'key'       => $key,
                    'value'     => $value,
                    'level'     => 'hint',
                ]],
                'haId' => self::HA_ID,
            ]),
            'id' => self::HA_ID,
        ]);
    }
}
