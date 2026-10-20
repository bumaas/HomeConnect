<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

/**
 * The configurator lists already created device instances even when the live discovery
 * fails. It must only list the devices of its own cloud instance - the form offers to
 * delete the listed rows.
 */
class HomeConnectConfiguratorTest extends TestCase
{
    private const CLOUD_GUID = '{CE76810D-B685-9BE0-CC04-38B204DEAD5E}';
    private const DEVICE_GUID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';

    private $configuratorID;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/IOStubs/library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        $this->configuratorID = IPS_CreateInstance('{CA0E667D-8F28-8DF1-2750-5CF587ECA85A}');

        parent::setUp();
    }

    /**
     * Review finding 9: with a second cloud instance (a second Home Connect account),
     * the configurator listed that account's devices as well - deletable from here.
     */
    public function testExistingRowsOnlyFromOwnCloud()
    {
        $ownCloud = IPS_GetInstance($this->configuratorID)['ConnectionID'];
        $foreignCloud = IPS_CreateInstance(self::CLOUD_GUID);

        $ownDevice = $this->createDevice($ownCloud, 'OWN-DEVICE');
        $foreignDevice = $this->createDevice($foreignCloud, 'FOREIGN-DEVICE');

        //No live discovery: only the rows of the existing instances are listed.
        IPS\InstanceManager::setStatus($ownCloud, IS_INACTIVE);
        $form = json_decode(IPS\InstanceManager::getInstanceInterface($this->configuratorID)->GetConfigurationForm(), true);
        $listed = array_column($form['actions'][0]['values'], 'instanceID');

        $this->assertContains($ownDevice, $listed, 'A device of the own cloud is listed');
        $this->assertNotContains($foreignDevice, $listed, 'A device of another cloud must not be listed');
    }

    /**
     * Module Store review: the configurator must filter by the instances at its own
     * splitter. A device without a cloud is not at it.
     */
    public function testExistingRowsSkipDeviceWithoutCloud()
    {
        $ownCloud = IPS_GetInstance($this->configuratorID)['ConnectionID'];
        $device = $this->createDevice($ownCloud, 'ORPHAN-DEVICE');
        IPS_DisconnectInstance($device);

        IPS\InstanceManager::setStatus($ownCloud, IS_INACTIVE);
        $form = json_decode(IPS\InstanceManager::getInstanceInterface($this->configuratorID)->GetConfigurationForm(), true);
        $listed = array_column($form['actions'][0]['values'], 'instanceID');

        $this->assertNotContains($device, $listed, 'A device without a cloud must not be listed');
    }

    /**
     * Module Store review: the match between a discovered appliance and an existing
     * instance must only consider instances at the own cloud. An appliance shared by two
     * accounts has the same HaID in both - the row must not point to the other account's
     * instance, but offer to create one at the own cloud.
     */
    public function testDiscoveryMatchesOnlyOwnInstances()
    {
        $ownCloud = IPS_GetInstance($this->configuratorID)['ConnectionID'];
        $foreignCloud = IPS_CreateInstance(self::CLOUD_GUID);

        //Both HaIDs are part of the discovery (tests/homeappliances/response.json).
        $ownDevice = $this->createDevice($ownCloud, 'SIEMENS-TI9575X1DE-68A40E251CAD');
        $foreignDevice = $this->createDevice($foreignCloud, 'BOSCH-KGN36HI32-68A40E01D5C6');

        IPS\InstanceManager::setStatus($ownCloud, IS_ACTIVE);
        $form = json_decode(IPS\InstanceManager::getInstanceInterface($this->configuratorID)->GetConfigurationForm(), true);
        $rows = array_column($form['actions'][0]['values'], 'instanceID', 'HaID');

        $this->assertSame($ownDevice, $rows['SIEMENS-TI9575X1DE-68A40E251CAD'] ?? null, 'The discovered appliance is matched to the own instance');
        $this->assertSame(0, $rows['BOSCH-KGN36HI32-68A40E01D5C6'] ?? null, 'The instance of another cloud must not be matched');
        $this->assertNotContains($foreignDevice, array_values($rows), 'The instance of another cloud must not be listed');
    }

    private function createDevice(int $cloudID, string $haID): int
    {
        $device = IPS_CreateInstance(self::DEVICE_GUID);
        //ConnectParent() already connected the device to the first cloud
        if (IPS_GetInstance($device)['ConnectionID'] !== $cloudID) {
            IPS_DisconnectInstance($device);
            IPS_ConnectInstance($device, $cloudID);
        }
        //Parent not active: the device does not try to reach the (stubbed) cloud.
        IPS\InstanceManager::setStatus($cloudID, IS_INACTIVE);
        IPS_SetProperty($device, 'HaID', $haID);
        IPS_ApplyChanges($device);
        return $device;
    }
}
