<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

/**
 * A preset start time was no longer sent (nuc, 02.11.2026): three starts with a start time
 * went straight to Run. The appliance answers programs/available/<program> depending on its
 * operation state - while running, Auto2 lists only VarioSpeedPlus and SilenceOnDemand, no
 * StartInRelative. An immediate start changes ActiveProgram while the appliance already runs,
 * refreshActiveProgramOptions() applied that reduced list and hid OptionStartInRelative.
 * Nothing showed it again after the program ended, a hidden option is dropped by
 * RequestAction without a message and left out of the start payload.
 *
 * Fixtures: response.json and selected/Auto2.json are captures of this dishwasher in Ready,
 * run.json (09:00:11) and delayedstart.json (08:26:39) are captures of 02.11.2026 in the
 * operation state named by the file.
 */
class HomeConnectDishwasherStartTest extends TestCase
{
    private const DEVICE_GUID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';
    private const HAID = '015070396331014803';
    private const AUTO2 = 'Dishcare.Dishwasher.Program.Auto2';
    private const AVAILABLE_AUTO2 = 'homeappliances/' . self::HAID . '/programs/available/' . self::AUTO2;

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

    public function testImmediateStartKeepsStartTimeForNextStart()
    {
        $dishwasher = $this->createDishwasher();
        $intf = IPS\InstanceManager::getInstanceInterface($dishwasher);
        $this->assertFalse($this->isHidden($dishwasher, 'OptionStartInRelative'), 'Precondition: start time offered in Ready');

        //Immediate start: the appliance runs before ActiveProgram arrives.
        $this->cloud($dishwasher)->responseVariants[self::AVAILABLE_AUTO2] = 'run';
        $intf->ReceiveData($this->message('STATUS', 'BSH.Common.Status.OperationState', 'BSH.Common.EnumType.OperationState.Run'));
        $intf->ReceiveData($this->message('NOTIFY', 'BSH.Common.Root.ActiveProgram', self::AUTO2));
        $this->assertFalse($this->isHidden($dishwasher, 'OptionStartInRelative'), 'The running program must not hide the start time');
        $this->assertFalse($this->isHidden($dishwasher, 'OptionHygienePlus'), 'The running program must not hide options of the selected program');

        //Program ends, the appliance is ready again.
        unset($this->cloud($dishwasher)->responseVariants[self::AVAILABLE_AUTO2]);
        $intf->ReceiveData($this->message('NOTIFY', 'BSH.Common.Root.ActiveProgram', null));
        $intf->ReceiveData($this->message('STATUS', 'BSH.Common.Status.OperationState', 'BSH.Common.EnumType.OperationState.Ready'));

        //Next start with a start time, as the start script on the nuc does it.
        $intf->RequestAction('OptionStartInRelative', 1980);
        $intf->RequestAction('Control', 'Start');

        $this->assertSame(1980, $this->startOption($dishwasher, 'BSH.Common.Option.StartInRelative'), 'The start time must be part of the start payload');
    }

    public function testHiddenOptionsReturnWhenApplianceIsReadyAgain()
    {
        $dishwasher = $this->createDishwasher();
        $intf = IPS\InstanceManager::getInstanceInterface($dishwasher);

        //State found on the nuc after a delayed start: options hidden by an earlier build.
        foreach (['OptionHygienePlus', 'OptionHalfLoad', 'OptionIntensivZone', 'OptionBrillianceDry'] as $ident) {
            IPS_SetHidden(IPS_GetObjectIDByIdent($ident, $dishwasher), true);
        }

        $intf->ReceiveData($this->message('STATUS', 'BSH.Common.Status.OperationState', 'BSH.Common.EnumType.OperationState.Run'));
        $intf->ReceiveData($this->message('STATUS', 'BSH.Common.Status.OperationState', 'BSH.Common.EnumType.OperationState.Ready'));

        foreach (['OptionHygienePlus', 'OptionHalfLoad', 'OptionIntensivZone', 'OptionBrillianceDry', 'OptionStartInRelative'] as $ident) {
            $this->assertFalse($this->isHidden($dishwasher, $ident), "$ident must be offered again in Ready");
        }
    }

    public function testSettingAnUnavailableOptionIsReported()
    {
        $dishwasher = $this->createDishwasher();
        $intf = IPS\InstanceManager::getInstanceInterface($dishwasher);
        IPS_SetHidden(IPS_GetObjectIDByIdent('OptionStartInRelative', $dishwasher), true);

        ob_start();
        $intf->RequestAction('OptionStartInRelative', 1980);
        $output = ob_get_clean();

        $this->assertNotSame('', $output, 'Setting an option the program does not offer must not be dropped silently');
        $this->assertSame(0, GetValue(IPS_GetObjectIDByIdent('OptionStartInRelative', $dishwasher)));
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

    private function cloud(int $dishwasher)
    {
        return IPS\InstanceManager::getInstanceInterface(IPS_GetInstance($dishwasher)['ConnectionID']);
    }

    private function isHidden(int $dishwasher, string $ident): bool
    {
        return IPS_GetObject(IPS_GetObjectIDByIdent($ident, $dishwasher))['ObjectIsHidden'];
    }

    /**
     * Value of an option in the last programs/active PUT, null if the option is missing.
     */
    private function startOption(int $dishwasher, string $key)
    {
        $starts = array_values(array_filter($this->cloud($dishwasher)->putRequests, static function ($request)
        {
            return substr($request[0], -strlen('/programs/active')) === '/programs/active';
        }));
        $this->assertNotEmpty($starts, 'No start command sent');
        foreach (json_decode(end($starts)[1], true)['data']['options'] as $option) {
            if ($option['key'] === $key) {
                return $option['value'];
            }
        }
        return null;
    }

    private function message(string $event, string $key, $value): string
    {
        return json_encode([
            'Event' => $event,
            'Data'  => json_encode(['items' => [['handling' => 'none', 'key' => $key, 'value' => $value, 'level' => 'hint']], 'haId' => self::HAID]),
            'ID'    => self::HAID
        ]);
    }
}
