=== Fines Finest Booking ===
Contributors: finesfinest
Tags: booking, appointments, google calendar, termine, dsgvo
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Terminbuchung im eigenen Design, mit Google-Kalender-Sync, Google Meet, Bestaetigungsmails und Brevo-Anbindung.

== Beschreibung ==

Ein schlankes Buchungsplugin fuer Solo-Selbstaendige. Beliebig viele Termintypen, Zwei-Wege-Abgleich mit Google Kalender, automatisch erzeugter Google-Meet-Link, Bestaetigungs- und Erinnerungsmail mit Kalenderdatei im Anhang, Absagelink fuer die Kundin, optionale Uebergabe der Kontakte an Brevo.

Alle Buchungsdaten liegen in der Datenbank der eigenen Website. Kein externer Anbieter im Frontend, kein Consent-Banner noetig, kein iframe.

= Funktionen =

* Mehrere Termintypen mit eigener Dauer, eigenen Zeitfenstern und eigenen Zusatzfragen
* Puffer vor und nach dem Termin, Vorlaufzeit, Buchungshorizont, Tageslimit
* Verfuegbarkeit wird gegen beliebig viele Google-Kalender geprueft
* Google Meet, Telefon oder Vor-Ort als Terminort
* Bestaetigungs- und Erinnerungsmail im eigenen Corporate Design, ICS-Datei im Anhang
* Absagelink in jeder Mail, Slot wird sofort wieder frei
* Brevo: jede Buchung landet als Kontakt in einer Liste
* DSGVO: Einwilligung mit Zeitstempel, automatische Loeschfrist, Anbindung an die WordPress-Datenschutz-Werkzeuge

= Shortcodes =

`[ff_booking event="erstanalyse"]` bindet einen bestimmten Termintyp ein.
`[ff_booking]` zeigt eine Auswahl, wenn mehrere Termintypen aktiv sind.

== Installation ==

1. ZIP unter Plugins, Installieren, Plugin hochladen einspielen und aktivieren.
2. Unter Buchungen, Einstellungen die Google-Zugangsdaten eintragen und verbinden.
3. Unter Buchungen, Termintypen die Zeiten pflegen.
4. Shortcode auf einer Seite einbauen.

Die ausfuehrliche Anleitung steht in der Datei INSTALL.md.

== Changelog ==

= 1.2.1 =
* Die Slot-Berechnung rechnet in Wanduhr-Minuten des Tages statt mit PHP-Zeitschritten. In der doppelten Stunde beim Sommerzeitende wird jede Uhrzeit genau einmal angeboten, unabhaengig von der PHP-Version.
* Automatische Tests fuer die Slot-Berechnung (Zeitfenster, Geschaeftszeiten, Puffer, Vorlaufzeit, Tageslimit, Sperrtage, Horizont, beide Sommerzeitwechsel), das Erinnerungsfenster und die Telefonnummern-Normalisierung. GitHub prueft sie bei jedem Stand und vor jedem Release.

= 1.2.0 =
* Das Plugin ist uebersetzbar. Quellsprache ist Englisch, die deutsche Fassung liegt als Sprachpaket bei, jetzt mit Umlauten in allen Texten und Mails.
* Die Textdomain heisst fines-finest-booking statt ff-booking, wie es das WordPress-Verzeichnis verlangt.
* Datum und Uhrzeit in Mails und Backend folgen der Sprache der Website.
* Block-Themes: Das Buchungsfeld bekam seine Konfiguration nicht, weil solche Themes den Inhalt vor dem Kopfbereich rendern. Die Skripte werden jetzt frueh registriert.
* Die Erfolgsmeldung nach der Buchung zeigte "Uhr" doppelt.
* Der Hinweis nach der Aktivierung sagte faelschlich, ohne Google sei nichts buchbar.
* Der gespeicherte Standard-Einwilligungstext ohne Umlaute wird einmalig durch die neue Fassung ersetzt. Eigene Formulierungen bleiben unveraendert.

= 1.1.2 =
* Die Sperre gegen Doppelbuchungen nutzt eine Datenbanksperre. Die bisherige Sperre ueber eine Option war nicht atomar, zwei gleichzeitige Buchungen konnten beide durchgehen.
* Wer weniger als 25 Stunden vor dem Termin bucht, bekommt keine zusaetzliche Erinnerungsmail mehr. Bisher kam sie direkt nach der Bestaetigung und sprach von "morgen".
* Die Erinnerungsmail sagt "heute", wenn der Termin am selben Tag ist, zum Beispiel wenn der Cron spaet dran war.
* Ein Termintyp mit kuenftigen Terminen laesst sich nicht mehr loeschen. Vorher verloren diese Termine still ihre Erinnerung.
* Brevo: Telefonnummern werden ins internationale Format gebracht. Lehnt Brevo die Nummer trotzdem ab, wird der Kontakt ohne Nummer angelegt statt gar nicht.
* Die Google-Verbindung meldet sich, wenn die Anmeldung abgelaufen ist oder bei Google abgebrochen wurde, statt stumm zur Einstellungsseite zurueckzukehren.

= 1.1.1 =
* Der mitgelieferte Beispiel-Termintyp ist neutral: "Erstgespraech", ohne vorgegebenen Beschreibungstext und ohne Tageslimit.
* Die Standardfarben sind neutral statt in einem bestimmten Corporate Design. Schriften stehen auf "inherit" und uebernehmen damit die der Website.
* Die Einrichtungsanleitung ist von konkreten Beispieladressen befreit und um die neuen Funktionen ergaenzt.
* Betrifft nur Neuinstallationen. Bestehende Einstellungen, Farben und Termintypen bleiben unveraendert.

= 1.1.0 =
* Neu: Einrichtungsstand oben auf der Einstellungsseite. Vier Punkte mit Ampel, jeder mit Link zur naechsten Handlung: Google verbunden, Termintyp aktiv, Buchungsseite bekannt, Mailversand getestet.
* Neu: Knopf "Testmail senden". Der Mailversand laesst sich pruefen, ohne eine echte Buchung anzulegen.
* Neu: Aufklappbare Hilfetexte hinter den Abschnitten, darin die komplette Anleitung fuer das Google-Cloud-Projekt. Zum Anklicken statt beim Darueberfahren, damit es auch auf dem Handy und mit Screenreadern funktioniert.
* Neu: Die Weiterleitungs-URI laesst sich mit einem Klick kopieren.
* Neu: Die Zeitzone der Website wird angezeigt. Steht dort ein fester Zeitversatz statt einer Stadt, warnt das Plugin wegen der Sommerzeit.
* Hinweis direkt nach dem Aktivieren, dass zuerst Google verbunden werden muss.

= 1.0.5 =
* Zeitfenster werden ausgewaehlt statt getippt: zwei Von-Bis-Paare pro Wochentag, in Viertelstundenschritten. Gilt fuer die Geschaeftszeiten und fuer jeden Termintyp.
* Unvollstaendige oder verdrehte Paare werden verworfen und sperren den Tag, statt ihn versehentlich zu oeffnen. Ein Tippfehler kann damit keine Nachtbuchung mehr ermoeglichen.
* Vorlaufzeiten lassen sich in Minuten, Stunden oder Tagen angeben. Gespeichert wird weiterhin in Minuten.

= 1.0.4 =
* Zielkalender wird ausgewaehlt statt eingetippt. Angeboten werden nur Kalender, in die das verbundene Konto schreiben darf.
* Weitere zu pruefende Kalender werden angekreuzt statt eingetippt. Es erscheinen nur Kalender, die das verbundene Konto wirklich sehen darf.
* Ein eingetragener Kalender, den Google fuer dieses Konto nicht liefert, wird jetzt deutlich als wirkungslos markiert statt still ignoriert.
* Google meldet Zugriffsprobleme pro Kalender und nicht als HTTP-Fehler. Diese Meldungen landen jetzt im Fehlerprotokoll.

= 1.0.3 =
* Neu: Grenzen fuer alle Termintypen in den Einstellungen. Geschaeftszeiten pro Wochentag, Mindest-Vorlaufzeit und Sperrtage gelten uebergreifend und koennen von keinem Termintyp ausgehebelt werden.
* Die globalen Zeiten werden mit den Zeiten des Termintyps geschnitten, schraenken also nur weiter ein. Ein leerer Wochentag sperrt diesen Tag komplett.
* Die laengere der beiden Vorlaufzeiten gewinnt, global oder pro Termintyp.

= 1.0.2 =
* Brevo: Die Liste wird jetzt ausgewaehlt statt per ID eingetippt, sobald der API-Key gespeichert ist. Fehlt die Liste, sagt das Plugin es deutlich, statt still nichts zu tun.
* Neu: Herkunft einer Buchung. Das Plugin merkt sich die Seite, auf der gebucht wurde; im Shortcode laesst sich das mit quelle="..." ueberschreiben. Sichtbar im Backend, in der Benachrichtigungsmail, im Kalendereintrag und als Attribut FFB_QUELLE in Brevo.
* Neu: Der Titel des Kalendereintrags ist pro Termintyp frei einstellbar, mit Platzhaltern {name}, {termintyp}, {quelle}, {datum}, {uhrzeit}, {email}.
* Mails bekommen zusaetzlich eine Nur-Text-Fassung. Reines HTML kostet bei Spamfiltern Punkte.

= 1.0.1 =
* Termintyp-Formular: Abbrechen-Button neben Speichern, fuehrt zurueck zur Liste.
* Absage-Links funktionieren jetzt auch, wenn die Buchungsseite ihren Inhalt nicht ausliefert (zum Beispiel bei Passwortschutz). In dem Fall liefert das Plugin eine eigene schlanke Absage-Seite aus.

= 1.0.0 =
* Erste Fassung.
