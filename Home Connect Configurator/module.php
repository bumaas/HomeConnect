<?php

declare(strict_types=1);
class HomeConnectConfigurator extends IPSModule
{
    public const MODULE_TYPES =
        [
            'Default' => '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}'
        ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}');
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
    }

    public function ForwardData($JSONString)
    {
        return $this->SendDataToParent($JSONString);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $devices = [];
        $knownHaIDs = [];

        // Only query the live appliance list when the parent (cloud) is usable. When it
        // is not (rate limit blocking the event stream, offline, ...) we skip discovery
        // but still fall through to listing the already-created device instances below.
        $homeapplianceData = $this->HasActiveParent() ? json_decode($this->getHomeAppliances(), true) : null;
        if (isset($homeapplianceData['data']) && isset($homeapplianceData['data']['homeappliances'])) {
            $homeappliances = $homeapplianceData['data']['homeappliances'];
            foreach ($homeappliances as $homeappliance) {
                $knownHaIDs[$homeappliance['haId']] = true;
                $devices[] = [
                    'HaID'       => $homeappliance['haId'],
                    'Name'       => $homeappliance['name'],
                    'Type'       => $this->Translate($homeappliance['type']),
                    'Brand'      => $homeappliance['brand'],
                    'Connected'  => $homeappliance['connected'] ? $this->Translate('Yes') : $this->Translate('No'),
                    'instanceID' => $this->getInstanceIDForGuid($homeappliance['haId'], '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}'),
                    'create'     => [
                        'moduleID'      => $this->getModuleIDByType($homeappliance['type']),
                        'configuration' => [
                            'HaID'       => $homeappliance['haId'],
                            'DeviceType' => $homeappliance['type']
                        ],
                        'name' => $homeappliance['name']
                    ]
                ];
                $this->SendDebug($homeappliance['name'], $this->getModuleIDByType($homeappliance['type']), 0);
            }
        } elseif ($homeapplianceData !== null) {
            $this->SendDebug('Error', json_encode($homeapplianceData), 0);
            $errorDescription = $this->Translate('No error description available');
            if (isset($homeapplianceData['error']) && isset($homeapplianceData['error']['description'])) {
                $errorDescription = $homeapplianceData['error']['description'];
            }
            $form['elements'][] = [
                'type'  => 'PopupAlert',
                'popup' => [
                    'items' => [
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate('An error occurred during the request to Home Connect:') . PHP_EOL . $errorDescription
                        ]
                    ]
                ]
            ];
        }

        // Always list already-created device instances so they stay visible and
        // manageable even when the live discovery fails (e.g. rate limit / offline).
        foreach ($this->getExistingDeviceRows($knownHaIDs) as $device) {
            $devices[] = $device;
        }

        $form['actions'][0]['values'] = $devices;
        return json_encode($form);
    }

    private function getHomeAppliances()
    {
        return $this->requestDataFromParent('homeappliances');
    }

    private function requestDataFromParent($endpoint)
    {
        return $this->SendDataToParent(json_encode([
            'DataID'      => '{41DDAA3B-65F0-B833-36EE-CEB57A80D022}',
            'Endpoint'    => $endpoint
        ]));
    }

    private function getModuleIDByType($type)
    {
        return isset(self::MODULE_TYPES[$type]) ? self::MODULE_TYPES[$type] : self::MODULE_TYPES['Default'];
    }

    private function getInstanceIDForGuid($haid, $guid)
    {
        $instanceIDs = IPS_GetInstanceListByModuleID($guid);
        foreach ($instanceIDs as $instanceID) {
            if (IPS_GetProperty($instanceID, 'HaID') == $haid) {
                return $instanceID;
            }
        }
        return 0;
    }

    /**
     * Build configurator rows for device instances that already exist locally but are
     * not part of the (possibly empty) discovery result. This keeps created devices
     * visible when the Home Connect API is unavailable (rate limit, offline, ...).
     *
     * @param array $knownHaIDs HaIDs already listed from the live discovery (deduplication).
     */
    private function getExistingDeviceRows(array $knownHaIDs)
    {
        $rows = [];
        foreach (self::MODULE_TYPES as $guid) {
            foreach (IPS_GetInstanceListByModuleID($guid) as $instanceID) {
                $haID = (string) @IPS_GetProperty($instanceID, 'HaID');
                if ($haID === '' || isset($knownHaIDs[$haID])) {
                    continue;
                }
                $knownHaIDs[$haID] = true;
                $deviceType = (string) @IPS_GetProperty($instanceID, 'DeviceType');
                $rows[] = [
                    'HaID'       => $haID,
                    'Name'       => IPS_GetName($instanceID),
                    'Type'       => $deviceType !== '' ? $this->Translate($deviceType) : '',
                    'Brand'      => '',
                    'Connected'  => '',
                    'instanceID' => $instanceID
                ];
            }
        }
        return $rows;
    }
}
