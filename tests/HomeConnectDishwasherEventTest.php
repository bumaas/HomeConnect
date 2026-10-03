<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

/**
 * Events the Event profile does not know showed "N/A" (forum/Discord pitti, 03.10.2026:
 * Dishcare.Dishwasher.Event.SaltNearlyEmpty). The profile is also created only once, so
 * associations added in later builds never reached existing installations (on the nuc the
 * dishwasher profile lacked RinseAidNearlyEmpty).
 *
 * The dishwasher fixtures are real captures of a Siemens dishwasher (02./03.10.2026). The
 * SaltNearlyEmpty event is pitti's log line verbatim.
 */
class HomeConnectDishwasherEventTest extends TestCase
{
    private const DEVICE_GUID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';
    private const HAID = '015070396331014803';

    // pitti's log line (03.10.2026): "PHPModule | Event:{...}"
    private const SALT_EVENT = '{"haId":"013040518724005112","items":[{"handling":"none","key":"Dishcare.Dishwasher.Event.SaltNearlyEmpty","level":"hint","timestamp":1791021935,"value":"BSH.Common.EnumType.EventPresentState.Present"}]}';

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

    public function testSaltNearlyEmptyHasReadableName()
    {
        $dishwasher = $this->createDishwasher();
        $intf = IPS\InstanceManager::getInstanceInterface($dishwasher);

        $intf->ReceiveData($this->event(self::SALT_EVENT));

        $eventID = IPS_GetObjectIDByIdent('Event', $dishwasher);
        $this->assertSame('Dishcare.Dishwasher.Event.SaltNearlyEmpty', GetValue($eventID));
        $this->assertSame('Please fill salt', $this->associationName($eventID));
    }

    public function testProfileFromOlderBuildIsCompletedOnEvent()
    {
        //Profile as found on the nuc: created by an older build, only two associations.
        IPS_CreateVariableProfile('HomeConnect.Event.Dishwasher', VARIABLETYPE_STRING);
        IPS_SetVariableProfileAssociation('HomeConnect.Event.Dishwasher', 'BSH.Common.Event.ProgramAborted', 'Program abgebrochen', '', -1);
        IPS_SetVariableProfileAssociation('HomeConnect.Event.Dishwasher', 'BSH.Common.Event.ProgramFinished', 'Program fertig', '', -1);

        $dishwasher = $this->createDishwasher();
        $intf = IPS\InstanceManager::getInstanceInterface($dishwasher);

        //Same structure as pitti's line, key of an event the module already knows.
        $intf->ReceiveData($this->event(str_replace('SaltNearlyEmpty', 'RinseAidNearlyEmpty', self::SALT_EVENT)));

        $this->assertSame('Please fill RinseAid tank', $this->associationName(IPS_GetObjectIDByIdent('Event', $dishwasher)));
    }

    public function testUnknownEventFallsBackToKeyName()
    {
        $dishwasher = $this->createDishwasher();
        $intf = IPS\InstanceManager::getInstanceInterface($dishwasher);

        //Not a real Home Connect key: stands for any event the module does not know yet.
        $intf->ReceiveData($this->event(str_replace('SaltNearlyEmpty', 'SomeFutureEvent', self::SALT_EVENT)));

        $this->assertSame('Some Future Event', $this->associationName(IPS_GetObjectIDByIdent('Event', $dishwasher)));
    }

    private function createDishwasher(): int
    {
        $dishwasher = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($dishwasher)['ConnectionID'];
        IPS\InstanceManager::getInstanceInterface($parent)->selectedProgram = 'Auto2';
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($dishwasher, 'HaID', self::HAID);
        IPS_SetProperty($dishwasher, 'DeviceType', 'Dishwasher');
        IPS_ApplyChanges($dishwasher);

        return $dishwasher;
    }

    /**
     * Name of the profile association matching the current value. The stubs neither
     * translate nor resolve profiles behind a presentation in GetValueFormatted, so the
     * association is read directly (names stay English here, see locale.json).
     */
    private function associationName(int $variableID): string
    {
        $variable = IPS_GetVariable($variableID);
        foreach (IPS_GetVariableProfile($variable['VariableProfile'])['Associations'] as $association) {
            if ($association['Value'] === $variable['VariableValue']) {
                return $association['Name'];
            }
        }
        return '-';
    }

    private function event(string $data): string
    {
        return json_encode(['Event' => 'EVENT', 'Data' => $data, 'ID' => self::HAID]);
    }
}
