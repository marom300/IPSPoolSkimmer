<?php

declare(strict_types=1);

/**
 * PoolSkimmerSensor - IP-Symcon Modul
 * -----------------------------------
 * 1) Empfaengt die Messwerte des ESP32-Fuellstandssensors (Deep-Sleep, MQTT)
 *    und schreibt die Sensor-Konfiguration als RETAINED Message auf
 *    <BaseTopic>/config ("Briefkasten-Prinzip").
 * 2) Automatische Nachfuell-Logik (dosierte Portionen ueber den Hunter):
 *    - berechnet Fehlmenge aus Ziel-Pegel und Pooloberflaeche
 *    - startet eine ZEITBEGRENZTE Portion ueber ein Benutzer-Skript
 *      (Hunter stoppt hardwareseitig nach Ablauf -> Totmann-Prinzip)
 *    - Tagesbudget, Plausibilitaets- und Frischepruefung
 *    - Erfolgskontrolle: steigt der Pegel nicht wie erwartet -> Sperre + Meldung
 *    - Kalibrierlauf ermittelt die Zuflussrate (l/min) automatisch
 *
 * Haengt als Geraet unter dem MQTT Server (Splitter).
 */
class PoolSkimmerSensor extends IPSModule
{
    // Datenfluss-GUIDs des IP-Symcon MQTT Servers
    private const GUID_MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'; // an MQTT Server senden (SendDataToParent)
    private const GUID_MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}'; // vom MQTT Server empfangen (ReceiveData)

    // RefillState-Werte
    private const ST_OFF     = 0;  // Automatik aus
    private const ST_READY   = 1;  // bereit
    private const ST_RUNNING = 2;  // Portion beauftragt/laeuft
    private const ST_VERIFY  = 3;  // warte auf Kontrollmessung
    private const ST_LOCKED  = 4;  // gesperrt (Fehler) -> quittieren
    private const ST_BUDGET  = 5;  // Tagesbudget erschoepft

    public function Create()
    {
        parent::Create();
        // Parent (MQTT Server) wird ueber parentRequirements + Schnittstellendialog gewaehlt.

        // --- Dashboard ---
        $this->RegisterPropertyString('DashboardTitle', 'Pool');
        $this->RegisterPropertyString('DashboardSubtitle', 'Füllstand & Nachfüllung');

        // --- Sensor / MQTT ---
        $this->RegisterPropertyString('BaseTopic', 'pool/skimmer');
        $this->RegisterPropertyString('Mode', 'daily');          // daily | interval
        $this->RegisterPropertyInteger('WakeHour', 21);
        $this->RegisterPropertyInteger('WakeMin', 0);
        $this->RegisterPropertyInteger('IntervalMin', 30);
        $this->RegisterPropertyInteger('CheckinMin', 240);       // 0 = aus
        $this->RegisterPropertyFloat('OffsetCm', 0.0);
        $this->RegisterPropertyInteger('NMeas', 10);
        $this->RegisterPropertyInteger('RetryMin', 5);   // Sende-Retry-Basis (min); 0 = aus

        // --- Nachfuellen: Geometrie & Hydraulik ---
        $this->RegisterPropertyBoolean('AutoRefill', false);
        $this->RegisterPropertyBoolean('UseActiveRefill', true);  // enges Intervall bei grossem Rueckstand
        $this->RegisterPropertyFloat('PoolArea', 22.75);         // m^2 Wasseroberflaeche
        $this->RegisterPropertyFloat('TargetLevelCm', 10.0);     // SOLL-Abstand Sensor->Wasser (voll)
        $this->RegisterPropertyFloat('ToleranceCm', 0.5);        // darunter keine Aktion
        // Zuflussrate ist KEIN Eingabewert -> ermittelt der Kalibrierlauf selbst
        // (Statusvariable 'CalcFlowRate'). Kein manuelles Messen/Eintragen.
        $this->RegisterPropertyInteger('RefillScriptID', 0);     // Skript: startet Hunter-Zone fuer $_IPS['DURATION'] min

        // --- Nachfuellen: Sicherheitsebenen ---
        $this->RegisterPropertyInteger('MaxRunMin', 30);         // max. Minuten pro Portion
        $this->RegisterPropertyInteger('DailyBudgetMin', 60);    // max. Minuten pro Tag
        $this->RegisterPropertyInteger('MaxAgeMin', 120);        // Messwert-Frische (retained-Schutz!)
        $this->RegisterPropertyFloat('PlausMinCm', 2.0);         // plausibles Messband
        $this->RegisterPropertyFloat('PlausMaxCm', 40.0);
        $this->RegisterPropertyFloat('VerifyFactor', 0.4);       // mind. 40% des erwarteten Anstiegs
        $this->RegisterPropertyInteger('CalibMinutes', 10);      // Dauer Kalibrierlauf

        // --- Dashboard: verknuepfte Fremd-Variablen (z.B. Violet-Steuerung) ---
        $this->RegisterPropertyInteger('DashWaterTemp', 0);
        $this->RegisterPropertyInteger('DashPumpStage', 0);
        $this->RegisterPropertyInteger('DashPumpRpm', 0);
        $this->RegisterPropertyInteger('DashPh', 0);
        $this->RegisterPropertyInteger('DashRedox', 0);
        $this->RegisterPropertyInteger('DashChlorine', 0);      // Chlor (mg/l)
        $this->RegisterPropertyInteger('DashProbeFlow', 0);     // Sondenanstroemung (cm/s)
        $this->RegisterPropertyInteger('DashDosePh', 0);        // Tagesdosierung pH-
        $this->RegisterPropertyInteger('DashDoseCl', 0);        // Tagesdosierung Chlor
        $this->RegisterPropertyInteger('DashRefillValve', 0);   // Zonen-/Ventilstatus Frischwasser
        $this->RegisterPropertyInteger('DashExtra1', 0);
        $this->RegisterPropertyInteger('DashExtra2', 0);

        // --- interne Zustaende ---
        $this->RegisterAttributeString('BudgetDate', '');
        $this->RegisterAttributeFloat('PreRefillCm', -1);        // Abstand vor der Portion
        $this->RegisterAttributeFloat('ExpectedRiseCm', 0);
        $this->RegisterAttributeInteger('PendingUntil', 0);      // Portion laeuft bis (+Puffer)
        $this->RegisterAttributeBoolean('CalibPending', false);
        $this->RegisterAttributeInteger('CalibRunMin', 0);
        $this->RegisterAttributeBoolean('ActiveRefill', false);  // Auffuell-Modus (enges Intervall)
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Empfangsfilter robust gegen (nicht) escapte Slashes im JSON
        $parts = array_map('preg_quote', explode('/', $this->ReadPropertyString('BaseTopic')));
        $this->SetReceiveDataFilter('.*' . implode('.*', $parts) . '.*');

        // --- Profile ---
        $this->ensureProfileFloat('PSK.cm', ' cm', 1);
        $this->ensureProfileFloat('PSK.volt', ' V', 2);
        $this->ensureProfileInt('PSK.pct', ' %');
        $this->ensureProfileInt('PSK.dbm', ' dBm');
        $this->ensureProfileFloat('PSK.liter', ' l', 0);
        $this->ensureProfileFloat('PSK.lmin', ' l/min', 1);
        $this->ensureProfileInt('PSK.min', ' min');
        $this->ensureRefillStateProfile();
        $this->ensureActiveRefillProfile();

        // --- Statusvariablen Sensor ---
        $this->RegisterVariableFloat('WaterLevel', 'Füllstand (Abstand)', 'PSK.cm', 10);
        $this->RegisterVariableFloat('BatteryV', 'Akkuspannung', 'PSK.volt', 20);
        $this->RegisterVariableInteger('BatteryPct', 'Akku', 'PSK.pct', 30);
        $this->RegisterVariableBoolean('Stale', 'Messwert veraltet', '~Alert', 40);
        $this->RegisterVariableInteger('LastSeen', 'Zuletzt gesehen', '~UnixTimestamp', 50);
        $this->RegisterVariableInteger('RSSI', 'WLAN-Signal', 'PSK.dbm', 60);
        $this->RegisterVariableString('FwVersion', 'Firmware', '', 70);
        $this->RegisterVariableString('ConfigAck', 'Konfig-Bestätigung (Sensor)', '', 80);

        // --- Statusvariablen Nachfuellen ---
        $this->RegisterVariableFloat('MissingCm', 'Fehlender Pegel', 'PSK.cm', 100);
        $this->RegisterVariableFloat('MissingLiters', 'Fehlmenge', 'PSK.liter', 110);
        $this->RegisterVariableInteger('RefillState', 'Nachfüll-Status', 'PSK.RefillState', 120);
        $this->RegisterVariableBoolean('ActiveRefillMode', 'Auffüll-Modus aktiv', 'PSK.ActiveRefill', 125);
        $this->RegisterVariableInteger('TodayRefillMin', 'Nachfüllzeit heute', 'PSK.min', 130);
        $this->RegisterVariableInteger('LastRefill', 'Letzte Nachfüllung', '~UnixTimestamp', 140);
        $this->RegisterVariableFloat('CalcFlowRate', 'Zuflussrate (kalibriert)', 'PSK.lmin', 150);
        $this->RegisterVariableInteger('LastCalib', 'Letzte Kalibrierung', '~UnixTimestamp', 155);
        $this->RegisterVariableString('RefillInfo', 'Nachfüll-Protokoll', '', 160);

        // Migration: alten Variablennamen angleichen (RegisterVariable benennt
        // bestehende Variablen nicht um).
        $vid = @$this->GetIDForIdent('CalcFlowRate');
        if ($vid && IPS_GetName($vid) === 'Gemessene Zuflussrate') {
            IPS_SetName($vid, 'Zuflussrate (kalibriert)');
        }

        if (!$this->ReadPropertyBoolean('AutoRefill') && $this->GetValue('RefillState') !== self::ST_LOCKED) {
            $this->SetValue('RefillState', self::ST_OFF);
        }
        // sichtbare Variable mit dem internen Zustand abgleichen
        $this->SetValue('ActiveRefillMode', $this->ReadAttributeBoolean('ActiveRefill'));

        $this->enableArchiving();

        // Dashboard-WebHook registrieren (erst wenn der Kernel bereit ist)
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/poolskimmer' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->RegisterHook('/hook/poolskimmer' . $this->InstanceID);
        }
    }

    /**
     * Aktiviert das Archiv-Logging fuer alle relevanten Statusvariablen
     * automatisch (einmalig; bereits geloggte Variablen bleiben unangetastet).
     */
    private function enableArchiving(): void
    {
        $archList = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archList) === 0) {
            return;
        }
        $arch = $archList[0];

        // Alle sinnvoll auswertbaren Zahlen-/Bool-Variablen. Text-Variablen
        // (Firmware, ConfigAck, RefillInfo) und reine Zeitstempel (LastSeen,
        // LastRefill, LastCalib) werden bewusst NICHT geloggt.
        $idents = ['WaterLevel', 'BatteryV', 'BatteryPct', 'MissingCm', 'MissingLiters',
                   'TodayRefillMin', 'RSSI', 'RefillState', 'Stale', 'CalcFlowRate',
                   'ActiveRefillMode'];
        $changed = false;
        foreach ($idents as $ident) {
            $vid = @$this->GetIDForIdent($ident);
            if ($vid === false || $vid <= 0) {
                continue;
            }
            if (!AC_GetLoggingStatus($arch, $vid)) {
                AC_SetLoggingStatus($arch, $vid, true);
                AC_SetAggregationType($arch, $vid, 0);   // Standard
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($arch);
            $this->SendDebug('ARCHIV', 'Logging fuer Statusvariablen aktiviert', 0);
        }
    }

    /**
     * Liefert alle Dashboard-Daten als JSON: eigene Statusvariablen plus
     * die im Formular verknuepften Fremd-Variablen (Violet etc.).
     * Aufruf z.B. per JSON-RPC: PSK_GetDashboardData(<InstanzID>)
     */
    public function GetDashboardData(): string
    {
        $out = [
            'level_cm'      => $this->GetValue('WaterLevel'),
            'missing_cm'    => $this->GetValue('MissingCm'),
            'missing_l'     => $this->GetValue('MissingLiters'),
            'battery_v'     => $this->GetValue('BatteryV'),
            'battery_pct'   => $this->GetValue('BatteryPct'),
            'stale'         => $this->GetValue('Stale'),
            'last_seen'     => $this->GetValue('LastSeen'),
            'rssi'          => $this->GetValue('RSSI'),
            'fw'            => $this->GetValue('FwVersion'),
            'refill_state'  => $this->GetValue('RefillState'),
            'active_refill' => $this->GetValue('ActiveRefillMode'),
            'refill_today'  => $this->GetValue('TodayRefillMin'),
            'refill_last'   => $this->GetValue('LastRefill'),
            'refill_info'   => $this->GetValue('RefillInfo'),
            'flow_measured' => $this->GetValue('CalcFlowRate'),
            'calib_last'    => $this->GetValue('LastCalib'),
            'ts'            => time()
        ];

        $links = [
            'water_temp'   => 'DashWaterTemp',
            'pump_stage'   => 'DashPumpStage',
            'pump_rpm'     => 'DashPumpRpm',
            'ph'           => 'DashPh',
            'redox'        => 'DashRedox',
            'chlorine'     => 'DashChlorine',
            'probe_flow'   => 'DashProbeFlow',
            'dose_ph'      => 'DashDosePh',
            'dose_cl'      => 'DashDoseCl',
            'refill_valve' => 'DashRefillValve',
            'extra1'       => 'DashExtra1',
            'extra2'       => 'DashExtra2'
        ];
        foreach ($links as $key => $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $out[$key] = GetValue($vid);
                $out[$key . '_str'] = GetValueFormatted($vid);
            }
        }

        return json_encode($out);
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
                // Nur Messwerte + Nachfuell-Logik. Akku/RSSI/FW/LastSeen kommen
                // AUSSCHLIESSLICH aus /status (bei jedem Aufwachen), sonst wuerde
                // bei Mess-Zyklen alles doppelt ins Archiv geschrieben.
                $j = json_decode($payload, true);
                if (is_array($j)) {
                    if (isset($j['level_cm'])) $this->SetValue('WaterLevel', (float)$j['level_cm']);
                    if (isset($j['stale']))    $this->SetValue('Stale', (bool)$j['stale']);
                    $this->updateMissing();
                    $this->evaluateRefill($j);
                }
                break;

            case $base . '/status':
                $j = json_decode($payload, true);
                if (is_array($j)) {
                    if (isset($j['rssi']))        $this->SetValue('RSSI', (int)$j['rssi']);
                    if (isset($j['fw']))          $this->SetValue('FwVersion', (string)$j['fw']);
                    if (isset($j['ts']))          $this->SetValue('LastSeen', (int)$j['ts']);
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

    // ================= Nachfuell-Logik =================
    private function updateMissing(): void
    {
        $missingCm = $this->GetValue('WaterLevel') - $this->ReadPropertyFloat('TargetLevelCm');
        if ($missingCm < 0) $missingCm = 0.0;
        $this->SetValue('MissingCm', round($missingCm, 1));
        $this->SetValue('MissingLiters', round($missingCm * $this->litersPerCm(), 0));
    }

    private function evaluateRefill(array $j): void
    {
        $now = time();
        $this->resetBudgetIfNewDay();

        // --- offene Erfolgskontrolle / Kalibrierung zuerst ---
        $pendingUntil = $this->ReadAttributeInteger('PendingUntil');
        if ($pendingUntil > 0) {
            if ($now < $pendingUntil) {
                $this->SendDebug('REFILL', 'Portion laeuft noch - keine Aktion', 0);
                return;                                   // Wasser laeuft evtl. noch
            }
            $this->finishPending();                       // Kontrolle durchfuehren
        }

        if (!$this->ReadPropertyBoolean('AutoRefill')) {
            if ($this->GetValue('RefillState') !== self::ST_LOCKED) {
                $this->SetValue('RefillState', self::ST_OFF);
            }
            return;
        }
        if ($this->GetValue('RefillState') === self::ST_LOCKED) {
            $this->SendDebug('REFILL', 'GESPERRT - erst quittieren', 0);
            return;
        }

        // --- Sicherheits-Checks ---
        if (!empty($j['stale'])) {
            $this->info('Messwert stale - keine Nachfüllung.');
            return;
        }
        $ts = (int)($j['ts'] ?? 0);
        if (abs($now - $ts) > $this->ReadPropertyInteger('MaxAgeMin') * 60) {
            $this->info('Messwert zu alt (retained?) - keine Nachfüllung.');
            return;
        }
        $level = (float)($j['level_cm'] ?? -1);
        if ($level < $this->ReadPropertyFloat('PlausMinCm') || $level > $this->ReadPropertyFloat('PlausMaxCm')) {
            $this->warn(sprintf('Messwert %.1f cm ausserhalb Plausibilitätsband - keine Nachfüllung.', $level));
            return;
        }

        // --- Bedarf ---
        $missingCm = $level - $this->ReadPropertyFloat('TargetLevelCm');
        if ($missingCm <= $this->ReadPropertyFloat('ToleranceCm')) {
            $this->SetValue('RefillState', self::ST_READY);
            $this->exitActiveRefill('Zielpegel erreicht');   // ggf. zurück auf Normalplan
            return;
        }

        // --- Zuflussrate muss kalibriert sein ---
        $flow = $this->GetValue('CalcFlowRate');
        if ($flow <= 0.5) {
            $this->SetValue('RefillState', self::ST_READY);
            $this->warn('Zuflussrate noch nicht kalibriert - bitte Kalibrierlauf starten. Keine Nachfüllung.');
            return;
        }

        // --- Portion berechnen (dosieren, nicht regeln) ---
        $needMin = (int)ceil($missingCm * $this->litersPerCm() / $flow);
        $runMin  = min($needMin, $this->ReadPropertyInteger('MaxRunMin'));

        $rest = $this->ReadPropertyInteger('DailyBudgetMin') - $this->GetValue('TodayRefillMin');
        if ($rest <= 0) {
            $this->SetValue('RefillState', self::ST_BUDGET);
            $this->warn('Tagesbudget erschöpft - keine weitere Nachfüllung heute.');
            $this->exitActiveRefill('Tagesbudget erreicht');   // Akku schonen bis morgen
            return;
        }
        $runMin = min($runMin, $rest);

        // --- Auffüll-Modus: reicht eine Portion nicht, den Sensor eng takten ---
        if ($needMin > $runMin) {
            $this->enterActiveRefill($missingCm);
        }

        $this->startPortion($runMin, $level, false);
    }

    private function startPortion(int $runMin, float $preLevelCm, bool $calib): void
    {
        $scriptID = $this->ReadPropertyInteger('RefillScriptID');
        if ($scriptID <= 0 || !IPS_ScriptExists($scriptID)) {
            $this->warn('Kein Nachfüll-Skript konfiguriert!');
            return;
        }

        $flowNow = max(0.0, $this->GetValue('CalcFlowRate'));
        $expRise = $runMin * $flowNow / $this->litersPerCm();
        $this->WriteAttributeFloat('PreRefillCm', $preLevelCm);
        $this->WriteAttributeFloat('ExpectedRiseCm', $expRise);
        $this->WriteAttributeInteger('PendingUntil', time() + $runMin * 60 + 300); // + 5 min Puffer
        $this->WriteAttributeBoolean('CalibPending', $calib);
        $this->WriteAttributeInteger('CalibRunMin', $runMin);

        $this->SetValue('TodayRefillMin', $this->GetValue('TodayRefillMin') + $runMin);
        $this->SetValue('LastRefill', time());
        $this->SetValue('RefillState', self::ST_RUNNING);
        $this->info(sprintf('%s: %d min gestartet (erwarteter Anstieg %.1f cm).',
            $calib ? 'Kalibrierlauf' : 'Nachfüllung', $runMin, $expRise));

        IPS_RunScriptEx($scriptID, ['DURATION' => $runMin, 'SENDER' => 'PoolSkimmer']);
    }

    private function finishPending(): void
    {
        $pre     = $this->ReadAttributeFloat('PreRefillCm');
        $expRise = $this->ReadAttributeFloat('ExpectedRiseCm');
        $calib   = $this->ReadAttributeBoolean('CalibPending');
        $runMin  = $this->ReadAttributeInteger('CalibRunMin');

        $this->WriteAttributeInteger('PendingUntil', 0);
        $this->WriteAttributeBoolean('CalibPending', false);

        $rise = $pre - $this->GetValue('WaterLevel');     // Abstand kleiner = Pegel hoeher

        if ($calib && $runMin > 0) {
            $flow = round(max(0.0, $rise * $this->litersPerCm() / $runMin), 1);
            if ($flow > 0.5) {
                $this->SetValue('CalcFlowRate', $flow);   // = die Zuflussrate, die die Logik nutzt
                $this->SetValue('LastCalib', time());
                $this->info(sprintf('Kalibrierlauf: +%.1f cm in %d min = %.1f l/min – als Zuflussrate übernommen.',
                    $rise, $runMin, $flow));
            } else {
                $this->warn(sprintf('Kalibrierlauf: nur +%.1f cm Anstieg – nicht plausibel. Zuflussrate unverändert (Ventil/Zulauf prüfen).',
                    $rise));
            }
            $this->SetValue('RefillState', self::ST_READY);
            return;
        }

        if ($rise < $expRise * $this->ReadPropertyFloat('VerifyFactor')) {
            $this->SetValue('RefillState', self::ST_LOCKED);
            $this->warn(sprintf('ERFOLGSKONTROLLE FEHLGESCHLAGEN: nur +%.1f cm statt ~%.1f cm. Nachfüllen GESPERRT - Ventil/Zulauf/Sensor prüfen, dann quittieren.',
                $rise, $expRise));
            $this->exitActiveRefill('gesperrt');   // eng takten macht gesperrt keinen Sinn
            return;
        }

        $this->info(sprintf('Kontrolle OK: +%.1f cm (erwartet ~%.1f cm).', $rise, $expRise));
        $this->SetValue('RefillState', self::ST_READY);
    }

    private function resetBudgetIfNewDay(): void
    {
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('BudgetDate') !== $today) {
            $this->WriteAttributeString('BudgetDate', $today);
            $this->SetValue('TodayRefillMin', 0);
            if ($this->GetValue('RefillState') === self::ST_BUDGET) {
                $this->SetValue('RefillState', self::ST_READY);
            }
        }
    }

    private function litersPerCm(): float
    {
        return max(0.1, $this->ReadPropertyFloat('PoolArea')) * 10.0;   // 1 cm auf 1 m^2 = 10 l
    }

    // ================= Aktionen (Buttons) =================
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
        echo 'OTA-Anforderung gesendet – beim nächsten Aufwachen bleibt der Sensor 5 Minuten als Netzwerk-Port erreichbar.';
    }

    public function AcknowledgeLock(): void
    {
        $this->SetValue('RefillState', $this->ReadPropertyBoolean('AutoRefill') ? self::ST_READY : self::ST_OFF);
        $this->WriteAttributeInteger('PendingUntil', 0);
        $this->info('Sperre quittiert.');
        echo 'Sperre quittiert.';
    }

    public function StartCalibration(): void
    {
        $this->resetBudgetIfNewDay();
        if ($this->ReadAttributeInteger('PendingUntil') > time()) {
            echo 'Es läuft bereits eine Portion.';
            return;
        }
        $level = $this->GetValue('WaterLevel');
        if ($level <= 0) {
            echo 'Noch kein gültiger Messwert vorhanden.';
            return;
        }
        if ($this->ReadPropertyString('Mode') !== 'interval') {
            echo "HINWEIS: Sensor steht auf Täglich-Modus. Für die Kontrollmessung nach dem Lauf bitte vorher auf Intervall (z.B. 5 min) stellen und 'Konfiguration an Sensor senden' - sonst kommt das Ergebnis erst am nächsten Messtermin.\n\n";
        }
        $min = max(1, $this->ReadPropertyInteger('CalibMinutes'));
        $this->startPortion($min, $level, true);
        echo "Kalibrierlauf ({$min} min) gestartet. Das Ergebnis (l/min) wird bei der nächsten Messung nach Ablauf automatisch als Zuflussrate übernommen.";
    }

    // ================= Auffüll-Modus =================
    // Reicht eine Portion nicht, wird der Sensor vorübergehend eng getaktet
    // (Intervall = Portionsdauer + Puffer), damit er nach jeder Portion neu misst
    // und weiterfüllt. Ist der Zielpegel erreicht (oder Budget/Sperre), zurück
    // auf den normalen Messplan – Akku schonen.
    private function enterActiveRefill(float $missingCm): void
    {
        if (!$this->ReadPropertyBoolean('UseActiveRefill')) {
            return;                                   // Funktion abgeschaltet
        }
        if ($this->ReadAttributeBoolean('ActiveRefill')) {
            return;                                   // schon aktiv
        }
        $this->WriteAttributeBoolean('ActiveRefill', true);
        $this->SetValue('ActiveRefillMode', true);
        $this->publishConfig(0, 0);                   // enges Intervall an den Sensor
        $this->info(sprintf('Großer Rückstand (%.1f cm) – Auffüll-Modus: Sensor misst eng getaktet bis zum Zielpegel.', $missingCm));
    }

    private function exitActiveRefill(string $grund): void
    {
        if (!$this->ReadAttributeBoolean('ActiveRefill')) {
            return;
        }
        $this->WriteAttributeBoolean('ActiveRefill', false);
        $this->SetValue('ActiveRefillMode', false);
        $this->publishConfig(0, 0);                   // zurück auf Normal-Messplan
        $this->info('Auffüll-Modus beendet (' . $grund . ') – zurück auf Normal-Messplan.');
    }

    // ================= intern =================
    private function publishConfig(int $portal, int $ota): void
    {
        $active = $this->ReadAttributeBoolean('ActiveRefill');
        // Im Auffüll-Modus: Intervall = Portionsdauer + 5 min Puffer (Portion
        // läuft + Wasser beruhigt sich, dann misst der Sensor wieder).
        $activeInterval = max(1, $this->ReadPropertyInteger('MaxRunMin') + 5);

        $cfg = [
            'mode'         => $active ? 'interval' : $this->ReadPropertyString('Mode'),
            'wake_hour'    => $this->ReadPropertyInteger('WakeHour'),
            'wake_min'     => $this->ReadPropertyInteger('WakeMin'),
            'interval_min' => $active ? $activeInterval : $this->ReadPropertyInteger('IntervalMin'),
            'checkin_min'  => $this->ReadPropertyInteger('CheckinMin'),
            'offset_cm'    => $this->ReadPropertyFloat('OffsetCm'),
            'n_meas'       => $this->ReadPropertyInteger('NMeas'),
            'retry_min'    => $this->ReadPropertyInteger('RetryMin'),
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

    private function info(string $msg): void
    {
        $this->SetValue('RefillInfo', date('d.m. H:i') . ' ' . $msg);
        $this->LogMessage('PoolSkimmer: ' . $msg, KL_NOTIFY);
        $this->SendDebug('REFILL', $msg, 0);
    }

    private function warn(string $msg): void
    {
        $this->SetValue('RefillInfo', date('d.m. H:i') . ' ⚠ ' . $msg);
        $this->LogMessage('PoolSkimmer: ' . $msg, KL_WARNING);
        $this->SendDebug('REFILL', $msg, 0);
    }

    private function decodePayload($raw): string
    {
        $p = (string)$raw;
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

    private function ensureRefillStateProfile(): void
    {
        $name = 'PSK.RefillState';
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileAssociation($name, self::ST_OFF,     'Automatik aus', '', 0x808080);
        IPS_SetVariableProfileAssociation($name, self::ST_READY,   'Bereit', '', 0x00A000);
        IPS_SetVariableProfileAssociation($name, self::ST_RUNNING, 'Portion läuft', '', 0x0080FF);
        IPS_SetVariableProfileAssociation($name, self::ST_VERIFY,  'Warte auf Kontrolle', '', 0xFFA500);
        IPS_SetVariableProfileAssociation($name, self::ST_LOCKED,  'GESPERRT', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($name, self::ST_BUDGET,  'Tagesbudget erreicht', '', 0xFFA500);
    }

    private function ensureActiveRefillProfile(): void
    {
        $name = 'PSK.ActiveRefill';
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 0);   // 0 = boolean
        }
        IPS_SetVariableProfileAssociation($name, 0, 'Normal', '', 0x808080);
        IPS_SetVariableProfileAssociation($name, 1, 'Auffüllen läuft', '', 0x0080FF);
    }

    // ================= WebHook / Dashboard =================
    private function RegisterHook(string $Hook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'); // WebHook Control
        if (count($ids) === 0) {
            return;
        }
        $hookID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookID, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $h) {
            if (($h['Hook'] ?? '') === $Hook && (int)($h['TargetID'] ?? 0) === $this->InstanceID) {
                return;   // schon registriert
            }
        }
        $hooks = array_values(array_filter($hooks, fn ($h) => ($h['Hook'] ?? '') !== $Hook));
        $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookID);
    }

    public function ProcessHookData()
    {
        $action = $_GET['action'] ?? '';

        if ($action === 'status') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildStatusData());
            return;
        }
        if ($action === 'history') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildHistoryData());
            return;
        }
        if ($action === 'ack') {
            ob_start();
            $this->AcknowledgeLock();
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }

        // Dashboard ausliefern
        $html = @file_get_contents(__DIR__ . '/dashboard.html');
        if ($html === false) {
            http_response_code(404);
            echo 'dashboard.html fehlt';
            return;
        }
        $html = str_replace(
            ['__HOOK__', '__TITLE__', '__SUBTITLE__'],
            [
                '/hook/poolskimmer' . $this->InstanceID,
                htmlspecialchars($this->ReadPropertyString('DashboardTitle')),
                htmlspecialchars($this->ReadPropertyString('DashboardSubtitle')),
            ],
            $html
        );
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    private function buildStatusData(): array
    {
        $d = json_decode($this->GetDashboardData(), true);
        if (!is_array($d)) {
            $d = [];
        }
        // Konfig-Kontext fuer die Anzeige
        $d['target_cm']    = $this->ReadPropertyFloat('TargetLevelCm');
        $d['tolerance_cm'] = $this->ReadPropertyFloat('ToleranceCm');
        $d['pool_area']    = $this->ReadPropertyFloat('PoolArea');
        $d['max_run_min']  = $this->ReadPropertyInteger('MaxRunMin');
        $d['budget_min']   = $this->ReadPropertyInteger('DailyBudgetMin');
        $d['auto_refill']  = $this->ReadPropertyBoolean('AutoRefill');
        return $d;
    }

    private function buildHistoryData(): array
    {
        $archList = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archList) === 0) {
            return [];
        }
        $arch = $archList[0];
        $out = [];
        $series = [
            'level' => 'WaterLevel',
            'batt'  => 'BatteryPct'
        ];
        foreach ($series as $key => $ident) {
            $vid = @$this->GetIDForIdent($ident);
            if ($vid === false || $vid <= 0 || !AC_GetLoggingStatus($arch, $vid)) {
                continue;
            }
            $vals = @AC_GetLoggedValues($arch, $vid, time() - 48 * 3600, time(), 400);
            if (!is_array($vals)) {
                continue;
            }
            $pts = [];
            foreach (array_reverse($vals) as $v) {
                $pts[] = [(int)$v['TimeStamp'], round((float)$v['Value'], 1)];
            }
            $out[$key] = $pts;
        }
        return $out;
    }
}
