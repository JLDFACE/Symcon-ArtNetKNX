<?php

declare(strict_types=1);

/**
 * Minimale Symcon-Attrappe, damit sich die Art-Net-Auswertung und die
 * Telegramm-Bremse ohne SymBox testen lassen. Bildet nur das nach, was
 * ArtNetKNXKonverter tatsächlich benutzt.
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;
const KL_WARNING           = 20;
const KR_READY             = 10103;

final class IPSKernel
{
    /** @var array<int, array> Objekte: id => ['type'=>'var'|'instance', ...] */
    public static $objects = [];
    /** @var array<int, mixed> */
    public static $values = [];
    /** @var array<int, array{0:int,1:mixed}> Mitschrift aller RequestAction-Aufrufe */
    public static $actions = [];
    /** @var string[] */
    public static $log = [];
    /** @var int[] Variablen, die den Wert NICHT annehmen (totes Gateway simulieren) */
    public static $deaf = [];
    public static $nextID = 1000;

    public static function reset(): void
    {
        self::$objects = [];
        self::$values  = [];
        self::$actions = [];
        self::$log     = [];
        self::$deaf    = [];
        self::$nextID  = 1000;
    }

    /** Legt eine KNX-DPT-artige Instanz mit Value-Variable an, liefert die Instanz-ID. */
    public static function makeKnxInstance(int $varType = VARIABLETYPE_INTEGER): int
    {
        $iid = self::$nextID++;
        $vid = self::$nextID++;
        self::$objects[$iid] = ['type' => 'instance', 'name' => 'KNX DPT 5', 'children' => [$vid]];
        self::$objects[$vid] = ['type' => 'var', 'name' => 'Value', 'ident' => 'Value',
                                'parent' => $iid, 'varType' => $varType, 'action' => 1];
        self::$values[$vid] = 0;
        return $iid;
    }

    public static function valueVarOf(int $iid): int
    {
        return self::$objects[$iid]['children'][0];
    }
}

function IPS_ObjectExists(int $id): bool   { return isset(IPSKernel::$objects[$id]); }
function IPS_VariableExists(int $id): bool { return isset(IPSKernel::$objects[$id]) && IPSKernel::$objects[$id]['type'] === 'var'; }
function IPS_InstanceExists(int $id): bool { return isset(IPSKernel::$objects[$id]) && IPSKernel::$objects[$id]['type'] === 'instance'; }
function IPS_GetChildrenIDs(int $id): array { return IPSKernel::$objects[$id]['children'] ?? []; }
function IPS_GetLocation(int $id): string  { return 'Test\\' . (IPSKernel::$objects[$id]['name'] ?? '?'); }
function IPS_GetName(int $id): string      { return IPSKernel::$objects[$id]['name'] ?? ''; }
function IPS_SetName(int $id, string $n): void { IPSKernel::$objects[$id]['name'] = $n; }

function IPS_GetObjectIDByIdent(string $ident, int $parent)
{
    foreach (IPS_GetChildrenIDs($parent) as $cid) {
        if ((IPSKernel::$objects[$cid]['ident'] ?? '') === $ident) {
            return $cid;
        }
    }
    return false;
}

function IPS_GetVariable(int $id): array
{
    return [
        'VariableType'   => IPSKernel::$objects[$id]['varType'] ?? VARIABLETYPE_INTEGER,
        'VariableAction' => IPSKernel::$objects[$id]['action'] ?? 0,
    ];
}

function IPS_GetInstance(int $id): array
{
    return ['ConnectionID' => 0, 'ModuleInfo' => ['ModuleID' => '{00000000-0000-0000-0000-000000000000}']];
}

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetProperty(int $id, string $k) { return null; }
function IPS_SetProperty(int $id, string $k, $v): void {}
function IPS_ApplyChanges(int $id): void {}

function GetValue(int $id) { return IPSKernel::$values[$id] ?? null; }
function SetValue(int $id, $v): void { IPSKernel::$values[$id] = $v; }

function RequestAction(int $id, $value): void
{
    // Ein "taubes" Ziel bekommt das Telegramm, übernimmt den Wert aber nicht –
    // genau das tut eine KNX-Variable, deren Gateway gerade weg ist.
    IPSKernel::$actions[] = [$id, $value];
    if (in_array($id, IPSKernel::$deaf, true)) {
        return;
    }
    IPSKernel::$values[$id] = $value;
}

// Profile – für den Test bedeutungslos, müssen nur existieren
function IPS_VariableProfileExists(string $n): bool { return true; }
function IPS_CreateVariableProfile(string $n, int $t): void {}
function IPS_DeleteVariableProfile(string $n): void {}
function IPS_GetVariableProfileList(): array { return []; }
function IPS_SetVariableProfileAssociation(string $p, $v, string $n, string $i, int $c): void {}
function IPS_SetVariableProfileIcon(string $p, string $i): void {}
function IPS_SetVariableProfileText(string $p, string $pre, string $suf): void {}
function IPS_SetVariableProfileValues(string $p, $min, $max, $step): void {}

/**
 * Basisklasse. $now ist steuerbar, damit sich Beruhigungszeit und
 * Token-Bucket ohne echtes Warten prüfen lassen.
 */
class IPSModule
{
    public $InstanceID = 4711;

    protected $properties  = [];
    protected $attributes  = [];
    protected $buffers     = [];
    protected $idents      = [];   // ident => objectID
    protected $timers      = [];
    protected $status      = 0;
    public    $debug       = [];

    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}

    public function RegisterPropertyString(string $n, string $v): void  { $this->properties[$n] = $v; }
    public function RegisterPropertyInteger(string $n, int $v): void    { $this->properties[$n] = $v; }
    public function RegisterPropertyBoolean(string $n, bool $v): void   { $this->properties[$n] = $v; }
    public function ReadPropertyString(string $n): string   { return (string) $this->properties[$n]; }
    public function ReadPropertyInteger(string $n): int     { return (int) $this->properties[$n]; }
    public function ReadPropertyBoolean(string $n): bool    { return (bool) $this->properties[$n]; }
    public function SetProperty(string $n, $v): void        { $this->properties[$n] = $v; }

    public function RegisterAttributeString(string $n, string $v): void { $this->attributes[$n] = $v; }
    public function RegisterAttributeBoolean(string $n, bool $v): void  { $this->attributes[$n] = $v; }
    public function ReadAttributeString(string $n): string  { return (string) $this->attributes[$n]; }
    public function ReadAttributeBoolean(string $n): bool   { return (bool) $this->attributes[$n]; }
    public function WriteAttributeString(string $n, string $v): void { $this->attributes[$n] = $v; }
    public function WriteAttributeBoolean(string $n, bool $v): void  { $this->attributes[$n] = $v; }

    public function SetBuffer(string $n, string $v): void { $this->buffers[$n] = $v; }
    public function GetBuffer(string $n): string { return $this->buffers[$n] ?? ''; }

    public function RegisterVariableBoolean(string $i, string $n, string $p = '', int $pos = 0): int { return $this->mkVar($i, VARIABLETYPE_BOOLEAN, false); }
    public function RegisterVariableInteger(string $i, string $n, string $p = '', int $pos = 0): int { return $this->mkVar($i, VARIABLETYPE_INTEGER, 0); }
    public function RegisterVariableFloat(string $i, string $n, string $p = '', int $pos = 0): int   { return $this->mkVar($i, VARIABLETYPE_FLOAT, 0.0); }
    public function RegisterVariableString(string $i, string $n, string $p = '', int $pos = 0): int  { return $this->mkVar($i, VARIABLETYPE_STRING, ''); }

    private function mkVar(string $ident, int $type, $init): int
    {
        if (isset($this->idents[$ident])) {
            return $this->idents[$ident];
        }
        $id = IPSKernel::$nextID++;
        IPSKernel::$objects[$id] = ['type' => 'var', 'name' => $ident, 'ident' => $ident, 'varType' => $type, 'action' => 0];
        IPSKernel::$values[$id]  = $init;
        $this->idents[$ident]    = $id;
        return $id;
    }

    public function EnableAction(string $i): void {}
    public function GetIDForIdent(string $i): int
    {
        if (!isset($this->idents[$i])) {
            throw new Exception('Ident ' . $i . ' unbekannt');
        }
        return $this->idents[$i];
    }
    public function SetValue(string $ident, $v): void { IPSKernel::$values[$this->GetIDForIdent($ident)] = $v; }
    public function GetValueOf(string $ident) { return IPSKernel::$values[$this->GetIDForIdent($ident)]; }

    public function RegisterTimer(string $n, int $ms, string $script): void { $this->timers[$n] = $ms; }
    public function SetTimerInterval(string $n, int $ms): void { $this->timers[$n] = $ms; }
    public function GetTimerInterval(string $n): int { return $this->timers[$n] ?? 0; }

    public function SetStatus(int $s): void { $this->status = $s; }
    public function GetStatus(): int { return $this->status; }
    public function ConnectParent(string $guid): void {}
    public function SendDebug(string $t, string $m, int $f): void { $this->debug[] = $t . ': ' . $m; }
    public function LogMessage(string $m, int $l): void { IPSKernel::$log[] = $m; }
}
