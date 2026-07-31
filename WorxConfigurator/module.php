<?php

declare(strict_types=1);

/**
 * Worx Configurator — listet die Mäher des Kontos auf und legt sie per Klick an.
 */
class WorxConfigurator extends IPSModule
{
    private const MOWER_GUID = '{474B5876-AE7B-45B8-A774-A758AA741D49}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent('{09B3E1A6-0D6F-4452-B1A2-5D4C9794BF34}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $values = [];
        foreach ($this->getDevices() as $device) {
            $serial = $device['serial_number'] ?? '';
            if ($serial === '') {
                continue;
            }
            $values[] = [
                'instanceID'  => $this->findInstance($serial),
                'name'        => $device['name'] ?? $serial,
                'serial'      => $serial,
                'model'       => $device['name'] ?? '',
                'firmware'    => (string) ($device['firmware_version'] ?? ''),
                'online'      => ($device['online'] ?? false) ? 'ja' : 'nein',
                'create'      => [
                    'moduleID'      => self::MOWER_GUID,
                    'name'          => $device['name'] ?? $serial,
                    'configuration' => ['Serial' => $serial],
                ],
            ];
        }

        return json_encode([
            'actions' => [
                [
                    'type'    => 'Configurator',
                    'name'    => 'Mowers',
                    'caption' => 'Mähroboter',
                    'rowCount' => 10,
                    'add'     => false,
                    'delete'  => true,
                    'columns' => [
                        ['caption' => 'Name',         'name' => 'name',     'width' => 'auto'],
                        ['caption' => 'Seriennummer', 'name' => 'serial',   'width' => '220px'],
                        ['caption' => 'Firmware',     'name' => 'firmware', 'width' => '120px'],
                        ['caption' => 'Online',       'name' => 'online',   'width' => '80px'],
                    ],
                    'values' => $values,
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
            ],
        ]);
    }

    private function getDevices(): array
    {
        $result = $this->SendDataToParent(json_encode([
            'DataID'  => '{5279818E-0317-46AB-A451-0683BEBC6E90}',
            'Command' => 'ListDevices',
        ]));
        $devices = json_decode((string) $result, true);
        return is_array($devices) ? $devices : [];
    }

    /** Bereits angelegte Instanz zu einer Seriennummer, sonst 0. */
    private function findInstance(string $serial): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::MOWER_GUID) as $id) {
            if (IPS_GetProperty($id, 'Serial') === $serial) {
                return $id;
            }
        }
        return 0;
    }
}
