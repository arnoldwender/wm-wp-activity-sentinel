# Sicherheits- und Härtungs-Audit — WM Activity Sentinel

> **Was die Kette nachweist, was sie nicht leistet, und die gemessenen Befunde**  
> Stand 2026-09-15, gemessen am Code und in einer lokalen WordPress-Installation.

---

## 1. Manipulationsnachweis

In Logging-Plugins ohne Kette kann ein Angreifer mit SQL-Zugriff seine Spuren verwischen (`DELETE FROM logs WHERE user_id = 42` oder `UPDATE logs SET ip = '127.0.0.1'`).

**WM Activity Sentinel verhindert das nicht — es weist es nach.** Die Zeilen lassen sich weiterhin ändern; die Änderung bleibt nur nicht unbemerkt, solange der Angreifer den Schlüssel nicht hat.

| Angriff | Was die Prüfung sieht |
| --- | --- |
| **Änderung einer Zeile** | Der neu berechnete Hash weicht vom gespeicherten ab → `Manipulationsnachweis bei Log-ID #X` |
| **Löschen einer Zeile in der Mitte** | Der `prev_hash` der Folgezeile passt nicht mehr → `Kettenbruch bei Log-ID #X` |
| **Einschleusen gefälschter Zeilen** | Ohne den Schlüssel aus der `wp-config.php` lässt sich kein gültiger Hash erzeugen |
| **Löschen der neuesten Zeilen** | `wm_sentinel_chain_tip` (außerhalb der Log-Tabelle) passt nicht mehr zum Kettenende |
| **Leeren der Tabelle** | Leere Tabelle bei verzeichnetem Kettenende mit mehr als 0 Einträgen → „Das Protokoll wurde gelöscht“ |
| **Nachträgliche Änderung einer pseudonymisierten Zeile** | Der Fingerabdruck im Löschnachweis passt nicht mehr → `Manipulationsnachweis` |

### Was dieses Verfahren ausdrücklich nicht leistet

* **Es ist nicht unveränderbar.** Wer die `wp-config.php` lesen kann, hat den Schlüssel und kann die gesamte Kette neu signieren. Auf einer selbst gehosteten Installation lässt sich das nicht verhindern.
* **Ohne `AUTH_KEY` liegt der Schlüssel in der Datenbank.** Dann schützt die Kette nur gegen Unfälle, nicht gegen jemanden mit Datenbankzugriff. Integritätsseite, `wp sentinel audit` und Hub (`DEGRADED`) sagen es.
* **Es beweist keinen Zeitpunkt.** Der Zeitstempel ist der, den der Server geschrieben hat. Es gibt keinen externen Anker (keinen RFC-3161-Zeitstempeldienst, keine veröffentlichte Prüfsumme).
* **Der Spiegel des Kettenendes liegt in `wp_options`** — in derselben Datenbank. Er erzwingt einen zweiten, getrennten Schreibvorgang; wer beide ausführen kann, kommt durch.
* **Der JSON-Export ist nicht signiert.** Seine SHA-256-Prüfsumme kann jeder neu berechnen, der die Datei ändert.

---

## 2. Befunde

### Audit vom 2026-08-27 (Version 1.3.0), alle drei behoben

1. **Das Löschen der neuesten Einträge war unsichtbar.** Nach `DELETE` der zwei jüngsten Zeilen meldete `verify_chain()` weiterhin `valid=true`. Behoben durch den Spiegel des Kettenendes.
2. **Das Plugin lieferte einen Knopf, der seinen eigenen Manipulationsnachweis löschte.** `WM_Sentinel_Database::rebuild_chain()` signierte beim Entfernen der Demodaten sämtliche verbleibenden Zeilen neu. Gemessen: Zeile von Hand ändern → Prüfung rot → Demodaten löschen → Prüfung grün, Änderung vorhanden. Die Methode ist entfernt; Demodaten lassen sich nur entfernen, solange keine echten Einträge vorliegen, und dann durch vollständigen Reset.
3. **Ausgelieferter Ersatzschlüssel.** Ohne `AUTH_KEY` war der Schlüssel das Literal `wm_sentinel_auth_key_2026` aus dem Quelltext. Ersetzt durch `wp_salt( 'auth' )` mit Warnung.

### Audit vom 2026-09-15, behoben

4. **Gefälschte kritische Einträge über die Telemetrie.** Jedes angemeldete Konto, auch ein Abonnent, konnte über `POST /wm-sentinel/v1/telemetry` bis zu 25 Einträge pro Anfrage mit beliebiger Ereignisart und beliebigem Schweregrad schreiben; ein gefälschtes `user_role_changed` mit `critical` landete in der Kette und löste die Alarm-Mail aus. Besucher mit einem REST-Nonce für Benutzer 0 kamen ebenfalls durch. Jetzt: nur angemeldete Benutzer, nur die Ereignisarten, Kategorien und Metadaten-Schlüssel des Skripts, immer `info`, höchstens 60 pro Benutzer und Minute. In WordPress gegengeprüft (`tests/test-sentinel-privacy-tools.php`).
5. **Datenschutz-Werkzeuge mit fatalem Fehler, Löschung ohne Nachweis.** Export und Löschung riefen eine nicht existierende Methode auf. Hätte die Löschung gelaufen, hätte sie Zeilen geändert, ohne dass die Prüfung sie anerkennen konnte, und die Adresse im Kontext stehen lassen. Jetzt mit verkettetem Löschnachweis; eine spätere Änderung einer gelöschten Zeile bleibt erkennbar.
6. **Ausgelieferter Ersatz-Salt für IP-Hashes.** Ohne `NONCE_SALT` war der Salt das Literal `wm_sentinel_default_salt_2026`: Mit einer Kopie des Plugins ließen sich Kandidaten-Adressen gegen gespeicherte Hashes prüfen. Ersetzt durch `wp_salt( 'nonce' )`.
7. **Falscher Kürzungsalarm.** Das Kettenende wurde nach dem `COMMIT` geschrieben; zwei gleichzeitige Anfragen konnten es in falscher Reihenfolge speichern. Jetzt vor dem `COMMIT`, unter der Zeilensperre.

Jede dieser Korrekturen hat eine Mutation in `tests/run-mutations.sh`, die die Suite rot macht.

### Offen

* **Aufwand der Prüfung.** `verify_chain()` lädt die ganze Tabelle bei jedem Dashboard-Aufruf (Widget), auf der Integritätsseite, beim JSON-Export und bei jeder Health- oder Telemetrie-Abfrage des Hubs. Bei großen Protokollen kostet das Speicher und Zeit.
* **Datenmenge durch Telemetrie.** Jedes angemeldete Konto kann weiterhin bis zu 60 `info`-Einträge pro Minute schreiben; jeder Backend- und Frontend-Aufruf eines angemeldeten Benutzers schreibt einen Eintrag. Nichts wird automatisch gelöscht.
* **Alarm-Flut.** Jeder Eintrag ab dem Mindest-Schweregrad sendet eine eigene Mail; es gibt keine Drosselung.
* **Webhook.** Der Versand nutzt `wp_remote_post()`, nicht `wp_safe_remote_post()`; die URL setzt ein Administrator, und ein Fehlschlag wird nicht vermerkt.
* **Andere Plugins** können `WM_Sentinel_Logger::log()` mit beliebiger Ereignisart und beliebigem Schweregrad aufrufen.

---

## 3. CWE-1236: Formeln im CSV-Export

Beim Export neutralisiert `WM_Sentinel_Exporter::sanitize_csv_cell()` jede Zelle:

```php
public static function sanitize_csv_cell( string $value ): string {
    $dangerous_prefixes = [ '=', '+', '-', '@', "\t", "\r" ];
    $first_char = mb_substr( $value, 0, 1 );

    if ( in_array( $first_char, $dangerous_prefixes, true ) ) {
        return "'" . $value;
    }

    return $value;
}
```

Test-Gruppe 11 prüft alle sechs Präfixe.

---

## 4. SQL

* Werte aus Anfragen (Filter, Suche, Adresse der Datenschutz-Werkzeuge) laufen über `$wpdb->prepare()` mit `%s` und `%d`; die Suche über `esc_like()`.
* Sortierspalte und Richtung der Liste sind Whitelists.
* Tabellennamen gehen in `get_logs()`, dem Zähler des Kettenendes und den Datenschutz-Abfragen über `%i`; in mehreren Lese-Abfragen, im Reset und in der Deinstallation werden sie aus `$wpdb->prefix` eingesetzt. Kein Anfragewert erreicht diese Stellen.
* Export, Einstellungen, Demodaten und Reset prüfen `manage_options` und ein Nonce.
