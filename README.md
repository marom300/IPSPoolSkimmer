# IPSPoolSkimmer

IP-Symcon-Modul für den batteriebetriebenen Pool-Füllstandssensor
(ESP32 Lolin D32 + VL53L0X ToF-Sensor, Deep-Sleep, MQTT). Gegenstück zur
Firmware `pool_sensor_firmware_v2.ino`.

Das Modul empfängt die Messwerte des Sensors, stellt sie als Variablen bereit,
konfiguriert den Sensor per MQTT fern und steuert eine **automatische,
mehrfach abgesicherte Nachfüllung** über den Bewässerungscomputer (Hunter /
Hydrawise).

---

## Funktionsprinzip

Der Sensor schläft fast immer (Deep-Sleep, ~0,07 mA). Direkte Befehle sind
daher nicht möglich – stattdessen gilt das **Briefkasten-Prinzip**:

1. Das Modul schreibt die komplette Sensor-Konfiguration als **retained**
   MQTT-Nachricht auf `<BaseTopic>/config`.
2. Der Sensor holt sie bei **jedem Aufwachen** ab, übernimmt Änderungen
   dauerhaft (in seinen internen Speicher) und bestätigt auf
   `<BaseTopic>/config_ack`.
3. One-Shot-Aktionen (Portal, OTA) setzt der Sensor nach Ausführung selbst
   zurück.

**Wichtig:** Konfigurationsänderungen greifen erst beim nächsten Aufwachen –
also nach maximal `Config-Check-in` Minuten.

---

## Installation

1. Repo als Modul einbinden: IP-Symcon → **Modulverwaltung** → Repository-URL
   `https://github.com/marom300/IPSPoolSkimmer` hinzufügen.
2. **Instanz hinzufügen → „PoolSkimmerSensor"**. Im Schnittstellendialog den
   vorhandenen **MQTT Server** wählen (der als Broker für den Sensor dient).
3. Basis-Topic prüfen, Messplan einstellen, **„Konfiguration an Sensor senden"**.

Voraussetzung: Ein **MQTT-Server**-Instanz (IP-Symcon als Broker), auf dem
Port, den auch der Sensor anspricht (Standard im Projekt: 1889).

---

## Parameter im Detail

### Basis

| Parameter | Standard | Beschreibung |
|---|---|---|
| **Basis-Topic** | `pool/skimmer` | Wurzel aller MQTT-Topics. Muss **exakt** mit dem Wert in der Sensor-Firmware übereinstimmen. Alle Topics hängen darunter (`.../json`, `.../status`, `.../config` …). Ändern nur, wenn du es auch in der Firmware änderst. |

### Messplan (Sensor) — wird per „Konfiguration an Sensor senden" übertragen

| Parameter | Standard | Beschreibung |
|---|---|---|
| **Modus** | Täglich | **Täglich:** genau eine Messung pro Tag zur eingestellten Uhrzeit (maximale Akkulaufzeit). **Intervall:** Messung alle X Minuten (für Einrichtung/Beobachtung, deutlich höherer Verbrauch). |
| **Mess-Stunde** / **Mess-Minute** | 21 / 0 | Uhrzeit der Tagesmessung (nur im Modus „Täglich"). Die Zeit wird bei jedem Aufwachen per NTP frisch geholt, daher kein Uhr-Drift. |
| **Mess-Intervall (Minuten)** | 30 | Abstand zwischen Messungen im Modus „Intervall". Bereich 1–1440. |
| **Config-Check-in alle X Minuten** | 240 | Zusätzlich zur Messung wacht der Sensor in diesem Abstand **kurz** auf (nur ~5 s), um die Konfiguration abzuholen und den Status (Akku, RSSI) zu melden – **ohne** volle Messung. Bestimmt, wie schnell Konfig-Änderungen ankommen. `0` = keine Check-ins (Konfig kommt dann nur beim regulären Messtermin an, im Tagesmodus also erst nach bis zu 24 h). Empfehlung: Einrichtphase 1–5, Produktiv 120–240. |
| **Kalibrier-Offset (cm)** | 0,0 | Fester Korrekturwert, der auf jeden Messwert addiert wird. Damit gleichst du z. B. einen konstanten Versatz durch Montage oder Messung durch ein Fenster aus. Bereich ±100. |
| **Einzelmessungen pro Zyklus (Median)** | 10 | Wie viele Einzelmessungen der Sensor pro Aufwachen nimmt. Aus diesen wird der **Median** gebildet (robust gegen Ausreißer durch Wellen/Reflexionen). Mehr = stabiler, aber minimal länger wach. Bereich 3–50. |
| **Sende-Retry-Basis (Minuten)** | 5 | Verhalten bei fehlgeschlagenem Senden (WLAN/MQTT weg). Statt bis zum nächsten Plantermin zu warten, misst der Sensor nach **1×/2×/3× … dieser Basis** erneut (Backoff, gedeckelt auf 60 min Abstand). Beispiel Basis 5: erneuter Versuch nach 5, dann 10, dann 15 … min. Beim ersten Erfolg zurück in den Normalplan. `0` = kein Retry (Plan strikt abwarten). Verhindert, dass ein verpasster 21:00-Messwert erst am Folgetag nachkommt. |

### Automatisches Nachfüllen

| Parameter | Standard | Beschreibung |
|---|---|---|
| **Automatisches Nachfüllen aktiv** | aus | Hauptschalter. Solange aus, misst und meldet das Modul nur, füllt aber **nie** nach. Erst einschalten, wenn Ziel-Abstand und Zuflussrate korrekt eingestellt und getestet sind. |
| **Auffüll-Modus** | ein | Bei großem Rückstand (mehr als eine Portion nötig) taktet der Sensor vorübergehend im **Minutentakt** (auch während die Portion läuft – Pegel live sichtbar), bis der Zielpegel erreicht ist. Aus = klassisch eine Portion pro Messtermin. Keine eigene Intervall-Einstellung nötig. |
| **Start-Skript** | – | PHP-Skript, das die Nachfüll-Zone am Bewässerungscomputer startet. Das Modul ruft es mit `$_IPS['DURATION']` (Laufzeit in Minuten) auf. Vorlage: `firmware/symcon_refill_start.php` bzw. Abschnitt „Start-Skript" unten. |
| **Wasseroberfläche (m²)** | 22,75 | Fläche der Wasseroberfläche. Daraus wird cm → Liter berechnet: 1 cm auf 1 m² = 10 l. Bei 22,75 m² also 227,5 l pro cm. **Nur die Oberfläche nötig** – Volumen/Tiefe sind irrelevant. |
| **Ziel-Abstand Sensor→Wasser (cm)** | 10 | Der Abstandswert, den der Sensor bei **vollem** Pool (Wunsch-Pegel) misst. Ist der gemessene Abstand größer, fehlt Wasser. Nach dem Einbau einmalig ablesen und eintragen. |
| **Toleranz (cm)** | 0,5 | Totband. Erst wenn mehr als diese Differenz fehlt, wird nachgefüllt – verhindert ständiges Nachdosieren bei minimalem Verdunsten/Messrauschen. |

### Sicherheit (Nachfüllen)

Diese Ebenen greifen ineinander; jede fängt Fehler der darüberliegenden ab.

| Parameter | Standard | Beschreibung |
|---|---|---|
| **Max. Minuten pro Portion** | 30 | Obergrenze für eine einzelne Nachfüllung, unabhängig von der berechneten Menge. Ein einzelner Fehlmesswert kann so nie eine Riesenmenge auslösen. Empfehlung an deinem Pool: 15 (≈ 3,3 cm pro Portion bei ~50 l/min). |
| **Tagesbudget (Minuten)** | 60 | Maximale Nachfüllzeit pro Kalendertag (Reset um Mitternacht). Schützt vor Endlos-Nachfüllen bei Leck oder klemmendem Ventil (Pegel steigt nicht → System würde sonst täglich weiterkippen). |
| **Max. Messwert-Alter (Minuten)** | 120 | Nachgefüllt wird nur mit einem **frischen** Messwert. Ist der Zeitstempel älter (z. B. nach Symcon-Neustart, wenn nur die retained Nachricht kommt, oder wenn der Sensor länger nicht sendet), passiert nichts. Schützt davor, auf Basis veralteter Daten zu handeln. |
| **Erfolgskontrolle: Mindest-Anteil** | 0,40 | Nach jeder Portion prüft das Modul bei der nächsten Messung, ob der Pegel um **mindestens diesen Anteil** des erwarteten Anstiegs gestiegen ist (0,40 = 40 %). Wenn nicht → Status **GESPERRT** + Warnung, bis manuell quittiert. Erkennt defektes Ventil, zugedrehten Zulauf, Sensor-Drift. Höher = strenger (mehr Fehlalarme), niedriger = toleranter. |
| **Plausibel ab / bis (cm)** | 2,0 / 12,0 | Gültiges Messband. Werte außerhalb (Sensor **ausgebaut**, blind, Fehlmessung, Gehäusereflexion) lösen keine Nachfüllung aus. **Bewusst eng halten** – etwa Ziel-Abstand + wenige cm: Ein auf dem Tisch liegender Sensor misst z. B. 15+ cm und würde sonst den Pool fluten. Auch im Dashboard (Einstellungen) verstellbar. |
| **Dauer Kalibrierlauf (Minuten)** | 10 | Laufzeit des Kalibrier-Laufs (siehe unten). 10 min bei ~50 l/min ≈ 2,2 cm Anstieg – gut messbar, unkritisch. |

### Dashboard-Verknüpfungen (optional)

Verlinke hier bestehende Variablen deiner Pool-/Violet-Steuerung. Das Modul
liefert sie zusammen mit den eigenen Werten gebündelt über
`PSK_GetDashboardData()` – so braucht das (kommende) Dashboard nur **einen**
Aufruf, egal woher die Werte stammen.

| Parameter | Zweck / Anzeige im Dashboard |
|---|---|
| **Wassertemperatur** | groß im Becken |
| **Pumpenstufe** | unter der Pumpe; Stufe > 0 startet die Fluss-Animation + drehendes Pumpenrad |
| **Pumpen-Drehzahl (RPM)** | optional neben der Stufe (Violet liefert i. d. R. nur die Stufen-RPM `PUMP_RPM_0..3`, keine Live-Drehzahl – kann leer bleiben) |
| **pH-Wert** / **Chlor (mg/l)** / **Redox** | Wasserchemie-Zeile im Becken |
| **Sondenanströmung (cm/s)** | beim Filter |
| **Tagesdosierung pH− / Chlor** | Zeile „Dosierung heute: …" im Becken |
| **Frischwasser-Ventil/Zone aktiv** | z. B. Zonenstatus der Hunter-Nachfüllzone: lässt den Frischwasser-Strang auch bei **manueller** Bewässerung grün fließen. Für **modulgesteuerte** Portionen ist diese Verknüpfung nicht nötig – da nutzt das Dashboard eine interne, **sekundengenaue Ventil-Uhr** (Startzeit + beauftragte Dauer, ohne Cloud-Latenz). Achtung: Der Hydrawise-Zonenstatus kommt über Hunters Cloud und hinkt mehrere Minuten hinterher – für eine schnelle Anzeige manueller Läufe besser eine **lokale Rückmeldung** verlinken (z. B. 24-V-Koppelrelais am Ventilkreis auf einen Shelly-Eingang). |
| **Zusatzwert 1 / 2** | frei belegbar |

---

## Buttons (Aktionen)

| Button | Wirkung |
|---|---|
| **Konfiguration an Sensor senden** | Schreibt alle Messplan-Parameter als retained Config. Der Sensor übernimmt sie beim nächsten Aufwachen. **Nach jeder Änderung im Messplan nötig.** |
| **Konfig-Portal öffnen (nächstes Aufwachen)** | Setzt das `portal`-Flag. Beim nächsten Aufwachen öffnet der Sensor den WLAN-Hotspot `PoolSensor-Setup` (Passwort `pool1234`) für WLAN-/Broker-Einstellungen per Handy. |
| **OTA-Fenster öffnen (nächstes Aufwachen)** | Setzt das `ota`-Flag. Beim nächsten Aufwachen bleibt der Sensor 5 min wach und ist in der Arduino IDE als Netzwerk-Port `pool-skimmer-sensor` flashbar (Firmware-Update über WLAN, kein USB nötig). |
| **Kalibrierlauf starten** | Startet eine Portion fester Länge (siehe „Dauer Kalibrierlauf") und berechnet aus dem Pegelanstieg bei der nächsten Messung die tatsächliche Zuflussrate → Variable „Gemessene Zuflussrate". Diesen Wert dann als Parameter „Zuflussrate" eintragen. Tipp: Sensor vorher in den Intervall-Modus (z. B. 5 min) schalten, damit die Kontrollmessung schnell kommt. |
| **Nachfüll-Sperre quittieren** | Hebt den Status GESPERRT wieder auf (nach behobenem Ventil-/Zulauf-/Sensorproblem). |

---

## Statusvariablen (werden automatisch angelegt & archiviert)

| Variable | Quelle | Bedeutung |
|---|---|---|
| **Füllstand (Abstand)** | `<base>/json` | Gemessener Abstand Sensor→Wasser in cm (inkl. Offset). Größer = weniger Wasser. |
| **Akkuspannung** / **Akku** | `<base>/json`, `<base>/status` | LiPo-Spannung (V) und grober Ladezustand (%). |
| **Messwert veraltet** | `<base>/json` (`stale`) | Alarm-Flag: Sensor konnte nicht gültig messen, letzter guter Wert wird weitergemeldet. |
| **Zuletzt gesehen** | `<base>/json`, `<base>/status` | Zeitstempel der letzten Sensor-Meldung. |
| **Nächster Kontakt / Nächste Messung** *(nur Dashboard)* | berechnet | Nächste Termine („in 47 min · 00:09"), berechnet aus der **vom Sensor bestätigten** Konfiguration (`config_ack`) – nicht aus den Modul-Properties, denn eine frisch geänderte Einstellung kennt der Sensor erst nach dem nächsten Aufwachen. Färbt sich **orange**, wenn der Termin samt Kulanz (halbes Intervall, mind. 3 min; im Tagesmodus 15 min) überschritten ist – so fällt ein verstummter Sensor sofort auf. |
| **Konfiguration** *(nur Dashboard, nur bei Bedarf)* | berechnet | Erscheint, solange eine geänderte Einstellung noch nicht vom Sensor übernommen wurde („wird beim nächsten Aufwachen übernommen"). |
| **WLAN-Signal** | `<base>/status` | RSSI in dBm (ab −80 wird's grenzwertig). |
| **Firmware** | `<base>/status` | Firmware-Version des Sensors. |
| **Konfig-Bestätigung (Sensor)** | `<base>/config_ack` | Die Konfiguration, die der Sensor tatsächlich übernommen hat (Kontrolle, ob „senden" angekommen ist). |
| **Fehlender Pegel** / **Fehlmenge** | berechnet | Wie viel cm bzw. Liter bis zum Ziel-Abstand fehlen. |
| **Nachfüll-Status** | berechnet | Automatik aus / Bereit / Portion läuft / Warte auf Kontrolle / GESPERRT / Tagesbudget erreicht. |
| **Auffüll-Modus aktiv** | berechnet | Zeigt (und loggt) an, ob der Sensor gerade im engen Auffüll-Takt läuft (Normal / Auffüllen läuft). |
| **Nachfüllzeit heute** | berechnet | Bereits heute verbrauchte Nachfüllminuten (gegen Tagesbudget). |
| **Letzte Nachfüllung** | berechnet | Zeitstempel der letzten gestarteten Portion. |
| **Zuflussrate (kalibriert)** | Kalibrierlauf | Vom Kalibrierlauf ermittelte Füllrate in l/min. Diesen Wert nutzt die Nachfüll-Logik direkt – ist er 0, wird nicht nachgefüllt (erst kalibrieren). |
| **Letzte Kalibrierung** | Kalibrierlauf | Zeitstempel des letzten erfolgreichen Kalibrierlaufs. |
| **Nachfüll-Protokoll** | berechnet | Letzte Aktion/Meldung der Nachfüll-Logik im Klartext. |

Das Logging ins Archiv aktiviert das Modul beim Übernehmen der Einstellungen
selbst für die auswertbaren Zahlen-/Status-Variablen (Füllstand, Akku V/%,
fehlender Pegel/Menge, Nachfüllzeit, RSSI, Nachfüll-Status, Stale, Zuflussrate,
Auffüll-Modus aktiv).
Bewusst **nicht** geloggt werden Text-Variablen (Firmware, Konfig-Bestätigung,
Nachfüll-Protokoll) und reine Zeitstempel (zuletzt gesehen, letzte Nachfüllung,
letzte Kalibrierung) – die ergeben als Archiv-Kurve keinen Sinn. Bestehende
Logging-Einstellungen bleiben unangetastet.

---

## Nachfüllen: Prinzip & Sicherheit

**Dosieren statt regeln.** Das Modul berechnet aus Ziel-Abstand,
Pooloberfläche und Zuflussrate eine **zeitbegrenzte Portion** und startet sie
über das Start-Skript. Der Bewässerungscomputer stoppt die Zone
**hardwareseitig nach Ablauf** – es ist kein Stopp-Signal nötig
(Totmann-Prinzip). Fällt WLAN, Symcon oder der Sensor aus, kann nichts
„weiterlaufen".

Mehrlagige Absicherung (von Hardware nach Software):
1. **Zeitlimit des Bewässerungscomputers** (Hardware)
2. **Max. Minuten pro Portion**
3. **Tagesbudget**
4. **Frische- + Plausibilitätsprüfung** des Messwerts
5. **Erfolgskontrolle** nach jeder Portion → Sperre bei Abweichung

### Auffüll-Modus (automatisch)

Sobald **irgendeine** Portion startet (automatisch, manuell oder Kalibrierlauf),
schaltet das Modul den Sensor **selbst** vorübergehend auf ein enges
Mess-Intervall (**Minutentakt**). So ist der Pegel-Fortschritt auch
**während** der laufenden Portion live sichtbar, und nach Portionsende +
5 min Beruhigung startet die Folgeportion zügig – bis der Zielpegel erreicht
ist, statt tagelang auf den nächsten Tagestermin zu warten. Danach stellt er
automatisch auf den normalen Messplan zurück (Akku schonen) – auch bei
abgeschalteter Automatik, nach einer Sperre, bei erschöpftem Tagesbudget und
nach dem Kalibrierlauf. Die Nachfüll-*Entscheidung* wartet immer Portionsende +
5 min ab (Erfolgskontrolle); die Zwischenmessungen dienen Anzeige, Archiv und
Protokoll.

Damit die Umstellung **sofort** greift und nicht erst am nächsten Tag, holt der
Sensor direkt nach dem Senden der Messung nochmal die Config ab (Firmware
≥ 2.0.6). Der Auffüll-Modus endet auch bei erschöpftem Tagesbudget oder einer
Sperre und läuft am Folgetag (bzw. nach Quittieren) weiter. Beispiel an diesem
Pool (22,75 m², ~50 l/min, Budget 40 min): 5 cm fehlen → ~1 h, zwei Portionen,
noch am selben Abend voll.

### Start-Skript

Kleines PHP-Skript, das die Nachfüll-Zone startet. Das Modul übergibt
`$_IPS['DURATION']` (Minuten). Beispiel (Hydrawise-Zone „Z4 – Pool" über das
demel42-Modul), Vorlage: `firmware/symcon_refill_start.php`:

```php
<?php
$zoneInstanzID = 0;   // ID der HydrawiseZone-Instanz "Z4 - Pool" eintragen
$minuten = (int)($_IPS['DURATION'] ?? 0);
if ($minuten <= 0 || $zoneInstanzID <= 0) return;
$aktionID = @IPS_GetObjectIDByIdent('ZoneAction', $zoneInstanzID);
if ($aktionID !== false) {
    RequestAction($aktionID, $minuten);   // >0 startet Zone; ggf. *60 falls Sekunden
}
```

### Kalibrierlauf (vollautomatisch)

Du musst die Zuflussrate **nicht selbst messen**. Ablauf:

1. Sensor in den **Intervall-Modus** (z. B. 5 min) stellen und senden – damit die
   Kontrollmessung nach dem Lauf zeitnah kommt (im Täglich-Modus käme sie erst
   am nächsten Messtermin). Das Modul weist beim Start darauf hin.
2. Button **„Kalibrierlauf starten"**. Das Modul merkt sich den aktuellen
   Abstand und startet die Zone für die eingestellte Dauer.
3. Nach Ablauf misst der Sensor erneut. Das Modul rechnet aus dem Pegelanstieg
   die tatsächliche Zuflussrate:

   **l/min = Pegelanstieg [cm] × Wasseroberfläche [m²] × 10 ÷ Laufminuten**

4. Das Ergebnis landet **automatisch** in der Statusvariable „Zuflussrate
   (kalibriert)" – die die Nachfüll-Logik direkt nutzt. Es gibt **kein**
   Eingabefeld für die Zuflussrate; du musst nichts von Hand messen oder
   eintragen. Steigt der Pegel nicht plausibel (< 0,5 l/min hochgerechnet),
   bleibt die Rate unverändert und es kommt eine Warnung.

Solange nie kalibriert wurde (Rate = 0), füllt das Modul **nicht** nach,
sondern meldet „bitte Kalibrierlauf starten".

Es wird **nur die Wasseroberfläche** benötigt – Volumen/Tiefe des Beckens
spielen keine Rolle, weil nur zählt, wie schnell der Pegel oben steigt.

---

## Einrichtungs-Reihenfolge (empfohlen)

1. Sensor im Skimmer montieren, WLAN/Empfang (RSSI) prüfen.
2. **Ziel-Abstand** bei vollem Pool ablesen und eintragen.
3. **Start-Skript** anlegen (Zonen-ID eintragen) und im Modul auswählen.
4. **Kalibrierlauf** starten → Zuflussrate wird automatisch ermittelt.
5. Plausibilitätsband an den realen Messbereich anpassen.
6. Messplan auf Produktiv (z. B. Täglich 21:00, Check-in 240) senden.
7. **Automatisches Nachfüllen aktivieren.**

---

## Dashboard (ab v2.0)

Das Modul liefert ein fertiges **Pool-Dashboard** selbst aus (gleiches
Design-System wie das StiebelWPM-Dashboard: Glas auf Anthrazit, Cyan-Glow).
Oben rechts schaltest du zwischen drei Ansichten um – direkt verlinkbar über
`?view=ov` / `?view=log` / `?view=cfg`:

| Ansicht | Inhalt |
|---|---|
| **Übersicht** | Anlagenschema, Nachfüll-Karte mit Steuerung, Sensor-Karte, Trends |
| **Protokoll** | vollständiges Vorgangs-Protokoll, scrollbar (Warnungen orange) |
| **Einstellungen** | alle laufenden Parameter direkt verstellbar + „zuletzt kalibriert" |

**Übersicht** enthält:

- **Anlagenschema** mit animierten Wasserflüssen: Becken (Wassertemperatur,
  pH, Redox) → Skimmer mit Sensor (Abstand, Messstrahl) → Pumpe (dreht bei
  laufender Pumpe, Stufe/RPM) → Filter → Rücklauf/Düsen. Der
  **Frischwasser-Strang** leuchtet grün, solange eine Nachfüll-Portion läuft.
- **Nachfüll-Karte:** Status, Automatik, Auffüll-Modus, Tagesbudget, letzte
  Portion, Zuflussrate, Ziel-Abstand, Protokollzeile – plus Button
  „Sperre quittieren" (erscheint nur bei Status GESPERRT).
- **Sensor-Karte:** Akku, WLAN, Messwert-Gültigkeit, zuletzt gesehen, Firmware.
- **Trends:** Sparklines der letzten 48 h (Füllstand, Akku) aus dem Archiv.

**Aufruf:** `http://<Symcon-IP>:3777/hook/poolskimmer<InstanzID>` – direkt im
Browser oder als URL im IPSView-WebView. Der Hook wird beim Übernehmen der
Instanz-Einstellungen automatisch registriert. Titel/Untertitel sind im
Formular einstellbar.

Endpoints des Hooks: ohne Parameter = HTML (optional `?view=ov|log|cfg`);
`?action=status` = Live-JSON (inkl. verknüpfter Violet-Werte und aller
Konfigwerte); `?action=history` = 48-h-Archivdaten; `?action=log` =
Vorgangs-Protokoll; `?action=cmd` (POST, JSON `{cmd,value,key,pin}`) =
Steuerung (`auto`, `portion`, `stop`, `calib`, `ack`, `cfg`). Lokaler Test der
HTML-Datei ohne Symcon: im Browser mit `?mock` öffnen (bzw. per `file://` –
dann laufen Beispieldaten).

Die Werte aus der Violet-Steuerung kommen über die **Dashboard-Verknüpfungen**
ins Schema – nicht verknüpfte Werte werden einfach ausgeblendet.

### Steuerung im Dashboard (mit PIN)

In der Nachfüll-Karte gibt es Steuer-Buttons:

| Button | Wirkung |
|---|---|
| **Automatik EIN/AUS** | schaltet „Automatisches Nachfüllen" um (grün = aktiv) |
| **Portion … / ■ Stoppen** | startet eine manuelle Portion (Auswahl 5/10/Max min; gekappt auf Max-Portion und Tagesbudget). **Läuft gerade eine Portion oder ein Kalibrierlauf, wird der Button zum roten Stopp-Button** – er stoppt die Hunter-Zone sofort (Skript wird mit `DURATION = -1` aufgerufen) und setzt den Vorgang zurück. |
| **Kalibrieren** | startet den Kalibrierlauf – mit **Sicherheitsabfrage** („Jetzt X Minuten füllen …?") vor dem Start |
| **Sperre quittieren** | erscheint nur bei Status GESPERRT |

**Wichtig für den Stopp-Button:** Das Start-Skript muss den Stopp-Fall kennen –
bei `DURATION <= 0` die Zone stoppen (`RequestAction($aktionID, -1)`), siehe
aktualisierte Vorlage `symcon_refill_start.php`.

### Einstellungen-Ansicht

Alle im Betrieb relevanten Parameter lassen sich **direkt im Dashboard**
verstellen (PIN-geschützt) – ohne Symcon-Konsole:

- **Messplan:** Modus (Täglich/Intervall), Mess-Stunde/-Minute,
  Mess-Intervall, Config-Check-in, Kalibrier-Offset, Einzelmessungen,
  Sende-Retry-Basis. Änderungen gehen **sofort an den Sensor** (Briefkasten).
- **Nachfüllen:** Automatik, Auffüll-Modus, Ziel-Abstand, Toleranz,
  Max. pro Portion, Tagesbudget, Dauer Kalibrierlauf, Plausibilitätsband.
  Oben rechts steht **„zuletzt kalibriert: … · x l/min"**.

Bedienung: **+/−-Tasten** oder **Mausrad über dem Wert** (Rad-Ticks werden
gesammelt und ~0,6 s nach dem letzten Tick gebündelt gesendet – die Anzeige
folgt sofort). Das Scrollen der Listen (Protokoll, Einstellungen) erledigt das
Dashboard selbst per eigenem Wheel-Handler – in eingebetteten Ansichten
(IPSView-WebView, iframe im Violet-Portal) kommt das native Scrolling
verschachtelter Container sonst teilweise nicht an. Ein **„Konfiguration an Sensor senden" ist nicht nötig**: Jede
Messplan-Änderung landet automatisch im Briefkasten und wird beim nächsten
Aufwachen übernommen (spätestens nach `Config-Check-in` Minuten).

Jede Änderung wird mit altem Kontext ins Protokoll geschrieben. In der
Symcon-Konsole bleiben nur die einmaligen Grundeinstellungen
(Pooloberfläche, Start-Skript, Plausibilitätsband, PIN, Verknüpfungen).

### Vorgangs-Protokoll

**Einzelne Messungen werden bewusst NICHT protokolliert** – die liegen als
Zeitreihe im Archiv der Variable „Füllstand" (Trend im Dashboard). Ausnahme:
Während einer **laufenden Portion** wird jede Messung mitgeschrieben
(„… Füllung läuft: 4,8 cm (Ziel 3,6 cm, noch 1,2 cm)"), damit ein Füllvorgang
lückenlos rekonstruierbar ist. Die Start-Meldung nennt außerdem den
auslösenden Messwert, das Ziel und die Fehlmenge.

Jeder Vorgang (Portion gestartet/gestoppt, Kalibrierlauf + Ergebnis,
Erfolgskontrolle, Sperre/Quittierung, Budget erschöpft, Auffüll-Modus
ein/aus, **Automatik ein/aus, jede Parameteränderung mit neuem Wert**,
übersprungene Aktionen mit Grund) wird mit Zeitstempel in die Variable
**„Nachfüll-Protokoll"** geschrieben. Diese wird **archiviert** – die
komplette Historie ist jederzeit rekonstruierbar (Variable → „Archiv" in der
Konsole). Die Dashboard-Ansicht **Protokoll** zeigt die letzten ~300 Einträge
scrollbar (Warnungen orange); parallel läuft alles ins Symcon-Meldungsfenster
(`LogMessage`).

**PIN-Schutz** (wie beim StiebelWPL-Dashboard): Im Formular unter „Dashboard"
eine **PIN** setzen – dann verlangt jede Steuer-Aktion die PIN über ein
Nummernfeld (einmal pro Browser-Sitzung, wird danach gemerkt; falsche PIN
verwirft den Merker). Leere PIN = Steuerung ohne Abfrage. Die Prüfung läuft
serverseitig im Modul.

Anzeige-Verhalten:
- **Touch-Panels:** Ab 1000 px Breite füllt die Seite exakt den Viewport
  (kein Scrollen); bei niedriger Fensterhöhe (z. B. 1080p mit 150 %
  Windows-Skalierung) greift automatisch eine Kompaktstufe.
- **„Animationen reduzieren"** im Betriebssystem stoppt nur die
  Einblend-Effekte – die Fluss-/Pumpenanimation läuft weiter (funktionale
  Anzeige). Das Pumpenrad rotiert per SVG-SMIL exakt um sein Zentrum.

## Config-JSON (Referenz, `<base>/config`)

```json
{
  "mode": "daily",          // "daily" | "interval"
  "wake_hour": 21, "wake_min": 0,
  "interval_min": 30,       // nur Modus interval
  "checkin_min": 240,       // Config-Check alle X min (0 = aus)
  "offset_cm": 0.0,
  "n_meas": 10,
  "retry_min": 5,           // Sende-Retry-Basis (0 = aus)
  "portal": 0, "ota": 0     // One-Shot-Flags (Sensor setzt zurück)
}
```

## Akku-Schutz (Firmware ≥ 2.0.8)

Ein zu kurz eingestelltes Mess-Intervall leert den Akku sehr schnell
(1-Minuten-Takt ≈ 1440 Aufwachvorgänge/Tag ≈ **4–6 Tage** Laufzeit) – und ein
leerer Sensor ist weder per MQTT noch per OTA erreichbar. Deshalb:

- **Firmware:** Ab **≤ 20 %** Akku schläft der Sensor mindestens **60 min**, ab
  **≤ 8 %** mindestens **6 h** – unabhängig vom eingestellten Intervall. Die
  Konfiguration bleibt unverändert und greift nach dem Laden wieder.
- **Dashboard:** Die Einstellungen-Ansicht zeigt unter dem Messplan eine
  **Reichweiten-Abschätzung** („Akku 71 % · Aufwachen alle 30 min ≈ Reichweite
  … Tage") und warnt orange, wenn die Laufzeit unter 14 Tage fällt.

**Empfehlung:** Kurze Intervalle (1–5 min) nur zum Beobachten/Einrichten
verwenden, für den Dauerbetrieb **Täglich 21:00 + Check-in 240 min**. Geladen
wird über die USB-Buchse des Lolin D32 (Onboard-Ladeschaltung).

## Hinweise

- Der Sensor öffnet das Konfig-Portal auch **automatisch**, wenn er den Broker
  ~20× in Folge nicht erreicht (Rettungsanker gegen Aussperren; mit dem
  Retry-Backoff entspricht das mehreren Stunden echtem Ausfall).
- Wert­grenzen (Firmware): `mode` daily/interval, `wake_hour` 0–23,
  `wake_min` 0–59, `interval_min` 1–1440, `checkin_min` 0–1440,
  `offset_cm` ±100, `n_meas` 3–50, `retry_min` 0–60.
