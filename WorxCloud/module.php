<?php

declare(strict_types=1);

/**
 * Worx Cloud — Anbindung an die Worx-/Kress-/Landxcape-Cloud.
 *
 * Meldet sich per OAuth an, hält den Token frisch und pollt die Mäherliste.
 * Die Daten werden an die Kind-Instanzen (Worx Mower) verteilt.
 *
 * Bewusst OHNE den Endpunkt api/v2/users/me: Worx beantwortet den seit Mitte 2026
 * mit HTTP 405 ("Supported methods: DELETE"), woran die verbreiteten Bridges
 * scheitern. Die dort früher geholten Felder (user_id, mqtt_endpoint) stehen
 * ohnehin in jedem Eintrag von product-items.
 */
class WorxCloud extends IPSModule
{
    private const AUTH_URL  = 'https://id.worx.com/oauth/token';
    private const CLIENT_ID = '150da4d2-bb44-433b-9429-3773adc70a2a';

    /** Cloud-Varianten: derselbe Code, unterschiedliche Hosts. */
    private const CLOUDS = [
        'worx'      => 'api.worxlandroid.com',
        'kress'     => 'api.kress-robotik.com',
        'landxcape' => 'api.landxcape-services.com',
        'ferrex'    => 'api.watermelon.smartmower.cloud',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Cloud', 'worx');
        $this->RegisterPropertyInteger('Interval', 120);

        // Laufzeitdaten — gehören nicht in die Konfiguration
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('Devices', '[]');

        $this->RegisterTimer('WorxPoll', 0, 'WORX_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetTimerInterval('WorxPoll', 0);
            $this->SetStatus(104); // inaktiv: Zugangsdaten fehlen
            return;
        }

        // Zugangsdaten geändert → alten Token verwerfen
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeInteger('TokenExpires', 0);

        $interval = max(30, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('WorxPoll', $interval * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Poll();
        }
    }

    /**
     * Holt die Mäherliste und verteilt sie an die Kind-Instanzen.
     */
    public function Poll(): bool
    {
        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            // Sonst meldete ein Poll ohne Zugangsdaten "Anmeldung fehlgeschlagen"
            $this->SetStatus(104);
            return false;
        }

        $token = $this->getToken();
        if ($token === '') {
            $this->SetStatus(201); // Login fehlgeschlagen
            return false;
        }

        $devices = $this->request('GET', '/api/v2/product-items?status=1', null, $token);
        if (!is_array($devices)) {
            $this->SetStatus(202); // Cloud nicht erreichbar
            return false;
        }

        $this->WriteAttributeString('Devices', json_encode($devices));
        $this->SetStatus(102);

        $names = [];
        foreach ($devices as $device) {
            if (!isset($device['serial_number'])) {
                continue;
            }
            $names[] = $device['name'] ?? $device['serial_number'];
            $this->SendDataToChildren(json_encode([
                'DataID' => '{01E8C2C0-BB9C-4615-AD25-A78E12D842F0}',
                'Serial' => $device['serial_number'],
                'Device' => $device,
            ]));
        }
        $this->SetSummary(implode(', ', $names));
        return true;
    }

    /**
     * Anfragen der Kind-Instanzen (Konfigurator: Geräteliste, Mower: Befehl).
     */
    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);

        switch ($data['Command'] ?? '') {
            case 'ListDevices':
                // Frische Liste, damit der Konfigurator neue Mäher sofort sieht
                $token = $this->getToken();
                if ($token !== '') {
                    $devices = $this->request('GET', '/api/v2/product-items?status=1', null, $token);
                    if (is_array($devices)) {
                        $this->WriteAttributeString('Devices', json_encode($devices));
                    }
                }
                return $this->ReadAttributeString('Devices');

            case 'GetDevice':
                foreach (json_decode($this->ReadAttributeString('Devices'), true) ?: [] as $device) {
                    if (($device['serial_number'] ?? '') === ($data['Serial'] ?? '')) {
                        return json_encode($device);
                    }
                }
                return json_encode(null);

            case 'Poll':
                return json_encode($this->Poll());
        }
        return json_encode(false);
    }

    /**
     * Gültiger Access-Token — meldet sich bei Bedarf neu an bzw. erneuert ihn.
     *
     * Der Token läuft nach einer Stunde ab; 60 s Sicherheitsabstand verhindern,
     * dass eine Anfrage genau in den Ablauf läuft.
     */
    private function getToken(): string
    {
        $token = $this->ReadAttributeString('AccessToken');
        if ($token !== '' && $this->ReadAttributeInteger('TokenExpires') > time() + 60) {
            return $token;
        }

        $refresh = $this->ReadAttributeString('RefreshToken');
        if ($refresh !== '') {
            $result = $this->authRequest([
                'client_id'     => self::CLIENT_ID,
                'refresh_token' => $refresh,
                'scope'         => '*',
                'grant_type'    => 'refresh_token',
            ]);
            if ($this->storeToken($result)) {
                return $this->ReadAttributeString('AccessToken');
            }
            $this->SendDebug('Auth', 'Refresh fehlgeschlagen — neuer Login', 0);
        }

        $result = $this->authRequest([
            'client_id'  => self::CLIENT_ID,
            'username'   => $this->ReadPropertyString('Email'),
            'password'   => $this->ReadPropertyString('Password'),
            'scope'      => '*',
            'grant_type' => 'password',
        ]);
        return $this->storeToken($result) ? $this->ReadAttributeString('AccessToken') : '';
    }

    private function storeToken($result): bool
    {
        if (!is_array($result) || !isset($result['access_token'])) {
            return false;
        }
        $this->WriteAttributeString('AccessToken', $result['access_token']);
        $this->WriteAttributeInteger('TokenExpires', time() + (int) ($result['expires_in'] ?? 3600));
        if (isset($result['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', $result['refresh_token']);
        }
        return true;
    }

    private function authRequest(array $body)
    {
        $ch = curl_init(self::AUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            // Passwort niemals mitloggen — nur der Grund ist interessant
            $this->SendDebug('Auth', sprintf('HTTP %d: %s', $code, substr((string) $response, 0, 200)), 0);
            return null;
        }
        return json_decode((string) $response, true);
    }

    private function request(string $method, string $path, $body, string $token)
    {
        $host = self::CLOUDS[$this->ReadPropertyString('Cloud')] ?? self::CLOUDS['worx'];
        $ch = curl_init('https://' . $host . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge($this->headers(), ['authorization: Bearer ' . $token]),
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->SendDebug('API', sprintf('%s %s → HTTP %d', $method, $path, $code), 0);
        if ($code === 401) {
            // Token serverseitig verfallen — beim nächsten Lauf neu anmelden
            $this->WriteAttributeInteger('TokenExpires', 0);
            return null;
        }
        if ($code < 200 || $code >= 300) {
            return null;
        }
        return json_decode((string) $response, true);
    }

    private function headers(): array
    {
        return [
            'accept: application/json',
            'content-type: application/json',
            'user-agent: IPSymconWorx/1.0',
            'accept-language: de-de',
        ];
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                ['type' => 'Select', 'name' => 'Cloud', 'caption' => 'Hersteller', 'options' => [
                    ['caption' => 'Worx Landroid', 'value' => 'worx'],
                    ['caption' => 'Kress', 'value' => 'kress'],
                    ['caption' => 'Landxcape', 'value' => 'landxcape'],
                    ['caption' => 'Ferrex', 'value' => 'ferrex'],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'Email', 'caption' => 'E-Mail'],
                ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => 'Passwort'],
                ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Abfrageintervall (Sekunden)', 'minimum' => 30],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt abfragen', 'onClick' => 'WORX_Poll($id);'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Verbunden'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Zugangsdaten fehlen'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Anmeldung fehlgeschlagen — E-Mail/Passwort prüfen'],
                ['code' => 202, 'icon' => 'error',    'caption' => 'Cloud nicht erreichbar'],
            ],
        ];
        return json_encode($form);
    }
}
