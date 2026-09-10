<?php

declare(strict_types=1);

/**
 * Art-Net → KNX Konverter
 *
 * Empfängt ArtDmx-Pakete (Art-Net über UDP, Standardport 6454) und legt
 * ausgewählte DMX-Kanäle in Echtzeit auf KNX-DPT-Instanzen (Dimmwert in
 * Prozent, DPT 5.001). Damit steuert ein Lichtpult / MagicQ / MADRIX die
 * KNX-Dimmaktoren (bzw. die dahinterliegenden DALI-Gateways) so, als wären
 * es DMX-Fixtures.
 *
 * Device Module (Type 3), Prefix ANKNX, Parent = UDP Socket
 * ({82347F20-F541-41E1-AC5B-A636FD3AE2D8}).
 *
 * ┌──────────┐  ArtDmx/UDP 6454  ┌────────────┐  RequestAction  ┌───────────┐
 * │  Pult    │ ────────────────► │ UDP Socket │ ──────────────► │ KNX DPT 5 │
 * │ (MagicQ) │                   │  + dieses  │                 │ Instanzen │
 * └──────────┘                   │   Modul    │                 └───────────┘
 *                                └────────────┘
 *
 * ═══ Warum nicht 1:1 durchreichen? ════════════════════════════════════════
 * Art-Net sendet bis zu 44 Frames/s. KNX-TP verträgt real ~10 Telegramme/s
 * dauerhaft, dann ist der Bus dicht und Taster reagieren nicht mehr. Das
 * Modul entkoppelt deshalb Empfang und Versand:
 *
 *   ReceiveData()  – nur Header prüfen, Frame in den Puffer legen. Sonst nichts.
 *   Flush()        – Timer (Standard 100 ms): rechnet Prozentwerte, filtert
 *                    über Totband, priorisiert nach Sprunghöhe und schickt
 *                    über einen Token-Bucket maximal N Telegramme/s auf den Bus.
 *
 * Ein Fade kommt dadurch leicht gestuft, aber flüssig an; der Endwert wird
 * über die Beruhigungszeit garantiert exakt nachgesendet.
 *
 * @author  FACE GmbH
 * @version 0.1
 */
class ArtNetKNXKonverter extends IPSModule
{
    // ─── Art-Net Protokoll ──────────────────────────────────────────
    private const ART_ID       = "Art-Net\0";
    private const OP_DMX       = 0x5000;   // ArtDmx (OpOutput)
    private const ARTDMX_HDR   = 18;       // Headerlänge vor den DMX-Daten
    private const DMX_PORT     = 6454;
    // Nach so langer Sendepause gilt die Sequenznummer als neu begonnen.
    private const SEQ_RESYNC_S = 1.0;

    // ─── Modul-Statuscodes ──────────────────────────────────────────
    private const STATUS_OK          = 102;
    private const STATUS_NO_CHANNELS = 104;   // inaktiv: nichts zugeordnet

    // ─── Skalierung je Kanalzeile ───────────────────────────────────
    private const SCALE_PERCENT = 0;   // 0…100 (DPT 5.001)
    private const SCALE_RAW     = 1;   // 0…255 (DPT 5.010)

    // ─── Verhalten bei Signalausfall ────────────────────────────────
    private const LOSS_HOLD = 0;   // letzten Wert stehen lassen
    private const LOSS_ZERO = 1;   // alles auf 0 fahren

    // ─── Puffergrenzen ──────────────────────────────────────────────
    private const FLUSH_MIN_MS = 50;
    private const FLUSH_MAX_MS = 2000;

    // ═══════════════════════════════════════════════════════════════
    //  LEBENSZYKLUS
    // ═══════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        // Empfang
        $this->RegisterPropertyString('BindIP', '');
        $this->RegisterPropertyInteger('BindPort', self::DMX_PORT);
        $this->RegisterPropertyInteger('PortAddress', 0);      // (Net << 8) | SubUni
        $this->RegisterPropertyString('SourceIP', '');          // optionaler Absenderfilter

        // Zuordnung DMX-Kanal → KNX-Instanz
        $this->RegisterPropertyString('Channels', '[]');

        // Bus-Schonung
        $this->RegisterPropertyInteger('MaxTelegramsPerSecond', 10);
        $this->RegisterPropertyInteger('FlushIntervalMs', 100);
        $this->RegisterPropertyInteger('DeadbandPercent', 2);
        $this->RegisterPropertyInteger('SettleMs', 400);

        // Signalausfall
        $this->RegisterPropertyInteger('TimeoutMs', 3000);
        $this->RegisterPropertyInteger('TimeoutAction', self::LOSS_HOLD);

        // Aufgelöste Kanalliste (Cache, wird in ApplyChanges gefüllt)
        $this->RegisterAttributeString('Map', '[]');
        // Einmalige Vorbelegung der Freigabe (RegisterVariableBoolean legt false an)
        $this->RegisterAttributeBoolean('Initialized', false);

        $this->EnsureProfiles();

        $this->RegisterVariableBoolean('Online', 'Online', $this->GetProfileName('Online'), 10);
        $this->RegisterVariableBoolean('Active', 'Freigabe', '~Switch', 20);
        $this->EnableAction('Active');
        $this->RegisterVariableInteger('PacketRate', 'Pakete', $this->GetProfileName('PacketRate'), 30);
        $this->RegisterVariableInteger('TelegramRate', 'Telegramme', $this->GetProfileName('TelegramRate'), 40);
        $this->RegisterVariableString('Source', 'Quelle', '', 50);

        $this->RegisterTimer('Flush', 0, 'ANKNX_Flush($_IPS[\'TARGET\']);');

        $this->ConnectParent('{82347F20-F541-41E1-AC5B-A636FD3AE2D8}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();

        // Beim allerersten ApplyChanges die Freigabe einschalten – sonst steht
        // eine frisch angelegte Instanz still und niemand weiß warum.
        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->SetValue('Active', true);
            $this->WriteAttributeBoolean('Initialized', true);
        }

        $map = $this->BuildMap();
        $this->WriteAttributeString('Map', json_encode($map));

        $this->ResetRuntimeState();
        $this->ConfigureParent();

        if (count($map) === 0) {
            $this->SetTimerInterval('Flush', 0);
            $this->SetValueIfChanged('Online', false);
            $this->SetStatus(self::STATUS_NO_CHANNELS);
            return;
        }

        $this->SetTimerInterval('Flush', $this->FlushInterval());
        $this->SetStatus(self::STATUS_OK);
    }

    public function Destroy()
    {
        $prefix = 'ANKNX.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Active') {
            $this->SetActive((bool) $Value);
            return;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  EMPFANG – so wenig Arbeit wie möglich, das läuft bis 44×/s
    // ═══════════════════════════════════════════════════════════════

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) {
            return '';
        }

        $buf = $this->FromSocket((string) $data['Buffer']);
        if (strlen($buf) < self::ARTDMX_HDR) {
            return '';
        }
        if (substr($buf, 0, 8) !== self::ART_ID) {
            return '';
        }
        // OpCode steht Little-Endian
        if ((ord($buf[8]) | (ord($buf[9]) << 8)) !== self::OP_DMX) {
            return '';   // ArtPoll, ArtSync, ArtTimeCode … interessiert uns nicht
        }

        // Universum prüfen, bevor irgendetwas kopiert wird
        $portAddress = (ord($buf[15]) << 8) | ord($buf[14]);
        if ($portAddress !== (int) $this->ReadPropertyInteger('PortAddress')) {
            return '';
        }

        $source = isset($data['ClientIP']) ? (string) $data['ClientIP'] : '';
        $filter = trim((string) $this->ReadPropertyString('SourceIP'));
        if ($filter !== '' && $source !== '' && $source !== $filter) {
            return '';
        }

        $dmxLen = (ord($buf[16]) << 8) | ord($buf[17]);
        if ($dmxLen < 1 || $dmxLen > 512) {
            return '';
        }
        $dmx = substr($buf, self::ARTDMX_HDR, $dmxLen);
        if (strlen($dmx) < $dmxLen) {
            return '';   // abgeschnittenes Paket
        }

        // Sequenznummer: 0 heißt "nicht benutzt". Sonst verspätete Pakete
        // verwerfen, sonst zuckt ein Fade rückwärts.
        //
        // Die Prüfung gilt nur innerhalb eines laufenden Stroms: nach einer
        // Pause (Pult neu gestartet, Kabel ab) fängt der Sender wieder bei
        // einer beliebigen Nummer an. Ohne diese Ausnahme bliebe das Modul
        // dauerhaft hängen, weil es die neue Zählung für "veraltet" hielte.
        $now  = $this->Now();
        $seq  = ord($buf[12]);
        $rx   = $this->ReadRx();
        $imStrom = ($rx['at'] > 0.0) && (($now - $rx['at']) < self::SEQ_RESYNC_S);
        if ($seq !== 0 && $rx['seq'] !== 0 && $imStrom) {
            $diff = ($seq - $rx['seq'] + 256) % 256;
            if ($diff === 0 || $diff > 128) {
                return '';
            }
        }

        $this->SetBuffer('Frame', base64_encode($dmx));
        $this->WriteRx($now, $seq, $rx['count'] + 1, $source);

        return '';
    }

    // ═══════════════════════════════════════════════════════════════
    //  VERSAND – Timer, entkoppelt vom Empfang
    // ═══════════════════════════════════════════════════════════════

    /**
     * Rechnet den zuletzt empfangenen Frame auf die Zielinstanzen um und
     * schickt so viele Telegramme, wie das Ratenlimit gerade hergibt.
     * Wird vom Timer aufgerufen.
     */
    public function Flush(): void
    {
        $now = $this->Now();
        $rx  = $this->ReadRx();

        $this->UpdateStats($now, $rx);

        $timeoutMs = max(200, (int) $this->ReadPropertyInteger('TimeoutMs'));
        $online    = ($rx['at'] > 0.0) && ((($now - $rx['at']) * 1000.0) < $timeoutMs);
        $this->SetValueIfChanged('Online', $online);

        $map = json_decode($this->ReadAttributeString('Map'), true);
        if (!is_array($map) || count($map) === 0) {
            return;
        }

        if (!$this->IsActive()) {
            // Freigabe aus: KNX gehört wieder der Hausautomation. Nichts senden,
            // aber den Sendezustand vergessen, damit es beim Einschalten
            // sauber neu synchronisiert.
            $this->SetBuffer('Sent', '[]');
            return;
        }

        $targets = $this->TargetPercents($map, $online);
        if ($targets === null) {
            return;   // noch kein Frame gesehen und kein Ausfallverhalten aktiv
        }

        $this->SendPending($map, $targets, $now);
    }

    /**
     * Sollwerte je Kanal (Index in $map → Prozent 0…100) bestimmen.
     * Liefert null, wenn es nichts zu tun gibt.
     */
    private function TargetPercents(array $map, bool $online): ?array
    {
        if (!$online) {
            if ((int) $this->ReadPropertyInteger('TimeoutAction') !== self::LOSS_ZERO) {
                return null;   // halten
            }
            return array_fill(0, count($map), 0);
        }

        $frame = base64_decode((string) $this->GetBuffer('Frame'), true);
        if ($frame === false || $frame === '') {
            return null;
        }
        $len = strlen($frame);

        $out = [];
        foreach ($map as $i => $row) {
            $ch = (int) $row['ch'];
            $out[$i] = ($ch >= 1 && $ch <= $len)
                ? $this->RawToPercent(ord($frame[$ch - 1]), $row)
                : 0;
        }
        return $out;
    }

    /**
     * DMX-Rohwert (0…255) auf Prozent abbilden.
     * 0 bleibt immer 0 (aus). Alles darüber wird in [Min, Max] gespreizt –
     * so lässt sich die Mindesthelligkeit eines Dimmaktors berücksichtigen,
     * ohne dass „Fader unten" plötzlich nicht mehr ausschaltet.
     */
    private function RawToPercent(int $raw, array $row): int
    {
        if ((bool) $row['invert']) {
            $raw = 255 - $raw;
        }
        if ($raw <= 0) {
            return 0;
        }
        $min = (int) $row['min'];
        $max = (int) $row['max'];
        $pct = $min + ($raw / 255.0) * ($max - $min);

        return (int) max(0, min(100, round($pct)));
    }

    /**
     * Abweichende Kanäle nach Sprunghöhe priorisieren und im Rahmen des
     * Ratenlimits auf den Bus geben.
     */
    private function SendPending(array $map, array $targets, float $now): void
    {
        $sent     = json_decode((string) $this->GetBuffer('Sent'), true);
        $stable   = json_decode((string) $this->GetBuffer('Stable'), true);
        $sent     = is_array($sent) ? $sent : [];
        $stable   = is_array($stable) ? $stable : [];

        $deadband = max(0, (int) $this->ReadPropertyInteger('DeadbandPercent'));
        $settleMs = max(0, (int) $this->ReadPropertyInteger('SettleMs'));

        $queue = [];
        foreach ($targets as $i => $pct) {
            $key = (string) $i;

            // Zeitpunkt der letzten Wertänderung mitführen (für die Beruhigung)
            if (!isset($stable[$key]) || (int) $stable[$key][0] !== $pct) {
                $stable[$key] = [$pct, $now];
            }
            $restingMs = ($now - (float) $stable[$key][1]) * 1000.0;

            if (!isset($sent[$key])) {
                $queue[] = ['i' => $i, 'pct' => $pct, 'prio' => 1000.0];
                continue;
            }
            $delta = abs($pct - (int) $sent[$key]);
            if ($delta === 0) {
                continue;
            }
            if ($delta >= $deadband) {
                $queue[] = ['i' => $i, 'pct' => $pct, 'prio' => (float) $delta];
            } elseif ($restingMs >= $settleMs) {
                // Kleiner Rest unterhalb des Totbands: einmal nachziehen,
                // damit der Endwert exakt stimmt.
                $queue[] = ['i' => $i, 'pct' => $pct, 'prio' => (float) $delta];
            }
            // Endpunkte (ganz aus / ganz auf) haben Vorrang – „aus" muss sitzen.
            if (($pct === 0 || $pct === 100) && count($queue) > 0) {
                $last = count($queue) - 1;
                if ($queue[$last]['i'] === $i) {
                    $queue[$last]['prio'] += 1000.0;
                }
            }
        }

        $this->SetBuffer('Stable', json_encode($stable));
        if (count($queue) === 0) {
            return;
        }

        usort($queue, function ($a, $b) {
            return ($b['prio'] <=> $a['prio']);
        });

        $tokens = $this->TakeTokens($now);
        $count  = 0;
        foreach ($queue as $item) {
            if ($tokens < 1.0) {
                break;
            }
            $row = $map[$item['i']];
            if ($this->WriteTarget($row, $item['pct'])) {
                $sent[(string) $item['i']] = $item['pct'];
                $tokens -= 1.0;
                $count++;
            } else {
                // Ziel weg (Instanz gelöscht) – nicht in Dauerschleife versuchen
                $sent[(string) $item['i']] = $item['pct'];
            }
        }

        $this->SetBuffer('Tokens', (string) $tokens);
        $this->SetBuffer('Sent', json_encode($sent));
        if ($count > 0) {
            $this->SetBuffer('TxCount', (string) ((int) $this->GetBuffer('TxCount') + $count));
        }
    }

    /**
     * Prozentwert auf die KNX-Variable schreiben. RequestAction löst das
     * Senden auf den Bus aus – SetValue würde nur die Variable beschreiben.
     */
    private function WriteTarget(array $row, int $pct): bool
    {
        $vid = (int) $row['vid'];
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return false;
        }

        $value = ((int) $row['scale'] === self::SCALE_RAW)
            ? (int) round($pct * 255.0 / 100.0)
            : $pct;

        switch (IPS_GetVariable($vid)['VariableType']) {
            case VARIABLETYPE_BOOLEAN: $cast = ($value > 0); break;
            case VARIABLETYPE_INTEGER: $cast = (int) $value; break;
            case VARIABLETYPE_FLOAT:   $cast = (float) $value; break;
            default:                   $cast = (string) $value; break;
        }

        @RequestAction($vid, $cast);
        $this->SendDebug('KNX', sprintf('Kanal %d → #%d = %s', (int) $row['ch'], $vid, (string) $value), 0);
        return true;
    }

    /**
     * Token-Bucket: pro Sekunde wachsen so viele Token nach, wie Telegramme
     * erlaubt sind. Das begrenzt die Buslast exakt und erlaubt trotzdem einen
     * kleinen Burst nach einer Ruhephase.
     */
    private function TakeTokens(float $now): float
    {
        $maxTps = max(1, (int) $this->ReadPropertyInteger('MaxTelegramsPerSecond'));
        $last   = (float) $this->GetBuffer('TokensAt');
        $tokens = (float) $this->GetBuffer('Tokens');

        if ($last <= 0.0) {
            $tokens = (float) $maxTps;
        } else {
            $tokens = min((float) $maxTps, $tokens + ($now - $last) * $maxTps);
        }
        $this->SetBuffer('TokensAt', (string) $now);
        return $tokens;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ÖFFENTLICHE FUNKTIONEN
    // ═══════════════════════════════════════════════════════════════

    /**
     * Freigabe setzen. Aus = KNX gehört wieder der Hausautomation,
     * eingehendes DMX wird verworfen.
     */
    public function SetActive(bool $Active): void
    {
        $this->SetValueIfChanged('Active', $Active);
        $this->SetBuffer('Sent', '[]');
        $this->SetBuffer('Stable', '[]');
    }

    /**
     * Alle zugeordneten Kanäle beim nächsten Durchlauf neu senden.
     */
    public function ResendAll(): void
    {
        $this->SetBuffer('Sent', '[]');
        $this->SetBuffer('Stable', '[]');
    }

    /**
     * Rohwert eines DMX-Kanals aus dem zuletzt empfangenen Frame (0…255),
     * -1 wenn nichts empfangen wurde.
     */
    public function GetChannel(int $Channel): int
    {
        $frame = base64_decode((string) $this->GetBuffer('Frame'), true);
        if ($frame === false || $Channel < 1 || $Channel > strlen($frame)) {
            return -1;
        }
        return ord($frame[$Channel - 1]);
    }

    /**
     * Diagnose für das Konfigurationsformular: zeigt, was gerade hereinkommt.
     */
    public function DumpUniverse(): string
    {
        $rx    = $this->ReadRx();
        $frame = base64_decode((string) $this->GetBuffer('Frame'), true);

        $out = sprintf("Universum (Port-Address): %d\n", (int) $this->ReadPropertyInteger('PortAddress'));
        if ($frame === false || $frame === '') {
            $out .= "Bisher kein ArtDmx-Paket für dieses Universum empfangen.\n\n"
                  . "Prüfen: sendet das Pult auf UDP " . $this->ReadPropertyInteger('BindPort')
                  . "? Stimmt die Port-Address (Net × 256 + Subnet × 16 + Universum)?\n"
                  . "Liegt die SymBox im selben Subnetz / kommt Broadcast an?";
            echo $out;
            return $out;
        }

        $age = ($rx['at'] > 0.0) ? ($this->Now() - $rx['at']) : -1.0;
        $out .= sprintf("Quelle: %s   letztes Paket vor: %.2f s   Kanäle im Frame: %d\n\n",
            $rx['source'] !== '' ? $rx['source'] : 'unbekannt', $age, strlen($frame));

        $map = json_decode($this->ReadAttributeString('Map'), true) ?: [];
        if (count($map) > 0) {
            $out .= "Zugeordnete Kanäle\n";
            $out .= "Kanal  Roh   %     Ziel\n";
            foreach ($map as $row) {
                $ch  = (int) $row['ch'];
                $raw = ($ch >= 1 && $ch <= strlen($frame)) ? ord($frame[$ch - 1]) : 0;
                $out .= sprintf("%5d  %3d  %3d %%  %s\n",
                    $ch, $raw, $this->RawToPercent($raw, $row),
                    IPS_ObjectExists((int) $row['vid']) ? IPS_GetLocation((int) $row['vid']) : '— nicht auflösbar —');
            }
            $out .= "\n";
        }

        $out .= "Alle Kanäle > 0\n";
        $any = false;
        for ($i = 0; $i < strlen($frame); $i++) {
            $v = ord($frame[$i]);
            if ($v > 0) {
                $out .= sprintf("  %3d: %3d (%d %%)\n", $i + 1, $v, (int) round($v * 100.0 / 255.0));
                $any = true;
            }
        }
        if (!$any) {
            $out .= "  (alle Kanäle auf 0)\n";
        }

        echo $out;
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════
    //  KONFIGURATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * Kanalliste einmalig auflösen: Zielinstanz → Value-Variable.
     * Spart pro Timer-Durchlauf ein IPS_GetObjectIDByIdent je Kanal.
     */
    private function BuildMap(): array
    {
        $rows = json_decode($this->ReadPropertyString('Channels'), true);
        if (!is_array($rows)) {
            return [];
        }

        $map  = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !(bool) ($row['Active'] ?? true)) {
                continue;
            }
            $ch = (int) ($row['Channel'] ?? 0);
            if ($ch < 1 || $ch > 512) {
                continue;
            }
            $vid = $this->ResolveTarget((int) ($row['TargetID'] ?? 0));
            if ($vid === 0) {
                $this->LogMessage(sprintf(
                    'Art-Net → KNX: Kanal %d hat kein auflösbares Ziel (Objekt #%d). Zeile wird übersprungen.',
                    $ch, (int) ($row['TargetID'] ?? 0)), KL_WARNING);
                continue;
            }
            if (isset($seen[$vid])) {
                $this->LogMessage(sprintf(
                    'Art-Net → KNX: Ziel #%d ist mehrfach zugeordnet (Kanal %d und %d). Nur der erste Kanal wird verwendet.',
                    $vid, $seen[$vid], $ch), KL_WARNING);
                continue;
            }
            $seen[$vid] = $ch;

            $min = (int) ($row['MinPercent'] ?? 0);
            $max = (int) ($row['MaxPercent'] ?? 100);
            if ($max <= $min) {
                $min = 0;
                $max = 100;
            }

            $map[] = [
                'ch'     => $ch,
                'vid'    => $vid,
                'name'   => (string) ($row['Name'] ?? ''),
                'invert' => (bool) ($row['Invert'] ?? false),
                'min'    => max(0, min(100, $min)),
                'max'    => max(0, min(100, $max)),
                'scale'  => (int) ($row['Scale'] ?? self::SCALE_PERCENT),
            ];
        }
        return $map;
    }

    /**
     * Akzeptiert eine KNX-DPT-Instanz (übliche Auswahl) genauso wie eine
     * direkt angegebene Variable.
     */
    private function ResolveTarget(int $id): int
    {
        if ($id <= 0 || !IPS_ObjectExists($id)) {
            return 0;
        }
        if (IPS_VariableExists($id)) {
            return $id;
        }
        if (!IPS_InstanceExists($id)) {
            return 0;
        }
        $vid = @IPS_GetObjectIDByIdent('Value', $id);
        if ($vid && IPS_VariableExists($vid)) {
            return (int) $vid;
        }
        // Fallback: erste schaltbare Variable der Instanz
        foreach (IPS_GetChildrenIDs($id) as $cid) {
            if (IPS_VariableExists($cid) && (int) IPS_GetVariable($cid)['VariableAction'] > 0) {
                return (int) $cid;
            }
        }
        return 0;
    }

    /**
     * Den UDP-Socket darüber passend einstellen. Nur schreiben, wenn sich
     * wirklich etwas ändert – sonst löst jedes ApplyChanges eine Kaskade aus.
     */
    private function ConfigureParent(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $pid = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($pid <= 0 || !IPS_InstanceExists($pid)) {
            return;
        }
        if (IPS_GetInstance($pid)['ModuleInfo']['ModuleID'] !== '{82347F20-F541-41E1-AC5B-A636FD3AE2D8}') {
            return;   // jemand hat bewusst einen anderen Socket gewählt
        }

        $want = [
            'BindPort'           => max(1, (int) $this->ReadPropertyInteger('BindPort')),
            'BindIP'             => (string) $this->ReadPropertyString('BindIP'),
            'EnableBroadcast'    => true,   // Pulte senden Art-Net oft als Broadcast
            'EnableReuseAddress' => true,   // mehrere Universen teilen sich Port 6454
            'Open'               => true,
        ];

        $changed = false;
        foreach ($want as $key => $value) {
            if (@IPS_GetProperty($pid, $key) !== $value) {
                IPS_SetProperty($pid, $key, $value);
                $changed = true;
            }
        }
        if ($changed) {
            if (IPS_GetName($pid) === 'UDP Socket') {
                @IPS_SetName($pid, 'Art-Net UDP ' . $want['BindPort']);
            }
            @IPS_ApplyChanges($pid);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  ZUSTAND & STATISTIK
    // ═══════════════════════════════════════════════════════════════

    private function ResetRuntimeState(): void
    {
        $this->SetBuffer('Sent', '[]');
        $this->SetBuffer('Stable', '[]');
        $this->SetBuffer('Tokens', '0');
        $this->SetBuffer('TokensAt', '0');
        $this->SetBuffer('TxCount', '0');
        $this->SetBuffer('StatsAt', (string) $this->Now());
        $this->SetBuffer('StatsRx', (string) $this->ReadRx()['count']);
    }

    /** Empfangszustand: Zeitstempel, Sequenz, Paketzähler, Absender. */
    private function ReadRx(): array
    {
        $raw = (string) $this->GetBuffer('Rx');
        if ($raw === '') {
            return ['at' => 0.0, 'seq' => 0, 'count' => 0, 'source' => ''];
        }
        $p = explode('|', $raw, 4);
        return [
            'at'     => (float) ($p[0] ?? 0),
            'seq'    => (int) ($p[1] ?? 0),
            'count'  => (int) ($p[2] ?? 0),
            'source' => (string) ($p[3] ?? ''),
        ];
    }

    private function WriteRx(float $at, int $seq, int $count, string $source): void
    {
        $this->SetBuffer('Rx', $at . '|' . $seq . '|' . $count . '|' . $source);
    }

    /** Paket- und Telegrammrate einmal pro Sekunde nachführen. */
    private function UpdateStats(float $now, array $rx): void
    {
        $at = (float) $this->GetBuffer('StatsAt');
        if ($at <= 0.0) {
            $this->SetBuffer('StatsAt', (string) $now);
            return;
        }
        $span = $now - $at;
        if ($span < 1.0) {
            return;
        }

        $pps = (int) round(max(0, $rx['count'] - (int) $this->GetBuffer('StatsRx')) / $span);
        $tps = (int) round(max(0, (int) $this->GetBuffer('TxCount')) / $span);

        $this->SetValueIfChanged('PacketRate', $pps);
        $this->SetValueIfChanged('TelegramRate', $tps);
        $this->SetValueIfChanged('Source', $rx['source']);

        $this->SetBuffer('StatsAt', (string) $now);
        $this->SetBuffer('StatsRx', (string) $rx['count']);
        $this->SetBuffer('TxCount', '0');
    }

    private function IsActive(): bool
    {
        $id = @$this->GetIDForIdent('Active');
        return $id > 0 ? (bool) GetValue($id) : true;
    }

    private function FlushInterval(): int
    {
        return max(self::FLUSH_MIN_MS,
               min(self::FLUSH_MAX_MS, (int) $this->ReadPropertyInteger('FlushIntervalMs')));
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELFER
    // ═══════════════════════════════════════════════════════════════

    /**
     * Symcon liefert den Datagramm-Inhalt UTF-8-verpackt. Zurück nach Latin-1
     * ist verlustfrei für Bytewerte 0…255 – genau das, was ein DMX-Frame ist.
     * utf8_decode() ist ab PHP 8.2 deprecated, deshalb mb_convert_encoding.
     */
    /**
     * Zeitquelle als eigene Methode – so lässt sich der Ablauf im Test ohne
     * echtes Warten durchspielen.
     */
    protected function Now(): float
    {
        return microtime(true);
    }

    private function FromSocket(string $s): string
    {
        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        }
        return utf8_decode($s);
    }

    private function GetProfileName(string $suffix): string
    {
        return 'ANKNX.' . $this->InstanceID . '.' . $suffix;
    }

    /**
     * Instanzeigene Profile. Bewusst bei jedem ApplyChanges – Destroy() räumt
     * sie bei jedem Modul-Update weg.
     */
    private function EnsureProfiles(): void
    {
        $pOnline = $this->GetProfileName('Online');
        if (!IPS_VariableProfileExists($pOnline)) {
            IPS_CreateVariableProfile($pOnline, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($pOnline, false, 'Kein Signal', 'Close', 0xFF0000);
        IPS_SetVariableProfileAssociation($pOnline, true, 'Signal', 'Ok', 0x00CC00);
        IPS_SetVariableProfileIcon($pOnline, 'Network');

        $pPkt = $this->GetProfileName('PacketRate');
        if (!IPS_VariableProfileExists($pPkt)) {
            IPS_CreateVariableProfile($pPkt, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileText($pPkt, '', ' Pkt/s');
        IPS_SetVariableProfileIcon($pPkt, 'Distance');

        $pTel = $this->GetProfileName('TelegramRate');
        if (!IPS_VariableProfileExists($pTel)) {
            IPS_CreateVariableProfile($pTel, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileText($pTel, '', ' Tel/s');
        IPS_SetVariableProfileIcon($pTel, 'Electricity');
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && GetValue($id) !== $value) {
            $this->SetValue($ident, $value);
        }
    }
}
