# Wandtablet einrichten

*English version: [`kiosk.md`](kiosk.md)*

Das Tablet ist ein **Gerät, kein Benutzer**. Niemand meldet sich an der Wand an.
Es bekommt einmalig über einen Kopplungscode ein dauerhaftes Token und bleibt
danach angemeldet — auch über Neustarts hinweg.

Diese Anleitung beschreibt beides: das Koppeln und den Dauerbetrieb im Kiosk.

---

## 1. Gerät in Fahoba anlegen

1. Verwaltung öffnen (`/admin`), **Geräte → Erstellen**
2. **Bezeichnung** eintragen — wo es hängt, etwa „Küche" oder „Flur"
3. **Art**: Wandtablet
4. Speichern

## 2. Kopplungscode erzeugen

In der Geräteliste bei dem Eintrag **„Kopplungscode“** wählen. Der Code
erscheint als Einblendung und bleibt stehen, bis er weggeklickt wird.

- **Acht Zeichen**, ohne `0`/`O` und `1`/`I` — damit nichts verwechselt wird
- **15 Minuten gültig**, danach einen neuen erzeugen
- **Einmal einlösbar**; beim Einlösen wird er gelöscht

Die Aktion lässt sich jederzeit wiederholen. Nötig ist das nach einem
Zurücksetzen des Tabletts oder bei einem Gerätetausch — der Eintrag samt
Bezeichnung bleibt dabei erhalten.

## 3. Am Tablet koppeln

Im Browser des Tabletts die Fahoba-Adresse öffnen. Es erscheint
**„Willkommen bei Fahoba"** mit drei Wegen; **„Wandtablet einrichten"** wählen,
Code eingeben, **Verbinden**. Wer den QR-Code scannt, überspringt die Auswahl.

Danach steht der Kalender. Das Token liegt in einem httpOnly-Cookie — es ist für
Skriptcode auf der Seite nicht lesbar. Das hat eine Folge für die
Kiosk-Einstellungen weiter unten: **Wer die Cookies löscht, koppelt das Gerät
ab.**

Gewollt geht das über die Geräte-Einstellungen (Zahnrad unten in der Leiste):
**„Gerät entkoppeln"**, zwei Tipps und die Eltern-PIN. Danach zeigt das Tablet
wieder die Startseite — der Weg, um sich im selben Browser mit einem Konto
anzumelden, denn ein gekoppeltes Tablet bleibt immer das Tablet. Zum
Wiederverbinden braucht es einen neuen Code aus der Verwaltung.

---

## 4. Fully Kiosk Browser

Die Menübezeichnungen unterscheiden sich zwischen den Fassungen leicht; die
Gruppierung ist seit Jahren stabil. Kostenpflichtige Punkte sind vermerkt.

### Web Content Settings

| Einstellung | Wert | Warum |
|---|---|---|
| Start URL | die Fahoba-Adresse | |
| Enable JavaScript | an | |
| Enable Cookies | **an** | Ohne Cookies kein Gerätetoken |
| Enable DOM Storage | an | |
| Enable Service Worker | **an** | Trägt den Offline-Stand |

### Advanced Web Settings

| Einstellung | Wert | Warum |
|---|---|---|
| Clear Cache on Reload / Restart | **aus** | Räumt sonst den Offline-Stand weg |
| Clear Cookies on Restart | **aus** | Räumt sonst das Gerätetoken weg |
| Enable Pull to Refresh | aus | Löst beim Wischen im Kalender ungewollt neu aus |
| Ignore SSL Errors | aus | Im Betrieb liegt ein gültiges Zertifikat an |

### Device Management

| Einstellung | Wert |
|---|---|
| Start on Boot / Launch on Boot | an |
| Keep Screen On | an |
| Screen Orientation | Landscape |
| Prevent Sleep while Screen Off | an |

### Kiosk Mode (kostenpflichtig)

| Einstellung | Wert |
|---|---|
| Enable Kiosk Mode | an |
| Kiosk Mode PIN | setzen und notieren |
| Disable Status Bar / Navigation Bar | an |
| Disable Home / Recents Button | an |

Ohne gesetzte PIN lässt sich der Kiosk nicht mehr verlassen — die PIN ist der
einzige Weg zurück in die Einstellungen.

### Screensaver und Power

Das Tablet hängt dauerhaft am Netz. Der Bildschirm soll nachts dunkel sein, aber
das Gerät nicht schlafen.

Fahoba hat einen eigenen **Ruhemodus** (siehe Abschnitt 5). Mit ihm läuft
tagsüber das Karussell, und Fully schaltet nur nachts ab: Screensaver-Timer
lang oder aus, *Time to Turn Screen Off* für die Nacht. Ein kurzer
Fully-Screensaver legte sich sonst über das Karussell.

| Einstellung | Wert |
|---|---|
| Screen Off Timer | 0 (nie über die Zeit abschalten) |
| Screensaver Timer | z. B. 1800 Sekunden — oder 0, wenn der Ruhemodus von Fahoba läuft |
| Screensaver Brightness | niedrig, etwa 0–10 |
| Screensaver Wallpaper URL | leer (schwarz) |
| Time to Turn Screen Off | z. B. 22:00–06:00 |

### Motion Detection (kostenpflichtig)

Weckt den Bildschirm, wenn jemand davorsteht.

| Einstellung | Wert |
|---|---|
| Enable Visual Motion Detection | an |
| Turn Screen On on Motion | an |
| Motion Sensitivity | mittel, im Betrieb nachjustieren |

Die Erkennung läuft über die Frontkamera und braucht deren Berechtigung. Wo das
nicht erwünscht ist, bleibt die Alternative **Touch to Wake** — dann weckt eine
Berührung das Gerät.

---

## 5. Was Fahoba selbst übernimmt

Sechs Dinge, die man bei Kiosk-Aufbauten sonst über Fully löst und hier **nicht**
einstellen muss:

- **Ruhemodus.** Nach einer Weile ohne Berührung (1–15 Minuten) wird die
  Wand dunkel, blendet Menü und Knöpfe aus und zeigt die gewählten Bereiche
  abwechselnd — Kalender (heute, je Person), Aufgaben, Stundenplan, Fotos,
  Essen, Sterne oder Dashboard, alle 15, 30 oder 60 Sekunden; Fotos laufen
  mehrere Bilder lang, die Schule zeigt jedes Kind für ein Intervall. Fotos
  füllen dort den ganzen Bildschirm, mit der Uhrzeit in der Ecke und einer
  unscharfen Kopie des Bildes statt schwarzer Balken. Eine schwarze Schicht
  dunkelt zusätzlich ab (über Fotos nur leicht); ist in Fully *Enable JavaScript
  Interface* an, senkt Fahoba auch die echte Helligkeit um denselben Anteil und
  stellt sie beim Aufwachen wieder her. Wie dunkel, regeln zwei Schieberegler
  in 5-%-Schritten, 0–90 %: „Abdunkeln am Tag“ (Vorgabe 50 %) und „Abdunkeln
  in der Nacht“ (Vorgabe 70 %), der gilt, solange der Haushalt das dunkle
  Design zeigt; beim Verschieben zeigt die Wand hinter den
  Einstellungen gleich, wie dunkel es wird. Der erste Tipp weckt nur und löst nichts aus; danach
  steht die Wand, wo sie war. Eingestellt an der Wand unter dem Zahnrad → „Ruhemodus“ oder in der Verwaltung beim Gerät. Nichts gewählt heißt aus.
  Offene Dialoge halten die Wand wach — auch ein Rezept, das unter „Essen“
  über Bild oder „Anleitung“ geöffnet wurde: Wer am Herd mitliest, soll nicht
  ins Dunkle schauen.
- **Bilderrahmen.** Jedes Tablet richtet seinen Rahmen selbst ein, unter dem
  Zahnrad → „Bilderrahmen“ oder in der Verwaltung beim Gerät: ein Bild, zwei
  Hochformate nebeneinander, ein Mosaik, das Bild neben Uhr, Wetter und den
  Terminen des Tages, oder Rückblicke („heute vor einigen Jahren“); aus allen
  Fotos, den Favoriten oder gewählten Alben; alle 10 Sekunden bis 5 Minuten,
  füllend oder eingepasst, mit oder ohne langsame Bewegung, Bildunterschrift
  und Uhr. Der Ruhemodus darf einen eigenen Stil haben — tagsüber ein Mosaik,
  in Ruhe das Bild neben der Uhrzeit. Wischen blättert vor und zurück, ein
  Tipp zeigt Pause und Favorit, ein Doppeltipp merkt das Bild als Favorit.
  Ein Foto, das ein Handy mit „An der Wand zeigen“ schickt, erscheint auf
  jeder Wand des Haushalts über allem, weckt eine ruhende und verschwindet
  mit einem Tipp oder nach zehn Minuten.
- **Neuladen nach einem Deploy.** Die Wand fragt ohnehin alle fünf Minuten die
  Agenda ab; ändert sich dabei die Anwendungsfassung (ein neues Image bringt
  eine neue mit), lädt sie sich selbst neu.
  Ein *Auto Reload on Idle* in Fully ist daher unnötig.
- **Aktualisieren nach dem Aufwachen.** Beim Sichtbarwerden holt die Wand neu ab.
- **Netzausfall.** Statt einer Fehlerseite bleibt der letzte Stand stehen,
  gekennzeichnet mit „Stand von 14:32". An der Wand hängt nie eine leere Seite.
- **Hell und dunkel.** Die Wand wechselt nach Uhrzeit oder Sonnenstand, wie beim
  Haushalt in der Verwaltung eingestellt — auch ohne Netz. Ein Tablett, das
  immer hell oder immer dunkel bleiben soll, stellt man direkt an der Wand über
  das Zahnrad unten in der Seitenleiste → „Erscheinungsbild“ um. Das ersetzt nicht den Screensaver
  oben: Das dunkle Thema schont die Augen am Abend, abgeschaltet wird der
  Bildschirm weiterhin von Fully.

---

## 6. Wenn etwas klemmt

**Nach einem Neustart steht wieder „Willkommen bei Fahoba".**
Fully löscht die Cookies. *Clear Cookies on Restart* aus, danach neu koppeln.

**Die Seite bleibt weiß.**
Meist ist die Start-URL falsch oder das Zertifikat wird nicht angenommen. Zum
Prüfen den Kiosk mit der PIN verlassen und die Adresse im normalen Browser des
Tabletts öffnen.

**Dauerhaft „Stand von …", obwohl das Netz steht.**
Das Tablet erreicht den Server nicht, oder der Server antwortet mit einem
Fehler. Erste Anlaufstelle sind die Logs des Servers, nicht das Tablet.

**Band „Google-Zugang abgelaufen".**
Das ist kein Tablet-Problem. Der Kalender ist wochenalt, bis das Konto in der
Verwaltung neu verbunden wird. Genau dafür ist das Band da — ein still
scheiternder Abgleich wäre gefährlicher als ein sichtbarer Hinweis.

**Der Code wird nicht angenommen.**
Abgelaufen (15 Minuten) oder schon eingelöst. Einen neuen erzeugen. Das Tablet
nennt den Grund bewusst nicht — an der Wand hilft die Unterscheidung niemandem.
