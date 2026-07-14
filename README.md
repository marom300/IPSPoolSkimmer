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

## Hinweise

- Der Sensor öffnet das Konfig-Portal auch **automatisch**, wenn er den
  Broker 5× in Folge nicht erreicht (Rettungsanker gegen Aussperren).
- Config-Werte und Grenzen: `mode` (`daily`/`interval`), `wake_hour` 0–23,
  `wake_min` 0–59, `interval_min` 1–1440, `checkin_min` 0–1440
  (0 = kein Check-in), `offset_cm` ±100, `n_meas` 3–50.
