# Datenschutz — welche Daten WM Activity Sentinel verarbeitet

> **Beschreibung der Verarbeitung, gemessen am Code (2026-09-15, Abschnitte 3 und 5 neu gemessen 2026-09-20). Keine rechtliche Bewertung.**  
> Die vorige Fassung dieser Datei nannte eine Rechtsgrundlage nach Art. 6 Abs. 1 lit. f DSGVO, eine Frist von 90 Tagen mit täglichem automatischem Löschen und eine „revisionssichere“ Aufbewahrung nach GoBD. Das Plugin löscht nichts automatisch, und ob eine Rechtsgrundlage trägt, entscheidet der Betreiber der Website mit rechtlicher Beratung, nicht das Plugin.

---

## 1. Welche Daten gespeichert werden

| Daten | Wann | Wo |
| --- | --- | --- |
| Benutzer-ID, Anzeigename und E-Mail des handelnden Benutzers | bei jedem Eintrag mit angemeldetem Benutzer | `wp_wm_activity_logs` |
| HMAC-SHA256 der Client-IP | bei jedem Eintrag | `wp_wm_activity_logs.ip_hmac` |
| versuchter Benutzername und User-Agent | fehlgeschlagene Anmeldung | `context` |
| Login und E-Mail eines neu angelegten Benutzers | Registrierung | `context` |
| Adresse, Titel und Referrer jeder Frontend-Seite, die ein angemeldeter Benutzer aufruft | Schalter „Frontend-Seitenaufrufe“ (Standard an) | `context` |
| Name und Adresse jeder Backend-Seite, die ein angemeldeter Benutzer aufruft | Schalter „Admin-Seitennavigation“ (Standard an) | `context` |
| Beschriftung, Ziel, CSS-Pfad und Seitenadresse von Klicks angemeldeter Benutzer | Schalter „Klick- & Interaktions-Telemetrie“ (Standard an) | `context` |
| alter und neuer Wert von neun Optionen, darunter `admin_email` und die Alarmadresse | Änderung der Option | `context` |
| Betreff jeder Alarm-Mail | Alarm | `wp_wm_activity_alerts` |

Klartext-IP-Adressen werden nicht gespeichert.

---

## 2. IP-Hash

$$\text{ip\_hmac} = \text{HMAC-SHA256}(\text{IP},\ \text{Datum (UTC)} \,\|\, \text{NONCE\_SALT})$$

* Hashes derselben Adresse stimmen nur innerhalb eines UTC-Tages überein; so lassen sich Anmeldeversuche eines Tages zusammenführen.
* **Der Hash ist ein Pseudonym, keine Anonymisierung.** Wer `NONCE_SALT` kennt (oder den gespeicherten Salt, wenn `NONCE_SALT` fehlt), kann für eine vermutete Adresse und das in `created_at` gespeicherte Datum denselben Hash berechnen.
* Hinter einem Proxy oder CDN ist `REMOTE_ADDR` die Adresse des Proxys; das Plugin wertet keine weitergeleiteten Header aus.

---

## 3. Aufbewahrung

> **Korrigiert 2026-09-20.** Dieser Abschnitt sagte bis heute, die Frist werde „gespeichert und nicht
> angewendet“. Das stimmte bis zum **2026-09-17** und danach nicht mehr — drei Tage lang beschrieb
> ausgerechnet das Datenschutz-Dokument eine Speicherbegrenzung als wirkungslos, die es gibt.

* **Die Aufbewahrungsfrist wird seit dem 2026-09-17 täglich angewendet.** Ein tägliches Cron-Ereignis
  (`wm_sentinel_apply_retention`) neutralisiert bei Einträgen jenseits von `wm_sentinel_retention_days`
  (Standard 90) die identifizierenden Felder: `user_name`, `user_email` und `ip_hmac`. Höchstens 500
  Zeilen pro Durchlauf; der Rest folgt beim nächsten. `wp sentinel retention [--dry-run]` führt ihn
  sofort aus oder zeigt nur, was er ändern würde.
* **Gelöscht wird dabei nichts, und das ist der Grund für die Pseudonymisierung.** Das Protokoll ist
  eine Hash-Kette: eine entfernte Zeile bricht die Verweisprüfung bei der ersten überlebenden Zeile
  und die Zählung am Kettenende — die Prüfung würde die eigene Aufbewahrungsregel der Website als
  Manipulation melden. Art. 5 Abs. 1 lit. e DSGVO begrenzt, wie lange **personenbezogene Daten**
  aufbewahrt werden, nicht wie lange die Tatsache eines Ereignisses aufbewahrt wird; Zeile und
  Kettenposition bleiben deshalb stehen.
* **`context` wird bewusst nicht angetastet.** Er ist der Nachweis dessen, was geschah. Wo ein
  Kontextfeld personenbezogene Daten enthält und die betroffene Person Löschung verlangt, ist der
  Weg über Art. 17 der richtige (Abschnitt 4) — pro Person, auf Antrag.
* Jeder Durchlauf schreibt **zuerst** einen verketteten Eintrag `retention_pseudonymized` mit einem
  geschlüsselten Fingerabdruck jeder Zeile, die er ändern wird. Lässt sich dieser Nachweis nicht
  schreiben, wird **keine einzige Zeile** geändert: eine pseudonymisierte Zeile ohne ihren
  Fingerabdruck läse sich für immer als Manipulation. Ausgenommen bleiben die Einträge der Kette
  selbst (`genesis_initialized`, `privacy_erasure_applied`, `retention_pseudonymized`).
* Ein Wert unter 30 (dem Minimum des Formulars) bedeutet **„alles behalten“** und das Ereignis wird
  abbestellt — nie „alles pseudonymisieren“. Fehlt oder stimmt der Wert nicht, ist der Standard nie
  der zerstörende.
* Vollständig **entfernen** lassen sich Einträge weiterhin nur durch den Werksreset (alle Einträge),
  durch die Deinstallation (beide Tabellen) oder von Hand in der Datenbank, was die Prüfung als
  Manipulation meldet.
* **Offen bleibt:** ob 90 Tage die richtige Frist sind und ob die Deinstallation das Protokoll
  löschen darf. Das entscheidet der Betreiber, nicht das Plugin.

Alle Einstellungen dazu: [docs/SETTINGS.md](SETTINGS.md#retention).

---

## 4. Auskunft und Löschung über die Werkzeuge von WordPress

* **Export** (*Werkzeuge › Personenbezogene Daten exportieren*): bis zu 1.000 Einträge, in denen die Adresse als handelnder Benutzer steht oder im Kontext vorkommt, mit Zeit, Ereignis, Schweregrad, Objekttyp, Name, Adresse und Hash.
* **Löschung** (*Werkzeuge › Personenbezogene Daten löschen*):
  * Einträge dieses Benutzers erhalten den Namen `[DSGVO Art. 17 Gelöscht]`, die Adresse `anonymized-<ID>@privacy.local` und einen neutralen IP-Hash.
  * Die Adresse wird in jedem Kontext ersetzt, in dem sie vorkommt.
  * Vorher schreibt das Plugin einen verketteten Eintrag `privacy_erasure_applied` mit einem geschlüsselten Fingerabdruck jeder geänderten Zeile, damit die Kettenprüfung diese Zeilen anerkennt.
  * **Stehen bleiben:** Benutzer-ID, Ereignisart, Zeit, Objekt-IDs, der übrige Kontext (etwa Benutzername eines Anmeldeversuchs, besuchte Adressen, Klick-Beschriftungen) und der Löschnachweis selbst.
  * Die Löschung erkennt nur die exakte Adresse, nicht Benutzernamen oder Anzeigenamen.
* Ob diese Pseudonymisierung einer Löschanfrage genügt, bewertet das Plugin nicht.

---

## 5. Übermittlungen

> **Ergänzt 2026-09-20.** Dieser Abschnitt nannte drei Wege; der Code hat **fünf**. Die beiden
> fehlenden kamen am 2026-09-16 dazu und wurden hier nie nachgetragen — eine davon ist eine
> Übermittlung personenbezogener Daten an einen Host, den der Betreiber selbst benennt.

* Alarm-Mails über `wp_mail()` an die eingestellte Adresse, mit Ereignis, Benutzername und dem Kontext als JSON.
* Falls eingerichtet, ein JSON-POST an die Webhook-URL mit Ereignis, Schweregrad, Benutzername, Website und Zeit.
* **Weiterleitung an ein SIEM — standardmäßig AUS.** Ist ein Collector eingerichtet, verlässt **jeder**
  Eintrag ab dem gewählten Schweregrad die Website als Syslog RFC 5424, CEF:0 oder LEEF:2.0 über
  `udp`, `tcp` oder `https` (`wp_remote_post` bzw. `stream_socket_client`). Übertragen werden damit
  auch Benutzer-ID, Anzeigename, E-Mail-Adresse und der Kontext — also genau die Daten aus
  Abschnitt 1. **Das ist eine Übermittlung an einen Dritten**, und liegt der Collector außerhalb der
  EU, eine Drittlandübermittlung mit eigener Rechtsgrundlage. Das Plugin bewertet das nicht und
  fragt nicht nach; es sendet an den Host, den der Betreiber einträgt.
* **Dateiintegritäts-Monitor — standardmäßig AN.** Ein täglicher `GET` auf
  `https://api.wordpress.org/core/checksums/1.0/` mit **genau zwei** Parametern, `version` und
  `locale`. Keine personenbezogenen Daten, keine Website-Adresse im Query-String — dieselben zwei
  Werte, die WordPress bei jeder Aktualisierungsprüfung ohnehin sendet. Abschaltbar über
  `wm_sentinel_fim_enabled`.
* Das Klick-Skript sendet nur an die REST-Route der eigenen Website. Es werden keine externen Dienste, CDNs oder Schriften geladen.

---

## 6. Offene Entscheidungen

* Rechtsgrundlage und Information der betroffenen Personen, insbesondere für die Protokollierung von Seitenaufrufen und Klicks angemeldeter Benutzer (etwa Beschäftigter oder Kunden).
* Aufbewahrungsdauer des Protokolls: ob **90 Tage** die richtige Frist sind (angewendet wird sie, Abschnitt 3).
* Ob die Deinstallation das Protokoll löschen darf (Flotten-Audit D6).
* Rechtsgrundlage der SIEM-Weiterleitung, falls sie eingeschaltet wird, und die Drittlandfrage, falls der Collector außerhalb der EU steht (Abschnitt 5).
