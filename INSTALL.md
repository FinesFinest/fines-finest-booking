# Fines Finest Booking – Einrichtung

Diese Anleitung führt dich einmal komplett durch die Einrichtung. Wo `deine-domain.de` steht, setzt du deine eigene Adresse ein.

Rechne mit 30 bis 45 Minuten. Der Google-Teil ist der einzige fummelige Schritt, und den machst du genau einmal.

---

## 1. Plugin installieren

WordPress-Backend, **Plugins → Installieren → Plugin hochladen**, ZIP wählen, aktivieren.

Beim Aktivieren entstehen zwei Datenbanktabellen und ein Beispiel-Termintyp „Erstgespräch", 30 Minuten, Mo–Do 9–12 und 14–17 Uhr, Fr 9–13 Uhr, zwölf Stunden Vorlauf. Alles änderbar.

Auf der Einstellungsseite steht oben ein Kasten mit dem Einrichtungsstand. Daran kannst du dich entlanghangeln, er zeigt dir jederzeit, was noch fehlt.

Im Menü links steht ab jetzt **Buchungen**.

---

## 2. Google-Cloud-Projekt anlegen

Das brauchst du, damit dein WordPress mit deinem Kalender reden darf. Kostenlos.

**2.1** Geh auf [console.cloud.google.com](https://console.cloud.google.com), melde dich mit dem Google-Konto an, in dessen Kalender die Termine landen sollen.

**2.2** Oben links auf die Projektauswahl, **Neues Projekt**. Name zum Beispiel `fines-finest-booking`. Anlegen und auswählen.

**2.3** Links **APIs und Dienste → Bibliothek**. Such nach *Google Calendar API*, öffnen, **Aktivieren**.

**2.4** Links **APIs und Dienste → OAuth-Zustimmungsbildschirm**.

- Nutzertyp: **Extern**
- App-Name: frei wählbar, zum Beispiel `Terminbuchung`
- Support-E-Mail und Entwickler-Kontakt: deine Adresse
- Speichern und weiter, bis der Bildschirm angelegt ist

Bei **Zielgruppe** oder **Testnutzer** trägst du die Google-Adresse ein, deren Kalender benutzt werden soll. Solange die App im Testmodus läuft, musst du die Verbindung alle sieben Tage erneuern. Willst du das nicht, klick auf **App veröffentlichen** und bestätige. Eine Google-Überprüfung brauchst du nicht, solange nur dein eigenes Konto zugreift.

**2.5** Links **APIs und Dienste → Anmeldedaten → Anmeldedaten erstellen → OAuth-Client-ID**.

- Anwendungstyp: **Webanwendung**
- Name: beliebig
- Unter **Autorisierte Weiterleitungs-URIs** trägst du genau die Adresse ein, die im Plugin unter **Buchungen → Einstellungen** bei „Weiterleitungs-URI" steht. Kopier sie von dort, tipp sie nicht ab.

Erstellen. Du bekommst **Client-ID** und **Client-Schlüssel** angezeigt.

---

## 3. Google verbinden

**Buchungen → Einstellungen**. Client-ID und Client-Secret eintragen, **Speichern**. Danach erscheint der Button **Mit Google verbinden**. Klicken, Konto wählen, Zugriff erlauben. Du landest zurück im Backend und siehst einen grünen Punkt.

**Zielkalender**: Hier landen die gebuchten Termine. Nach dem Verbinden ist das ein Auswahlfeld, angeboten werden nur Kalender, in die du schreiben darfst.

**Weitere Kalender prüfen**: Kalender, die nur blocken sollen. Zum Ankreuzen, siehe Abschnitt 3b.

---

## 3b. Weitere Kalender blocken lassen

Das Plugin prüft vor jeder Anzeige deinen Kalender. Du kannst zusätzliche Kalender eintragen, die ebenfalls blocken sollen – zum Beispiel einen privaten.

**Wichtig:** Das funktioniert nur mit Kalendern, die das verbundene Google-Konto auch sehen darf. Ein Kalender aus einem anderen Google-Konto muss vorher freigegeben werden. Ohne Freigabe antwortet Google mit einem Fehler für diesen Kalender, und er blockt schlicht nichts.

Das Plugin erkennt das: Unter *Buchungen → Einstellungen* werden nur Kalender zum Ankreuzen angeboten, die wirklich abgefragt werden können. Steht ein Kalender in den Einstellungen, der nicht erreichbar ist, erscheint eine rote Warnung.

### Einen Kalender aus einem anderen Konto freigeben

Im **anderen** Google-Konto, also dem, dem der Kalender gehört:

1. Google Kalender am Computer öffnen, links unter **Meine Kalender** den Kalender suchen.
2. Daneben auf das Dreipunkt-Menü, dann **Einstellungen und Freigabe**.
3. Links auf **Geteilt mit**, dann **Personen und Gruppen hinzufügen**.
4. Die Adresse des verbundenen Kontos eintragen, also das Konto, mit dem das Plugin verbunden ist.
5. Als Berechtigung **„Nur Verfügbarkeit anzeigen (keine Details)"** wählen. Mehr braucht das Plugin nicht – es muss nur wissen, wann du belegt bist, nicht womit.
6. Auf **Senden** klicken. Das verbundene Konto bekommt eine Mail und muss den Link darin einmal anklicken.

Danach erscheint der Kalender in den Plugin-Einstellungen in der Ankreuzliste. Die Liste ist zehn Minuten zwischengespeichert, warte also kurz oder lade die Seite später neu.

Googles eigene Anleitung dazu: https://support.google.com/calendar/answer/37082?hl=de

Ob die niedrigste Berechtigungsstufe für den Abruf über die Kalender-Schnittstelle genügt, steht bei Google nicht ausdrücklich. Die Probe ist einfach: Taucht der Kalender in der Ankreuzliste auf, reicht sie.

---

## 4. Mails einrichten

Immer noch unter **Einstellungen**:

- **Benachrichtigung an**: deine Adresse, dorthin geht die Meldung über jede neue Buchung
- **Absendername** und **Absenderadresse**: die Adresse muss zu deiner Domain passen, sonst landen die Mails im Spam
- **Buchungsseite**: die URL der Seite mit dem Shortcode, wird für die Absage-Links gebraucht

Wichtig: WordPress verschickt Mails über `wp_mail`, und das ist auf den meisten Hostings unzuverlässig. Nimm ein SMTP-Plugin und leg Brevo als Relay dahinter. Du hast den Account ohnehin. Ohne das kommen Bestätigungen sporadisch nicht an, und das merkst du erst, wenn jemand nicht zum Termin erscheint.

---

## 5. Brevo verbinden

**Einstellungen → Brevo**. Den API-Key findest du in Brevo unter *Einstellungen → SMTP & API → API-Keys*. Listen-ID eintragen, **Verbindung testen** klicken.

Jede Buchung legt einen Kontakt an oder aktualisiert ihn, mit den Attributen `VORNAME`, `NACHNAME`, `FFB_TERMINTYP`, `FFB_TERMIN_AM`, `FFB_QUELLE`. Leg die drei `FFB_`-Attribute in Brevo vorher als **Text** an, sonst ignoriert Brevo sie stillschweigend.

Die Liste wählst du im Plugin aus einer Liste aus, sobald der API-Key gespeichert ist. Ohne gewählte Liste legt das Plugin keine Kontakte an und sagt das auch.

Eine Sache noch: Eine Terminbuchung ist keine Newsletter-Einwilligung. Nimm eine eigene Liste dafür und schick von dort keine Werbung raus.

---

## 6. Termintyp anpassen

**Buchungen → Termintypen → Kostenlose Erstanalyse**.

- **Zeitfenster**: pro Wochentag, Format `09:00-12:00, 14:00-17:00`. Leer heißt: nicht buchbar.
- **Raster**: in welchem Abstand Slots angeboten werden. 30 bedeutet halbstündlich.
- **Puffer danach**: Zeit, die nach jedem Termin freibleibt. 15 Minuten sind ein guter Wert, sonst hetzt du von Call zu Call.
- **Vorlaufzeit**: in Minuten. 720 heißt, niemand kann für die nächsten zwölf Stunden buchen.
- **Zusatzfragen**: eine pro Zeile, Format `Label | typ | pflicht`. Typen sind `text`, `textarea`, `tel`, `url`.

Urlaub trägst du nicht hier ein, sondern als ganztägigen Termin im Google-Kalender. Das blockt automatisch.

---

## 7. Auf der Website einbauen

Der Shortcode steht in der Termintypen-Liste, für den Standardtyp:

```
[ff_booking event="erstanalyse"]
```

**In Oxygen**: Seite anlegen, zum Beispiel `/termin`. Ein **Code Block** oder ein **Shortcode**-Element in die Section, Shortcode einfügen. Fertig.

Die Farben stellst du unter **Einstellungen → Design** ein. Ab Werk sind sie neutral gehalten, damit das Buchungsfeld auf jeder Website erträglich aussieht, bevor du es anpasst. Schriften stehen auf `inherit`, übernehmen also die der Seite.

Die Absage-Links in den Mails zeigen auf dieselbe Seite mit dem Parameter `?ffb_cancel=…`. Das Plugin erkennt das und zeigt statt des Kalenders die Absage-Ansicht. Du brauchst dafür keine zweite Seite. Liefert die Seite ihren Inhalt nicht aus, etwa bei Passwortschutz, zeigt das Plugin eine eigene schlanke Absage-Seite.

---

## 8. Testen, bevor es live geht

Arbeite die Liste durch. Jeder Punkt hat schon mal jemandem den Launch versaut.

1. Seite im privaten Fenster öffnen, Slots erscheinen
2. Testmail über den Knopf in den Einstellungen verschicken und prüfen, ob sie ankommt
3. Termin buchen mit einer echten zweiten Mailadresse
4. Bestätigungsmail kommt an, Kalenderdatei im Anhang lässt sich öffnen
5. Termin steht im Google-Kalender, Meet-Link funktioniert
6. Benachrichtigung an dich ist da
7. Kontakt liegt in Brevo
8. Slot ist auf der Website weg, der Puffer blockt auch den direkt folgenden
9. Trag im Google-Kalender einen Termin ein, lade die Seite neu, der Slot ist blockiert
10. Absage-Link aus der Mail klicken, Termin verschwindet aus dem Kalender, Slot ist wieder frei
11. Auf dem Handy einmal komplett durchbuchen

---

## Wenn etwas klemmt

**Keine Slots, obwohl Zeiten gepflegt sind.** Prüf die Vorlaufzeit und ob im Google-Kalender ganztägige Termine liegen.

**Google-Verbindung bricht nach sieben Tagen ab.** Die App steht im Testmodus. In der Cloud-Konsole auf **App veröffentlichen**.

**Fehler beim Verbinden.** Weiterleitungs-URI vergleichen, Zeichen für Zeichen. Ein fehlendes `www` reicht.

**Termin gebucht, aber nicht im Kalender.** Unter **Einstellungen** ganz unten steht „Letzte Fehler" mit der Google-Antwort im Klartext.

**Mails kommen nicht an.** Erst den Testmail-Knopf benutzen, dann ein SMTP-Plugin einrichten. Siehe Schritt 4.

**Zeiten verschieben sich um eine Stunde.** Dann steht die Zeitzone der Website auf einem festen Versatz statt auf einer Stadt. Unter Einstellungen, Allgemein eine Stadt wählen. Der Kasten oben auf der Plugin-Seite warnt davor.

**Erinnerungen kommen nicht.** WP-Cron läuft nur, wenn die Seite Besuch bekommt. Bei wenig Traffic richtest du beim Hoster einen echten Cronjob ein, der stündlich `wp-cron.php` aufruft.

---

## Was in die Datenschutzerklärung muss

Das Plugin liefert einen fertigen Textbaustein. **Einstellungen → Datenschutz** in WordPress, dort unter „Vorgeschlagener Text" steht der Abschnitt zur Terminbuchung. Übernimm ihn und lass ihn im Zweifel von jemandem gegenlesen, der das darf. Das hier ist keine Rechtsberatung.

Wenn du Brevo benutzt: Schalte in Brevo unter *Transaktional → Einstellungen* das Klick- und Öffnungs-Tracking ab. Sonst schreibt Brevo alle Links in deinen Terminbestätigungen auf eigene Trackingdomains um und misst das Verhalten deiner Kundinnen, ohne dass die zugestimmt haben. Nebenbei steigt dadurch der Spam-Score.
