# LCNShutterRelay – IP-Symcon Modul

Rolladensteuerung über zwei LCN-Relaisausgänge für IP-Symcon ≥ 7.x

---

## Inhalt
1. [Funktionsübersicht](#funktionsübersicht)
2. [Verdrahtungsschema](#verdrahtungsschema)
3. [Unterschiede zu bestehenden Lösungen](#unterschiede-zu-bestehenden-lösungen)
4. [Installation](#installation)
5. [Konfiguration](#konfiguration)
6. [Einmessen der Fahrzeiten](#einmessen-der-fahrzeiten)
7. [Kalibrierung](#kalibrierung)
8. [Funktionsreferenz](#funktionsreferenz)
9. [Statusvariablen](#statusvariablen)
10. [Fehlersuche](#fehlersuche)

---

## Funktionsübersicht

| Feature | Beschreibung |
|---|---|
| Auf/Ab/Stopp | Vollständiges Öffnen, Schließen und Sofortstopp |
| Positionsfahrt | `MoveTo(50)` fährt exakt auf 50 % |
| Zeitbasierte Position | Positionsschätzung via Fahrtzeit – kein Encoder nötig |
| Gegenseitige Verriegelung | Motorschutz: nie beide Relais gleichzeitig aktiv |
| Richtungsumkehr | Einfaches Tauschen der Verdrahtung per Checkbox |
| Kalibrierung | Anfahren der Endlage, danach exakte Positionsreferenz |
| Webfront-Integration | Position-Variable ist direkt im Dashboard steuerbar |
| PHP 8 / IPSModuleStrict | Moderner Modulstandard, vollständig typisiert |

---

## Verdrahtungsschema

```
                     LCN-Modul (z.B. LCN-SHD, LCN-UPP)
                    ┌──────────────────────────────────┐
                    │                                  │
  Phaseleiter L ───►│ Relais 1 (NO)  ──────────────────┼──► Motor BRAUN (AUF)
                    │                                  │
  Phaseleiter L ───►│ Relais 2 (NO)  ──────────────────┼──► Motor SCHWARZ (AB)
                    │                                  │
  Neutral N ────────┼──────────────────────────────────┼──► Motor BLAU (N)
                    │                                  │
  Schutzleiter PE ──┼──────────────────────────────────┼──► Motor GELB/GRÜN (PE)
                    └──────────────────────────────────┘

  WICHTIG:
  ► Relais 1 und Relais 2 dürfen NIEMALS gleichzeitig geschlossen sein!
  ► Das Modul stellt dies durch Verriegelungslogik und 50 ms Pause sicher.
  ► Zusätzliche elektromechanische Verriegelung empfohlen!
```

### Belegung in IP-Symcon

Nach Anlegen des LCN-Moduls entstehen im Objektbaum boolean-Variablen für jeden Relaisausgang. Diese Variable-IDs werden in der Modulkonfiguration eingetragen:

```
Objektbaum
└── LCN
    └── LCN-Modul Adresse 5
        ├── Relais 1  ← Variable-ID notieren → "Relais AUF"
        └── Relais 2  ← Variable-ID notieren → "Relais AB"
```

---

## Unterschiede zu bestehenden Lösungen

| | ShutterControl (Legacy) | LCN_Shutter (nativ) | BlindControl | **LCNShutterRelay** |
|---|---|---|---|---|
| LCN-Relais nativ | Über separates Skript | Ja (veraltet) | Nein | **Ja, direkt** |
| Symcon ≥ 7 / IPSModuleStrict | Nein | Nein | Ja | **Ja** |
| Ohne Zusatzskript | Nein | Ja | Ja | **Ja** |
| Parametrierbar (ab IPS 4.2) | Eingeschränkt | Nicht mehr | Ja | **Ja** |
| Fahrtrichtungsumkehr | Über zwei Skripte | Nein | Nein | **Per Checkbox** |
| Manueller Stopp + Positionsberechnung | Nein | Nein | Nein | **Ja** |
| Kalibrierung | Nein | Nein | Nein | **Ja** |

---

## Installation

### 1. Über Module Control (empfohlen)

1. In IP-Symcon: **Kerninstanzen → Modules** öffnen
2. `+` klicken und folgende URL eintragen:
   ```
   https://github.com/community/LCNShutterRelay
   ```
3. **Instanz hinzufügen** (`Strg+1`) → **LCN Rollladen** auswählen

### 2. Manuell (ohne GitHub)

1. Den Ordner `LCNShutterRelay/` in das Symcon-Modulverzeichnis kopieren
   ```
   /var/lib/symcon/modules/LCNShutterRelay/
   ```
2. In Symcon: **Kerninstanzen → Modules → Aktualisieren**
3. **Instanz hinzufügen** → **LCN Rollladen**

---

## Konfiguration

| Feld | Typ | Beschreibung |
|---|---|---|
| **Relais AUF** | Variable-ID (Boolean) | Variable des LCN-Relaisausgangs für Aufwärtsfahrt |
| **Relais AB** | Variable-ID (Boolean) | Variable des LCN-Relaisausgangs für Abwärtsfahrt |
| **Fahrtzeit AUF** | Float (Sekunden) | Zeit für vollständige Aufwärtsfahrt (0→100 %) |
| **Fahrtzeit AB** | Float (Sekunden) | Zeit für vollständige Abwärtsfahrt (0→100 %) |
| **Sicherheitspause** | Integer (ms) | Pause zwischen Richtungswechsel (Standard: 100 ms) |
| **Fahrtrichtung umkehren** | Boolean | Aktivieren wenn Relais 1/2 vertauscht verdrahtet sind |

---

## Einmessen der Fahrzeiten

Die Positionsberechnung ist nur so genau wie die eingemessenen Fahrzeiten.

### Vorgehensweise

1. Rollladen vollständig in **Mittelposition** (ca. 50 %) fahren
2. Mit Stoppuhr die Zeit für **vollständige Aufwärtsfahrt** (Start → Endlage oben) messen
3. Ebenso für **vollständige Abwärtsfahrt**
4. Werte in der Konfiguration unter "Fahrtzeit AUF" / "Fahrtzeit AB" eintragen
5. **Kalibrierung** einmalig ausführen (s. nächster Abschnitt)

**Tipp:** Bei neueren Motoren mit Endlagenschalter die tatsächliche Motorlaufzeit messen, nicht die Laufzeit bis zum mechanischen Anschlag.

---

## Kalibrierung

Die Kalibrierungsfunktion referenziert die interne Positionsschätzung auf einen bekannten Wert (100 % = geschlossen).

### Ablauf

1. Das Modul fährt den Rollladen vollständig auf **untere Endlage** (+ 20 % Puffer)
2. Nach Abschluss wird die Position auf **100 %** gesetzt
3. Die Variable **Letzte Kalibrierung** wird mit dem aktuellen Zeitstempel versehen

### Wann kalibrieren?

- Nach Erstinstallation
- Nach Stromausfall oder manuellem Eingriff
- Wenn die angezeigte Position sichtbar von der realen Position abweicht

```php
// Per Skript kalibrieren:
LRS_Calibrate(12345 /*InstanceID*/);
```

---

## Funktionsreferenz

### `LRS_MoveUp(int $InstanceID): void`
Fährt den Rollladen vollständig auf (Position → 0 %).

```php
LRS_MoveUp(12345);
```

---

### `LRS_MoveDown(int $InstanceID): void`
Fährt den Rollladen vollständig zu (Position → 100 %).

```php
LRS_MoveDown(12345);
```

---

### `LRS_Stop(int $InstanceID): void`
Sofortstopp. Die aktuelle Position wird aus der bisherigen Fahrtzeit berechnet.

```php
LRS_Stop(12345);
```

---

### `LRS_MoveTo(int $InstanceID, int $position): void`
Fährt auf eine Zielposition. Wert 0–100, wobei 0 = offen und 100 = geschlossen.

```php
LRS_MoveTo(12345, 50);   // Halb geöffnet
LRS_MoveTo(12345, 0);    // Vollständig offen
LRS_MoveTo(12345, 100);  // Vollständig geschlossen
```

---

### `LRS_Calibrate(int $InstanceID): void`
Fährt auf untere Endlage und setzt Position = 100 %.

```php
LRS_Calibrate(12345);
```

---

### `LRS_GetPosition(int $InstanceID): int`
Gibt die aktuelle (geschätzte) Position zurück.

```php
$pos = LRS_GetPosition(12345);
echo "Position: $pos %";
```

---

## Statusvariablen

| Variable | Typ | Profil | Beschreibung |
|---|---|---|---|
| `Position` | Integer | LRS.Position (0–100) | Aktuelle Position: 0 = offen, 100 = geschlossen |
| `Direction` | Integer | LRS.Direction | 0 = Stopp, 1 = Auf, 2 = Ab |
| `Moving` | Boolean | ~Switch | `true` während einer Fahrt |
| `LastCalibration` | Integer | ~UnixTimestamp | Zeitpunkt der letzten Kalibrierung |

**Hinweis:** Die Variable `Position` ist mit `EnableAction` versehen – sie kann direkt im Webfront per Schieberegler gesteuert werden.

---

## Fehlersuche

### Modul-Status „Konfiguration unvollständig"
→ Beide Relais-Variablen müssen ausgewählt sein.

### Modul-Status „Relais AUF und AB sind identisch"
→ Es wurde dieselbe Variable für beide Relais eingetragen. Bitte prüfen.

### Modul-Status „Variable nicht gefunden"
→ Die eingetragene Variable-ID existiert nicht mehr. LCN-Modul prüfen, ggf. neue Variable auswählen.

### Rollladen fährt in falsche Richtung
→ Checkbox **Fahrtrichtung umkehren** aktivieren.

### Position stimmt nach einiger Zeit nicht mehr
→ Kalibrierung ausführen: `LRS_Calibrate($id)` oder Schaltfläche in der Konfiguration.

### Relais werden nicht geschaltet
→ In der IP-Symcon Konsole prüfen, ob `RequestAction($relayVarID, true)` die LCN-Variable korrekt setzt. Ggf. LCN-Verbindung und PCHK/LCN-PCHK-Konfiguration überprüfen.

---

## Lizenz

Dieses Modul steht für die nicht-kommerzielle Nutzung frei zur Verfügung.

---

## Changelog

### 1.0.0
- Erstveröffentlichung
- MoveUp, MoveDown, Stop, MoveTo, Calibrate, GetPosition
- Gegenseitige Relaisverriegelung
- Richtungsumkehr per Konfiguration
- Zeitbasierte Positionsschätzung mit Kalibrierungsfunktion
- IPSModuleStrict / PHP 8 kompatibel
