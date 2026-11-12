# Setting up the wall tablet

*Deutsche Fassung: [`kiosk.de.md`](kiosk.de.md)*

The tablet is a **device, not a user**. Nobody signs in on the wall. It receives
a permanent token once via a pairing code and stays signed in afterwards — even
across restarts.

This guide covers both: pairing and permanent kiosk operation.

---

## 1. Create the device in Fahoba

1. Open the admin (`/admin`), **Devices → New device** („Geräte → Erstellen“)
2. Enter a **Title** („Bezeichnung“) — where it hangs, e.g. “Kitchen” or “Hallway”
3. **Kind** („Art“): Wall tablet
4. Save

## 2. Generate the pairing code

In the device list, choose **“Pairing code”**
(„Kopplungscode“) on the entry. The code appears as an overlay and stays until it is
clicked away.

- **Eight characters**, without `0`/`O` and `1`/`I` — so nothing gets mixed up
- **Valid for 15 minutes**, then generate a new one
- **Redeemable once**; it is deleted when redeemed

The action can be repeated at any time. This is needed after resetting the
tablet or when replacing a device — the entry including its label is kept.

## 3. Pair on the tablet

Open the Fahoba address in the tablet's browser. **“Welcome to Fahoba”**
(„Willkommen bei Fahoba“) appears with three options; choose **“Set up a wall
tablet”** („Wandtablet einrichten“), enter the code, **Connect** („Verbinden“).
Anyone who scans the QR code skips the selection.

After that the calendar appears. The token is stored in an httpOnly cookie — it
cannot be read by script code on the page. That has a consequence for the kiosk
settings further below: **Anyone who deletes the cookies unpairs the device.**

On purpose, it goes through the device settings (gear at the foot of the
bar): **“Unpair this device”** („Gerät entkoppeln“), two taps and the parent
PIN. The tablet then shows the start page again — the way to sign in with an
account in the same browser, since a paired tablet always stays the tablet.
Pairing again needs a new code from the admin.

---

## 4. Fully Kiosk Browser

The menu labels differ slightly between versions; the grouping has been stable
for years. Paid items are marked.

### Web Content Settings

| Setting | Value | Why |
|---|---|---|
| Start URL | the Fahoba address | |
| Enable JavaScript | on | |
| Enable Cookies | **on** | Without cookies there is no device token |
| Enable DOM Storage | on | |
| Enable Service Worker | **on** | Carries the offline state |

### Advanced Web Settings

| Setting | Value | Why |
|---|---|---|
| Clear Cache on Reload / Restart | **off** | Otherwise wipes the offline state |
| Clear Cookies on Restart | **off** | Otherwise wipes the device token |
| Enable Pull to Refresh | off | Triggers an unwanted reload when swiping in the calendar |
| Ignore SSL Errors | off | In production a valid certificate is in place |

### Device Management

| Setting | Value |
|---|---|
| Start on Boot / Launch on Boot | on |
| Keep Screen On | on |
| Screen Orientation | Landscape |
| Prevent Sleep while Screen Off | on |

### Kiosk Mode (paid)

| Setting | Value |
|---|---|
| Enable Kiosk Mode | on |
| Kiosk Mode PIN | set and write down |
| Disable Status Bar / Navigation Bar | on |
| Disable Home / Recents Button | on |

Without a PIN set, the kiosk can no longer be left — the PIN is the only way
back into the settings.

### Screensaver and power

The tablet is permanently connected to power. The screen should be dark at
night, but the device must not sleep.

Fahoba has its own **resting mode** (see section 5). With it, the carousel
runs during the day, and Fully only switches off at night: screensaver timer
long or off, *Time to Turn Screen Off* for the night. A short Fully screensaver
would otherwise lie over the carousel.

| Setting | Value |
|---|---|
| Screen Off Timer | 0 (never switch off based on time) |
| Screensaver Timer | e.g. 1800 seconds — or 0 if Fahoba's resting mode is running |
| Screensaver Brightness | low, about 0–10 |
| Screensaver Wallpaper URL | empty (black) |
| Time to Turn Screen Off | e.g. 22:00–06:00 |

### Motion Detection (paid)

Wakes the screen when someone is standing in front of it.

| Setting | Value |
|---|---|
| Enable Visual Motion Detection | on |
| Turn Screen On on Motion | on |
| Motion Sensitivity | medium, fine-tune in operation |

Detection runs via the front camera and needs its permission. Where that is not
wanted, the alternative is **Touch to Wake** — then a touch wakes the device.

---

## 5. What Fahoba handles itself

Six things that in kiosk setups are otherwise solved through Fully and that you
do **not** have to configure here:

- **Resting mode.** After a while without touch (1–15 minutes) the wall goes
  dark, hides the menu and buttons, and shows the chosen areas in turn —
  calendar (today, per person), tasks, timetable, photos, meals, stars or
  dashboard, every 15, 30 or 60 seconds; photos run for several images, the
  school area shows each child for one interval. Photos fill the whole screen
  there, with the time in a corner and a blurred copy of the picture instead
  of black bars. A black layer additionally
  dims the screen (only lightly over photos); if *Enable JavaScript Interface* is on in Fully, Fahoba
  also lowers the real brightness by the same share and restores it on waking.
  How dark is set with two sliders in steps of 5 %, 0–90 %: “Darken by day”
  („Abdunkeln am Tag“, 50 % by default) and “Darken at night” („Abdunkeln in
  der Nacht“, 70 %), which applies while the household shows the dark theme;
  while you move one, the wall shows the result behind the settings. The first tap only
  wakes and triggers nothing; afterwards the wall stands where it was. Set on the wall under the gear → “Resting mode” („Ruhemodus“), or in the
  admin on the device. Nothing chosen means off.
  Open dialogs keep the wall awake — including a recipe opened under “Essen”
  through its picture or “Anleitung”: whoever reads along at the stove should
  not look at a dark screen.
- **Photo frame.** Each tablet sets up its own frame under the gear →
  “Photo frame” („Bilderrahmen“), or in the admin on the device: one picture,
  two portraits side by side, a mosaic, the picture beside clock, weather and
  the day's events, or memories (“on this day” in earlier years); from all
  photos, the favourites or chosen albums; every 10 seconds to 5 minutes,
  filled or fitted, with or without slow movement, caption and clock. The
  resting mode may use a style of its own — a mosaic by day, the picture
  beside the time while resting. A swipe goes back and forth, a tap shows
  pause and favourite, a double tap marks a favourite. A picture sent from a
  phone with “Show on the wall” („An der Wand zeigen“) appears over
  everything on every wall of the household, wakes a resting one, and goes
  with a tap or after ten minutes.
- **Reloading after a deploy.** The wall queries the agenda every five minutes
  anyway; if the application version changes in the process (a new image
  brings a new one), it reloads itself.
  An *Auto Reload on Idle* in Fully is therefore unnecessary.
- **Refreshing after waking.** When it becomes visible, the wall fetches fresh
  data.
- **Network outage.** Instead of an error page, the last state stays in place,
  labelled “As of 14:32” („Stand von 14:32“). The wall never shows an empty
  page.
- **Light and dark.** The wall switches by time of day or sun position, as set
  on the household in the admin — even without a network. A tablet that should
  always stay light or always dark is set directly on the wall via the gear at the bottom of the sidebar → “Appearance” („Erscheinungsbild“). This does not replace the
  screensaver above: the dark theme is easier on the eyes in the evening, but
  the screen is still switched off by Fully.

---

## 6. When something gets stuck

**After a restart “Welcome to Fahoba” shows again.**
Fully deletes the cookies. Turn *Clear Cookies on Restart* off, then pair again.

**The page stays white.**
Usually the start URL is wrong or the certificate is not accepted. To check,
leave the kiosk with the PIN and open the address in the tablet's normal
browser.

**Permanently “As of …” although the network is up.**
The tablet cannot reach the server, or the server responds with an error. The
first place to look is the server's logs, not the tablet.

**Banner “Google access expired”.**
This is not a tablet problem. The calendar is weeks old until the account is
reconnected in the admin. That is exactly what the banner is for — a silently
failing sync would be more dangerous than a visible notice.

**The code is not accepted.**
Expired (15 minutes) or already redeemed. Generate a new one. The tablet
deliberately does not state the reason — on the wall the distinction helps
nobody.
