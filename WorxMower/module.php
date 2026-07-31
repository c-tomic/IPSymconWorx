<?php

declare(strict_types=1);

/**
 * Worx Mower — ein Mähroboter als Symcon-Gerät.
 *
 * Bekommt die Daten von der Worx-Cloud-Instanz (REST-Abfrage oder MQTT-Push) und
 * schreibt sie in Variablen. Steuerbefehle gehen den umgekehrten Weg und laufen
 * bei Worx ausschließlich über MQTT — per REST nimmt die Cloud sie zwar mit
 * HTTP 200 entgegen, führt sie aber nicht aus.
 */
class WorxMower extends IPSModule
{
    private const IF_CLOUD = '{01E8C2C0-BB9C-4615-AD25-A78E12D842F0}';

    /** Betriebszustand (dat.ls). */
    private const STATES = [
        0 => 'Bereit', 1 => 'In der Ladestation', 2 => 'Startsequenz', 3 => 'Verlässt Ladestation',
        4 => 'Folgt Begrenzung', 5 => 'Sucht Ladestation', 6 => 'Sucht Begrenzung', 7 => 'Mäht',
        8 => 'Angehoben', 9 => 'Eingeklemmt', 10 => 'Messer blockiert', 11 => 'Debug',
        12 => 'Fernsteuerung', 30 => 'Fährt zur Ladestation', 31 => 'Zonentraining',
        32 => 'Kantenschnitt', 33 => 'Sucht Zone', 34 => 'Pausiert',
    ];

    /** Fehlercode (dat.le). */
    private const ERRORS = [
        0 => 'Kein Fehler', 1 => 'Eingeklemmt', 2 => 'Angehoben', 3 => 'Begrenzungsdraht fehlt',
        4 => 'Außerhalb der Begrenzung', 5 => 'Regen', 6 => 'Klappe schließen zum Mähen',
        7 => 'Klappe schließen zur Rückkehr', 8 => 'Messermotor blockiert', 9 => 'Radmotor blockiert',
        10 => 'Eingeklemmt (Zeitüberschreitung)', 11 => 'Umgedreht', 12 => 'Batterie leer',
        13 => 'Begrenzungsdraht vertauscht', 14 => 'Ladefehler', 15 => 'Ladestation nicht gefunden',
        16 => 'Mäher gesperrt', 17 => 'Batterietemperatur zu hoch/niedrig', 18 => 'Fehler Lagesensor',
        19 => 'Radmotor blockiert', 20 => 'Ladestation belegt', 21 => 'Kamerafehler',
        22 => 'Fehler Antriebssystem', 23 => 'Fehler Höhenverstellung', 24 => 'Fehler RFID',
    ];

    /** Steuerbefehle: Profilwert → cmd-Code im MQTT-Protokoll. */
    private const COMMANDS = [1 => 1, 2 => 2, 3 => 3];   // Start, Pause, Heimfahrt

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Serial', '');
        $this->ConnectParent('{09B3E1A6-0D6F-4452-B1A2-5D4C9794BF34}');

        $this->registerProfiles();

        $pos = 0;
        $this->RegisterVariableInteger('Control', 'Steuerung',      'WORX.Control', $pos++);
        $this->RegisterVariableInteger('State',   'Status',         'WORX.State', $pos++);
        $this->RegisterVariableInteger('Error',   'Fehler',         'WORX.Error', $pos++);
        $this->RegisterVariableBoolean('Online',  'Online',         '~Alert.Reversed', $pos++);
        $this->RegisterVariableInteger('Battery', 'Akku',           '~Battery.100', $pos++);
        $this->RegisterVariableBoolean('Charging', 'Lädt',          '~Switch', $pos++);
        $this->RegisterVariableFloat('BatteryTemp',    'Akkutemperatur', '~Temperature', $pos++);
        $this->RegisterVariableFloat('BatteryVoltage', 'Akkuspannung',   '~Volt', $pos++);
        $this->RegisterVariableInteger('ChargeCycles', 'Ladezyklen',     '', $pos++);
        $this->RegisterVariableInteger('WifiSignal',   'WLAN-Signal',    'WORX.dBm', $pos++);
        $this->RegisterVariableFloat('Distance',  'Gesamtstrecke',  'WORX.km', $pos++);
        $this->RegisterVariableFloat('WorkTime',  'Mähzeit gesamt', 'WORX.Hours', $pos++);
        $this->RegisterVariableFloat('BladeTime', 'Messerlaufzeit', 'WORX.Hours', $pos++);
        $this->RegisterVariableBoolean('Rain',    'Regen erkannt',  '~Alert', $pos++);
        $this->RegisterVariableBoolean('Locked',  'Gesperrt',       '~Lock', $pos++);
        $this->RegisterVariableInteger('Zone',    'Aktuelle Zone',  '', $pos++);
        $this->RegisterVariableString('Firmware', 'Firmware',       '', $pos++);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Meldung', '~UnixTimestamp', $pos++);

        $this->EnableAction('Control');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->ReadPropertyString('Serial') === '') {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'Control') {
            return;
        }
        $cmd = self::COMMANDS[(int) $Value] ?? null;
        if ($cmd === null) {
            return;
        }
        $this->Command($cmd);
    }

    /** Schickt einen Rohbefehl, z.B. 1 = Start, 2 = Pause, 3 = Heimfahrt. */
    public function Command(int $Command): bool
    {
        $result = $this->SendDataToParent(json_encode([
            'DataID'  => self::IF_CLOUD,
            'Command' => 'Command',
            'Serial'  => $this->ReadPropertyString('Serial'),
            'Payload' => json_encode(['cmd' => $Command]),
        ]));
        $ok = json_decode((string) $result, true) === true;
        if (!$ok) {
            $this->SendDebug('Befehl', "cmd=$Command konnte nicht gesendet werden", 0);
        }
        return $ok;
    }

    public function Start(): bool  { return $this->Command(1); }
    public function Pause(): bool  { return $this->Command(2); }
    public function Home(): bool   { return $this->Command(3); }

    /** Holt den zwischengespeicherten Stand aus der Cloud-Instanz. */
    public function Update(): bool
    {
        $serial = $this->ReadPropertyString('Serial');
        if ($serial === '') {
            return false;
        }
        $result = $this->SendDataToParent(json_encode([
            'DataID'  => self::IF_CLOUD,
            'Command' => 'GetDevice',
            'Serial'  => $serial,
        ]));
        $device = json_decode((string) $result, true);
        if (!is_array($device)) {
            return false;
        }
        $this->applyDevice($device);
        return true;
    }

    /** Daten, die die Cloud-Instanz aktiv verteilt (REST-Abfrage oder MQTT-Push). */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (($data['Serial'] ?? '') !== $this->ReadPropertyString('Serial')) {
            return '';
        }
        if (isset($data['Device'])) {
            $this->applyDevice($data['Device']);
        }
        return '';
    }

    private function applyDevice(array $device): void
    {
        $this->SetValueSafe('Online', (bool) ($device['online'] ?? false));
        if (isset($device['firmware_version'])) {
            $this->SetValueSafe('Firmware', (string) $device['firmware_version']);
        }

        $dat = $device['last_status']['payload']['dat'] ?? null;
        if (!is_array($dat)) {
            $this->SetSummary($device['name'] ?? '');
            return;
        }

        $state = (int) ($dat['ls'] ?? 0);
        $this->SetValueSafe('State', $state);
        $this->SetValueSafe('Error', (int) ($dat['le'] ?? 0));

        // Steuerungs-Variable dem tatsächlichen Zustand nachführen, damit die
        // Oberfläche nicht "Start" anzeigt, während er längst zu Hause ist.
        $this->SetValueSafe('Control', $this->stateToControl($state));

        if (isset($dat['bt']) && is_array($dat['bt'])) {
            $bt = $dat['bt'];
            $this->SetValueSafe('Battery', (int) ($bt['p'] ?? 0));
            $this->SetValueSafe('BatteryTemp', (float) ($bt['t'] ?? 0));
            $this->SetValueSafe('BatteryVoltage', (float) ($bt['v'] ?? 0));
            $this->SetValueSafe('ChargeCycles', (int) ($bt['nr'] ?? 0));
            $this->SetValueSafe('Charging', ((int) ($bt['c'] ?? 0)) > 0);
        }
        if (isset($dat['st']) && is_array($dat['st'])) {
            // Strecke in Metern, Zeiten in Minuten
            $this->SetValueSafe('Distance', round(((float) ($dat['st']['d'] ?? 0)) / 1000, 1));
            $this->SetValueSafe('WorkTime', round(((float) ($dat['st']['wt'] ?? 0)) / 60, 1));
            $this->SetValueSafe('BladeTime', round(((float) ($dat['st']['b'] ?? 0)) / 60, 1));
        }
        if (isset($dat['rsi']))        { $this->SetValueSafe('WifiSignal', (int) $dat['rsi']); }
        if (isset($dat['rain']['s']))  { $this->SetValueSafe('Rain', ((int) $dat['rain']['s']) > 0); }
        if (isset($dat['lk']))         { $this->SetValueSafe('Locked', ((int) $dat['lk']) > 0); }
        if (isset($dat['cut']['z']))   { $this->SetValueSafe('Zone', (int) $dat['cut']['z']); }
        if (isset($dat['tm'])) {
            $ts = strtotime((string) $dat['tm']);
            if ($ts !== false) {
                $this->SetValueSafe('LastUpdate', $ts);
            }
        }

        $error = (int) ($dat['le'] ?? 0);
        $summary = sprintf('%s · %d%%', self::STATES[$state] ?? 'Unbekannt', (int) ($dat['bt']['p'] ?? 0));
        if ($error > 0) {
            $summary .= ' · ' . (self::ERRORS[$error] ?? ('Fehler ' . $error));
        }
        $this->SetSummary($summary);
    }

    /** Betriebszustand auf die drei Steuerungs-Stellungen abbilden. */
    private function stateToControl(int $state): int
    {
        if (in_array($state, [2, 3, 4, 6, 7, 31, 32, 33], true)) {
            return 1;   // unterwegs / mäht
        }
        if ($state === 34) {
            return 2;   // pausiert
        }
        return 3;       // zu Hause, auf dem Weg dorthin oder untätig
    }

    /** Schreibt nur bei Änderung — hält das Änderungsprotokoll sauber. */
    private function SetValueSafe(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false || $id === 0) {
            return;
        }
        if (GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }

    private function registerProfiles(): void
    {
        if (!IPS_VariableProfileExists('WORX.Control')) {
            IPS_CreateVariableProfile('WORX.Control', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('WORX.Control', 'Motor');
            IPS_SetVariableProfileAssociation('WORX.Control', 1, 'Start', 'Play', 0x00AA00);
            IPS_SetVariableProfileAssociation('WORX.Control', 2, 'Pause', 'Pause', 0xCC8800);
            IPS_SetVariableProfileAssociation('WORX.Control', 3, 'Ladestation', 'Home', 0x0066CC);
        }
        if (!IPS_VariableProfileExists('WORX.State')) {
            IPS_CreateVariableProfile('WORX.State', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('WORX.State', 'Motor');
            foreach (self::STATES as $code => $text) {
                IPS_SetVariableProfileAssociation('WORX.State', $code, $text, '', -1);
            }
        }
        if (!IPS_VariableProfileExists('WORX.Error')) {
            IPS_CreateVariableProfile('WORX.Error', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('WORX.Error', 'Warning');
            foreach (self::ERRORS as $code => $text) {
                IPS_SetVariableProfileAssociation('WORX.Error', $code, $text, '', $code === 0 ? 0x00FF00 : 0xFF0000);
            }
        }
        if (!IPS_VariableProfileExists('WORX.km')) {
            IPS_CreateVariableProfile('WORX.km', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('WORX.km', '', ' km');
            IPS_SetVariableProfileDigits('WORX.km', 1);
            IPS_SetVariableProfileIcon('WORX.km', 'Distance');
        }
        if (!IPS_VariableProfileExists('WORX.Hours')) {
            IPS_CreateVariableProfile('WORX.Hours', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('WORX.Hours', '', ' h');
            IPS_SetVariableProfileDigits('WORX.Hours', 1);
            IPS_SetVariableProfileIcon('WORX.Hours', 'Clock');
        }
        if (!IPS_VariableProfileExists('WORX.dBm')) {
            IPS_CreateVariableProfile('WORX.dBm', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('WORX.dBm', '', ' dBm');
            IPS_SetVariableProfileValues('WORX.dBm', -100, 0, 1);
            IPS_SetVariableProfileIcon('WORX.dBm', 'Network');
        }
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Serial', 'caption' => 'Seriennummer'],
            ],
            'actions' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Start',       'onClick' => 'WORXMOWER_Start($id);'],
                    ['type' => 'Button', 'caption' => 'Pause',       'onClick' => 'WORXMOWER_Pause($id);'],
                    ['type' => 'Button', 'caption' => 'Ladestation', 'onClick' => 'WORXMOWER_Home($id);'],
                ]],
                ['type' => 'Button', 'caption' => 'Jetzt aktualisieren', 'onClick' => 'WORXMOWER_Update($id);'],
                ['type' => 'Label', 'caption' => 'Steuerbefehle laufen über MQTT. Ist die Echtzeit-Verbindung in der Worx-Cloud-Instanz abgeschaltet, bleiben die Schaltflächen wirkungslos.'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Seriennummer fehlt'],
            ],
        ]);
    }
}
