# Barrierefreiheit — WM Activity Sentinel

> **Was im Backend gemessen und umgesetzt ist, und was nicht geprüft wurde**  
> Stand 2026-09-15. Die vorige Fassung erklärte, Dashboard und Stream erfüllten „vollumfänglich“ BFSG 2025 und WCAG 2.2 AA und alle Bedienelemente hätten 48 × 48 px. Eine vollständige Prüfung mit Screenreader und nach WCAG hat nicht stattgefunden, und die 48 px stimmten nicht. Das Plugin hat kein Frontend außer dem unsichtbaren Klick-Skript.

---

## 1. Kontrast der Schweregrad-Badges (WCAG 2.2 SC 1.4.3)

Die Badges rendern aus den Tokens der WM-Suite-Familie (`assets/css/wm-admin-tokens.css`, byte-identisch mit dem Hub). Gemessen mit dem Kontrast-Prüfskript der Flotte am 2026-09-15:

* **Critical:** `--wm-danger` `#b32d2e` auf `--wm-danger-bg` `#fcf0f1` → **5.67:1**
* **Warning:** `--wm-warning` `#996800` auf `--wm-warning-bg` `#fcf9e8` → **4.58:1**
* **Notice:** `--wm-accent-deep` `#0c4a6e` auf `--wm-accent-light` `#f0f9ff` → **8.87:1**
* **Info:** `--wm-text-secondary` `#334155` auf `--wm-bg-surface-subtle` `#f8fafc` → **9.90:1**

Das Skript meldet für die Standard-, WordPress- und Hochkontrast-Paletten 0 Paare unter 4.5:1. Die Paare der dunklen Palette nutzen halbtransparente Hintergründe und werden vom Skript nicht berechnet.

---

## 2. Größe der Bedienelemente (WCAG 2.2 SC 2.5.8)

Gemessen am 2026-09-15 in Chrome: Einstellungs- und Stream-Seite per WP-CLI gerendert, mit den Stylesheets des WordPress-Kerns und des Plugins aus einer lokalen WordPress-Installation, Breite 1440 px.

| Element | Höhe × Breite |
| --- | --- |
| Schließen-Knopf des Kontext-Inspektors | 44 × 44 px (`--wm-target-min`) |
| Reiter der Navigation, Sprungmarken, „Filtern“, Demodaten-Buttons, Werksreset | 44 px hoch |
| „Einstellungen & Filter speichern“ (`button-large`) | 40 px hoch |
| Schweregrad-Auswahl / Suchfeld im Stream | 40 px / 38 px hoch |
| Knopf „Kontext von Eintrag #N anzeigen“ (`button-small`, 25 pro Seite) | **24 × 38 px** |
| Buttons des Dashboard-Widgets, Checkboxen | nicht gemessen |

SC 2.5.8 verlangt mindestens 24 × 24 px oder ausreichenden Abstand; das erfüllt auch der Kontext-Knopf. Den Workspace-Standard von 44 px erfüllte er bis 1.3.1 nicht: Die Regel `.wp-core-ui .button.button-small` des Kerns wog schwerer als die 44-px-Regel der Familie. Seit **1.3.2** überschreibt `assets/css/sentinel-admin.css` mit hoher Spezifität (`.wp-core-ui .wm-admin .button.button-small`, `.wp-core-ui .wm-sentinel-view-context`, `.wp-core-ui .wm-sentinel-widget-actions .button.button-small`, `.wm-sentinel-modal-close`) auf `min-height: 44px; min-width: 44px;` (noch nicht erneut in Chrome vermessen).

---

## 3. Tastatur und Screenreader (WCAG 2.2 SC 2.1.1, 2.4.3, 4.1.2)

* **Kontext-Inspektor:** `role="dialog"`, `aria-modal="true"`, `aria-labelledby` auf den Titel; `#wm-sentinel-context-json` trägt `tabindex="0"`. Beim Öffnen setzt `assets/js/sentinel-admin.js` umgebende Wrapper (`.wm-sentinel-admin-wrap`, `#adminmenuwrap`, `#wpadminbar`, `#wpfooter`) auf `aria-hidden="true"` und `inert`, fängt `Tab`/`Shift+Tab` sowie `focusin` im Dialog ein und fokussiert den Schließen-Knopf. `Escape`, Schließen-Knopf und Hintergrund-Klick stellen den vorherigen Zustand vor `lastFocus.focus()` wieder her.
* Der Knopf, der den Inspektor öffnet, zeigt nur ein Symbol und trägt einen zugänglichen Namen („Kontext von Eintrag #N anzeigen“).
* Die Stream-Tabelle hat Spaltenüberschriften mit `<th scope="col">`.
* Die Navigation ist ein `<nav>` mit `aria-label`; der aktive Reiter trägt `aria-current="page"`.
* Alle Dashicons im Backend tragen `aria-hidden="true"` (bis 2026-09-15 wurden 20 davon vorgelesen; Test-Gruppe 12 prüft es).
* Die Einstellungsfelder haben `<label for>`; Checkbox-Gruppen stehen in `<fieldset>`, allerdings ohne `<legend>`.
* Links, Buttons, Felder, `summary` und Elemente mit `tabindex` erhalten in den Sentinel-Seiten einen sichtbaren `:focus-visible`-Ring aus den Familien-Tokens; kein Stylesheet des Plugins setzt `outline: none`.

---

## 4. Darstellung

* Farben, Abstände und Radien kommen aus Tokens; das Backend enthält außer dem Umschalter des Dialogs keine Inline-Styles mehr (bis 2026-09-15: 22 mit Pixelwerten).
* Die breiten Auswahllisten der Einstellungen sind höchstens so breit wie ihre Zelle (`min-width: min(380px, 100%)`); bei 500 px Fensterbreite, der kleinsten, die Chrome zuließ, blieben sie in der Zelle und die Seite ohne horizontalen Scroll. Unter 782 px stapeln sich die Kennzahl-Kacheln.

---

## 5. Nicht geprüft

* Durchgang mit NVDA, JAWS oder VoiceOver.
* Zoom auf 200 % und 400 % (SC 1.4.4, 1.4.10).
* Kontrast der dunklen Palette und der Texte in den WordPress-Kernelementen.
* Die Handbuch-Seite im Einzelnen.
