<?php

/**
 * LCNShutterRelay – IP-Symcon Modul  v2.0
 *
 * Steuert einen Rollladen-Motor ueber zwei LCN-Relaisausgaenge nach dem
 * Fahren+Richtung-Prinzip (klassische LCN-Schaltung):
 *
 *   Relais FAHREN   (RelayRunID)       ON  = Motor laeuft  / OFF = Motor gestoppt
 *   Relais RICHTUNG (RelayDirectionID) OFF = Richtung AUF  / ON  = Richtung AB
 *
 * Schaltsequenz (Motorschutz):
 *   AUF:   Fahren=OFF → Pause → Richtung=OFF → 50ms → Fahren=ON
 *   AB:    Fahren=OFF → Pause → Richtung=ON  → 50ms → Fahren=ON
 *   STOPP: Fahren=OFF  (Richtungsrelais bleibt unveraendert)
 *
 * Externe Betaetigung (LCN-Taster) wird via MessageSink erkannt:
 *   Fahren geht ON  → externe Fahrt gestartet, Richtung wird ausgewertet
 *   Fahren geht OFF → externe Fahrt beendet, Position wird berechnet
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
    //  LIFECYCLE
    // ==========================================================================

    public function Destroy()
    {
        $remaining = array_filter(
            IPS_GetInstanceListByModuleID('{7139D58D-5B64-4BE4-9640-C63BC851E8D6}'),
            fn($id) => $id !== $this->InstanceID
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

    public function Create()
    {
        parent::Create();

        // Profile zuerst anlegen (bevor RegisterVariable* sie referenziert)
        $this->EnsureProfiles();

        // --- Konfigurationsparameter ---
        $this->RegisterPropertyInteger('RelayRunID',       0);    // Relais FAHREN
        $this->RegisterPropertyInteger('RelayDirectionID', 0);    // Relais RICHTUNG
        $this->RegisterPropertyFloat('TravelTimeUp',       30.0);
        $this->RegisterPropertyFloat('TravelTimeDown',     30.0);
        $this->RegisterPropertyInteger('SafetyDelayMS',    100);
        $this->RegisterPropertyBoolean('InvertDirection',  false);

        // --- Modul-initiierte Fahrt ---
        $this->RegisterAttributeFloat('StartTime',         0.0);
        $this->RegisterAttributeInteger('StartPosition',   50);
        $this->RegisterAttributeInteger('LogicalDir',      self::DIRECTION_STOP);
        $this->RegisterAttributeInteger('TargetPosition',  50);

        // --- Extern (LCN-Taster) initiierte Fahrt ---
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

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->EnsureProfiles();
        $this->RegisterRelayMessages();
        $this->RegisterReferences();
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

    public function MoveUp()    { $this->StartFullTravel(self::DIRECTION_UP); }
    public function MoveDown()  { $this->StartFullTravel(self::DIRECTION_DOWN); }
    public function Stop()      { $this->StopAndRecalcPosition(); }

    public function MoveTo($position)
    {
        $position = max(0, min(100, (int)$position));
        $current  = $this->GetValue('Position');
        if ($current === $position) return;

        if ($position < $current) {
            $logicDir = self::DIRECTION_UP;
            $ms = (int) round($this->ReadPropertyFloat('TravelTimeUp')   * ($current - $position) / 100.0 * 1000);
        } else {
            $logicDir = self::DIRECTION_DOWN;
            $ms = (int) round($this->ReadPropertyFloat('TravelTimeDown') * ($position - $current) / 100.0 * 1000);
        }
        $this->BeginMovement($logicDir, $position, $ms);
    }

    public function Calibrate()
    {
        $this->LogMessage('LCNShutterRelay: Kalibrierung gestartet – fahre auf untere Endlage', KL_MESSAGE);
        $calibMs = (int) round($this->ReadPropertyFloat('TravelTimeDown') * 1.2 * 1000);

        $this->WriteAttributeInteger('LogicalDir',     self::DIRECTION_DOWN);
        $this->WriteAttributeInteger('ExternalDir',    self::DIRECTION_STOP);
        $this->WriteAttributeFloat('StartTime',        microtime(true));
        $this->WriteAttributeInteger('StartPosition',  $this->GetValue('Position'));
        $this->WriteAttributeInteger('TargetPosition', 100);

        $this->SetTimerInterval('StopTimer',        0);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->StopMotor();

        IPS_Sleep($this->ReadPropertyInteger('SafetyDelayMS'));
        $this->SetDirectionRelay(self::DIRECTION_DOWN);
        IPS_Sleep(50);
        $this->StartMotor();

        $this->SetValue('Direction', self::DIRECTION_DOWN);
        $this->SetValue('Moving',    true);
        $this->SetTimerInterval('CalibrationTimer', $calibMs);
    }

    public function GetPosition()
    {
        return $this->GetValue('Position');
    }

    // ==========================================================================
    //  TIMER-CALLBACKS
    // ==========================================================================

    public function StopTimer()
    {
        $target = $this->ReadAttributeInteger('TargetPosition');
        $this->WriteAttributeInteger('LogicalDir', self::DIRECTION_STOP);
        $this->SetTimerInterval('StopTimer', 0);
        $this->StopMotor();

        $this->SetValue('Position',  $target);
        $this->SetValue('Direction', self::DIRECTION_STOP);
        $this->SetValue('Moving',    false);
        $this->SetModuleSummary();
        $this->LogMessage(sprintf('LCNShutterRelay: Zielposition %d %% erreicht', $target), KL_DEBUG);
    }

    public function CalibrationTimer()
    {
        $this->WriteAttributeInteger('LogicalDir', self::DIRECTION_STOP);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->StopMotor();

        $this->SetValue('Position',        100);
        $this->SetValue('Direction',       self::DIRECTION_STOP);
        $this->SetValue('Moving',          false);
        $this->SetValue('LastCalibration', time());
        $this->SetModuleSummary();
        $this->LogMessage('LCNShutterRelay: Kalibrierung abgeschlossen – Position = 100 %', KL_MESSAGE);
    }

    // ==========================================================================
    //  MESSAGESINK – erkennt externe LCN-Betaetigung via Fahren+Richtung
    // ==========================================================================

    public function MessageSink($timeStamp, $senderID, $message, $data)
    {
        // Niemals diese Zeile loeschen!
        parent::MessageSink($timeStamp, $senderID, $message, $data);

        if ($message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }

        if ($message !== VM_UPDATE) return;

        $runID = $this->ReadPropertyInteger('RelayRunID');
        $dirID = $this->ReadPropertyInteger('RelayDirectionID');

        if ($senderID !== $runID && $senderID !== $dirID) return;

        // Modul steuert selbst → Relay-Aenderungen ignorieren
        if ($this->ReadAttributeInteger('LogicalDir') !== self::DIRECTION_STOP) return;

        $motorOn = (@IPS_VariableExists($runID)) ? (bool) GetValue($runID) : false;
        $dirDown = (@IPS_VariableExists($dirID)) ? (bool) GetValue($dirID) : false;

        $extDir = $this->ReadAttributeInteger('ExternalDir');

        if ($motorOn && $extDir === self::DIRECTION_STOP) {
            // ── Externes Fahren gestartet ─────────────────────────────────
            // Richtung: dirDown=false → AUF, dirDown=true → AB
            $invert    = $this->ReadPropertyBoolean('InvertDirection');
            $logicDir  = ($dirDown xor $invert) ? self::DIRECTION_DOWN : self::DIRECTION_UP;

            $this->WriteAttributeInteger('ExternalDir',   $logicDir);
            $this->WriteAttributeFloat('ExternalStart',   microtime(true));
            $this->WriteAttributeInteger('ExternalPos',   $this->GetValue('Position'));
            $this->SetValue('Direction', $logicDir);
            $this->SetValue('Moving',    true);
            $this->LogMessage(
                'LCNShutterRelay: Externe Fahrt erkannt – ' .
                ($logicDir === self::DIRECTION_UP ? 'AUF' : 'AB') . ' (LCN-Taster)',
                KL_MESSAGE
            );

        } elseif (!$motorOn && $extDir !== self::DIRECTION_STOP) {
            // ── Externes Fahren gestoppt ──────────────────────────────────
            $elapsed  = microtime(true) - $this->ReadAttributeFloat('ExternalStart');
            $startPos = $this->ReadAttributeInteger('ExternalPos');
            $newPos   = $this->CalcPositionFromElapsed($elapsed, $extDir, $startPos);

            $this->WriteAttributeInteger('ExternalDir', self::DIRECTION_STOP);
            $this->SetValue('Position',  $newPos);
            $this->SetValue('Direction', self::DIRECTION_STOP);
            $this->SetValue('Moving',    false);
            $this->SetModuleSummary();
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
        switch ($ident) {
            case 'Position':
                $this->MoveTo((int) $value);
                break;
            default:
                throw new Exception('LCNShutterRelay RequestAction: Unbekannter Ident – ' . $ident);
        }
    }

    // ==========================================================================
    //  PRIVATE HELPERS
    // ==========================================================================

    private function StartFullTravel($logicDir)
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

    private function BeginMovement($logicDir, $targetPosition, $travelMs)
    {
        if ($this->GetStatus() !== self::STATUS_ACTIVE) {
            $this->LogMessage('LCNShutterRelay: Modul nicht aktiv – Befehl ignoriert.', KL_WARNING);
            return;
        }

        // Zustand VOR Relaisoperationen setzen (MessageSink ignoriert eigene Aenderungen)
        $this->WriteAttributeInteger('LogicalDir',     $logicDir);
        $this->WriteAttributeInteger('ExternalDir',    self::DIRECTION_STOP);
        $this->WriteAttributeFloat('StartTime',        microtime(true));
        $this->WriteAttributeInteger('StartPosition',  $this->GetValue('Position'));
        $this->WriteAttributeInteger('TargetPosition', $targetPosition);

        $this->SetTimerInterval('StopTimer',        0);
        $this->SetTimerInterval('CalibrationTimer', 0);

        // Schaltsequenz: Fahren AUS → Pause → Richtung setzen → Motor AN
        $this->StopMotor();
        $delay = $this->ReadPropertyInteger('SafetyDelayMS');
        if ($delay > 0) IPS_Sleep($delay);

        $this->SetDirectionRelay($logicDir);
        IPS_Sleep(50);
        $this->StartMotor();

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

    private function StopAndRecalcPosition()
    {
        $logicDir = $this->ReadAttributeInteger('LogicalDir');
        $extDir   = $this->ReadAttributeInteger('ExternalDir');

        $this->WriteAttributeInteger('LogicalDir',  self::DIRECTION_STOP);
        $this->WriteAttributeInteger('ExternalDir', self::DIRECTION_STOP);

        $this->SetTimerInterval('StopTimer',        0);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->StopMotor();

        $this->SetValue('Direction', self::DIRECTION_STOP);
        $this->SetValue('Moving',    false);

        if ($logicDir !== self::DIRECTION_STOP) {
            $elapsed  = microtime(true) - $this->ReadAttributeFloat('StartTime');
            $startPos = $this->ReadAttributeInteger('StartPosition');
            $newPos   = $this->CalcPositionFromElapsed($elapsed, $logicDir, $startPos);
            $this->SetValue('Position', $newPos);
            $this->SetModuleSummary();
            $this->LogMessage(sprintf('LCNShutterRelay: Stopp – berechnete Position: %d %%', $newPos), KL_DEBUG);
        } elseif ($extDir !== self::DIRECTION_STOP) {
            $elapsed  = microtime(true) - $this->ReadAttributeFloat('ExternalStart');
            $startPos = $this->ReadAttributeInteger('ExternalPos');
            $newPos   = $this->CalcPositionFromElapsed($elapsed, $extDir, $startPos);
            $this->SetValue('Position', $newPos);
            $this->SetModuleSummary();
            $this->LogMessage(sprintf('LCNShutterRelay: Ext. Stopp – berechnete Position: %d %%', $newPos), KL_DEBUG);
        }
    }

    /**
     * Schaltet den Motor AUS (Fahren=OFF). Richtungsrelais bleibt unveraendert.
     * Sicher jederzeit aufrufbar – kein IPS_Sleep noetig.
     */
    private function StopMotor()
    {
        $runID = $this->ReadPropertyInteger('RelayRunID');
        if ($runID > 0 && @IPS_VariableExists($runID)) {
            try {
                RequestAction($runID, false);
            } catch (Exception $e) {
                $this->LogMessage('LCNShutterRelay StopMotor: ' . $e->getMessage(), KL_ERROR);
            }
        }
    }

    /**
     * Schaltet den Motor AN (Fahren=ON).
     * Nur aufrufen nachdem SetDirectionRelay() bereits gesetzt wurde!
     */
    private function StartMotor()
    {
        $runID = $this->ReadPropertyInteger('RelayRunID');
        if ($runID > 0 && @IPS_VariableExists($runID)) {
            try {
                RequestAction($runID, true);
            } catch (Exception $e) {
                $this->LogMessage('LCNShutterRelay StartMotor: ' . $e->getMessage(), KL_ERROR);
            }
        }
    }

    /**
     * Setzt das Richtungsrelais gemaess logischer Richtung.
     * Beruecksichtigt InvertDirection.
     *   AUF normal:   Richtung=OFF
     *   AB  normal:   Richtung=ON
     *   AUF invertiert: Richtung=ON
     *   AB  invertiert: Richtung=OFF
     */
    private function SetDirectionRelay($logicDir)
    {
        $invert = $this->ReadPropertyBoolean('InvertDirection');
        // AB = Richtung ON, AUF = Richtung OFF (ggf. invertiert)
        $dirState = (($logicDir === self::DIRECTION_DOWN) xor $invert);  // Klammern nötig: xor < = in Priorität!

        $dirID = $this->ReadPropertyInteger('RelayDirectionID');
        if ($dirID > 0 && @IPS_VariableExists($dirID)) {
            try {
                RequestAction($dirID, $dirState);
            } catch (Exception $e) {
                $this->LogMessage('LCNShutterRelay SetDirectionRelay: ' . $e->getMessage(), KL_ERROR);
            }
        }
    }

    private function CalcPositionFromElapsed($elapsedSec, $logicDir, $startPos)
    {
        if ($logicDir === self::DIRECTION_UP) {
            $newPos = $startPos - ($elapsedSec / $this->ReadPropertyFloat('TravelTimeUp'))   * 100.0;
        } else {
            $newPos = $startPos + ($elapsedSec / $this->ReadPropertyFloat('TravelTimeDown')) * 100.0;
        }
        return max(0, min(100, (int) round($newPos)));
    }

    private function RegisterReferences()
    {
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        foreach (['RelayRunID', 'RelayDirectionID'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $this->RegisterReference($id);
            }
        }
    }

    private function SetModuleSummary()
    {
        $pos = $this->GetValue('Position');
        $this->SetSummary($pos . ' %');
    }

    private function RegisterRelayMessages()
    {
        foreach ($this->GetMessageList() as $sid => $msgs) {
            if (in_array(VM_UPDATE, $msgs)) {
                $this->UnregisterMessage($sid, VM_UPDATE);
            }
        }
        foreach (['RelayRunID', 'RelayDirectionID'] as $prop) {
            $varID = $this->ReadPropertyInteger($prop);
            if ($varID > 0 && @IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }
    }

    private function ValidateConfiguration()
    {
        $runID = $this->ReadPropertyInteger('RelayRunID');
        $dirID = $this->ReadPropertyInteger('RelayDirectionID');

        if ($runID === 0 || $dirID === 0)                          { $this->SetStatus(self::STATUS_CONFIG_INCOMPLETE); return; }
        if ($runID === $dirID)                                      { $this->SetStatus(self::STATUS_SAME_RELAY);        return; }
        if (!@IPS_VariableExists($runID) || !@IPS_VariableExists($dirID)) { $this->SetStatus(self::STATUS_VAR_NOT_FOUND); return; }
        $this->SetStatus(self::STATUS_ACTIVE);
    }

    private function EnsureProfiles()
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
