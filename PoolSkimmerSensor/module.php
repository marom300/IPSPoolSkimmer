<?php

declare(strict_types=1);

/**
 * PoolSkimmerSensor - IP-Symcon Modul
 * -----------------------------------
 * Empfaengt die Messwerte des ESP32-Fuellstandssensors (Deep-Sleep, MQTT)
 * und schreibt die Konfiguration als RETAINED Message auf <BaseTopic>/config.
 * Der Sensor holt sie bei jedem Aufwachen ab ("Briefkasten-Prinzip").
 *
 * Haengt als Geraet unter dem MQTT Server (Splitter).
 */
class PoolSkimmerSensor extends IPSModule
{
    // Datenfluss-GUIDs des IP-Symcon MQTT Servers
    private const GUID_MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'; // an MQTT Server senden
    private const GUID_MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}'; // vom MQTT Server empfangen

    public function Create()
    {
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}'); // MQTT Server

        // --- Konfigurations-Properties (werden per Button an den Sensor gesendet) ---
        $this->RegisterPropertyString('BaseTopic', 'pool/skimmer');
        $this->RegisterPropertyString('Mode', 'daily');          // daily | interval
        $this->RegisterPropertyInteger('WakeHour', 21);
        $this->RegisterPropertyInteger('WakeMin', 0);
        $this->RegisterPropertyInteger('IntervalMin', 30);
        $this->RegisterPropertyInteger('CheckinMin', 240);       // 0 = aus
        $this->RegisterPropertyFloat('OffsetCm', 0.0);
        $this->RegisterPropertyInteger('NMeas', 10);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Empfangsfilter: nur unser Basis-Topic (Slashes im JSON escaped)
        $topicRegex = str_replace('/', '\\\\/', $this->ReadPropertyString('BaseTopic'));
        $this->SetReceiveDataFilter('.*' . $topicRegex . '.*');

        // --- Profile ---
        $this->ensureProfileFloat('PSK.cm', ' cm', 1);
        $this->ensureProfileFloat('PSK.volt', ' V', 2);
        $this->ensureProfileInt('PSK.pct', ' %');
        $this->ensureProfileInt('PSK.dbm', ' dBm');

        // --- Statusvariablen ---
        $this->RegisterVariableFloat('WaterLevel', 'Füllstand', 'PSK.cm', 10);
        $this->RegisterVariableFloat('BatteryV', 'Akkuspannung', 'PSK.volt', 20);
        $this->RegisterVariableInteger('BatteryPct', 'Akku', 'PSK.pct', 30);
        $this->RegisterVariableBoolean('Stale', 'Messwert veraltet', '~Alert', 40);
        $this->RegisterVariableInteger('LastSeen', 'Zuletzt gesehen', '~UnixTimestamp', 50);
        $this->RegisterVariableInteger('RSSI', 'WLAN-Signal', 'PSK.dbm', 60);
        $this->RegisterVariableString('FwVersion', 'Firmware', '', 70);
        $this->RegisterVariableString('ConfigAck', 'Konfig-Bestätigung (Sensor)', '', 80);
    }

    // ================= Empfang =================
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if ($data === null || !isset($data->Topic)) {
            return '';
        }
        $topic   = (string)$data->Topic;
        $payload = $this->decodePayload($data->Payload ?? '');
        $base    = $this->ReadPropertyString('BaseTopic');

        $this->SendDebug('RX', $topic . ' = ' . $payload, 0);

        switch ($topic) {
            case $base . '/json':
                $j = json_decode($payload, true);
                if (is_array($j)) {
                    if (isset($j['level_cm']))    $this->SetValue('WaterLevel', (float)$j['level_cm']);
                    if (isset($j['battery_v']))   $this->SetValue('BatteryV', (float)$j['battery_v']);
                    if (isset($j['battery_pct'])) $this->SetValue('BatteryPct', (int)$j['battery_pct']);
                    if (isset($j['stale']))       $this->SetValue('Stale', (bool)$j['stale']);
                    if (isset($j['ts']))          $this->SetValue('LastSeen', (int)$j['ts']);
                }
                break;

            case $base . '/status':
                $j = json_decode($payload, true);
                if (is_array($j)) {
                    if (isset($j['rssi']))        $this->SetValue('RSSI', (int)$j['rssi']);
                    if (isset($j['fw']))          $this->SetValue('FwVersion', (string)$j['fw']);
                    if (isset($j['ts']))          $this->SetValue('LastSeen', (int)$j['ts']);
                    // Check-in liefert auch Akku-Stand (ohne Messung)
                    if (isset($j['battery_v']))   $this->SetValue('BatteryV', (float)$j['battery_v']);
                    if (isset($j['battery_pct'])) $this->SetValue('BatteryPct', (int)$j['battery_pct']);
                }
                break;

            case $base . '/config_ack':
                $this->SetValue('ConfigAck', $payload);
                break;
        }
        return '';
    }

    // ================= Aktionen (Buttons im Formular) =================
    public function SendConfig(): void
    {
        $this->publishConfig(0, 0);
        echo 'Konfiguration gesendet – der Sensor übernimmt sie beim nächsten Aufwachen.';
    }

    public function RequestPortal(): void
    {
        $this->publishConfig(1, 0);
        echo 'Portal-Anforderung gesendet – beim nächsten Aufwachen öffnet der Sensor den Hotspot "PoolSensor-Setup".';
    }

    public function RequestOta(): void
    {
        $this->publishConfig(0, 1);
        echo 'OTA-Anforderung gesendet – beim nächsten Aufwachen bleibt der Sensor 5 Minuten als Netzwerk-Port "pool-skimmer-sensor" erreichbar.';
    }

    // ================= intern =================
    private function publishConfig(int $portal, int $ota): void
    {
        $cfg = [
            'mode'         => $this->ReadPropertyString('Mode'),
            'wake_hour'    => $this->ReadPropertyInteger('WakeHour'),
            'wake_min'     => $this->ReadPropertyInteger('WakeMin'),
            'interval_min' => $this->ReadPropertyInteger('IntervalMin'),
            'checkin_min'  => $this->ReadPropertyInteger('CheckinMin'),
            'offset_cm'    => $this->ReadPropertyFloat('OffsetCm'),
            'n_meas'       => $this->ReadPropertyInteger('NMeas'),
            'portal'       => $portal,
            'ota'          => $ota
        ];
        $this->publish($this->ReadPropertyString('BaseTopic') . '/config', json_encode($cfg), true);
    }

    private function publish(string $topic, string $payload, bool $retain): void
    {
        $this->SendDebug('TX', $topic . ' = ' . $payload, 0);
        $this->SendDataToParent(json_encode([
            'DataID'           => self::GUID_MQTT_TX,
            'PacketType'       => 3,        // PUBLISH
            'QualityOfService' => 0,
            'Retain'           => $retain,
            'Topic'            => $topic,
            'Payload'          => $payload
        ]));
    }

    private function decodePayload($raw): string
    {
        $p = (string)$raw;
        // Manche IPS-Versionen liefern das Payload hex-codiert
        if ($p !== '' && ctype_xdigit($p) && strlen($p) % 2 === 0) {
            $bin = @hex2bin($p);
            if ($bin !== false && preg_match('//u', $bin) && (json_decode($bin) !== null || !is_numeric($p))) {
                return $bin;
            }
        }
        return $p;
    }

    private function ensureProfileFloat(string $name, string $suffix, int $digits): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileDigits($name, $digits);
    }

    private function ensureProfileInt(string $name, string $suffix): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
    }
}
