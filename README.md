# Art-Net → KNX Konverter

IP-Symcon-Modul, das ankommendes **Art-Net (DMX über Ethernet)** in Echtzeit auf
**KNX-Instanzen** legt. Ein Lichtpult (MagicQ, MADRIX, grandMA …) steuert damit
KNX-Dimmaktoren bzw. die dahinterliegenden DALI-Gateways, als wären es
DMX-Fixtures.

```
┌──────────┐  ArtDmx / UDP 6454  ┌────────────┐  RequestAction  ┌─────────────┐
│  Pult    │ ──────────────────► │ UDP Socket │ ──────────────► │  KNX DPT 5  │
│ (MagicQ) │                     │  + Modul   │                 │  Instanzen  │
└──────────┘                     └────────────┘                 └─────────────┘
                                                                       │
                                                                 KNX ──┴── DALI-GW
```

- **Bibliothek:** Art-Net KNX Konverter
- **Modul:** ArtNet KNX Konverter (Type 3, Prefix `ANKNX`)
- **Parent:** UDP Socket (wird automatisch angelegt und eingestellt)
- **Stand:** 0.1 / Build 1 — Logik offline getestet (71 Prüfungen), **noch nicht
  gegen echte Hardware verifiziert**

---

## Warum das Modul bremst

Art-Net liefert bis zu **44 Frames pro Sekunde**. KNX-TP verträgt dauerhaft
rund **10 Telegramme pro Sekunde** — darüber ist der Bus dicht und Taster
reagieren spürbar träge. Ein 1:1-Durchreichen würde die Anlage lahmlegen.

Das Modul entkoppelt deshalb Empfang und Versand:

| Ebene | Aufgabe |
|---|---|
| `ReceiveData()` | Nur Header prüfen, Universum filtern, Frame in den Puffer legen. Sonst nichts. |
| `Flush()` (Timer, Standard 100 ms) | Prozentwerte rechnen, Totband anwenden, nach Sprunghöhe priorisieren, über einen Token-Bucket max. N Telegramme/s absetzen. |

Drei Regeln sorgen dafür, dass trotz Bremse nichts auf einem Zwischenwert
hängen bleibt:

1. **Totband** (Standard 2 %) — kleine Zappeleien werden unterdrückt.
2. **Beruhigung** (Standard 400 ms) — steht ein Kanal so lange still, geht der
   *exakte* Endwert einmal raus, auch wenn er unter dem Totband liegt.
3. **Endpunkt-Vorrang** — „ganz aus" und „ganz auf" drängeln sich in der
   Warteschlange nach vorn. Aus muss sitzen.

Gemessen im Testlauf: ein 3-Sekunden-Fade über 8 Kanäle bei 40 Hz Pult-Rate
ergibt **47 Telegramme** statt der 9 600, die roh anfallen würden — und alle
8 Kanäle stehen am Ende exakt auf 100 %.

---

## Warum nicht das Standard-DMX-Modul von Symcon?

Weil es die falsche Richtung kann. Das mitgelieferte Modul **DMX / ArtNet** ist
ein reiner **Sender**: Symcon ist dort der Master und schickt auf
`Sende-Host:6454` hinaus. Es gibt ausschließlich Setter
(`DMX_SetChannel`, `DMX_FadeChannel`, `DMX_SetRGB`, `DMX_SetBlackOut`) — kein
`DMX_GetChannel`, keine Variablen für eingehende Kanäle, kein Empfangsereignis.

Aus dem Forum (Thread „ArtNet DMX auslesen"):

> „DMX ist im Standardfall unidirektional, somit kann man den ‚Zustand' nicht
> auslesen." — das Modul unterstützt das für ArtNet nicht.

Das Konfigurationsfeld **„Empf.-Host"** führt dabei in die Irre: gemeint ist die
eigene Netzwerkkarte für die Antworten *des Interfaces*. Der zugehörige
UDP-Socket bindet auf **Port 53000**, nicht auf 6454 — er lauscht also nicht auf
ankommendes ArtDmx.

**Kein Portkonflikt:** Soll Symcon zusätzlich selbst DMX *senden*, ist das
Standardmodul weiterhin die richtige Wahl. Es bindet 53000, dieses Modul 6454.

Quellen:
[Modulreferenz DMX / ArtNet](https://www.symcon.de/de/service/dokumentation/modulreferenz/dmx-artnet/),
[Forum: ArtNet DMX auslesen](https://community.symcon.de/thread/37307)

---

## Einrichtung

1. **Instanz anlegen:** „ArtNet KNX Konverter". Der UDP-Socket darüber wird
   automatisch erzeugt und auf Port 6454, Broadcast und Adressweiterverwendung
   gestellt.
2. **Universum** eintragen (Port-Address = `Net × 256 + Subnet × 16 + Universum`).
   Ein Pult, das schlicht „Universum 1" anzeigt, sendet meistens auf **0**.
3. **Kanäle zuordnen:** je Zeile DMX-Kanal → KNX-Instanz.
4. **Prüfen** mit dem Knopf **„Universum anzeigen"** — der zeigt den zuletzt
   empfangenen Frame, noch bevor irgendetwas auf den Bus geht.

> **Per Skript angelegt?** `ConnectParent()` greift nur beim Anlegen über die
> Konsole. Wer die Instanz aus einem Skript erzeugt, muss den Socket selbst
> anlegen und verbinden:
>
> ```php
> $sock = IPS_CreateInstance('{82347F20-F541-41E1-AC5B-A636FD3AE2D8}');
> IPS_ConnectInstance($id, $sock);
> ```

### Mehrere Universen

Je Universum eine Instanz anlegen. Alle dürfen sich denselben UDP-Socket
teilen; jede Instanz filtert selbst auf ihre Port-Address.

### Als Ziel gehört dorthin die Dimmwert-GA

Die Zielinstanz ist die **KNX-DPT-Instanz des Dimmwerts** (DPT 5.001), nicht die
Status-GA. Das Modul löst die `Value`-Variable der Instanz auf und schreibt per
`RequestAction()` — nur das löst das Senden auf den Bus aus. Eine direkt
angegebene Variable wird ebenfalls akzeptiert.

### Kennlinie je Kanal

| Feld | Wirkung |
|---|---|
| **Wertebereich** | Prozent 0–100 (DPT 5.001) oder roh 0–255 (DPT 5.010) |
| **Min % / Max %** | DMX 0 schaltet **immer** aus; alles darüber wird in `Min…Max` gespreizt. So lässt sich die Mindesthelligkeit eines Aktors berücksichtigen, ohne dass der Fader unten nicht mehr ausschaltet. |
| **Invertiert** | für Kanäle, die verkehrt herum laufen |

---

## Variablen

| Ident | Typ | Bedeutung |
|---|---|---|
| `Online` | bool | Es kommen Art-Net-Pakete für das eingestellte Universum an |
| `Active` | bool, schaltbar | **Freigabe.** Aus = KNX gehört wieder der Hausautomation, DMX wird verworfen. Beim Einschalten wird sofort neu synchronisiert. |
| `PacketRate` | int | empfangene Pakete/s — zeigt, ob das Pult wirklich sendet |
| `TelegramRate` | int | abgesetzte KNX-Telegramme/s — die tatsächliche Buslast |
| `Source` | string | IP des sendenden Pults |

Die **Freigabe** ist der wichtigste Schalter im Betrieb: solange sie an ist,
gehört das Licht dem Pult. Aus heißt, Szenen und Taster haben wieder freie Bahn.

---

## Öffentliche Funktionen

```php
ANKNX_Flush(int $InstanceID);                    // Sendezyklus (Timer)
ANKNX_SetActive(int $InstanceID, bool $Active);  // Freigabe setzen
ANKNX_ResendAll(int $InstanceID);                // alles neu senden
ANKNX_GetChannel(int $InstanceID, int $Channel); // Rohwert 0…255, -1 = nichts empfangen
ANKNX_DumpUniverse(int $InstanceID);             // Diagnoseausgabe
```

---

## Verhalten bei Signalausfall

Kommt länger als `TimeoutMs` (Standard 3 s) nichts an, fällt `Online` ab. Dann
greift eine von zwei Einstellungen:

- **Letzten Wert halten** (Standard) — fällt das Pult aus, bleibt das Licht so,
  wie es war. Das ist der sichere Fall.
- **Alles auf 0 fahren** — nur wählen, wenn ausgeschaltetes Licht wirklich das
  gewünschte Ergebnis ist.

Startet das Pult neu und beginnt seine Sequenznummern von vorn, nimmt das Modul
die neue Zählung nach einer Sendepause von 1 s an — sonst bliebe es hängen, weil
es die neue Zählung für veraltet hielte.

---

## Test und Inbetriebnahme

### Offline-Testlauf (ohne SymBox)

```bash
php tests/run.php
```

71 Prüfungen zu Paketauswertung, Wertumrechnung, Totband, Ratenlimit,
Sequenznummern, Signalausfall und Fehlkonfiguration. `tests/stubs.php` bildet
die benutzten Symcon-Funktionen nach.

### Art-Net senden, wenn kein Pult da ist

```bash
# Kanal 1 auf Vollwert
python3 tools/artnet_send.py --host 192.168.10.56 --set 1=255

# Fade über 5 s auf drei Kanälen – zeigt die Bremse in Aktion
python3 tools/artnet_send.py --host 192.168.10.56 --fade 1,2,3 --seconds 5

# Broadcast, Universum 3
python3 tools/artnet_send.py --host 192.168.10.255 --universe 3 --set 1=128
```

---

## Offene Punkte

- **Noch nicht an echter Hardware verifiziert.** Zu prüfen sind vor allem: ob
  der UDP-Socket die Datagramme mit `ClientIP` über
  `{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}` liefert, und wie viel PHP-Last 44
  `ReceiveData()`-Aufrufe pro Sekunde auf der SymBox wirklich erzeugen
  (`PacketRate` gegen die Pult-Rate halten).
- **Kein ArtPollReply.** Das Modul antwortet nicht auf ArtPoll, taucht also in
  der Node-Liste des Pults nicht auf. Zum Empfangen ist das nicht nötig.
- Nur Dimmerkanäle. Schalten, RGB/RGBW und Tunable White sind bewusst nicht
  drin — bei Bedarf nachrüstbar.
