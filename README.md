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
| **Worx Cloud** | Anmeldung, Token-Verwaltung, Abfrage, MQTT-Verteilung |
| **Worx Configurator** | findet die Mäher des Kontos und legt sie an |
| **Worx Mower** | ein Mähroboter mit Variablen und Steuerung |

Darunter liegt eine Transportkette aus Symcon-Bordmitteln, die das Modul selbst
anlegt und pflegt:

```
WS Client  ─▶  MQTT Client  ─▶  Worx Cloud  ─▶  Worx Mower
```

## Echtzeit und Steuerung

Der Zustand kommt über zwei Wege: **MQTT** liefert ihn in dem Moment, in dem der
Mäher ihn meldet, **REST** dient als Bestandsabfrage und Rückfallebene. Steuerbefehle
(Start, Pause, Heimfahrt) laufen ausschließlich über MQTT — per REST nimmt die Worx-Cloud
sie zwar mit HTTP 200 entgegen, führt sie aber nicht aus (nachgemessen: ein `cmd` mit
Unsinnswert wird ebenso kommentarlos geschluckt, während echte Felder mit HTTP 422
abgelehnt werden).

MQTT spricht AWS IoT über WebSocket. Zwei Details entscheiden über Erfolg:

- Der Handshake braucht das Subprotokoll `Sec-WebSocket-Protocol: mqtt`. Fehlt es,
  antwortet AWS mit **HTTP 426** und die Verbindung kommt nie zustande.
- Die Auth-Header werden aus dem Zugangs-Token abgeleitet (base64url → base64,
  an den Punkten zerlegt) und laufen nach einer Stunde ab. Das Modul erneuert sie
  alle 45 Minuten selbsttätig.

Es fallen **keine AWS-Kosten** an: Die Infrastruktur gehört Worx, das Modul meldet
sich dort lediglich als Client an — genau wie die Landroid-App.

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

## Abfrageintervall

Mit aktivem MQTT genügt ein großes Intervall (Standard 300 s) — der Zustand kommt
von selbst. Häufigeres Abfragen bringt ohnehin wenig: Die REST-API liefert nur den
letzten Stand, den der Mäher von sich aus gemeldet hat, und der ist oft einige Minuten
alt. Worx begrenzt auf **2880 Abfragen pro Tag** (`X-RateLimit-Limit`), was einer
Abfrage alle 30 Sekunden entspricht.

## Hinweis

Worx bietet keine öffentliche API und keine Dokumentation. Diese Anbindung beruht auf
den Erkenntnissen der Community. Ändert Worx die Schnittstelle, kann sie brechen.

## Lizenz

MIT
