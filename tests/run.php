<?php

declare(strict_types=1);

/**
 * Offline-Testlauf für den Art-Net → KNX Konverter.
 *
 *   php tests/run.php
 *
 * Prüft Paketauswertung, Wertumrechnung und vor allem die Telegramm-Bremse –
 * also genau das, was sich an einer echten Anlage nur schlecht provozieren
 * lässt, ohne den KNX-Bus zuzumüllen.
 */

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../ArtNetKNX/module.php';

// ═══════════════════════════════════════════════════════════════════
//  Testgerüst
// ═══════════════════════════════════════════════════════════════════

final class T
{
    public static $pass = 0;
    public static $fail = 0;
    public static $case = '';

    public static function group(string $name): void
    {
        self::$case = $name;
        echo "\n── " . $name . "\n";
    }

    public static function ok(bool $cond, string $what): void
    {
        if ($cond) {
            self::$pass++;
            echo "   ✓ " . $what . "\n";
        } else {
            self::$fail++;
            echo "   ✗ " . $what . "\n";
        }
    }

    public static function eq($actual, $expected, string $what): void
    {
        $good = ($actual === $expected);
        if (!$good) {
            $what .= sprintf('  [erwartet %s, bekommen %s]',
                var_export($expected, true), var_export($actual, true));
        }
        self::ok($good, $what);
    }
}

/** Modul mit steuerbarer Uhr. */
final class TestKonverter extends ArtNetKNXKonverter
{
    public $t = 1000.0;

    protected function Now(): float
    {
        return $this->t;
    }

    public function tick(float $seconds): void
    {
        $this->t += $seconds;
    }

    /** Datagramm so einspeisen, wie es der UDP-Socket liefern würde. */
    public function feed(string $bin, string $ip = '192.168.10.50'): void
    {
        $this->ReceiveData(json_encode([
            'DataID'     => '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}',
            'Buffer'     => mb_convert_encoding($bin, 'UTF-8', 'ISO-8859-1'),
            'ClientIP'   => $ip,
            'ClientPort' => 6454,
        ]));
    }
}

/** ArtDmx-Paket bauen. $channels: Kanalnummer (1-basiert) => Wert 0…255 */
function artdmx(array $channels, int $portAddress = 0, int $seq = 0, int $len = 512): string
{
    $data = str_repeat("\0", $len);
    foreach ($channels as $ch => $v) {
        $data[$ch - 1] = chr($v);
    }
    return self_artnet_header($portAddress, $seq, $len) . $data;
}

function self_artnet_header(int $portAddress, int $seq, int $len): string
{
    return "Art-Net\0"
         . chr(0x00) . chr(0x50)              // OpCode 0x5000 (ArtDmx), Little-Endian
         . chr(0) . chr(14)                   // ProtVer 14
         . chr($seq) . chr(0)                 // Sequence, Physical
         . chr($portAddress & 0xFF)           // SubUni
         . chr(($portAddress >> 8) & 0xFF)    // Net
         . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
}

/** Modulinstanz mit Kanalliste aufsetzen. */
function makeModule(array $channels, array $props = []): TestKonverter
{
    $m = new TestKonverter();
    $m->Create();
    $m->SetProperty('Channels', json_encode($channels));
    foreach ($props as $k => $v) {
        $m->SetProperty($k, $v);
    }
    $m->ApplyChanges();
    return $m;
}

/** Seit dem letzten Aufruf abgesetzte KNX-Schreibvorgänge. */
function takeActions(): array
{
    $a = IPSKernel::$actions;
    IPSKernel::$actions = [];
    return $a;
}

// ═══════════════════════════════════════════════════════════════════
//  Tests
// ═══════════════════════════════════════════════════════════════════

T::group('Grundfall: DMX-Wert landet als Prozent auf der KNX-Instanz');
{
    IPSKernel::reset();
    $knx1 = IPSKernel::makeKnxInstance();
    $knx2 = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'Name' => 'Spot', 'TargetID' => $knx1, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
        ['Active' => true, 'Channel' => 5, 'Name' => 'Wand', 'TargetID' => $knx2, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ]);

    T::eq($m->GetStatus(), 102, 'Status 102 bei gültiger Zuordnung');
    T::eq($m->GetTimerInterval('Flush'), 100, 'Sendetakt 100 ms aktiv');
    T::eq($m->GetValueOf('Active'), true, 'Freigabe ist beim ersten Start an');

    $m->feed(artdmx([1 => 255, 5 => 128]));
    $m->Flush();

    $acts = takeActions();
    T::eq(count($acts), 2, 'zwei Telegramme abgesetzt');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx1)], 100, 'Kanal 1 (255) → 100 %');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx2)], 50, 'Kanal 5 (128) → 50 %');
    T::eq($m->GetValueOf('Online'), true, 'Online gesetzt');

    // Unverändertes Bild darf nichts mehr auslösen
    $m->tick(0.1);
    $m->feed(artdmx([1 => 255, 5 => 128], 0, 2));
    $m->Flush();
    T::eq(count(takeActions()), 0, 'unveränderter Frame erzeugt keine Telegramme');
}

T::group('Universum-Filter');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule(
        [['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false]],
        ['PortAddress' => 3]
    );

    $m->feed(artdmx([1 => 255], 1));      // falsches Universum
    $m->Flush();
    T::eq(count(takeActions()), 0, 'fremdes Universum wird verworfen');
    T::eq($m->GetValueOf('Online'), false, 'ohne passendes Paket kein Online');

    $m->feed(artdmx([1 => 255], 3));      // richtiges Universum
    $m->Flush();
    T::eq(count(takeActions()), 1, 'eigenes Universum wird verarbeitet');

    // Net-Anteil: Port-Address 300 = Net 1, SubUni 44
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m2 = makeModule(
        [['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false]],
        ['PortAddress' => 300]
    );
    $m2->feed(artdmx([1 => 255], 300));
    $m2->Flush();
    T::eq(count(takeActions()), 1, 'Port-Address über 255 (Net-Anteil) trifft');
}

T::group('Absenderfilter');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule(
        [['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false]],
        ['SourceIP' => '192.168.10.7']
    );

    $m->feed(artdmx([1 => 255]), '192.168.10.99');
    $m->Flush();
    T::eq(count(takeActions()), 0, 'fremde Absender-IP wird verworfen');

    $m->feed(artdmx([1 => 255]), '192.168.10.7');
    $m->Flush();
    T::eq(count(takeActions()), 1, 'erlaubte Absender-IP kommt durch');
    T::eq($m->GetValueOf('Source'), '', 'Quelle wird erst im Statistikfenster nachgeführt');
}

T::group('Totband und Beruhigung');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule(
        [['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false]],
        ['DeadbandPercent' => 5, 'SettleMs' => 400]
    );

    $m->feed(artdmx([1 => 255]));
    $m->Flush();
    takeActions();
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 100, 'Startwert 100 %');

    // 253 → 99 %: Sprung 1 % liegt unter dem Totband
    $m->tick(0.1);
    $m->feed(artdmx([1 => 253], 0, 2));
    $m->Flush();
    T::eq(count(takeActions()), 0, 'Änderung unter dem Totband wird zunächst unterdrückt');

    // ... aber nach der Beruhigungszeit muss der exakte Endwert raus
    $m->tick(0.5);
    $m->Flush();
    $acts = takeActions();
    T::eq(count($acts), 1, 'Endwert wird nach der Beruhigungszeit nachgezogen');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 99, 'nachgezogener Wert ist exakt (99 %)');

    // Großer Sprung geht sofort
    $m->tick(0.1);
    $m->feed(artdmx([1 => 0], 0, 3));
    $m->Flush();
    T::eq(count(takeActions()), 1, 'großer Sprung geht sofort raus');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 0, 'Aus ist angekommen');
}

T::group('Telegramm-Bremse (Token-Bucket)');
{
    IPSKernel::reset();
    $rows = [];
    $knx  = [];
    for ($i = 1; $i <= 5; $i++) {
        $knx[$i] = IPSKernel::makeKnxInstance();
        $rows[]  = ['Active' => true, 'Channel' => $i, 'TargetID' => $knx[$i],
                    'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false];
    }
    $m = makeModule($rows, ['MaxTelegramsPerSecond' => 2, 'DeadbandPercent' => 2]);

    $m->feed(artdmx([1 => 255, 2 => 255, 3 => 255, 4 => 255, 5 => 255]));
    $m->Flush();
    T::eq(count(takeActions()), 2, 'erster Durchlauf sendet nur den vollen Eimer (2)');

    $m->tick(0.1);   // 0,2 Token nachgewachsen – zu wenig
    $m->Flush();
    T::eq(count(takeActions()), 0, 'nach 100 ms reicht das Guthaben nicht');

    $m->tick(1.0);   // 2 Token
    $m->Flush();
    T::eq(count(takeActions()), 2, 'nach einer Sekunde wieder 2 Telegramme');

    $m->tick(1.0);
    $m->Flush();
    T::eq(count(takeActions()), 1, 'letzter offener Kanal geht raus');

    $m->tick(1.0);
    $m->Flush();
    T::eq(count(takeActions()), 0, 'danach ist Ruhe');

    $sum = 0;
    foreach ($knx as $iid) {
        $sum += (int) IPSKernel::$values[IPSKernel::valueVarOf($iid)];
    }
    T::eq($sum, 500, 'am Ende stehen alle fünf Kanäle auf 100 %');
}

T::group('Priorisierung: der größte Sprung zuerst');
{
    IPSKernel::reset();
    $klein = IPSKernel::makeKnxInstance();
    $gross = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $klein, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
        ['Active' => true, 'Channel' => 2, 'TargetID' => $gross, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['MaxTelegramsPerSecond' => 1, 'DeadbandPercent' => 2]);

    // Beide auf einen Ausgangswert bringen
    $m->feed(artdmx([1 => 128, 2 => 128]));
    for ($i = 0; $i < 4; $i++) {
        $m->tick(1.0);
        $m->Flush();
    }
    takeActions();

    // Kanal 1 rutscht 10 %, Kanal 2 springt auf 0
    $m->tick(1.0);
    $m->feed(artdmx([1 => 154, 2 => 0], 0, 2));
    $m->Flush();
    $acts = takeActions();
    T::eq(count($acts), 1, 'nur ein Telegramm erlaubt');
    T::eq($acts[0][0], IPSKernel::valueVarOf($gross), 'der große Sprung (auf 0) kommt zuerst');
}

T::group('Kennlinie: Invertierung und Mindesthelligkeit');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0,
         'MinPercent' => 10, 'MaxPercent' => 80, 'Invert' => false],
    ], ['MaxTelegramsPerSecond' => 50, 'DeadbandPercent' => 0]);
    $vid = IPSKernel::valueVarOf($knx);

    $steps = [0 => 0, 1 => 10, 255 => 80, 128 => 45];
    foreach ($steps as $raw => $expect) {
        $m->tick(1.0);
        $m->feed(artdmx([1 => $raw], 0, 0));
        $m->Flush();
        T::eq(IPSKernel::$values[$vid], $expect, sprintf('roh %3d → %d %% (Bereich 10–80)', $raw, $expect));
    }

    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $mi = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0,
         'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => true],
    ], ['DeadbandPercent' => 0]);
    $mi->feed(artdmx([1 => 0]));
    $mi->Flush();
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 100, 'invertiert: roh 0 → 100 %');
}

T::group('Rohwert-Skalierung (DPT 5.010) und Variablentypen');
{
    IPSKernel::reset();
    $roh   = IPSKernel::makeKnxInstance(VARIABLETYPE_INTEGER);
    $float = IPSKernel::makeKnxInstance(VARIABLETYPE_FLOAT);
    $bool  = IPSKernel::makeKnxInstance(VARIABLETYPE_BOOLEAN);
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $roh, 'Scale' => 1, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
        ['Active' => true, 'Channel' => 2, 'TargetID' => $float, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
        ['Active' => true, 'Channel' => 3, 'TargetID' => $bool, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['MaxTelegramsPerSecond' => 50]);

    $m->feed(artdmx([1 => 128, 2 => 128, 3 => 128]));
    $m->Flush();
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($roh)], 128, 'Rohmodus: 50 % → 128');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($float)], 50.0, 'Float-Ziel bekommt einen Float');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($bool)], true, 'Boolean-Ziel bekommt true');
}

T::group('Sequenznummern: verspätete Pakete verwerfen');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['DeadbandPercent' => 0, 'MaxTelegramsPerSecond' => 50]);
    $vid = IPSKernel::valueVarOf($knx);

    $m->feed(artdmx([1 => 255], 0, 10));
    $m->Flush();
    T::eq(IPSKernel::$values[$vid], 100, 'Paket mit Sequenz 10 wird übernommen');

    $m->tick(0.1);
    $m->feed(artdmx([1 => 0], 0, 9));      // überholt eingetroffen
    $m->Flush();
    T::eq(IPSKernel::$values[$vid], 100, 'ältere Sequenz 9 wird ignoriert');

    $m->tick(0.1);
    $m->feed(artdmx([1 => 0], 0, 11));
    $m->Flush();
    T::eq(IPSKernel::$values[$vid], 0, 'neuere Sequenz 11 kommt durch');

    // Überlauf 255 → 1: in realistischen Schritten bis ans Ende zählen
    foreach ([100, 200, 255] as $seq) {
        $m->tick(0.025);
        $m->feed(artdmx([1 => 255], 0, $seq));
        $m->Flush();
    }
    T::eq(IPSKernel::$values[$vid], 100, 'Sequenz bis 255 hochgezählt');

    $m->tick(0.025);
    $m->feed(artdmx([1 => 51], 0, 1));
    $m->Flush();
    T::eq(IPSKernel::$values[$vid], 20, 'Sequenzüberlauf 255 → 1 wird als neuer erkannt');

    // Pult startet neu und beginnt mitten in der Zählung von vorn: nach einer
    // Sendepause muss das Modul die neue Zählung annehmen, statt hängenzubleiben.
    foreach ([100, 200] as $seq) {
        $m->tick(0.025);
        $m->feed(artdmx([1 => 255], 0, $seq));
        $m->Flush();
    }
    T::eq(IPSKernel::$values[$vid], 100, 'Sequenz auf 200 gebracht');

    $m->tick(3.0);                                   // Pause: Pult war weg
    $m->feed(artdmx([1 => 26], 0, 1));               // Zählung beginnt neu bei 1
    $m->Flush();
    T::eq(IPSKernel::$values[$vid], 10, 'nach einer Sendepause wird die neue Zählung übernommen');

    $m->tick(0.025);
    $m->feed(artdmx([1 => 0], 0, 2));
    $m->Flush();
    T::eq(IPSKernel::$values[$vid], 0, 'und läuft danach normal weiter');
}

T::group('Freigabe');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['MaxTelegramsPerSecond' => 50]);

    $m->RequestAction('Active', false);
    $m->feed(artdmx([1 => 255]));
    $m->Flush();
    T::eq(count(takeActions()), 0, 'bei ausgeschalteter Freigabe wird nichts gesendet');

    $m->tick(0.1);
    $m->RequestAction('Active', true);
    $m->Flush();
    T::eq(count(takeActions()), 1, 'nach dem Einschalten wird sofort neu synchronisiert');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 100, 'aktueller DMX-Wert steht wieder an');
}

T::group('Signalausfall');
{
    // Halten (Standard)
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['TimeoutMs' => 1000, 'TimeoutAction' => 0, 'MaxTelegramsPerSecond' => 50]);
    $m->feed(artdmx([1 => 255]));
    $m->Flush();
    takeActions();

    $m->tick(2.0);
    $m->Flush();
    T::eq($m->GetValueOf('Online'), false, 'Online fällt nach dem Timeout ab');
    T::eq(count(takeActions()), 0, 'Halten: kein Telegramm');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 100, 'Licht bleibt stehen');

    // Auf 0 fahren
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m2 = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['TimeoutMs' => 1000, 'TimeoutAction' => 1, 'MaxTelegramsPerSecond' => 50]);
    $m2->feed(artdmx([1 => 255]));
    $m2->Flush();
    takeActions();

    $m2->tick(2.0);
    $m2->Flush();
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 0, 'Ausfallverhalten „auf 0" greift');
    T::eq(count(takeActions()), 1, 'genau ein Telegramm zum Ausschalten');

    $m2->tick(1.0);
    $m2->Flush();
    T::eq(count(takeActions()), 0, 'die 0 wird nicht in Dauerschleife wiederholt');
}

T::group('Robustheit gegen Fremdpakete');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ]);

    $m->feed('');                                   // leeres Datagramm
    $m->feed('irgendwas kurzes');                   // kein Art-Net
    $m->feed("Art-Net\0" . str_repeat("\0", 40));   // Art-Net, aber OpCode 0
    $m->feed("Art-Net\0" . chr(0x00) . chr(0x20) . str_repeat("\0", 30));  // ArtPoll
    $m->feed(substr(artdmx([1 => 255]), 0, 12));    // abgeschnittener Header
    $m->Flush();
    T::eq(count(takeActions()), 0, 'Müll und Fremdpakete lösen nichts aus');
    T::eq($m->GetValueOf('Online'), false, 'und setzen auch kein Online');

    // Kurzes, aber gültiges Paket (nur 8 Kanäle im Frame)
    $m->feed(artdmx([1 => 255], 0, 1, 8));
    $m->Flush();
    T::eq(count(takeActions()), 1, 'gültiges Kurzpaket wird verarbeitet');

    // Kanal jenseits der Framelänge → 0, kein Fehler
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m3 = makeModule([
        ['Active' => true, 'Channel' => 400, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ]);
    $m3->feed(artdmx([1 => 255], 0, 1, 16));
    $m3->Flush();
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 0, 'Kanal außerhalb des Frames bleibt 0');
}

T::group('Konfigurationsfehler');
{
    IPSKernel::reset();
    $m = makeModule([]);
    T::eq($m->GetStatus(), 104, 'ohne Kanäle Status 104');
    T::eq($m->GetTimerInterval('Flush'), 0, 'Timer bleibt aus');

    IPSKernel::reset();
    $m2 = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => 999999, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ]);
    T::eq($m2->GetStatus(), 104, 'nicht auflösbares Ziel → keine gültige Zuordnung');
    T::ok(count(IPSKernel::$log) > 0, 'und ein Hinweis im Log');

    // Doppelt belegtes Ziel
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m3 = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
        ['Active' => true, 'Channel' => 2, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ]);
    $map = json_decode($m3->ReadAttributeString('Map'), true);
    T::eq(count($map), 1, 'doppelt belegtes Ziel wird nur einmal übernommen');
    T::ok(count(IPSKernel::$log) > 0, 'mit Warnung im Log');

    // Deaktivierte Zeile
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m4 = makeModule([
        ['Active' => false, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ]);
    T::eq($m4->GetStatus(), 104, 'deaktivierte Zeile zählt nicht');
}

T::group('Statistik');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['MaxTelegramsPerSecond' => 50, 'DeadbandPercent' => 0]);

    // 40 Pakete in einer Sekunde, dabei ein sanfter Fade
    for ($i = 0; $i < 40; $i++) {
        $m->tick(0.025);
        $m->feed(artdmx([1 => (int) round($i * 255 / 39)], 0, ($i % 255) + 1));
        $m->Flush();
    }
    $m->tick(0.05);
    $m->Flush();

    T::ok($m->GetValueOf('PacketRate') >= 30, 'Paketrate wird gemessen (' . $m->GetValueOf('PacketRate') . ' Pkt/s)');
    T::ok($m->GetValueOf('TelegramRate') > 0, 'Telegrammrate wird gemessen (' . $m->GetValueOf('TelegramRate') . ' Tel/s)');
    T::eq($m->GetValueOf('Source'), '192.168.10.50', 'Quelle wird angezeigt');
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 100, 'Fade endet exakt auf 100 %');
}

T::group('Buslast über einen echten Fade');
{
    // 8 Kanäle, 3 Sekunden Fade von 0 auf voll, Pult sendet mit 40 Hz.
    IPSKernel::reset();
    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = ['Active' => true, 'Channel' => $i, 'TargetID' => IPSKernel::makeKnxInstance(),
                   'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false];
    }
    $m = makeModule($rows, ['MaxTelegramsPerSecond' => 10, 'DeadbandPercent' => 2, 'SettleMs' => 400]);

    $frames = 120;   // 3 s bei 40 Hz
    for ($f = 0; $f < $frames; $f++) {
        $m->tick(0.025);
        $v = (int) round($f * 255 / ($frames - 1));
        $m->feed(artdmx(array_fill_keys(range(1, 8), $v), 0, ($f % 255) + 1));
        $m->Flush();
    }
    // Nachlaufzeit, damit die Endwerte durchkommen
    for ($f = 0; $f < 40; $f++) {
        $m->tick(0.1);
        $m->Flush();
    }

    $tel = count(IPSKernel::$actions);
    $sek = 3.0 + 4.0;
    T::ok($tel <= (int) ceil(10 * $sek) + 1,
        sprintf('Ratenlimit eingehalten: %d Telegramme in %.0f s (Grenze %d)', $tel, $sek, (int) ceil(10 * $sek)));

    $alleVoll = true;
    foreach ($rows as $r) {
        if ((int) IPSKernel::$values[IPSKernel::valueVarOf((int) $r['TargetID'])] !== 100) {
            $alleVoll = false;
        }
    }
    T::ok($alleVoll, 'trotz Bremse stehen am Ende alle 8 Kanäle exakt auf 100 %');
    takeActions();
}

T::group('Ziel nimmt den Wert nicht an (totes Gateway)');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    IPSKernel::$deaf[] = IPSKernel::valueVarOf($knx);   // Telegramm ja, Wert nein
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ], ['MaxTelegramsPerSecond' => 50, 'DeadbandPercent' => 0]);

    $m->feed(artdmx([1 => 255]));
    $versuche = 0;
    for ($i = 0; $i < 6; $i++) {
        $m->tick(0.1);
        $m->Flush();
        $versuche += count(takeActions());
    }
    T::eq($versuche, 3, 'unbestätigter Wert wird dreimal wiederholt');
    T::ok(count(IPSKernel::$log) > 0, 'danach steht eine Warnung im Log');

    $m->tick(1.0);
    $m->Flush();
    T::eq(count(takeActions()), 0, 'danach ist Ruhe – kein Dauerfeuer auf den Bus');

    // Ziel wird wieder gesprächig: neuer Wert muss ankommen
    IPSKernel::$deaf = [];
    $m->tick(0.1);
    $m->feed(artdmx([1 => 128], 0, 2));
    $m->Flush();
    T::eq(IPSKernel::$values[IPSKernel::valueVarOf($knx)], 50, 'nach der Erholung kommt der nächste Wert an');
    takeActions();
}

T::group('Bindeadresse');
{
    IPSKernel::reset();
    $knx = IPSKernel::makeKnxInstance();
    $m = makeModule([
        ['Active' => true, 'Channel' => 1, 'TargetID' => $knx, 'Scale' => 0, 'MinPercent' => 0, 'MaxPercent' => 100, 'Invert' => false],
    ]);
    T::eq($m->ReadPropertyString('BindIP'), '0.0.0.0', 'Vorgabe ist 0.0.0.0, nicht leer');
}

// ═══════════════════════════════════════════════════════════════════

echo "\n";
echo str_repeat('─', 60) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", T::$pass, T::$fail);
exit(T::$fail === 0 ? 0 : 1);
