# IP-Symcon: Worx Landroid

Bindet Mähroboter von **Worx Landroid**, **Kress**, **Landxcape** und **Ferrex** direkt
in IP-Symcon ein — ohne Zusatzdienst auf dem Server.

## Warum dieses Modul

Die verbreitete Lösung besteht aus zwei Teilen: einem Symcon-Modul und einer
Node.js-Bridge (`mqtt-landroid-bridge`), die als eigener Dienst auf dem Server läuft.
Diese Bridge ist seit Februar 2024 eingestellt („end of support") und seit Mitte 2026
funktionsunfähig: Sie ruft `GET /api/v2/users/me` auf, das Worx inzwischen mit
**HTTP 405** beantwortet (`Supported methods: DELETE`). Danach fehlt der
`mqtt_endpoint`, die Cloud-Verbindung kommt nie zustande — der Prozess läuft aber
weiter, sodass es wie ein sporadisch abstürzender Dienst aussieht.

Dieses Modul umgeht das Problem, statt es zu flicken: `user_id` und `mqtt_endpoint`
stehen ohnehin in jedem Eintrag von `product-items`. Der kaputte Endpunkt wird gar
nicht erst aufgerufen. Denselben Weg geht die aktiv gepflegte Bibliothek
[pyworxcloud](https://github.com/MTrab/pyworxcloud) hinter der Home-Assistant-Integration.

Damit entfallen Node.js, der systemd-Dienst und der MQTT-Umweg über den lokalen Broker.

## Aufbau

| Instanz | Aufgabe |
|---|---|
| **Worx Cloud** | Anmeldung, Token-Verwaltung, regelmäßiges Abfragen |
| **Worx Configurator** | findet die Mäher des Kontos und legt sie an |
| **Worx Mower** | ein Mähroboter mit seinen Variablen |

## Installation

Modul-Store → *Modul hinzufügen* → URL:

```
https://github.com/c-tomic/IPSymconWorx
```

Danach eine Instanz **Worx Cloud** anlegen, E-Mail und Passwort des Worx-Kontos
eintragen — das sind dieselben Zugangsdaten wie in der Landroid-App. Über den
**Worx Configurator** werden die gefundenen Mäher per Klick angelegt.

## Variablen

Status, Fehler, Online, Akkustand, Ladezustand, Akkutemperatur und -spannung,
Ladezyklen, WLAN-Signal, Gesamtstrecke, Mähzeit, Messerlaufzeit, Regenerkennung,
Sperre, aktuelle Zone, Firmware und Zeitpunkt der letzten Meldung.

Status- und Fehlercodes werden in Klartext übersetzt („Mäht", „In der Ladestation",
„Messermotor blockiert" …).

## Grenzen

Dieses Modul **liest** den Zustand. Steuerbefehle (Start, Pause, Heimfahrt) laufen bei
Worx über AWS IoT MQTT mit Custom-Auth-Headern — das ist als nächster Schritt geplant,
aber noch nicht enthalten.

Das Abfrageintervall steht standardmäßig auf 120 Sekunden. Deutlich kürzere Intervalle
sind nicht ratsam: Worx sperrt Konten bei zu häufigen Abfragen zeitweise aus.

## Hinweis

Worx bietet keine öffentliche API und keine Dokumentation. Diese Anbindung beruht auf
den Erkenntnissen der Community. Ändert Worx die Schnittstelle, kann sie brechen.

## Lizenz

MIT
