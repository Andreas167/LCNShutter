<?php

/**
 * LCNShutterRelay – IP-Symcon Modul  v2.1
 *
 * Steuert einen Rollladen-Motor ueber zwei LCN-Schaltinstanzen nach dem
 * Fahren+Richtung-Prinzip:
 *
 *   Schaltinstanz FAHREN   (RelayRunInstanceID)       Status=ON  = Motor laeuft
 *                                                      Status=OFF = Motor gestoppt
 *   Schaltinstanz RICHTUNG (RelayDirectionInstanceID)  Status=OFF = Richtung AUF
 *                                                      Status=ON  = Richtung AB
 *
 * Steuerung: IPS_RequestAction() auf die Status-Variable der jeweiligen Instanz.
 * Ueberwachung: VM_UPDATE auf die Status-Variable der jeweiligen Instanz.
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
    private const STATUS_SAME_INSTANCE     = 200;
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
        $this->RegisterPropertyInteger('RelayRunInstanceID',       0);  // Schaltinstanz FAHREN
        $this->RegisterPropertyInteger('RelayDirectionInstanceID', 0);  // Schaltinstanz RICHTUNG
        $this->RegisterPropertyFloat('TravelTimeUp',               30.0);
        $this->RegisterPropertyFloat('TravelTimeDown',             30.0);
        $this->RegisterPropertyInteger('SafetyDelayMS',            100);
        $this->RegisterPropertyBoolean('InvertDirection',          false);

        // --- Modul-initiierte Fahrt ---
        $this->RegisterAttributeFloat('StartTime',       0.0);
        $this->RegisterAttributeInteger('StartPosition', 50);
        $this->RegisterAttributeInteger('LogicalDir',    self::DIRECTION_STOP);
        $this->RegisterAttributeInteger('TargetPosition',50);

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

    public function MoveUp()   { $this->StartFullTravel(self::DIRECTION_UP); }
    public function MoveDown() { $this->StartFullTravel(self::DIRECTION_DOWN); }
    public function Stop()     { $this->StopAndRecalcPosition(); }

    public function MoveTo($position)
    {
        $position = max(0, min(100, (int)$position));
        $current  = $this->GetValue('Position');
        if ($current === $position) return;

        if ($position < $current) {
            $logicDir = self::DIRECTION_UP;
            $ms = (int)round($this->ReadPropertyFloat('TravelTimeUp')   * ($current - $position) / 100.0 * 1000);
        } else {
            $logicDir = self::DIRECTION_DOWN;
            $ms = (int)round($this->ReadPropertyFloat('TravelTimeDown') * ($position - $current) / 100.0 * 1000);
        }
        $this->BeginMovement($logicDir, $position, $ms);
    }

    public function Calibrate()
    {
        $this->LogMessage('LCNShutterRelay: Kalibrierung gestartet – fahre auf untere Endlage', KL_MESSAGE);
        $calibMs = (int)round($this->ReadPropertyFloat('TravelTimeDown') * 1.2 * 1000);

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

        $runVarID = $this->GetRelayVarID('RelayRunInstanceID');
        $dirVarID = $this->GetRelayVarID('RelayDirectionInstanceID');

        if ($senderID !== $runVarID && $senderID !== $dirVarID) return;

        // Modul steuert selbst → Relay-Aenderungen ignorieren
        if ($this->ReadAttributeInteger('LogicalDir') !== self::DIRECTION_STOP) return;

        $motorOn = ($runVarID > 0 && @IPS_VariableExists($runVarID)) ? (bool)GetValue($runVarID) : false;
        $dirDown = ($dirVarID > 0 && @IPS_VariableExists($dirVarID)) ? (bool)GetValue($dirVarID) : false;

        $extDir = $this->ReadAttributeInteger('ExternalDir');

        if ($motorOn && $extDir === self::DIRECTION_STOP) {
            // ── Externes Fahren gestartet ──────────────────────────────────
            $invert   = $this->ReadPropertyBoolean('InvertDirection');
            $logicDir = (($dirDown xor $invert)) ? self::DIRECTION_DOWN : self::DIRECTION_UP;

            $this->WriteAttributeInteger('ExternalDir',  $logicDir);
            $this->WriteAttributeFloat('ExternalStart',  microtime(true));
            $this->WriteAttributeInteger('ExternalPos',  $this->GetValue('Position'));
            $this->SetValue('Direction', $logicDir);
            $this->SetValue('Moving',    true);
            $this->LogMessage(
                'LCNShutterRelay: Externe Fahrt – ' .
                ($logicDir === self::DIRECTION_UP ? 'AUF' : 'AB') . ' (LCN-Taster)',
                KL_MESSAGE
            );

        } elseif (!$motorOn && $extDir !== self::DIRECTION_STOP) {
            // ── Externes Fahren gestoppt ────────────────────────────────────
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
                $this->MoveTo((int)$value);
                break;
            default:
                throw new Exception('LCNShutterRelay RequestAction: Unbekannter Ident – ' . $ident);
        }
    }

    // ==========================================================================
    //  PRIVATE HELPERS
    // ==========================================================================

    /**
     * Gibt die Status-Variablen-ID der angegebenen Schaltinstanz zurueck.
     * Schaltinstanzen in Symcon verwenden den Ident "Status" fuer ihre
     * boolesche Ausgangsvariable.
     */
    private function GetRelayVarID(string $prop): int
    {
        $instID = $this->ReadPropertyInteger($prop);
        if ($instID <= 0 || !@IPS_ObjectExists($instID)) return 0;
        $varID = @IPS_GetObjectIDByIdent('Status', $instID);
        return ($varID !== false && $varID > 0) ? (int)$varID : 0;
    }

    private function StartFullTravel($logicDir)
    {
        $current = $this->GetValue('Position');
        if ($logicDir === self::DIRECTION_UP) {
            $fraction = $current / 100.0;
            $target   = 0;
            $ms       = (int)round($this->ReadPropertyFloat('TravelTimeUp')   * $fraction * 1000);
        } else {
            $fraction = (100 - $current) / 100.0;
            $target   = 100;
            $ms       = (int)round($this->ReadPropertyFloat('TravelTimeDown') * $fraction * 1000);
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
        $this->SetTimerInterval('StopTimer', (int)round($travelMs * 1.1));

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
            $this->LogMessage(sprintf('LCNShutterRelay: Stopp – Position: %d %%', $newPos), KL_DEBUG);
        } elseif ($extDir !== self::DIRECTION_STOP) {
            $elapsed  = microtime(true) - $this->ReadAttributeFloat('ExternalStart');
            $startPos = $this->ReadAttributeInteger('ExternalPos');
            $newPos   = $this->CalcPositionFromElapsed($elapsed, $extDir, $startPos);
            $this->SetValue('Position', $newPos);
            $this->SetModuleSummary();
            $this->LogMessage(sprintf('LCNShutterRelay: Ext. Stopp – Position: %d %%', $newPos), KL_DEBUG);
        }
    }

    /** Motor AUS: setzt FAHREN-Relais auf false. */
    private function StopMotor()
    {
        $instID = $this->ReadPropertyInteger('RelayRunInstanceID');
        if ($instID > 0 && @IPS_ObjectExists($instID)) {
            try { IPS_RequestAction($instID, 'Status', false); }
            catch (Exception $e) { $this->LogMessage('StopMotor: ' . $e->getMessage(), KL_ERROR); }
        }
    }

    /** Motor AN: setzt FAHREN-Relais auf true. */
    private function StartMotor()
    {
        $instID = $this->ReadPropertyInteger('RelayRunInstanceID');
        if ($instID > 0 && @IPS_ObjectExists($instID)) {
            try { IPS_RequestAction($instID, 'Status', true); }
            catch (Exception $e) { $this->LogMessage('StartMotor: ' . $e->getMessage(), KL_ERROR); }
        }
    }

    /**
     * Setzt das RICHTUNG-Relais gemaess logischer Fahrtrichtung.
     * InvertDirection kehrt das Richtungsrelais um (bei vertauschter Verdrahtung).
     *   Normal:    AUF=false / AB=true
     *   Invertiert: AUF=true  / AB=false
     */
    private function SetDirectionRelay($logicDir)
    {
        $invert   = $this->ReadPropertyBoolean('InvertDirection');
        $dirState = (($logicDir === self::DIRECTION_DOWN) xor $invert);  // Klammern noetig: xor < = Prioritaet!

        $instID = $this->ReadPropertyInteger('RelayDirectionInstanceID');
        if ($instID > 0 && @IPS_ObjectExists($instID)) {
            try { IPS_RequestAction($instID, 'Status', $dirState); }
            catch (Exception $e) { $this->LogMessage('SetDirectionRelay: ' . $e->getMessage(), KL_ERROR); }
        }
    }

    private function CalcPositionFromElapsed($elapsedSec, $logicDir, $startPos)
    {
        if ($logicDir === self::DIRECTION_UP) {
            $newPos = $startPos - ($elapsedSec / $this->ReadPropertyFloat('TravelTimeUp'))   * 100.0;
        } else {
            $newPos = $startPos + ($elapsedSec / $this->ReadPropertyFloat('TravelTimeDown')) * 100.0;
        }
        return max(0, min(100, (int)round($newPos)));
    }

    private function RegisterReferences()
    {
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        foreach (['RelayRunInstanceID', 'RelayDirectionInstanceID'] as $prop) {
            $instID = $this->ReadPropertyInteger($prop);
            if ($instID > 0 && @IPS_ObjectExists($instID)) {
                $this->RegisterReference($instID);
            }
        }
    }

    private function RegisterRelayMessages()
    {
        // Alte Subscriptions abmelden
        foreach ($this->GetMessageList() as $sid => $msgs) {
            if (in_array(VM_UPDATE, $msgs)) {
                $this->UnregisterMessage($sid, VM_UPDATE);
            }
        }
        // Status-Variablen der Instanzen abonnieren
        foreach (['RelayRunInstanceID', 'RelayDirectionInstanceID'] as $prop) {
            $varID = $this->GetRelayVarID($prop);
            if ($varID > 0) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }
    }

    private function ValidateConfiguration()
    {
        $runInstID = $this->ReadPropertyInteger('RelayRunInstanceID');
        $dirInstID = $this->ReadPropertyInteger('RelayDirectionInstanceID');

        if ($runInstID === 0 || $dirInstID === 0) {
            $this->SetStatus(self::STATUS_CONFIG_INCOMPLETE); return;
        }
        if ($runInstID === $dirInstID) {
            $this->SetStatus(self::STATUS_SAME_INSTANCE); return;
        }
        $runVarID = $this->GetRelayVarID('RelayRunInstanceID');
        $dirVarID = $this->GetRelayVarID('RelayDirectionInstanceID');
        if ($runVarID === 0 || $dirVarID === 0) {
            $this->SetStatus(self::STATUS_VAR_NOT_FOUND); return;
        }
        $this->SetStatus(self::STATUS_ACTIVE);
    }

    private function SetModuleSummary()
    {
        $this->SetSummary($this->GetValue('Position') . ' %');
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
