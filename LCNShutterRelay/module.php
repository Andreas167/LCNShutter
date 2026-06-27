<?php

/**
 * LCNShutterRelay – IP-Symcon Modul  v1.2
 *
 * Steuert einen Rollladen-/Jalousie-Motor ueber zwei LCN-Relaisausgaenge.
 * Ab v1.2: Erkennt externe Betaetigung ueber LCN-Taster via MessageSink und
 *          aktualisiert die Position entsprechend.
 *
 * Positionsquellen:
 *   - Modul-initiiert:  BeginMovement → StopTimer/StopAndRecalcPosition
 *   - Extern (LCN):     Relay-Variable aendert sich → MessageSink → Zeitberechnung
 *
 * Steuerungsreihenfolge bei MessageSink:
 *   LogicalDir != 0  → Modul steuert gerade, externe Aenderungen ignorieren
 *   LogicalDir == 0  → Relay-Aenderung ist extern → Position nachfuehren
 *
 * Verfuegbare Funktionen (Prefix LRS_):
 *   LRS_MoveUp($id), LRS_MoveDown($id), LRS_Stop($id)
 *   LRS_MoveTo($id, $pos), LRS_Calibrate($id), LRS_GetPosition($id)
 *
 * Prefix: LRS  |  Symcon >= 7.1  |  IPSModule  |  PHP 8.2
 */
class LCNShutterRelay extends IPSModule
{
    private const DIRECTION_STOP = 0;
    private const DIRECTION_UP   = 1;
    private const DIRECTION_DOWN = 2;

    private const PROFILE_POSITION  = 'LRS.Position';
    private const PROFILE_DIRECTION = 'LRS.Direction';

    private const STATUS_ACTIVE            = 102;
    private const STATUS_CONFIG_INCOMPLETE = 104;
    private const STATUS_SAME_RELAY        = 200;
    private const STATUS_VAR_NOT_FOUND     = 201;


    // ==========================================================================
    //  LIFECYCLE – DESTROY
    // ==========================================================================

    /**
     * Wird aufgerufen wenn die Instanz geloescht wird.
     * Bereinigt Profile, falls keine weitere LRS-Instanz mehr vorhanden ist.
     */
    public function Destroy()
    {
        // Profile nur entfernen wenn dies die letzte LRS-Instanz war
        $remaining = array_filter(
            IPS_GetInstanceListByModuleID('{7139D58D-5B64-4BE4-9640-C63BC851E8D6}'),
            fn(int $id) => $id !== $this->InstanceID
        );

        if (count($remaining) === 0) {
            foreach (['LRS.Position', 'LRS.Direction'] as $profile) {
                if (IPS_VariableProfileExists($profile)) {
                    IPS_DeleteVariableProfile($profile);
                }
            }
        }

        parent::Destroy();
    }

    // ==========================================================================
    //  LIFECYCLE
    // ==========================================================================

    public function Create()
    {
        parent::Create();

        // --- Konfigurationsparameter ---
        $this->RegisterPropertyInteger('RelayUpID',       0);
        $this->RegisterPropertyInteger('RelayDownID',     0);
        $this->RegisterPropertyFloat('TravelTimeUp',      30.0);
        $this->RegisterPropertyFloat('TravelTimeDown',    30.0);
        $this->RegisterPropertyInteger('SafetyDelayMS',   100);
        $this->RegisterPropertyBoolean('InvertDirection', false);

        // --- Modul-initiierte Fahrt ---
        $this->RegisterAttributeFloat('StartTime',         0.0);
        $this->RegisterAttributeInteger('StartPosition',   50);
        $this->RegisterAttributeInteger('LogicalDir',      self::DIRECTION_STOP);
        $this->RegisterAttributeInteger('TargetPosition',  50);

        // --- Extern (LCN) initiierte Fahrt ---
        $this->RegisterAttributeInteger('ExternalDir',   self::DIRECTION_STOP);
        $this->RegisterAttributeFloat('ExternalStart',   0.0);
        $this->RegisterAttributeInteger('ExternalPos',   50);

        // --- Statusvariablen ---
        $this->RegisterVariableInteger('Position',        'Position',            self::PROFILE_POSITION,  10);
        $this->EnableAction('Position');
        $this->RegisterVariableInteger('Direction',       'Fahrtrichtung',       self::PROFILE_DIRECTION, 20);
        $this->RegisterVariableBoolean('Moving',          'In Fahrt',            '~Switch',               30);
        $this->RegisterVariableInteger('LastCalibration', 'Letzte Kalibrierung', '~UnixTimestamp',        40);

        // --- Timer ---
        $id = $this->InstanceID;
        $this->RegisterTimer('StopTimer',        0, "LRS_StopTimer($id);");
        $this->RegisterTimer('CalibrationTimer', 0, "LRS_CalibrationTimer($id);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Auf IPS_KERNELSTARTED registrieren (Absicherung bei Systemstart)
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Beim Systemstart koennen andere Instanzen noch nicht verfuegbar sein.
        // Warten bis KR_READY, dann via MessageSink erneut aufrufen.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->EnsureProfiles();
        $this->RegisterRelayMessages();
        $this->RegisterReferences();  // F-4: Relay-Variablen als Referenzen registrieren
        $this->SetModuleSummary();
        $this->ValidateConfiguration();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    // ==========================================================================
    //  PUBLIC API
    // ==========================================================================

    public function MoveUp(): void   { $this->StartFullTravel(self::DIRECTION_UP); }
    public function MoveDown(): void { $this->StartFullTravel(self::DIRECTION_DOWN); }
    public function Stop(): void     { $this->StopAndRecalcPosition(); }

    public function MoveTo(int $position): void
    {
        $position = max(0, min(100, $position));
        $current  = $this->GetValue('Position');
        if ($current === $position) return;

        if ($position < $current) {
            $logicDir = self::DIRECTION_UP;
            $ms = (int) round($this->ReadPropertyFloat('TravelTimeUp')  * ($current - $position) / 100.0 * 1000);
        } else {
            $logicDir = self::DIRECTION_DOWN;
            $ms = (int) round($this->ReadPropertyFloat('TravelTimeDown') * ($position - $current) / 100.0 * 1000);
        }
        $this->BeginMovement($logicDir, $position, $ms);
    }

    public function Calibrate(): void
    {
        $this->LogMessage('LCNShutterRelay: Kalibrierung gestartet – fahre auf untere Endlage', KL_MESSAGE);

        $calibMs = (int) round($this->ReadPropertyFloat('TravelTimeDown') * 1.2 * 1000);
        $dir     = self::DIRECTION_DOWN;

        // Zustand VOR Relaisoperationen setzen (MessageSink ignoriert unsere Aenderungen)
        $this->WriteAttributeInteger('LogicalDir',     $dir);
        $this->WriteAttributeInteger('ExternalDir',   self::DIRECTION_STOP);
        $this->WriteAttributeFloat('StartTime',       microtime(true));
        $this->WriteAttributeInteger('StartPosition', $this->GetValue('Position'));
        $this->WriteAttributeInteger('TargetPosition', 100);

        $this->SetTimerInterval('StopTimer',        0);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->StopRelays();
        IPS_Sleep($this->ReadPropertyInteger('SafetyDelayMS'));

        $this->SetRelayForLogicalDirection($dir);
        $this->SetValue('Direction', $dir);
        $this->SetValue('Moving',    true);
        $this->SetTimerInterval('CalibrationTimer', $calibMs);
    }

    public function GetPosition(): int { return $this->GetValue('Position'); }

    // ==========================================================================
    //  TIMER-CALLBACKS
    // ==========================================================================

    public function StopTimer(): void
    {
        $target = $this->ReadAttributeInteger('TargetPosition');

        // Attrs VOR Relaisoperationen zuruecksetzen
        $this->WriteAttributeInteger('LogicalDir', self::DIRECTION_STOP);
        $this->SetTimerInterval('StopTimer', 0);
        $this->StopRelays();

        $this->SetValue('Position',  $target);
        $this->SetValue('Direction', self::DIRECTION_STOP);
        $this->SetValue('Moving',    false);
        $this->SetModuleSummary();
        $this->LogMessage(sprintf('LCNShutterRelay: Zielposition %d %% erreicht', $target), KL_DEBUG);
    }

    public function CalibrationTimer(): void
    {
        // Attr VOR Relaisoperationen zuruecksetzen
        $this->WriteAttributeInteger('LogicalDir', self::DIRECTION_STOP);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->StopRelays();

        $this->SetValue('Position',        100);
        $this->SetValue('Direction',       self::DIRECTION_STOP);
        $this->SetValue('Moving',          false);
        $this->SetValue('LastCalibration', time());
        $this->SetModuleSummary();
        $this->LogMessage('LCNShutterRelay: Kalibrierung abgeschlossen – Position = 100 %', KL_MESSAGE);
    }

    // ==========================================================================
    //  MESSAGESINK – erkennt externe LCN-Betaetigung
    // ==========================================================================

    /**
     * Wird aufgerufen wenn sich eine abonnierte Variable aendert (VM_UPDATE).
     *
     * Logik:
     *  1. Nur Relay-Variablen-Aenderungen interessieren uns
     *  2. Wenn das Modul gerade selbst steuert (LogicalDir != 0): ignorieren
     *  3. Sonst: externe Betaetigung erkannt → Start/Stopp tracken, Position berechnen
     *
     * Voraussetzung: LCN-Modul muss Relay-Status zurueckmelden (Standard in IP-Symcon LCN)
     */
    public function MessageSink($timeStamp, $senderID, $message, $data)
    {
        // Niemals diese Zeile loeschen!
        parent::MessageSink($timeStamp, $senderID, $message, $data);

        // Kernelstart: ApplyChanges erneut ausfuehren (jetzt sind alle Instanzen verfuegbar)
        if ($message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }

        if ($message !== VM_UPDATE) return;

        $upID = $this->ReadPropertyInteger('RelayUpID');
        $dnID = $this->ReadPropertyInteger('RelayDownID');

        if ($senderID !== $upID && $senderID !== $dnID) return;

        // Modul steuert gerade selbst → eigene Relay-Aenderungen ignorieren
        if ($this->ReadAttributeInteger('LogicalDir') !== self::DIRECTION_STOP) return;

        $upOn = (@IPS_VariableExists($upID)) ? (bool) GetValue($upID) : false;
        $dnOn = (@IPS_VariableExists($dnID)) ? (bool) GetValue($dnID) : false;

        $extDir = $this->ReadAttributeInteger('ExternalDir');

        if ($upOn && !$dnOn) {
            // ── Externe Aufwaertsfahrt gestartet ──────────────────────────
            if ($extDir !== self::DIRECTION_UP) {
                $this->WriteAttributeInteger('ExternalDir',   self::DIRECTION_UP);
                $this->WriteAttributeFloat('ExternalStart',   microtime(true));
                $this->WriteAttributeInteger('ExternalPos',   $this->GetValue('Position'));
                $this->SetValue('Direction', self::DIRECTION_UP);
                $this->SetValue('Moving',    true);
                $this->LogMessage('LCNShutterRelay: Externe Aufwaertsfahrt erkannt (LCN)', KL_MESSAGE);
            }

        } elseif (!$upOn && $dnOn) {
            // ── Externe Abwaertsfahrt gestartet ───────────────────────────
            if ($extDir !== self::DIRECTION_DOWN) {
                $this->WriteAttributeInteger('ExternalDir',   self::DIRECTION_DOWN);
                $this->WriteAttributeFloat('ExternalStart',   microtime(true));
                $this->WriteAttributeInteger('ExternalPos',   $this->GetValue('Position'));
                $this->SetValue('Direction', self::DIRECTION_DOWN);
                $this->SetValue('Moving',    true);
                $this->LogMessage('LCNShutterRelay: Externe Abwaertsfahrt erkannt (LCN)', KL_MESSAGE);
            }

        } elseif (!$upOn && !$dnOn && $extDir !== self::DIRECTION_STOP) {
            // ── Externe Fahrt gestoppt ────────────────────────────────────
            $elapsed  = microtime(true) - $this->ReadAttributeFloat('ExternalStart');
            $startPos = $this->ReadAttributeInteger('ExternalPos');
            $newPos   = $this->CalcPositionFromElapsed($elapsed, $extDir, $startPos);

            $this->WriteAttributeInteger('ExternalDir', self::DIRECTION_STOP);
            $this->SetValue('Position',  $newPos);
            $this->SetValue('Direction', self::DIRECTION_STOP);
            $this->SetValue('Moving',    false);

            $this->LogMessage(
                sprintf('LCNShutterRelay: Externe Fahrt beendet – neue Position: %d %%', $newPos),
                KL_MESSAGE
            );
        }
    }

    // ==========================================================================
    //  ACTION HANDLER
    // ==========================================================================

    public function RequestAction($ident, $value)
    {
        match ($ident) {
            'Position' => $this->MoveTo((int) $value),
            default     => throw new Exception('LCNShutterRelay RequestAction: Unbekannter Ident – ' . $ident),
        };
    }

    // ==========================================================================
    //  PRIVATE HELPERS
    // ==========================================================================

    private function StartFullTravel(int $logicDir): void
    {
        $current = $this->GetValue('Position');
        if ($logicDir === self::DIRECTION_UP) {
            $fraction = $current / 100.0;
            $target   = 0;
            $ms       = (int) round($this->ReadPropertyFloat('TravelTimeUp')   * $fraction * 1000);
        } else {
            $fraction = (100 - $current) / 100.0;
            $target   = 100;
            $ms       = (int) round($this->ReadPropertyFloat('TravelTimeDown') * $fraction * 1000);
        }
        if ($fraction <= 0.001) return;
        $this->BeginMovement($logicDir, $target, $ms);
    }

    /**
     * Zentrale Bewegungsinitiierung.
     * LogicalDir und ExternalDir werden VOR jeder Relaisoperation gesetzt,
     * damit MessageSink unsere eigenen Aenderungen korrekt ignoriert.
     */
    private function BeginMovement(int $logicDir, int $targetPosition, int $travelMs): void
    {
        if ($this->GetStatus() !== self::STATUS_ACTIVE) {
            $this->LogMessage('LCNShutterRelay: Modul nicht aktiv – Befehl ignoriert.', KL_WARNING);
            return;
        }

        // ── Zustand sofort sichern – VOR allen Relaisoperationen ──────────
        $this->WriteAttributeInteger('LogicalDir',     $logicDir);        // MessageSink ignoriert ab hier
        $this->WriteAttributeInteger('ExternalDir',   self::DIRECTION_STOP); // laufende ext. Fahrt abbrechen
        $this->WriteAttributeFloat('StartTime',       microtime(true));
        $this->WriteAttributeInteger('StartPosition', $this->GetValue('Position'));
        $this->WriteAttributeInteger('TargetPosition', $targetPosition);

        // ── Sicherheitsstopp ──────────────────────────────────────────────
        $this->SetTimerInterval('StopTimer',        0);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->StopRelays();

        $delay = $this->ReadPropertyInteger('SafetyDelayMS');
        if ($delay > 0) IPS_Sleep($delay);

        // ── Fahrtrichtung einschalten ─────────────────────────────────────
        $this->SetRelayForLogicalDirection($logicDir);
        $this->SetValue('Direction', $logicDir);
        $this->SetValue('Moving',    true);
        $this->SetTimerInterval('StopTimer', (int) round($travelMs * 1.1));

        $this->LogMessage(
            sprintf('LCNShutterRelay: Fahrt %s → %d %% (%.1f s)',
                $logicDir === self::DIRECTION_UP ? 'AUF' : 'AB',
                $targetPosition, $travelMs / 1000.0),
            KL_DEBUG
        );
    }

    /**
     * Manueller Stopp.
     * Cleared LogicalDir und ExternalDir VOR StopRelays, damit MessageSink
     * die eigenen Relay-OFF-Signale nicht als externe Fahrt wertet.
     */
    private function StopAndRecalcPosition(): void
    {
        $logicDir = $this->ReadAttributeInteger('LogicalDir');
        $extDir   = $this->ReadAttributeInteger('ExternalDir');

        // ── Zustand VOR Relaisoperationen zuruecksetzen ───────────────────
        $this->WriteAttributeInteger('LogicalDir',  self::DIRECTION_STOP);
        $this->WriteAttributeInteger('ExternalDir', self::DIRECTION_STOP);

        $this->SetTimerInterval('StopTimer',        0);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->StopRelays();

        $this->SetValue('Direction', self::DIRECTION_STOP);
        $this->SetValue('Moving',    false);

        // ── Position berechnen ────────────────────────────────────────────
        if ($logicDir !== self::DIRECTION_STOP) {
            // Modul-initiierte Fahrt gestoppt
            $elapsed  = microtime(true) - $this->ReadAttributeFloat('StartTime');
            $startPos = $this->ReadAttributeInteger('StartPosition');
            $newPos   = $this->CalcPositionFromElapsed($elapsed, $logicDir, $startPos);
            $this->SetValue('Position', $newPos);
            $this->LogMessage(
                sprintf('LCNShutterRelay: Stopp – berechnete Position: %d %%', $newPos), KL_DEBUG);

        } elseif ($extDir !== self::DIRECTION_STOP) {
            // Extern initiierte Fahrt manuell gestoppt (z.B. LRS_Stop() von Automation)
            $elapsed  = microtime(true) - $this->ReadAttributeFloat('ExternalStart');
            $startPos = $this->ReadAttributeInteger('ExternalPos');
            $newPos   = $this->CalcPositionFromElapsed($elapsed, $extDir, $startPos);
            $this->SetValue('Position', $newPos);
            $this->LogMessage(
                sprintf('LCNShutterRelay: Ext. Stopp – berechnete Position: %d %%', $newPos), KL_DEBUG);
        }
    }

    private function CalcPositionFromElapsed(float $elapsedSec, int $logicDir, int $startPos): int
    {
        if ($logicDir === self::DIRECTION_UP) {
            $newPos = $startPos - ($elapsedSec / $this->ReadPropertyFloat('TravelTimeUp'))   * 100.0;
        } else {
            $newPos = $startPos + ($elapsedSec / $this->ReadPropertyFloat('TravelTimeDown')) * 100.0;
        }
        return max(0, min(100, (int) round($newPos)));
    }

    private function StopRelays(): void
    {
        foreach (['RelayUpID', 'RelayDownID'] as $prop) {
            $varID = $this->ReadPropertyInteger($prop);
            if ($varID > 0 && @IPS_VariableExists($varID)) {
                try {
                    RequestAction($varID, false);
                } catch (Exception $e) {
                    $this->LogMessage("LCNShutterRelay StopRelays ($prop): " . $e->getMessage(), KL_ERROR);
                }
            }
        }
    }

    private function SetRelayForLogicalDirection(int $logicDir): void
    {
        $invert  = $this->ReadPropertyBoolean('InvertDirection');
        $physDir = ($logicDir === self::DIRECTION_UP)
            ? ($invert ? self::DIRECTION_DOWN : self::DIRECTION_UP)
            : ($invert ? self::DIRECTION_UP   : self::DIRECTION_DOWN);

        $upID   = $this->ReadPropertyInteger('RelayUpID');
        $downID = $this->ReadPropertyInteger('RelayDownID');

        try {
            if ($physDir === self::DIRECTION_UP) {
                if ($downID > 0 && @IPS_VariableExists($downID)) { RequestAction($downID, false); IPS_Sleep(50); }
                if ($upID   > 0 && @IPS_VariableExists($upID))   { RequestAction($upID,   true);  }
            } else {
                if ($upID   > 0 && @IPS_VariableExists($upID))   { RequestAction($upID,   false); IPS_Sleep(50); }
                if ($downID > 0 && @IPS_VariableExists($downID)) { RequestAction($downID, true);  }
            }
        } catch (Exception $e) {
            $this->LogMessage('LCNShutterRelay SetRelayForLogicalDirection: ' . $e->getMessage(), KL_ERROR);
        }
    }

    /**
     * Registriert die Relay-Variablen als Referenzen in Symcon.
     * Verhindert versehentliches Loeschen der LCN-Variablen (Symcon zeigt Abhaengigkeit).
     * Alte Referenzen werden zuerst abgemeldet, um bei ID-Aenderung sauber zu bleiben.
     */
    private function RegisterReferences(): void
    {
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        foreach (['RelayUpID', 'RelayDownID'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $this->RegisterReference($id);
            }
        }
    }

    /**
     * Setzt die Instanz-Zusammenfassung im Objektbaum (aktuelle Position).
     */
    private function SetModuleSummary(): void
    {
        $pos = $this->GetValue('Position');
        $this->SetSummary($pos . ' %');
    }

    /**
     * Abonniert VM_UPDATE fuer beide Relay-Variablen.
     * Wird bei jeder ApplyChanges-Konfigurationsaenderung aktualisiert.
     * Alte Subscriptions werden vorher via UnregisterMessage abgemeldet.
     */
    private function RegisterRelayMessages(): void
    {
        // Alte Subscriptions abmelden um nach ID-Aenderung sauber zu bleiben (E-2)
        foreach ($this->GetMessageList() as $senderID => $messages) {
            if (in_array(VM_UPDATE, $messages)) {
                $this->UnregisterMessage($senderID, VM_UPDATE);
            }
        }
        foreach (['RelayUpID', 'RelayDownID'] as $prop) {
            $varID = $this->ReadPropertyInteger($prop);
            if ($varID > 0 && @IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }
    }

    private function ValidateConfiguration(): void
    {
        $upID   = $this->ReadPropertyInteger('RelayUpID');
        $downID = $this->ReadPropertyInteger('RelayDownID');

        if ($upID === 0 || $downID === 0)                          { $this->SetStatus(self::STATUS_CONFIG_INCOMPLETE); return; }
        if ($upID === $downID)                                      { $this->SetStatus(self::STATUS_SAME_RELAY);        return; }
        if (!@IPS_VariableExists($upID) || !@IPS_VariableExists($downID)) { $this->SetStatus(self::STATUS_VAR_NOT_FOUND);     return; }
        $this->SetStatus(self::STATUS_ACTIVE);
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_POSITION)) {
            IPS_CreateVariableProfile(self::PROFILE_POSITION, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon(self::PROFILE_POSITION, 'Jalousie');
            IPS_SetVariableProfileValues(self::PROFILE_POSITION, 0, 100, 5);
            IPS_SetVariableProfileText(self::PROFILE_POSITION, '', ' %');
        }
        if (!IPS_VariableProfileExists(self::PROFILE_DIRECTION)) {
            IPS_CreateVariableProfile(self::PROFILE_DIRECTION, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues(self::PROFILE_DIRECTION, 0, 2, 1);
            IPS_SetVariableProfileAssociation(self::PROFILE_DIRECTION, 0, 'Stopp', '',      0x888888);
            IPS_SetVariableProfileAssociation(self::PROFILE_DIRECTION, 1, 'Auf',   'Arrow', 0x00BB00);
            IPS_SetVariableProfileAssociation(self::PROFILE_DIRECTION, 2, 'Ab',    'Arrow', 0xFF6600);
        }
    }
}
