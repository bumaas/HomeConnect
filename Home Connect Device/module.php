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
        'ConsumerProducts.CoffeeMaker.Event.BeanContainerEmpty'           => 'Please fill bean container',
        'ConsumerProducts.CoffeeMaker.Event.WaterTankEmpty'               => 'Please fill water tank',
        'ConsumerProducts.CoffeeMaker.Event.DripTrayFull'                 => 'Please empty drip tray',
        'Refrigeration.FridgeFreezer.Event.DoorAlarmFreezer'              => 'Please close door',
        'Refrigeration.FridgeFreezer.Event.DoorAlarmRefrigerator'         => 'Please close door',
        'Refrigeration.FridgeFreezer.Event.TemperatureAlarmFreezer'       => 'The freezer temperature is too high',
        'ConsumerProducts.CleaningRobot.Event.EmptyDustBoxAndCleanFilter' => 'Please empty the dust box and clean the filter',
        'ConsumerProducts.CleaningRobot.Event.RobotIsStuck'               => 'The robot cannot continue  it\'s run',
        'ConsumerProducts.CleaningRobot.Event.DockingStationNotFound'     => 'The robot cannot find the charging station'

    ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->ConnectParent('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}');

        $this->RegisterPropertyString('HaID', '');
        $this->RegisterPropertyString('DeviceType', '');

        $this->RegisterAttributeString('Settings', '[]');
        $this->RegisterAttributeString('OptionKeys', '[]');

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

        if (IPS_GetKernelRunlevel() == KR_READY) {
            if ($this->HasActiveParent()) {
                if ($this->ReadPropertyString('HaID')) {
                    $this->SetSummary($this->ReadPropertyString('HaID'));
                    $this->InitializeDevice();
                }
            }
        }

        $this->SetReceiveDataFilter('.*' . $this->ReadPropertyString('HaID') . '.*');
    }

    public function ReceiveData($String)
    {
        $this->SendDebug('ReceiveData', $String, 0);
        $data = json_decode($String, true);
        switch ($data['Event']) {
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
                                    $this->updateOptionValues($this->getSelectedProgram());
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
                        $detailedEvent = isset(self::EVENT_DESCRIPTIONS[$item['key']]) ? $this->Translate(self::EVENT_DESCRIPTIONS[$item['key']]) : '';
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
        switch ($Ident) {
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
                    $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected', json_encode($payload));
                    $this->updateOptionValues($this->getSelectedProgram());
                } else {
                    $this->updateOptionValues($this->getProgram($Value));
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
                                'options' => in_array($this->ReadPropertyString('DeviceType'), ['Oven', 'Hood']) ? $this->createOptionPayload() : []
                            ]
                        ];
                        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active', json_encode($payload));
                        break;

                    case 'Stop':
                        if (!$this->switchable()) {
                            echo $this->Translate('RemoteControl not active / RemoteStart not active / LocalControl active');
                            return;
                        }
                        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active', 'DELETE');
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
                    if (!in_array($this->ReadPropertyString('DeviceType'), ['Oven', 'Hood'])) {
                        $optionKeys = json_decode($this->ReadAttributeString('OptionKeys'), true);
                        $payload = [
                            'data' => [
                                'key'   => $optionKeys[$Ident],
                                'value' => $Value
                            ]
                        ];
                        $profileName = IPS_GetVariable($this->GetIDForIdent($Ident))['VariableProfile'];
                        $profile = IPS_GetVariableProfile($profileName);
                        $suffix = str_replace(' ', '', $profile['Suffix']);
                        if ($suffix) {
                            $payload['data']['unit'] = $suffix;
                        }
                        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $optionKeys[$Ident], json_encode($payload));
                    } else {
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
                    $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $availableSettings[$Ident]['key'], json_encode($payload));
                }
                break;
        }
        $this->SetValue($Ident, $Value);
    }

    public function InitializeDevice()
    {
        if ($this->createStates()) {
            $this->setupSettings();
            if ($this->createPrograms()) {
                //If the device is inactive, we cannot retrieve information about the current selected Program
                if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID) && ($this->GetValue('OperationState') == 'BSH.Common.EnumType.OperationState.Ready')) {
                    $this->updateOptionValues($this->getSelectedProgram());
                }
            }
            $this->createEventProfile();
            $this->MaintainVariable('Event', $this->Translate('Event'), VARIABLETYPE_STRING, 'HomeConnect.Event.' . $this->ReadPropertyString('DeviceType'), 0, true);
            $this->MaintainVariable('EventDescription', $this->Translate('Event Description'), VARIABLETYPE_STRING, '', 0, true);
        }
    }

    private function createPrograms()
    {
        $rawPrograms = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs'), true);
        if (isset($rawPrograms['error'])) {
            return;
        }
        $programs = $rawPrograms['data']['programs'];
        $this->SendDebug('Programs', json_encode($programs), 0);
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
        foreach ($availableOptions as $ident => $key) {
            $optionsPayload[] = [
                'key'   => $optionKeys[$ident],
                'value' => $this->GetValue($ident)
            ];
        }
        return $optionsPayload;
    }

    private function updateOptionVariables($program)
    {
        $rawOptions = $this->getProgram($program);
        $this->SendDebug('RawOptions', json_encode($rawOptions), 0);
        if (!$rawOptions) {
            $this->SetValue('SelectedProgram', '');
            $this->setOptionsDisabled(true);
            return;
        }
        $this->setOptionsDisabled(false);
        $options = $rawOptions['options'];
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

        if (!IPS_VariableProfileExists("HomeConnect.Control.$deviceType")) {
            IPS_CreateVariableProfile("HomeConnect.Control.$deviceType", VARIABLETYPE_STRING);
            $associations = [
                ['Value' => 'Start', 'Name' => $this->Translate('Start')],
                ['Value' => 'Stop', 'Name' => $this->Translate('Stop')]
            ];
            if (in_array($deviceType, ['Oven', 'CleaningRobot', 'Dryer', 'Washer', 'DryerWasher'])) {
                $associations[] = ['Value' => 'Pause', 'Name' => $this->Translate('Pause')];
            }
            if (in_array($deviceType, ['Oven', 'CleaningRobot', 'Dishwasher', 'Dryer', 'Washer', 'DryerWasher'])) {
                $associations[] = ['Value' => 'Resume', 'Name' => $this->Translate('Resume')];
            }
            $this->createAssociations("HomeConnect.Control.$deviceType", $associations);
        }
        if (!@IPS_GetObjectIDByIdent('Control', $this->InstanceID)) {
            $this->MaintainVariable('Control', $this->Translate('Control'), VARIABLETYPE_STRING, "HomeConnect.Control.$deviceType", $position, true);
            $this->SetValue('Control', 'Start');
            $this->EnableAction('Control');
        }
    }

    private function getSelectedProgram()
    {
        $selectedProgram = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected'), true);
        return isset($selectedProgram['data']) ? $selectedProgram['data'] : false;
    }

    private function getProgram($key)
    {
        $endpoint = 'homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available/' . $key;
        $data = json_decode($this->requestDataFromParent($endpoint), true);
        return isset($data['data']) ? $data['data'] : false;
    }

    private function getOption($key)
    {
        if (in_array($key, self::EXCLUDE)) {
            return false;
        }
        $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $key), true)['data'];
        return $data;
    }
    private function updateOptionValues($program)
    {
        if (!$program) {
            $this->setOptionsDisabled(true);
            return;
        }
        $this->SetValue('SelectedProgram', $program['key']);
        $this->updateOptionVariables($program['key']);
        $optionKeys = [];
        foreach ($program['options'] as $option) {
            $ident = 'Option' . $this->getLastSnippet($option['key']);
            $optionKeys[$ident] = $option['key'];
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) && !IPS_GetObject($this->GetIDForIdent($ident))['ObjectIsHidden']) {
                if (isset($option['value'])) {
                    $value = $option['value'];
                } elseif (isset($option['constraints']['default'])) {
                    $value = $option['constraints']['default'];
                } else {
                    $value = $option['constraints']['allowedvalues'][0];
                }
                $this->SendDebug('Value', strval($value), 0);
                $this->SetValue($ident, $value);
            }
        }
        $this->WriteAttributeString('OptionKeys', json_encode($optionKeys));
    }

    private function createStates($states = '')
    {
        if (!$states) {
            $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/status'), true);
            if (isset($data['error'])) {
                return false;
            }
        } else {
            $data = $states;
        }
        $this->SendDebug('States', json_encode($data), 0);
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

    public function requestDataFromParent($endpoint, $payload = '')
    {
        $this->SendDebug(__FUNCTION__, sprintf('endpoint: %s, payload: %s', $endpoint, $payload), 0);
        $data = [
            'DataID'      => '{41DDAA3B-65F0-B833-36EE-CEB57A80D022}',
            'Endpoint'    => $endpoint
        ];
        if ($payload) {
            $data['Payload'] = $payload;
        }
        $response = $this->SendDataToParent(json_encode($data));
        $errorDetector = json_decode($response, true);
        if (isset($errorDetector['error'])) {
            switch ($errorDetector['error']['key']) {
                case 'SDK.Error.UnsupportedProgram':
                case 'SDK.Error.UnsupportedOperation':
                case 'SDK.Error.NoProgramSelected':
                    // case 'SDK.Error.HomeAppliance.Connection.Initialization.Failed':
                    return $response;

                default:
                    $this->SendDebug('ErrorPayload', $payload, 0);
                    $this->SendDebug('ErrorEndpoint', $endpoint, 0);
                    echo $errorDetector['error']['description']; //Not translated  due to the dynamic content
                    break;
            }
        }
        $this->SendDebug('responseData', $response, 0);
        return $response;
    }

    private function createAssociations($profileName, $associations)
    {
        foreach ($associations as $association) {
            IPS_SetVariableProfileAssociation($profileName, $association['Value'], $this->Translate($association['Name']), '', -1);
        }
    }

    private function setupSettings()
    {
        $allSettings = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings'), true);
        $this->SendDebug('Settings', json_encode($allSettings), 0);
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
                $this->SendDebug('Setting', json_encode($setting), 0);
                //Create variable accordingly
                $profileName = str_replace('BSH', 'HomeConnect', $setting['key']);
                if ($ident == 'PowerState') {
                    $profileName .= '.' . $this->ReadPropertyString('DeviceType');
                }
                $variableType = $this->getVariableType($value);
                $settingDetails = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $setting['key']), true);
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
        $this->SendDebug('UpdatingProfile', $profileName, 0);

        $this->SendDebug('OptionDetails', json_encode($data), 0);
        $ident = $this->getLastSnippet($data['key']);
        if ($attribute == 'Option') {
            $ident = $attribute . $ident;
        }

        switch ($data['type']) {
            case 'Int':
                $variableType = VARIABLETYPE_INTEGER;
                break;

            case 'Double':
                $variableType = VARIABLETYPE_FLOAT;
                break;

            case 'Boolean':
                $variableType = VARIABLETYPE_BOOLEAN;
                break;

            default:
                $variableType = VARIABLETYPE_STRING;
                break;
        }

        switch ($variableType) {
            case VARIABLETYPE_INTEGER:
            case VARIABLETYPE_FLOAT:
                $constraints = $data['constraints'];
                if (!IPS_VariableProfileExists($profileName)) {
                    //Create profile
                    IPS_CreateVariableProfile($profileName, $variableType);
                }
                IPS_SetVariableProfileText($profileName, '', ' ' . $data['unit']);
                $min = isset($constraints['min']) ? $constraints['min'] : 0;
                $max = isset($constraints['max']) ? $constraints['max'] : 86340;
                IPS_SetVariableProfileValues($profileName, $min, $max, isset($constraints['stepsize']) ? $constraints['stepsize'] : 1);
                $this->SendDebug('UpdatedProfile', $min . ' - ' . $max, 0);
                break;

            case VARIABLETYPE_BOOLEAN:
                $profileName = 'HomeConnect.YesNo';
                break;

            default:
                $constraints = $data['constraints'];
                $variableType = VARIABLETYPE_STRING;
                if (!IPS_VariableProfileExists($profileName)) {
                    //Create profile
                    IPS_CreateVariableProfile($profileName, $variableType);
                }
                //Add potential new options
                $newAssociations = [];
                for ($i = 0, $size = count($constraints['allowedvalues']); $i < $size; $i++) {
                    $displayName = isset($constraints['displayvalues'][$i]) ? $constraints['displayvalues'][$i] : $this->getLastSnippet($constraints['allowedvalues'][$i]);
                    $newAssociations[$constraints['allowedvalues'][$i]] = $displayName;
                }

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
                if (in_array($deviceType, ['Oven', 'Dishwasher', 'Washer', 'Dryer', 'WasherDryer', 'Cooktop', 'Hood', 'CleaningRobot', 'CookProcessor'])) {
                    $associations[] = ['Value' => 'BSH.Common.Event.ProgramFinished', 'Name' => 'Program Finished'];
                }
                if (in_array($deviceType, ['Oven',  'Cooktop'])) {
                    $associations[] = ['Value' => 'BSH.Common.Event.AlarmClockElapsed', 'Name' => 'Alarm Clock Elapsed'];
                }
                if (in_array($deviceType, ['Oven',  'Cooktop'])) {
                    $associations[] = ['Value' => 'BSH.Common.Event.PreheatFinished', 'Name' => 'Pre-heat Finished'];
                }
                if (in_array($deviceType, ['CoffeeMaker'])) {
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.BeanContainerEmpty', 'Name' => 'Bean Container Empty'];
                }
                if (in_array($deviceType, ['CoffeeMaker'])) {
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.WaterTankEmpty', 'Name' => 'Water Tank Empty'];
                }
                if (in_array($deviceType, ['CoffeeMaker'])) {
                    $associations[] = ['Value' => 'ConsumerProducts.CoffeeMaker.Event.DripTrayFull', 'Name' => 'Drip Tray Full'];
                }
                if (in_array($deviceType, ['FridgeFreezer', 'Freezer'])) {
                    $associations[] = ['Value' => 'Refrigeration.FridgeFreezer.Event.DoorAlarmFreezer', 'Name' => 'Door Alarm Freezer'];
                }
                if (in_array($deviceType, ['FridgeFreezer', 'Refrigerator'])) {
                    $associations[] = ['Value' => 'Refrigeration.FridgeFreezer.Event.DoorAlarmRefrigerator', 'Name' => 'Door Alarm Refrigerator'];
                }
                if (in_array($deviceType, ['FridgeFreezer', 'Freezer'])) {
                    $associations[] = ['Value' => 'Refrigeration.FridgeFreezer.Event.TemperatureAlarmFreezer', 'Name' => 'Temperature Alarm Freezer'];
                }
                if (in_array($deviceType, ['CleaningRobot'])) {
                    $associations[] = ['Value' => 'ConsumerProducts.CleaningRobot.Event.EmptyDustBoxAndCleanFilter', 'Name' => 'Empty Dust Box and Clean Filter'];
                }
                if (in_array($deviceType, ['CleaningRobot'])) {
                    $associations[] = ['Value' => 'ConsumerProducts.CleaningRobot.Event.RobotIsStuck', 'Name' => 'Robot is Stuck'];
                }
                if (in_array($deviceType, ['CleaningRobot'])) {
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
        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/commands/' . $command, json_encode($payload));
        return true;
    }

    private function getAvailableCommands()
    {
        return json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/commands'), true)['data']['commands'];
    }
}