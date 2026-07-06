<?php

declare(strict_types=1);
class HomeConnectDevice extends IPSModule
{
    public const RESTRICTIONS = [
        'BSH.Common.Status.RemoteControlStartAllowed',
        'BSH.Common.Status.RemoteControlActive',
        'BSH.Common.Status.LocalControlActive'
    ];

    public const EXCLUDE = [
        'BSH.Common.Root.ActiveProgram'
    ];

    //Options which are not real options but a kind of 'status updates'
    public const UPDATE_OPTIONS = [
        'BSH.Common.Option.ProgramProgress',
        'BSH.Common.Option.RemainingProgramTime',
        'BSH.Common.Option.ElapsedProgramTime'
    ];

    public const EVENT_DESCRIPTIONS = [
        'BSH.Common.Event.ProgramAborted'                                 => 'The program was aborted',
        'BSH.Common.Event.ProgramFinished'                                => 'The program is finished',
        'BSH.Common.Event.AlarmClockElapsed'                              => 'The alarm clock has elapsed',
        'Cooking.Oven.Event.PreheatFinished'                              => 'The preheating phase is finished',
        'Cooking.Oven.Event.RegularPreheatFinished'                       => 'The preheating phase is finished',
        'ConsumerProducts.CoffeeMaker.Event.BeanContainerEmpty'           => 'Please fill bean container',
        'ConsumerProducts.CoffeeMaker.Event.WaterTankEmpty'               => 'Please fill water tank',
        'ConsumerProducts.CoffeeMaker.Event.DripTrayFull'                 => 'Please empty drip tray',
        'ConsumerProducts.CoffeeMaker.Event.DeviceShouldBeDescaled'       => 'Please descale device',
        'ConsumerProducts.CoffeeMaker.Event.DeviceDescalingOverdue'       => 'Descaling overdue',
        'ConsumerProducts.CoffeeMaker.Event.DeviceDescalingBlockage'      => 'Device blocked because of descaling overdue',
        'ConsumerProducts.CoffeeMaker.Event.DeviceShouldBeCleaned'        => 'Please clean device',
        'ConsumerProducts.CoffeeMaker.Event.DeviceCleaningOverdue'        => 'Cleaning overdue',
        'ConsumerProducts.CoffeeMaker.Event.DeviceShouldBeCalcNCleaned'   => 'Please calc n clean device',
        'ConsumerProducts.CoffeeMaker.Event.DeviceCalcNCleanOverdue'      => 'Device calc n clean overdue',
        'ConsumerProducts.CoffeeMaker.Event.DeviceCalcNCleanBlockage'     => 'Device blocked because of calc n clean overdue',
        'Dishcare.Dishwasher.Event.RinseAidNearlyEmpty'                   => 'Please fill RinseAid tank',
        'LaundryCare.Dryer.Event.DryingProcessFinished'                   => 'Drying Process Finished',
        'Refrigeration.FridgeFreezer.Event.DoorAlarmFreezer'              => 'Please close door',
        'Refrigeration.FridgeFreezer.Event.DoorAlarmRefrigerator'         => 'Please close door',
        'Refrigeration.FridgeFreezer.Event.TemperatureAlarmFreezer'       => 'The freezer temperature is too high',
        'ConsumerProducts.CleaningRobot.Event.EmptyDustBoxAndCleanFilter' => 'Please empty the dust box and clean the filter',
        'ConsumerProducts.CleaningRobot.Event.RobotIsStuck'               => 'The robot cannot continue  it\'s run',
        'ConsumerProducts.CleaningRobot.Event.DockingStationNotFound'     => 'The robot cannot find the charging station'

    ];
    private const OPTION_DURATION = 'BSH.Common.Option.Duration';
    private const START_IN_RELATIVE = 'BSH.Common.Option.StartInRelative';
    private const START_IN_RELATIVE_DEVICES = ['Microwave', 'Dishwasher', 'Oven'];

    // Minimum seconds between two full server refreshes (init or value refresh) of a
    // single device. Guards against the request storm caused by bursts of CONNECTED
    // events / parent status flaps, which quickly exhaust the "50 requests per minute"
    // Home Connect limit and, in turn, the daily quota.
    private const REFRESH_MIN_INTERVAL = 30;

    // Counts calls to RequestDataFromParent within a single PHP call so a value
    // refresh can report how many server requests it cost (rate-limit diagnostics).
    private $requestCounter = 0;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->ConnectParent('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}');

        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if (IPS_InstanceExists($parent)) {
            $this->RegisterMessage($parent, IM_CHANGESTATUS);
        }
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterPropertyString('HaID', '');
        $this->RegisterPropertyString('DeviceType', '');

        $this->RegisterAttributeString('Settings', '[]');
        $this->RegisterAttributeString('OptionKeys', '[]');
        $this->RegisterAttributeString('InitializationSignature', '');
        $this->RegisterAttributeBoolean('Initialized', false);

        //Common States
        //States
        if (!IPS_VariableProfileExists('HomeConnect.Common.Status.OperationState')) {
            IPS_CreateVariableProfile('HomeConnect.Common.Status.OperationState', VARIABLETYPE_STRING);
            $this->createAssociations('HomeConnect.Common.Status.OperationState', [
                ['Value' => 'BSH.Common.EnumType.OperationState.Inactive', 'Name' => 'Inactive'],
                ['Value' => 'BSH.Common.EnumType.OperationState.Ready', 'Name' => 'Ready'],
                ['Value' => 'BSH.Common.EnumType.OperationState.DelayedStart', 'Name' => 'Delayed Start'],
                ['Value' => 'BSH.Common.EnumType.OperationState.Run', 'Name' => 'Run'],
                ['Value' => 'BSH.Common.EnumType.OperationState.ActionRequired', 'Name' => 'Action Required'],
                ['Value' => 'BSH.Common.EnumType.OperationState.Finished', 'Name' => 'Finished'],
                ['Value' => 'BSH.Common.EnumType.OperationState.Error', 'Name' => 'Error'],
                ['Value' => 'BSH.Common.EnumType.OperationState.Aborting', 'Name' => 'Aborting'],
            ]);
        }
        if (!IPS_VariableProfileExists('HomeConnect.Common.Status.DoorState')) {
            IPS_CreateVariableProfile('HomeConnect.Common.Status.DoorState', VARIABLETYPE_STRING);
            $this->createAssociations('HomeConnect.Common.Status.DoorState', [
                ['Value' => 'BSH.Common.EnumType.DoorState.Open', 'Name' => 'Open'],
                ['Value' => 'BSH.Common.EnumType.DoorState.Closed', 'Name' => 'Closed'],
                ['Value' => 'BSH.Common.EnumType.DoorState.Locked', 'Name' => 'Locked'],
            ]);
        }

        //Update Options
        if (!IPS_VariableProfileExists('HomeConnect.Common.Option.ProgramProgress')) {
            IPS_CreateVariableProfile('HomeConnect.Common.Option.ProgramProgress', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('HomeConnect.Common.Option.ProgramProgress', '', ' ' . '%');
        }

        if (!IPS_VariableProfileExists('HomeConnect.Common.Option.RemainingProgramTime')) {
            IPS_CreateVariableProfile('HomeConnect.Common.Option.RemainingProgramTime', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('HomeConnect.Common.Option.RemainingProgramTime', '', ' ' . $this->Translate('Seconds'));
        }

        if (!IPS_VariableProfileExists('HomeConnect.Common.Option.ElapsedProgramTime')) {
            IPS_CreateVariableProfile('HomeConnect.Common.Option.ElapsedProgramTime', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('HomeConnect.Common.Option.ElapsedProgramTime', '', ' ' . $this->Translate('Seconds'));
        }

        //Restriction
        if (!IPS_VariableProfileExists('HomeConnect.YesNo')) {
            IPS_CreateVariableProfile('HomeConnect.YesNo', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('HomeConnect.YesNo', true, $this->Translate('Yes'), '', -1);
            IPS_SetVariableProfileAssociation('HomeConnect.YesNo', false, $this->Translate('No'), '', -1);
        }
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->refreshDeviceState($this->needsInitialization(), 'ApplyChanges');
        }

        $this->SetReceiveDataFilter('.*' . $this->ReadPropertyString('HaID') . '.*');
    }

    public function MessageSink($Timestamp, $SenderID, $MessageID, $Data)
    {
        //Never delete this line!
        parent::MessageSink($Timestamp, $SenderID, $MessageID, $Data);

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($SenderID == $parentID && $MessageID == IM_CHANGESTATUS) {
            // Only react to a real parent status transition. During rate-limit
            // flapping IM_CHANGESTATUS fires many times per second with the same
            // status; without this guard each one would trigger a refresh.
            $newStatus = (int) $Data[0];
            if ((int) $this->GetBuffer('LastParentStatus') === $newStatus) {
                return;
            }
            $this->SetBuffer('LastParentStatus', (string) $newStatus);
            $this->refreshDeviceState($newStatus == IS_ACTIVE && $this->needsInitialization(), sprintf('IM_CHANGESTATUS(%s)', $newStatus));
            return;
        }

        if ($SenderID == $this->InstanceID) {
            switch ($MessageID) {
                case FM_CONNECT:
                    $this->RegisterMessage($Data[0], IM_CHANGESTATUS);
                    $this->refreshDeviceState($this->needsInitialization(), 'FM_CONNECT');
                    return;

                case FM_DISCONNECT:
                    $this->setInstanceStatus(IS_INACTIVE);
                    return;
            }
        }
    }

    public function ReceiveData($String)
    {
        $this->SendDebug('ReceiveData', $String, 0);
        $data = json_decode($String, true);
        switch ($data['Event']) {
            case 'DISCONNECTED':
                if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID)) {
                    // Offline device set OperationState to Inactive
                    $this->SetValue('OperationState', 'BSH.Common.EnumType.OperationState.Inactive');
                }
                break;
            case 'CONNECTED':
                // Device comes online -> refresh states. Decouple from the event thread:
                // refreshDeviceState performs synchronous cloud calls, and doing them
                // inline blocks ReceiveData while the parent is busy (e.g. reconnecting the
                // stream) -> "Warten auf Skriptresultat fehlgeschlagen" / thread pile-up.
                $this->RegisterOnceTimer('RefreshDeviceState', 'IPS_RequestAction($_IPS[\'TARGET\'], "RefreshDeviceState", "");');
                break;
            case 'STATUS':
            case 'NOTIFY':
                $items = json_decode($data['Data'], true)['items'];
                // $this->SendDebug($cleanData['event'], json_encode($items), 0);
                foreach ($items as $item) {
                    if (in_array($item['key'], self::EXCLUDE)) {
                        continue;
                    }
                    $ident = $this->getLastSnippet($item['key']);
                    if (in_array($item['key'], self::RESTRICTIONS)) {
                        $this->createVariableByData($item);
                        $this->SendDebug('Restriction', json_encode($item), 0);
                        continue;
                    }

                    preg_match('/.+\.(?P<type>.+)\..+/m', $item['key'], $matches);
                    if ($matches) {
                        switch ($matches['type']) {
                            case 'Status':
                                $this->createStates(['data' => ['status' => [$item]]]);
                                break;

                            default:
                                if (in_array($item['key'], self::UPDATE_OPTIONS)) {
                                    $this->createVariableByData($item);
                                    break;
                                }
                                if ($ident == 'SelectedProgram') {
                                    // Decouple the cloud lookup from the event thread: doing
                                    // it inline blocks ReceiveData on the flow-handler thread
                                    // while the parent is still delivering the event (re-entrant
                                    // deadlock -> "Warten auf Skriptresultat fehlgeschlagen").
                                    // A one-shot timer runs the refresh via RequestAction on its
                                    // own thread; a burst of events just re-arms it (coalesces).
                                    $this->RegisterOnceTimer('RefreshSelectedProgram', 'IPS_RequestAction($_IPS[\'TARGET\'], "RefreshSelectedProgram", "");');
                                }
                                if (strpos($item['key'], 'Option') != false) {
                                    $ident = 'Option' . $ident;
                                }
                                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                                    $this->SetValue($ident, $item['value']);
                                }
                                $this->SendDebug($ident, strval($item['value']), 0);
                                break;
                        }
                    }
                }
                break;

            case 'EVENT':
                $eventData = json_decode($data['Data'], true);
                foreach ($eventData['items'] as $item) {
                    if ($item['value'] == 'BSH.Common.EnumType.EventPresentState.Present') {
                        $this->SetValue('Event', $item['key']);
                        $level = $this->Translate($item['level']);
                        $event = GetValueFormattedEx($this->GetIDForIdent('Event'), $item['key']);
                        if (isset(self::EVENT_DESCRIPTIONS[$item['key']])) {
                            $detailedEvent = $this->Translate(self::EVENT_DESCRIPTIONS[$item['key']]);
                        } else {
                            $this->LogMessage('Event:' . $data['Data'], KL_NOTIFY);
                            $detailedEvent = '';
                        }
                        $eventDescription = sprintf('%s: %s - %s', $level, $event, $detailedEvent);
                        $this->SetValue('EventDescription', $eventDescription);
                    } else {
                        $this->SetValue('Event', '');
                        $this->SetValue('EventDescription', '');
                    }
                }
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }

    public function RequestAction($Ident, $Value)
    {
        $applyValue = false;
        switch ($Ident) {
            case 'RefreshSelectedProgram':
                // Internal action, triggered by the one-shot timer armed in ReceiveData.
                // Runs the deferred selected-program/option refresh off the event thread.
                $this->updateOptionValues($this->getSelectedProgram());
                return;

            case 'RefreshDeviceState':
                // Internal action, triggered by the one-shot timer armed on a CONNECTED
                // event. Runs the (cloud-heavy) state refresh off the event thread.
                $this->refreshDeviceState($this->needsInitialization(), 'Event:CONNECTED (deferred)');
                return;

            case 'UseDuration':
                $applyValue = true;
                break;

            case 'SelectedProgram':
                if (!$this->switchable()) {
                    //TODO: better error message
                    echo $this->Translate('RemoteControl not active / RemoteStart not active / LocalControl active');
                    return;
                }
                if ($this->GetValue('OperationState') == 'BSH.Common.EnumType.OperationState.Run') {
                    echo $this->Translate('Device is running');
                    return;
                }
                if (!in_array($this->ReadPropertyString('DeviceType'), ['Oven', 'Hood'])) {
                    $payload = [
                        'data' => [
                            'key'     => $Value,
                            'options' => []
                        ]
                    ];
                    $response = $this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected', json_encode($payload));
                    if (!$this->responseHasError($response)) {
                        $this->updateOptionValues($this->getSelectedProgram());
                    }
                } else {
                    $program = $this->getProgram($Value);
                    if ($program !== false) {
                        $this->updateOptionValues($program);
                    }
                }
                break;

            case 'Control':
                switch ($Value) {
                    case 'Start':
                        if (!$this->switchable()) {
                            echo $this->Translate('RemoteControl not active / RemoteStart not active / LocalControl active');
                            return;
                        }
                        $payload = [
                            'data' => [
                                'key'     => $this->GetValue('SelectedProgram'),
                                'options' => $this->sendOptionsOnProgramStart() ? $this->createOptionPayload() : []
                            ]
                        ];
                        $this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active', json_encode($payload));
                        break;

                    case 'Stop':
                        if (!$this->switchable()) {
                            echo $this->Translate('RemoteControl not active / RemoteStart not active / LocalControl active');
                            return;
                        }
                        $this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active', 'DELETE');
                        break;

                    case 'Pause':
                        if (!$this->executeApplicanceCommand('BSH.Common.Command.PauseProgram')) {
                            return;
                        }
                        break;

                    case 'Resume':
                        if (!$this->executeApplicanceCommand('BSH.Common.Command.ResumeProgram')) {
                            return;
                        }
                        break;
                }
                break;

            default:
                if (!$this->switchable()) {
                    echo $this->Translate('RemoteControl not active / RemoteStart not active / LocalControl active');
                    return;
                }
                $availableOptions = $this->getValidOptions();
                if (isset($availableOptions[$Ident])) {
                    $optionKeys = json_decode($this->ReadAttributeString('OptionKeys'), true);
                    if (!isset($optionKeys[$Ident])) {
                        break;
                    }
                    $optionKey = $optionKeys[$Ident];
                    if ($optionKey == self::OPTION_DURATION && @IPS_GetObjectIDByIdent('UseDuration', $this->InstanceID) && !$this->GetValue('UseDuration')) {
                        $applyValue = true;
                        break;
                    }
                    if (!in_array($this->ReadPropertyString('DeviceType'), ['Oven', 'Hood'])) {
                        if ($optionKey == self::START_IN_RELATIVE && $this->useStartInRelativeStartCommand()) {
                            if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID) && ($this->GetValue('OperationState') == 'BSH.Common.EnumType.OperationState.DelayedStart')) {
                                $payload = ['data' => $this->createOptionRequestData($Ident, $optionKey, $Value)];
                                $endpoint = 'homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active/options/' . $optionKey;
                                $response = $this->RequestDataFromParent($endpoint, json_encode($payload));
                                $applyValue = !$this->responseHasError($response);
                            } else {
                                $this->SendDebug(__FUNCTION__, self::START_IN_RELATIVE . ' is sent with programs/active on start command', 0);
                                $applyValue = true;
                            }
                        } else {
                            $payload = ['data' => $this->createOptionRequestData($Ident, $optionKey, $Value)];
                            $response = $this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $optionKey, json_encode($payload));
                            $applyValue = !$this->responseHasError($response);
                        }
                    } else {
                        $applyValue = true;
                    }
                }

                $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
                $this->SendDebug('Settings', json_encode($availableSettings), 0);
                if (isset($availableSettings[$Ident])) {
                    $payload = [
                        'data' => [
                            'key'   => $availableSettings[$Ident]['key'],
                            'value' => $Value
                        ]
                    ];
                    $response = $this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $availableSettings[$Ident]['key'], json_encode($payload));
                    $applyValue = !$this->responseHasError($response);
                }
                break;
        }
        if ($Ident != 'Control' && $applyValue) {
            $this->SetValue($Ident, $Value);
        }
    }

    public function InitializeDevice()
    {
        $this->WriteAttributeBoolean('Initialized', false);
        if ($this->createStates()) {
            $this->setupSettings();
            if ($this->createPrograms()) {
                $this->ensureControlVariable(2);
                if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID) && ($this->GetValue('OperationState') == 'BSH.Common.EnumType.OperationState.Ready')) {
                    $this->updateOptionValues($this->getSelectedProgram());
                }
            }
            $this->createEventProfile();
            $this->MaintainVariable('Event', $this->Translate('Event'), VARIABLETYPE_STRING, 'HomeConnect.Event.' . $this->ReadPropertyString('DeviceType'), 0, true);
            $this->MaintainVariable('EventDescription', $this->Translate('Event Description'), VARIABLETYPE_STRING, '', 0, true);
            $this->WriteAttributeString('InitializationSignature', $this->getInitializationSignature());
            $this->WriteAttributeBoolean('Initialized', true);
        }
    }

    public function RequestDataFromParent(string $endpoint, string $payload = '')
    {
        $this->requestCounter++;
        $this->SendDebug(__FUNCTION__, sprintf('endpoint: %s, payload: %s', $endpoint, $payload), 0);
        $data = [
            'DataID'      => '{41DDAA3B-65F0-B833-36EE-CEB57A80D022}',
            'Endpoint'    => $endpoint
        ];
        if ($payload) {
            $data['Payload'] = $payload;
        }
        $response = $this->SendDataToParent(json_encode($data));
        if (!is_string($response)) {
            $this->SendDebug('ErrorResponseRaw', var_export($response, true), 0);
            $response = $this->buildParentResponseError('Client.Error.ParentResponse', $this->Translate('No response from parent instance'));
        }

        if ($response === '') {
            $this->SendDebug('responseData', $response, 0);
            return $response;
        }

        $errorDetector = json_decode($response, true);
        if (!is_array($errorDetector)) {
            $this->SendDebug('ErrorResponseRaw', $response, 0);
            $response = $this->buildParentResponseError('Client.Error.ParentResponse', $this->Translate('Invalid JSON response from parent instance'));
            $errorDetector = json_decode($response, true);
        }

        if (isset($errorDetector['error'])) {
            // Log error responses so failures are visible in debug output.
            $this->SendDebug('ErrorResponse', $response, 0);
            switch ($errorDetector['error']['key']) {
                case 'SDK.Error.UnsupportedProgram':
                case 'SDK.Error.UnsupportedOperation':
                case 'SDK.Error.NoProgramSelected':
                case 'SDK.Error.HomeAppliance.Connection.Initialization.Failed':
                case '429':
                    return $response;

                default:
                    $this->SendDebug('ErrorPayload', $payload, 0);
                    $this->SendDebug('ErrorEndpoint', $endpoint, 0);
                    if (isset($errorDetector['error']['description'])) {
                        echo $errorDetector['error']['description']; //Not translated  due to the dynamic content
                    }
                    break;
            }
        }
        $this->SendDebug('responseData', $response, 0);
        return $response;
    }

    private function refreshDeviceState(bool $initializeDevice, string $trigger = ''): void
    {
        // DEBUG (rate-limit analysis): record every refresh, its trigger and the chosen path.
        $this->SendDebug(__FUNCTION__, sprintf('trigger: %s, mode: %s, activeParent: %s', $trigger, $initializeDevice ? 'init' : 'valueRefresh', $this->HasActiveParent() ? 'yes' : 'no'), 0);
        if ($this->HasActiveParent() && $this->ReadPropertyString('HaID')) {
            $this->SetSummary($this->ReadPropertyString('HaID'));
            if ($this->refreshThrottled()) {
                // Too soon since the last refresh - skip the server round-trips but
                // keep the instance active. Live STATUS/NOTIFY events still update
                // values directly (they do not go through this path).
                $this->SendDebug(__FUNCTION__, sprintf('throttled (trigger: %s)', $trigger), 0);
                $this->setInstanceStatus(IS_ACTIVE);
                return;
            }
            if ($initializeDevice) {
                $this->InitializeDevice();
            } else {
                // Structure is already in place; pull fresh values so a restart or
                // reconnect does not keep showing stale status (e.g. OperationState).
                $this->refreshDeviceValues();
            }
            $this->setInstanceStatus(IS_ACTIVE);
            return;
        }

        $this->setInstanceStatus(IS_INACTIVE);
    }

    /**
     * Rate-limit guard shared by the init and the value-refresh path: allows at most
     * one full server refresh per REFRESH_MIN_INTERVAL seconds. The timestamp lives in
     * a runtime buffer, so it resets on restart (a restart should refresh immediately).
     */
    private function refreshThrottled(): bool
    {
        $now = time();
        $last = (int) $this->GetBuffer('LastRefresh');
        if ($last !== 0 && ($now - $last) < self::REFRESH_MIN_INTERVAL) {
            return true;
        }
        $this->SetBuffer('LastRefresh', (string) $now);
        return false;
    }

    /**
     * Lightweight value refresh for an already initialized device. Only updates the
     * values of existing variables (status, settings, selected program) without
     * recreating variables/profiles/programs. This keeps the structure-init
     * optimization (needsInitialization) intact while fixing stale values after a
     * restart or event-stream reconnect.
     */
    private function refreshDeviceValues(): void
    {
        // Nothing to refresh before the structure exists - the init path handles that.
        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->SendDebug(__FUNCTION__, 'skipped (not initialized)', 0);
            return;
        }

        // Count the server requests this refresh causes (rate-limit diagnostics).
        $requestsBefore = $this->requestCounter;

        // Current status values (OperationState, DoorState, restrictions, ...).
        // Skips silently on an offline device (createStates returns false on error).
        $this->createStates();

        // Current setting values (PowerState, temperatures, ...) - values only,
        // no per-setting constraint fetch and no profile rebuild.
        $settings = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings'), true);
        if (isset($settings['data']['settings'])) {
            foreach ($settings['data']['settings'] as $setting) {
                $ident = $this->getLastSnippet($setting['key']);
                if (array_key_exists('value', $setting) && @IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $this->SetValue($ident, $setting['value']);
                }
            }
        }

        // Current selected program and its option values (only meaningful when ready).
        if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID)
            && ($this->GetValue('OperationState') == 'BSH.Common.EnumType.OperationState.Ready')) {
            $this->updateOptionValues($this->getSelectedProgram());
        }

        $this->SendDebug(__FUNCTION__, sprintf('done (%d requests)', $this->requestCounter - $requestsBefore), 0);
    }

    private function setInstanceStatus(int $status): void
    {
        // Only update the status when it actually changes, so monitoring
        // (e.g. EventControl scripts) is not triggered on every refresh.
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
    }

    private function needsInitialization(): bool
    {
        if ($this->ReadPropertyString('HaID') == '') {
            return false;
        }

        if (!$this->ReadAttributeBoolean('Initialized')) {
            return true;
        }

        return $this->ReadAttributeString('InitializationSignature') !== $this->getInitializationSignature();
    }

    private function getInitializationSignature(): string
    {
        return json_encode([
            'HaID'       => $this->ReadPropertyString('HaID'),
            'DeviceType' => $this->ReadPropertyString('DeviceType')
        ]);
    }

    private function createPrograms()
    {
        $rawPrograms = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs'), true);
        if (isset($rawPrograms['error'])) {
            return;
        }
        $programs = $rawPrograms['data']['programs'];
        $this->SendDebug(__FUNCTION__, json_encode($programs), 0);
        $profileName = 'HomeConnect.' . $this->ReadPropertyString('DeviceType') . '.Programs';
        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, VARIABLETYPE_STRING);
        } else {
            //Clear profile if it exists
            foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', 0);
            }
        }
        foreach ($programs as $program) {
            preg_match('/(?P<program>.+)\.(?P<value>.+)/m', $program['key'], $matches);
            $displayName = isset($program['name']) ? $program['name'] : $matches['value'];
            IPS_SetVariableProfileAssociation($profileName, $program['key'], $displayName, '', -1);
        }
        $ident = 'SelectedProgram';
        $this->MaintainVariable($ident, $this->Translate('Program'), VARIABLETYPE_STRING, $profileName, 1, true);
        $this->EnableAction($ident);
        return true;
    }

    private function createOptionPayload()
    {
        $availableOptions = $this->getValidOptions();
        $optionKeys = json_decode($this->ReadAttributeString('OptionKeys'), true);
        $this->SendDebug('OptionKeys', json_encode($optionKeys), 0);
        $optionsPayload = [];
        $useDuration = !@IPS_GetObjectIDByIdent('UseDuration', $this->InstanceID) || $this->GetValue('UseDuration');
        // Re-check the selected program definition so the start payload only contains options accepted by programs/active.
        $startableOptionKeys = $this->getStartableOptionKeys();
        foreach ($availableOptions as $ident => $key) {
            if (!isset($optionKeys[$ident])) {
                continue;
            }
            $optionKey = $optionKeys[$ident];
            if ($optionKey == self::OPTION_DURATION && !$useDuration) {
                continue;
            }
            if ($startableOptionKeys !== [] && !isset($startableOptionKeys[$optionKey])) {
                $this->SendDebug(__FUNCTION__, sprintf('Skipping unsupported start option: %s', $optionKey), 0);
                continue;
            }
            $optionsPayload[] = $this->createOptionRequestData($ident, $optionKey, $this->GetValue($ident));
        }
        return $optionsPayload;
    }

    private function getStartableOptionKeys()
    {
        $selectedProgram = $this->GetValue('SelectedProgram');
        if ($selectedProgram == '') {
            return [];
        }

        $program = $this->getProgram($selectedProgram);
        if (!is_array($program) || !isset($program['options']) || !is_array($program['options'])) {
            return [];
        }

        $startableOptionKeys = [];
        foreach ($program['options'] as $option) {
            if (!isset($option['key'])) {
                continue;
            }

            $constraints = isset($option['constraints']) && is_array($option['constraints']) ? $option['constraints'] : [];
            $access = isset($constraints['access']) ? strtolower((string) $constraints['access']) : '';
            if ($access !== '' && strpos($access, 'write') === false) {
                continue;
            }

            $startableOptionKeys[$option['key']] = true;
        }

        return $startableOptionKeys;
    }

    private function createOptionRequestData($ident, $key, $value)
    {
        $data = [
            'key'   => $key,
            'value' => $value
        ];
        $profileName = IPS_GetVariable($this->GetIDForIdent($ident))['VariableProfile'];
        if ($profileName) {
            $profile = IPS_GetVariableProfile($profileName);
            $suffix = str_replace(' ', '', $profile['Suffix']);
            if ($suffix) {
                $data['unit'] = $suffix;
            }
        }
        return $data;
    }

    private function useStartInRelativeStartCommand()
    {
        return in_array($this->ReadPropertyString('DeviceType'), self::START_IN_RELATIVE_DEVICES);
    }

    private function sendOptionsOnProgramStart()
    {
        return in_array($this->ReadPropertyString('DeviceType'), ['Oven', 'Hood', 'Dishwasher', 'Microwave']);
    }

    /**
     * @param string|array $program Der Programmschlüssel oder das bereits abgerufene Programmdaten-Array.
     */
    private function updateOptionVariables($program)
    {
        $rawOptions = $this->resolveProgramData($program);

        $this->SendDebug('RawOptions', json_encode($rawOptions), 0);
        if (!$rawOptions) {
            $this->SetValue('SelectedProgram', '');
            $this->setOptionsDisabled(true);
            $this->syncUseDurationVariable(false, 0);
            return;
        }
        $this->setOptionsDisabled(false);
        $options = isset($rawOptions['options']) && is_array($rawOptions['options']) ? $rawOptions['options'] : [];
        $position = 10;
        $availableOptions = [];
        $deviceType = $this->ReadPropertyString('DeviceType');
        foreach ($options as $option) {
            if (in_array($option['key'], self::EXCLUDE)) {
                continue;
            }
            $key = $option['key'];
            preg_match('/.+\.(?P<option>.+)/m', $key, $matches);
            $ident = $matches['option'];
            $availableOptions[] = "Option$ident";
            $profileName = "HomeConnect.$deviceType.Option.$ident";
            $this->createVariableFromConstraints($profileName, $option, 'Option', $position);
            $position++;
        }

        $children = IPS_GetChildrenIDs($this->InstanceID);
        $optionVariables = [];
        foreach ($children as $child) {
            $object = IPS_GetObject($child);
            if (strpos($object['ObjectIdent'], 'Option') !== false) {
                $optionVariables[$object['ObjectIdent']] = $child;
            }
        }
        foreach ($optionVariables as $ident => $variableID) {
            IPS_SetHidden($variableID, !in_array($ident, $availableOptions));
        }

        $this->syncUseDurationVariable(in_array('OptionDuration', $availableOptions), $position);
        $position++;
        $this->ensureControlVariable($position);
    }

    private function syncUseDurationVariable($visible, $position)
    {
        $ident = 'UseDuration';
        $exists = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $this->MaintainVariable($ident, $this->Translate('Use duration option'), VARIABLETYPE_BOOLEAN, 'HomeConnect.YesNo', $position, true);
        $this->EnableAction($ident);
        if (!$exists) {
            $this->SetValue($ident, false);
        }
        $variableID = $this->GetIDForIdent($ident);
        IPS_SetHidden($variableID, !$visible);
        IPS_SetDisabled($variableID, !$visible);
    }

    private function ensureControlVariable($position)
    {
        $deviceType = $this->ReadPropertyString('DeviceType');
        if (!$deviceType) {
            return;
        }
        $profileName = "HomeConnect.Control.$deviceType";
        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, VARIABLETYPE_STRING);
            $associations = [
                ['Value' => 'Start', 'Name' => $this->Translate('Start')],
                ['Value' => 'Stop', 'Name' => $this->Translate('Stop')]
            ];
            if (in_array($deviceType, ['Oven', 'CleaningRobot', 'Dryer', 'Washer', 'WasherDryer'])) {
                $associations[] = ['Value' => 'Pause', 'Name' => $this->Translate('Pause')];
            }
            if (in_array($deviceType, ['Oven', 'CleaningRobot', 'Dishwasher', 'Dryer', 'Washer', 'WasherDryer'])) {
                $associations[] = ['Value' => 'Resume', 'Name' => $this->Translate('Resume')];
            }
            $this->createAssociations($profileName, $associations);
        }
        $this->MaintainVariable('Control', $this->Translate('Control'), VARIABLETYPE_STRING, $profileName, $position, true);
        $this->EnableAction('Control');
    }

    private function getSelectedProgram()
    {
        $selectedProgram = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected'), true);
        return isset($selectedProgram['data']) ? $selectedProgram['data'] : false;
    }

    private function getProgram($key)
    {
        $endpoint = 'homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available/' . $key;
        $data = json_decode($this->RequestDataFromParent($endpoint), true);
        return isset($data['data']) ? $data['data'] : false;
    }

    private function getOption($key)
    {
        if (in_array($key, self::EXCLUDE)) {
            return false;
        }
        $data = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $key), true);
        return isset($data['data']) ? $data['data'] : false;
    }
    /**
     * @param string|array $program Der Programmschlüssel oder das bereits abgerufene Programmdaten-Array.
     */
    private function updateOptionValues($program)
    {
        if (!$program) {
            $this->setOptionsDisabled(true);
            $this->syncUseDurationVariable(false, 0);
            return;
        }
        $this->SetValue('SelectedProgram', $program['key']);
        $this->updateOptionVariables($program);
        $optionKeys = [];
        $programOptions = isset($program['options']) && is_array($program['options']) ? $program['options'] : [];
        foreach ($programOptions as $option) {
            $ident = 'Option' . $this->getLastSnippet($option['key']);
            $optionKeys[$ident] = $option['key'];
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) && !IPS_GetObject($this->GetIDForIdent($ident))['ObjectIsHidden']) {
                if (isset($option['value'])) {
                    $value = $option['value'];
                } elseif (isset($option['constraints']['default'])) {
                    $value = $option['constraints']['default'];
                } elseif (isset($option['constraints']['allowedvalues']) && is_array($option['constraints']['allowedvalues']) && count($option['constraints']['allowedvalues']) > 0) {
                    $value = $option['constraints']['allowedvalues'][0];
                } else {
                    $this->SendDebug(__FUNCTION__, sprintf('Skipping option without usable value: %s', json_encode($option)), 0);
                    continue;
                }
                $debugValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $this->SendDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $ident, $debugValue), 0);
                $this->SetValue($ident, $value);
            }
        }
        $this->WriteAttributeString('OptionKeys', json_encode($optionKeys));
    }

    /**
     * Enrich selected program data with the available-program metadata so variable
     * creation can rely on stable option types and constraints while keeping the
     * current selected values.
     *
     * @param string|array $program
     */
    private function resolveProgramData($program)
    {
        if (!is_array($program)) {
            return $this->getProgram($program);
        }

        if (!isset($program['key'])) {
            return $program;
        }

        $resolvedProgram = $this->getProgram($program['key']);
        if (!is_array($resolvedProgram)) {
            return $program;
        }

        $selectedOptions = [];
        $programOptions = isset($program['options']) && is_array($program['options']) ? $program['options'] : [];
        foreach ($programOptions as $option) {
            if (!isset($option['key'])) {
                continue;
            }
            $selectedOptions[$option['key']] = $option;
        }

        if (!isset($resolvedProgram['options']) || !is_array($resolvedProgram['options'])) {
            return $resolvedProgram;
        }

        foreach ($resolvedProgram['options'] as &$option) {
            if (!isset($option['key']) || !isset($selectedOptions[$option['key']])) {
                continue;
            }

            $selectedOption = $selectedOptions[$option['key']];
            if (array_key_exists('value', $selectedOption)) {
                $option['value'] = $selectedOption['value'];
            }
            if (isset($selectedOption['displayvalue'])) {
                $option['displayvalue'] = $selectedOption['displayvalue'];
            }
            if (isset($selectedOption['name'])) {
                $option['name'] = $selectedOption['name'];
            }
            if (isset($selectedOption['unit'])) {
                $option['unit'] = $selectedOption['unit'];
            }
        }

        return $resolvedProgram;
    }

    private function createStates($states = '')
    {
        if (!$states) {
            $data = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/status'), true);
            if (isset($data['error'])) {
                return false;
            }
        } else {
            $data = $states;
        }
        $this->SendDebug(__FUNCTION__, json_encode($data), 0);
        if (isset($data['data']['status'])) {
            foreach ($data['data']['status'] as $state) {
                $ident = $this->getLastSnippet($state['key']);
                //Skip remote control states and transfer to attributess
                if (in_array($state['key'], self::RESTRICTIONS)) {
                    $this->createVariableByData($state);
                    continue;
                }
                $value = $state['value'];

                $profileName = str_replace('BSH', 'HomeConnect', $state['key']);
                $variableType = $this->getVariableType($value);
                if ($variableType == VARIABLETYPE_FLOAT) {
                    $profileName = $profileName . '.f';
                }
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $existingType = IPS_GetVariable($this->GetIDForIdent($ident))['VariableType'];
                    if (($existingType == VARIABLETYPE_FLOAT && $variableType == VARIABLETYPE_INTEGER) ||
                    ($existingType == VARIABLETYPE_INTEGER && $variableType == VARIABLETYPE_FLOAT)) {
                        if ($existingType == VARIABLETYPE_FLOAT) {
                            $profileName = $profileName . '.f';
                        }
                        if (!IPS_VariableProfileExists($profileName)) {
                            IPS_CreateVariableProfile($profileName, VARIABLETYPE_FLOAT);
                        }
                        if (isset($state['unit'])) {
                            IPS_SetVariableProfileText($profileName, '', ' ' . $state['unit']);
                        }
                        $variableDisplayName = isset($state['name']) ? $state['name'] : $this->splitCamelCase($ident);
                        $this->MaintainVariable($ident, $variableDisplayName, VARIABLETYPE_FLOAT, $profileName, 0, true);
                        $this->SetValue($ident, $value);
                        continue;
                    }
                }

                if (!IPS_VariableProfileExists($profileName)) {
                    IPS_CreateVariableProfile($profileName, $variableType);
                }
                switch ($variableType) {
                    case VARIABLETYPE_STRING:
                        $this->addAssociation($profileName, $value, isset($state['displayvalue']) ? $state['displayvalue'] : $this->splitCamelCase($this->getLastSnippet($state['value'])));
                        break;

                    case VARIABLETYPE_FLOAT:
                        IPS_SetVariableProfileDigits($profileName, 2);
                        // Only float needs decimal places but both need suffixes
                        // No break. Add additional comment above this line if intentional
                    case VARIABLETYPE_INTEGER:
                        if (isset($state['unit'])) {
                            IPS_SetVariableProfileText($profileName, '', ' ' . $state['unit']);
                        }
                        break;

                    default:
                        break;

                }
                $variableDisplayName = isset($state['name']) ? $state['name'] : $this->splitCamelCase($ident);
                $this->MaintainVariable($ident, $variableDisplayName, $variableType, $profileName, 0, true);
                $this->SetValue($ident, $value);
            }
            return true;
        }
    }

    private function addAssociation($profileName, $value, $name)
    {
        foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
            if ($association['Value'] == $value) {
                return;
            }
        }
        IPS_SetVariableProfileAssociation($profileName, $value, $name, '', -1);
    }

    private function getLastSnippet($string)
    {
        return substr($string, strrpos($string, '.') + 1, strlen($string) - strrpos($string, '.'));
    }

    private function createAssociations($profileName, $associations)
    {
        foreach ($associations as $association) {
            IPS_SetVariableProfileAssociation($profileName, $association['Value'], $this->Translate($association['Name']), '', -1);
        }
    }

    private function setupSettings()
    {
        $allSettings = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings'), true);
        $this->SendDebug(__FUNCTION__, sprintf('allSettings: %s', json_encode($allSettings)), 0);
        if (isset($allSettings['data']['settings'])) {
            $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
            $position = 0;
            foreach ($allSettings['data']['settings'] as $setting) {
                $value = $setting['value'];
                $ident = $this->getLastSnippet($setting['key']);

                //Add setting to available settings
                if (!isset($availableSettings[$ident])) {
                    $availableSettings[$ident] = ['key' => $setting['key']];
                }
                $this->SendDebug(__FUNCTION__, sprintf('setting: %s', json_encode($setting)), 0);
                //Create variable accordingly
                $profileName = str_replace('BSH', 'HomeConnect', $setting['key']);
                if ($ident == 'PowerState') {
                    $profileName .= '.' . $this->ReadPropertyString('DeviceType');
                }
                $variableType = $this->getVariableType($value);
                $settingDetails = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $setting['key']), true);
                if (!isset($settingDetails['data'])) {
                    continue;
                }
                $this->createVariableFromConstraints($profileName, $settingDetails['data'], 'Setting', $position);
                $position++;
                $this->SetValue($ident, $value);
            }

            $this->WriteAttributeString('Settings', json_encode($availableSettings));
        }
    }

    private function createVariableByData($data)
    {
        $ident = $this->getLastSnippet($data['key']);
        $displayName = isset($data['name']) ? $data['name'] : $this->Translate($ident);
        $profileName = in_array($data['key'], self::RESTRICTIONS) ? 'HomeConnect.YesNo' : str_replace('BSH', 'HomeConnect', $data['key']);
        $profile = IPS_VariableProfileExists($profileName) ? $profileName : '';
        $this->MaintainVariable($ident, $displayName, $this->getVariableType($data['value']), $profile, 0, true);
        $this->SetValue($ident, $data['value']);
    }

    private function getVariableType($value)
    {
        switch (gettype($value)) {
            case 'double':
                return VARIABLETYPE_FLOAT;

            case 'integer':
                return VARIABLETYPE_INTEGER;

            case 'boolean':
                return VARIABLETYPE_BOOLEAN;

            default:
                return VARIABLETYPE_STRING;
        }
    }

    private function splitCamelCase($string)
    {
        preg_match_all('/(?:^|[A-Z])[a-z]+/', $string, $matches);
        return $this->Translate(implode(' ', $matches[0]));
    }

    private function createVariableFromConstraints($profileName, $data, $attribute, $position)
    {
        $this->SendDebug(__FUNCTION__, sprintf('profileName: %s, attribute: %s, position: %s, data: %s', $profileName, $attribute, $position, json_encode($data)), 0);

        $ident = $this->getLastSnippet($data['key']);
        if ($attribute == 'Option') {
            $ident = $attribute . $ident;
        }

        if (isset($data['type'])) {
            $variableType = $this->mapConstraintTypeToVariableType($data['type']);
        } else {
            $variableType = $this->inferConstraintVariableType($data);
            $this->SendDebug(__FUNCTION__, sprintf('Missing type for %s, inferred variable type: %d', $data['key'], $variableType), 0);
        }
        switch ($variableType) {
            case VARIABLETYPE_INTEGER:
            case VARIABLETYPE_FLOAT:
                $constraints = isset($data['constraints']) && is_array($data['constraints']) ? $data['constraints'] : [];
                if (!IPS_VariableProfileExists($profileName)) {
                    //Create profile
                    IPS_CreateVariableProfile($profileName, $variableType);
                }
                $existingProfileType = IPS_GetVariableProfile($profileName)['ProfileType'];
                if ($existingProfileType === VARIABLETYPE_INTEGER || $existingProfileType === VARIABLETYPE_FLOAT) {
                    IPS_SetVariableProfileText($profileName, '', isset($data['unit']) ? ' ' . $data['unit'] : '');
                    $min = isset($constraints['min']) ? $constraints['min'] : 0;
                    $max = isset($constraints['max']) ? $constraints['max'] : 86340;
                    IPS_SetVariableProfileValues($profileName, $min, $max, isset($constraints['stepsize']) ? $constraints['stepsize'] : 1);
                    $this->SendDebug('UpdatedProfile', $min . ' - ' . $max, 0);
                } else {
                    // A profile with this name already exists with an incompatible
                    // (non-numeric) type. Modifying its values would emit "String
                    // profiles cannot be modified". Keep the existing profile and match
                    // the variable to it instead of fighting the type.
                    $this->SendDebug(__FUNCTION__, sprintf('Profile %s already exists as type %d; skipping numeric setup', $profileName, $existingProfileType), 0);
                    $variableType = $existingProfileType;
                }
                break;

            case VARIABLETYPE_BOOLEAN:
                $profileName = 'HomeConnect.YesNo';
                break;

            default:
                $constraints = isset($data['constraints']) && is_array($data['constraints']) ? $data['constraints'] : [];
                $variableType = VARIABLETYPE_STRING;
                if (!IPS_VariableProfileExists($profileName)) {
                    //Create profile
                    IPS_CreateVariableProfile($profileName, $variableType);
                }
                //Add potential new options
                $newAssociations = [];
                $allowedValues = isset($constraints['allowedvalues']) && is_array($constraints['allowedvalues']) ? $constraints['allowedvalues'] : [];
                $displayValues = isset($constraints['displayvalues']) && is_array($constraints['displayvalues']) ? $constraints['displayvalues'] : [];
                for ($i = 0, $size = count($allowedValues); $i < $size; $i++) {
                    $displayName = isset($displayValues[$i]) ? $displayValues[$i] : $this->getLastSnippet($allowedValues[$i]);
                    $newAssociations[$allowedValues[$i]] = $displayName;
                }
                $newAssociations = $this->sortAssociations($data['key'], $newAssociations);

                //Get current options from profile
                $oldAssociations = [];
                foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                    $oldAssociations[$association['Value']] = $association['Name'];
                }
                //Only refresh the profile if changes occured
                $diffold = array_diff_assoc($oldAssociations, $newAssociations);
                $diffnew = array_diff_assoc($newAssociations, $oldAssociations);
                if ($diffold || $diffnew) {
                    //Clear profile if it exists
                    foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                        IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', -1);
                    }
                    foreach ($newAssociations as $value => $name) {
                        IPS_SetVariableProfileAssociation($profileName, $value, $name, '', -1);
                    }
                }
                break;

        }

        //Create variable with created profile
        if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
            $displayName = isset($data['name']) ? $data['name'] : $ident;
            $this->MaintainVariable($ident, $displayName, $variableType, $profileName, $position, true);
            if (strpos(strtolower($data['key']), 'setting') != false) {
                if (isset($data['constraints']['access']) && strpos(strtolower($data['constraints']['access']), 'write') != false) {
                    $this->EnableAction($ident);
                }
            } else {
                $this->EnableAction($ident);
            }
        }
    }

    private function mapConstraintTypeToVariableType($type)
    {
        switch ($type) {
            case 'Int':
                return VARIABLETYPE_INTEGER;

            case 'Double':
                return VARIABLETYPE_FLOAT;

            case 'Boolean':
                return VARIABLETYPE_BOOLEAN;

            default:
                return VARIABLETYPE_STRING;
        }
    }

    private function inferConstraintVariableType($data)
    {
        if (array_key_exists('value', $data)) {
            return $this->getVariableType($data['value']);
        }

        $constraints = isset($data['constraints']) && is_array($data['constraints']) ? $data['constraints'] : [];

        if (array_key_exists('default', $constraints)) {
            return $this->getVariableType($constraints['default']);
        }

        if (isset($constraints['allowedvalues']) && is_array($constraints['allowedvalues']) && count($constraints['allowedvalues']) > 0) {
            return $this->getVariableType($constraints['allowedvalues'][0]);
        }

        foreach (['min', 'max', 'stepsize'] as $numericConstraint) {
            if (!isset($constraints[$numericConstraint]) || !is_numeric($constraints[$numericConstraint])) {
                continue;
            }

            if ((float) $constraints[$numericConstraint] != (int) $constraints[$numericConstraint]) {
                return VARIABLETYPE_FLOAT;
            }

            return VARIABLETYPE_INTEGER;
        }

        return VARIABLETYPE_STRING;
    }

    private function switchable()
    {
        $restrictions = $this->getAvailableRestrictions();
        $switchable = true;
        foreach ($restrictions as $restriction) {
            $value = $this->GetValue($restriction);
            if ($restriction != 'LocalControlActive') {
                $switchable = $value;
            } else {
                $switchable = !$value;
            }
            if (!$switchable) {
                return false;
            }
        }
        return true;
    }

    private function getValidOptions()
    {
        $children = IPS_GetChildrenIDs($this->InstanceID);
        $options = [];
        foreach ($children as $child) {
            $object = IPS_GetObject($child);
            if (strpos($object['ObjectIdent'], 'Option') !== false) {
                if ($object['ObjectIsHidden'] == false) {
                    $options[$object['ObjectIdent']] = str_replace('Option', '', $object['ObjectIdent']);
                }
            }
        }

        return $options;
    }

    private function setOptionsDisabled($disabled)
    {
        $options = $this->getValidOptions();
        foreach ($options as $ident => $key) {
            IPS_SetDisabled($this->GetIDForIdent($ident), $disabled);
        }
    }

    private function getAvailableRestrictions()
    {
        $restrictions = [];
        foreach (self::RESTRICTIONS as $restriction) {
            if (@IPS_GetObjectIDByIdent($this->getLastSnippet($restriction), $this->InstanceID)) {
                $restrictions[] = $this->getLastSnippet($restriction);
            }
        }
        return $restrictions;
    }

    private function createEventProfile()
    {
        $deviceType = $this->ReadPropertyString('DeviceType');
        if ($deviceType) {
            $this->SendDebug('Profile', 'HomeConnect.Event.' . $deviceType, 0);
            if (!IPS_VariableProfileExists('HomeConnect.Event.' . $deviceType)) {
                IPS_CreateVariableProfile('HomeConnect.Event.' . $deviceType, VARIABLETYPE_STRING);
                $associations = [];
                if (in_array($deviceType, ['Dishwasher', 'CleaningRobot', 'CookProcessor'])) {
                    $associations[] = ['Value' => 'BSH.Common.Event.ProgramAborted', 'Name' => 'Program Aborted'];
                }
                if (in_array($deviceType, ['Dishwasher'])) {
                    $associations[] = ['Value' => 'Dishcare.Dishwasher.Event.RinseAidNearlyEmpty', 'Name' => 'Please fill RinseAid tank'];
                }
                if (in_array($deviceType, ['Oven', 'Dishwasher', 'Washer', 'Dryer', 'WasherDryer', 'Cooktop', 'Hood', 'CleaningRobot', 'CookProcessor'])) {
                    $associations[] = ['Value' => 'BSH.Common.Event.ProgramFinished', 'Name' => 'Program Finished'];
                }
                if (in_array($deviceType, ['Oven',  'Cooktop'])) {
                    $associations[] = ['Value' => 'BSH.Common.Event.AlarmClockElapsed', 'Name' => 'Alarm Clock Elapsed'];
                    $associations[] = ['Value' => 'BSH.Common.Event.PreheatFinished', 'Name' => 'Pre-heat Finished'];
                }
                if (in_array($deviceType, ['Hob'])) {
                    $associations[] = ['Value' => 'BSH.Common.Event.ProgramFinished', 'Name' => 'Program Finished'];
                    $associations[] = ['Value' => 'BSH.Common.Event.AlarmClockElapsed', 'Name' => 'Alarm Clock Elapsed'];
                }
                if (in_array($deviceType, ['CoffeeMaker'])) {
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.BeanContainerEmpty', 'Name' => 'Bean Container Empty'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.WaterTankEmpty', 'Name' => 'Water Tank Empty'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DripTrayFull', 'Name' => 'Drip Tray Full'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceShouldBeDescaled', 'Name' => 'Please descale device'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceDescalingOverdue', 'Name' => 'Descaling overdue'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceDescalingBlockage', 'Name' => 'Device blocked because of descaling overdue'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceShouldBeCleaned', 'Name' => 'Please clean device'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceCleaningOverdue', 'Name' => 'Cleaning overdue'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceShouldBeCalcNCleaned', 'Name' => 'Please calc n clean device'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceCalcNCleanOverdue', 'Name' => 'Device calc n clean overdue'];
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DeviceCalcNCleanBlockage', 'Name' => 'Device blocked because of calc n clean overdue'];
                }
                if (in_array($deviceType, ['FridgeFreezer', 'Freezer'])) {
                    $associations[] = ['Value' => 'Refrigeration.FridgeFreezer.Event.DoorAlarmFreezer', 'Name' => 'Door Alarm Freezer'];
                    $associations[] = ['Value' => 'Refrigeration.FridgeFreezer.Event.TemperatureAlarmFreezer', 'Name' => 'Temperature Alarm Freezer'];
                }
                if (in_array($deviceType, ['FridgeFreezer', 'Refrigerator'])) {
                    $associations[] = ['Value' => 'Refrigeration.FridgeFreezer.Event.DoorAlarmRefrigerator', 'Name' => 'Door Alarm Refrigerator'];
                }
                if (in_array($deviceType, ['CleaningRobot'])) {
                    $associations[] = ['Value' => 'ConsumerProducts.CleaningRobot.Event.EmptyDustBoxAndCleanFilter', 'Name' => 'Empty Dust Box and Clean Filter'];
                    $associations[] = ['Value' => 'ConsumerProducts.CleaningRobot.Event.RobotIsStuck', 'Name' => 'Robot is Stuck'];
                    $associations[] = ['Value' => 'ConsumerProducts.CleaningRobot.Event.DockingStationNotFound', 'Name' => 'Docking Station not Found'];
                }
                $this->createAssociations('HomeConnect.Event.' . $deviceType, $associations);
            }
        }
    }

    private function executeApplicanceCommand($command)
    {
        $availableCommands = $this->getAvailableCommands();
        $commandAvailable = function () use ($command, $availableCommands)
        {
            foreach ($availableCommands as $availableCommand) {
                if ($availableCommand['key'] == $command) {
                    return true;
                }
            }
            return false;
        };
        if (!$commandAvailable()) {
            echo $this->Translate('Action can currently not be performed');
            return false;
        }
        $payload = [
            'data' => [
                'key'  => $command,
                'value'=> true
            ]
        ];
        $this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/commands/' . $command, json_encode($payload));
        return true;
    }

    private function responseHasError($response)
    {
        if (!is_string($response)) {
            return true;
        }

        if ($response === '') {
            return false;
        }

        $decodedResponse = json_decode($response, true);
        if (!is_array($decodedResponse)) {
            return true;
        }

        return isset($decodedResponse['error']);
    }

    private function buildParentResponseError(string $key, string $description): string
    {
        return json_encode([
            'error' => [
                'key'         => $key,
                'description' => $description
            ]
        ]);
    }

    private function sortAssociations($key, array $associations)
    {
        if ($key !== 'BSH.Common.Setting.PowerState') {
            return $associations;
        }

        $preferredOrder = [
            'BSH.Common.EnumType.PowerState.MainsOff',
            'BSH.Common.EnumType.PowerState.Off',
            'BSH.Common.EnumType.PowerState.Standby',
            'BSH.Common.EnumType.PowerState.On'
        ];

        $sortedAssociations = [];
        foreach ($preferredOrder as $preferredValue) {
            if (isset($associations[$preferredValue])) {
                $sortedAssociations[$preferredValue] = $associations[$preferredValue];
                unset($associations[$preferredValue]);
            }
        }

        foreach ($associations as $value => $name) {
            $sortedAssociations[$value] = $name;
        }

        return $sortedAssociations;
    }

    private function getAvailableCommands()
    {
        $commands = json_decode($this->RequestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/commands'), true);
        return isset($commands['data']['commands']) ? $commands['data']['commands'] : [];
    }
}
