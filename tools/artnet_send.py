#!/usr/bin/env python3
"""
Kleiner Art-Net-Sender für die Inbetriebnahme – ersetzt das Lichtpult,
solange noch keins am Netz hängt.

    # Kanal 1 auf Vollwert, dauerhaft senden (40 Hz)
    python3 artnet_send.py --host 192.168.10.56 --set 1=255

    # Fade über 5 s auf mehreren Kanälen
    python3 artnet_send.py --host 192.168.10.56 --fade 1,2,3 --seconds 5

    # Broadcast ins Subnetz, Universum 3
    python3 artnet_send.py --host 192.168.10.255 --universe 3 --set 1=128

Ohne --host wird an 255.255.255.255 gebroadcastet.
"""

import argparse
import socket
import struct
import sys
import time

ART_PORT = 6454


def artdmx(port_address: int, sequence: int, data: bytes) -> bytes:
    """ArtDmx-Paket bauen (18 Byte Header + DMX-Daten)."""
    length = len(data)
    if length % 2:                      # Länge muss gerade sein
        data += b"\x00"
        length += 1
    return (
        b"Art-Net\x00"
        + struct.pack("<H", 0x5000)     # OpCode, Little-Endian
        + struct.pack(">H", 14)         # ProtVer
        + bytes([sequence & 0xFF, 0])   # Sequence, Physical
        + bytes([port_address & 0xFF, (port_address >> 8) & 0xFF])
        + struct.pack(">H", length)
        + data
    )


def parse_set(values):
    out = {}
    for item in values:
        for pair in item.split(","):
            ch, _, val = pair.partition("=")
            out[int(ch)] = int(val)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description="Art-Net Testsender")
    ap.add_argument("--host", default="255.255.255.255", help="Ziel-IP (Standard: Broadcast)")
    ap.add_argument("--port", type=int, default=ART_PORT)
    ap.add_argument("--universe", type=int, default=0, help="Port-Address (Net*256 + Subnet*16 + Universum)")
    ap.add_argument("--channels", type=int, default=512, help="Anzahl Kanäle im Frame")
    ap.add_argument("--rate", type=float, default=40.0, help="Frames pro Sekunde")
    ap.add_argument("--set", action="append", default=[], metavar="KANAL=WERT",
                    help="feste Werte, z. B. --set 1=255,5=128")
    ap.add_argument("--fade", metavar="KANÄLE", help="Kanäle für einen Auf-/Ab-Fade, z. B. 1,2,3")
    ap.add_argument("--seconds", type=float, default=5.0, help="Dauer eines Fade-Durchlaufs")
    ap.add_argument("--once", action="store_true", help="nur ein Paket senden")
    args = ap.parse_args()

    fixed = parse_set(args.set)
    fade_channels = [int(c) for c in args.fade.split(",")] if args.fade else []

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)

    print(f"→ {args.host}:{args.port}  Universum {args.universe}  {args.rate:.0f} Hz")
    if fixed:
        print("   fest:  " + ", ".join(f"K{c}={v}" for c, v in sorted(fixed.items())))
    if fade_channels:
        print(f"   Fade:  Kanäle {fade_channels} über {args.seconds:.1f} s (auf und ab)")
    print("   Strg+C zum Beenden\n")

    seq = 1
    start = time.time()
    period = 1.0 / max(1.0, args.rate)
    sent = 0

    try:
        while True:
            frame = bytearray(args.channels)
            for ch, val in fixed.items():
                if 1 <= ch <= args.channels:
                    frame[ch - 1] = max(0, min(255, val))

            if fade_channels:
                # Dreieck: hoch, runter, hoch …
                phase = ((time.time() - start) % (2 * args.seconds)) / args.seconds
                level = int(round(255 * (phase if phase <= 1 else 2 - phase)))
                for ch in fade_channels:
                    if 1 <= ch <= args.channels:
                        frame[ch - 1] = level

            sock.sendto(artdmx(args.universe, seq, bytes(frame)), (args.host, args.port))
            seq = seq % 255 + 1
            sent += 1
            if sent % 40 == 0:
                print(f"\r   {sent} Pakete gesendet", end="", flush=True)

            if args.once:
                print("\r   1 Paket gesendet")
                return 0
            time.sleep(period)
    except KeyboardInterrupt:
        print(f"\r   {sent} Pakete gesendet – Ende")
        return 0


if __name__ == "__main__":
    sys.exit(main())
