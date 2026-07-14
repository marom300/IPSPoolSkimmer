# IPSPoolSkimmer

IP-Symcon-Modul für den batteriebetriebenen Pool-Füllstandssensor
(ESP32 Lolin D32 + VL53L0X, Deep-Sleep, MQTT). Gegenstück zur Firmware
`pool_sensor_firmware_v2.ino`.

## Funktionsprinzip

Der Sensor schläft fast immer (Deep-Sleep). Direkte Befehle sind daher nicht
möglich – stattdessen gilt das **Briefkasten-Prinzip**:

1. Das Modul schreibt die Konfiguration als **retained** MQTT-Message auf
   `<BaseTopic>/config`.
2. Der Sensor holt sie bei **jedem Aufwachen** ab (Messung oder Check-in),
   übernimmt Änderungen dauerhaft und bestätigt auf `<BaseTopic>/config_ack`.
3. One-Shot-Flags (`portal`, `ota`) setzt der Sensor nach Ausführung selbst
   zurück.

**Latenz:** Änderungen greifen erst beim nächsten Aufwachen – also nach
maximal `CheckinMin` Minuten (Standard 240).

## Installation

1. Ordner `IPSPoolSkimmer` in das Symcon-Modulverzeichnis kopieren
   (`.../IP-Symcon/modules/`) **oder** als eigenes Git-Repo über die
   Modulverwaltung (Module Control) einbinden.
2. IP-Symcon: Modulverwaltung aktualisieren.
3. **Instanz hinzufügen → "PoolSkimmerSensor"** – sie verbindet sich
   automatisch mit dem vorhandenen **MQTT Server** (Splitter).
4. In der Instanz das Basis-Topic prüfen (`pool/skimmer`) und den Messplan
   einstellen, dann **"Konfiguration an Sensor senden"**.

## Statusvariablen (werden automatisch angelegt)

| Variable | Quelle (Topic) |
|---|---|
| Füllstand (cm) | `<base>/json` |
| Akkuspannung (V), Akku (%) | `<base>/json`, `<base>/status` |
| Messwert veraltet | `<base>/json` (`stale`) |
| Zuletzt gesehen | `<base>/json`, `<base>/status` |
| WLAN-Signal (dBm), Firmware | `<base>/status` |
| Konfig-Bestätigung | `<base>/config_ack` |

## Buttons

- **Konfiguration an Sensor senden** – überträgt Messplan/Offset/etc. als
  retained Config.
- **Konfig-Portal öffnen** – beim nächsten Aufwachen öffnet der Sensor den
  WLAN-Hotspot `PoolSensor-Setup` (Passwort `pool1234`) für WLAN/Broker-IP.
- **OTA-Fenster öffnen** – beim nächsten Aufwachen bleibt der Sensor 5 min
  wach und ist in der Arduino IDE als Netzwerk-Port `pool-skimmer-sensor`
  flashbar (kein USB-Kabel nötig).

## Automatisches Nachfüllen (ab v1.1)

Prinzip: **dosieren statt regeln.** Das Modul berechnet aus Ziel-Pegel,
Pooloberfläche und Zuflussrate eine **zeitbegrenzte Portion** und startet sie
über ein Benutzer-Skript (Hunter-Zone für X Minuten). Der Hunter stoppt
hardwareseitig – es ist kein Stopp-Signal nötig (Totmann-Prinzip).

Sicherheitsebenen:
1. Hunter-Zeitlimit (Hardware)
2. Max. Minuten pro Portion
3. Tagesbudget (Minuten/Tag)
4. Frische- + Plausibilitätsprüfung des Messwerts (`stale`, Alter, Messband)
5. Erfolgskontrolle: steigt der Pegel nach einer Portion nicht wie erwartet
   → Status **GESPERRT** + Log-Warnung, bis manuell quittiert wird

**Start-Skript:** Ein kleines PHP-Skript, das die Hunter-Zone startet.
Das Modul ruft es mit `$_IPS['DURATION']` (Minuten) auf, z. B.:

```php
<?php
// Beispiel: Hydrawise-Zone "Pool" fuer $_IPS['DURATION'] Minuten starten
$minuten = (int)($_IPS['DURATION'] ?? 0);
if ($minuten <= 0) return;
// >>> hier deinen vorhandenen Zonen-Start einsetzen, z.B.:
// HYDRA_RunZone(12345, $minuten * 60);
IPS_LogMessage('PoolRefill', "Zone Pool fuer $minuten min gestartet");
```

**Kalibrierlauf:** Button im Formular. Startet eine Portion fester Länge und
berechnet aus dem Pegelanstieg bei der nächsten Messung die Zuflussrate
(Variable „Gemessene Zuflussrate") – den Wert dann als Property „Zuflussrate"
eintragen. Für schnelles Feedback den Sensor vorher in den Intervall-Modus
(z. B. 5 min) schalten.

## Hinweise

- Der Sensor öffnet das Konfig-Portal auch **automatisch**, wenn er den
  Broker 5× in Folge nicht erreicht (Rettungsanker gegen Aussperren).
- Config-Werte und Grenzen: `mode` (`daily`/`interval`), `wake_hour` 0–23,
  `wake_min` 0–59, `interval_min` 1–1440, `checkin_min` 0–1440
  (0 = kein Check-in), `offset_cm` ±100, `n_meas` 3–50.
