=== Fines Finest Booking ===
Contributors: finesfinest
Donate link: https://www.paypal.com/paypalme/finesfinest
Tags: booking, appointments, google calendar, google meet, gdpr
Requires at least: 6.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Appointment booking in your own design: Google Calendar sync, Google Meet, confirmation emails and Brevo. All data stays in your own database.

== Description ==

A lean booking plugin for solo professionals. Any number of appointment types, two-way sync with Google Calendar, an automatically created Google Meet link, confirmation and reminder emails with a calendar file attached, a cancellation link for the client, and optional hand-over of contacts to Brevo.

All booking data lives in your own WordPress database. No third-party widget in the frontend, no consent banner needed, no iframe.

= Features =

* Several appointment types, each with its own duration, time windows and extra questions
* Buffer before and after the appointment, optionally entered in Google Calendar as blocked time; minimum notice, booking horizon, daily limit
* Availability is checked against any number of Google calendars
* Google Meet, phone or on site as the meeting place
* Confirmation and reminder emails in your own corporate design, ICS file attached
* Cancellation link in every email, the slot is released at once
* Brevo: every booking becomes a contact in a list of your choice
* GDPR: consent with timestamp, automatic deletion period, integration with the WordPress privacy tools
* Guided Google setup: a step-by-step assistant, checks for your entries before anyone signs in, and plain-language messages when Google refuses something

= Shortcodes =

`[ffbooking event="intro-call"]` embeds one appointment type.
`[ffbooking]` shows a choice when several appointment types are active.
`[ff_booking]`, the name used up to version 1.2.6, keeps working on existing pages.

= External services =

The plugin can talk to two external services. Nothing is sent until you connect them in the settings.

* **Google Calendar API** (Google LLC): reads busy times from the calendars you select, creates and deletes calendar events for bookings and creates Google Meet links. Sent per booking: date and time, the client's name and email address, the appointment type and the answers to extra questions as the event description. When you click "Check with Google" or "Check connection" in the setup assistant, the plugin also sends your own client ID and client secret to Google's sign-in service (oauth2.googleapis.com, accounts.google.com) to see whether Google accepts them. Nobody is signed in and no booking data is sent. [Terms](https://developers.google.com/terms), [Privacy](https://policies.google.com/privacy).
* **Brevo API** (Brevo SAS): used only if you enter an API key. After each booking the client's name, email address and, if given, phone number are added to the list you select. [Terms](https://www.brevo.com/legal/termsofuse/), [Privacy](https://www.brevo.com/legal/privacypolicy/).

== Installation ==

1. Install the plugin from the directory or upload the ZIP under Plugins, Add New Plugin, Upload Plugin. Activate it.
2. Under Bookings, Settings enter your Google credentials and connect the account.
3. Under Bookings, Appointment types set up your times.
4. Put the shortcode on a page and enter that page's address in the settings.

A detailed setup guide in German, including the Google Cloud project, ships in the file docs/INSTALL.md. The same steps are shown in the help sections of the settings page.

== Frequently Asked Questions ==

= Why do I need my own Google project? =

Google only lets a website read and write a calendar after the website owner has registered the connection in a Google project. Using your own project keeps everything between your website and your Google account: no booking data, tokens or keys pass through the plugin author. It is free and takes about ten minutes. A step-by-step assistant on the settings page guides you through it and checks your entries before you connect.

= Does the plugin work without Google? =

Yes. Without a Google connection the plugin checks only its own bookings when it calculates free slots. Google adds the comparison with your calendars, the calendar entries and the Meet links.

= Where is the data stored? =

In two tables of your own WordPress database. Nothing is stored with the plugin author. Uninstalling deletes the tables and settings only if you tick that option in the settings first.

= What happens when a client cancels? =

The booking is marked as cancelled, the slot is free again, the Google Calendar event is deleted and a cancellation email goes out.

= The test email landed in my spam folder. What can I do? =

The spam filter of the receiving mailbox decides, not the plugin. Send through a mail service that signs your domain (SPF, DKIM, DMARC) and use a sender address on your own domain. Then book once with an address from a testing service such as mail-tester.com to see how a real confirmation email scores. The plugin adds a plain-text version to every email it sends, which most filters expect.

= Why do cancellation links still work after I renamed the booking page? =

The plugin answers every link with the cancellation parameter itself. If the booking page is missing, renamed or password protected, it shows its own lean cancellation page.

== Screenshots ==

1. Day picker: visitors see the next bookable days with the number of free slots.
2. Free times for the chosen day, calculated from your time windows, buffers and Google calendars.
3. Booking form with name, email, optional phone, your own extra questions and the consent checkbox.
4. Confirmation: the appointment is booked, the email with the calendar file is on its way.
5. Appointment type: duration, interval, buffers and two time windows per weekday.
6. Bookings overview with contact details, source page and the answers to your questions.
7. Settings with the setup status: every open step links to the next action.

== Changelog ==

= 1.4.2 =
* The settings page is split into tabs: Google Calendar, Times and limits, Emails, Brevo, Privacy, Design and Recent errors. The setup status stays on top. The page remembers the tab you used last.
* Links from the setup status open the right tab. If the browser rejects a field on saving, its tab opens so you can see why.

= 1.4.1 =
* New in the plugin list: links to the setup guide and to the support forum, and a donate link next to the settings.
* Self-hosted edition only: "Check for updates" in the plugin list. The edition in the WordPress plugin directory updates through wordpress.org.

= 1.4.0 =
* New: a step-by-step Google setup assistant on the settings page. It links to the right pages of the Google Cloud Console and has copy buttons for the values you need to enter.
* New: "Check with Google" tests the client ID, the client secret and the redirect URI before anyone signs in, and says what is wrong in plain words.
* New: "Check connection" tests the token, the Calendar API and the target calendar without changing anything.
* New: the client ID and secret are trimmed on saving, and the fields warn while you type when they are swapped or incomplete.
* New: if Google ends the connection, for example because the app is still in testing mode, or the client is deleted, the settings page and the setup status show the cause and the way out.
* If you untick the calendar permission on Google's consent screen, the plugin refuses the connection with a clear message instead of failing later.
* German translation updated.

= 1.3.2 =
* The plugin checks that its two database tables exist after creating them. Only then it stores the schema version.
* If a table is missing, the backend shows a notice with the table name and the message of the database. The plugin tries again on every page load and finishes the setup once the tables exist.
* Creating the tables prints nothing, also with WP_DEBUG, so activation never shows "unexpected output".
* Without its tables the plugin runs no queries: the booking form shows a short note, the REST routes and the reminder cron stay off.

= 1.3.1 =
* The plain-text version of the plugin's emails now reaches the recipient with mail plugins that talk to the provider directly, for example Fluent SMTP with Brevo. Before, those emails went out as HTML only, which spam filters score higher.
* Only emails sent by this plugin get the plain-text version. Emails from other plugins are left alone.
* Links appear with their address in the plain-text version, so the cancellation link is no longer missing there.
* The test email uses the same layout as the real emails and shows the sender address, so the spam check says more about real bookings.
* The plugin uses its own random secret for the form token and for the hashed IP address in the rate limit. It no longer reads the keys and salts of WordPress.
* Fonts and colours from the settings are checked before they reach a stylesheet or an email. A font list keeps only font names.
* Emails and the cancellation page pass their HTML through wp_kses() before it is output.

= 1.3.0 =
* Every name the plugin registers in WordPress now starts with ffbooking, as the plugin directory requires: database tables, options, hooks, REST namespace, shortcode, admin pages and asset handles. The update renames the tables and options by itself. Bookings, appointment types, settings and the Google connection stay as they are.
* Shortcode: `[ffbooking]` replaces `[ff_booking]`. Pages with the old name keep working.
* Cancellation links in emails sent before the update keep working.
* The redirect URI of the Google connection now ends in page=ffbooking-settings. The existing connection keeps working. Before you connect Google again, add the new address in the Google Cloud Console; a notice in the backend shows it.
* If the database tables cannot be renamed, the backend shows the database error. Nothing is deleted, and the plugin tries again on the next page load.
* The stand-alone cancellation page attaches its two style rules to the stylesheet instead of printing a style element.
* The plugin directory edition ships without translation files; translations come from translate.wordpress.org. The self-hosted edition keeps the German translation.

= 1.2.6 =
* Plugin URI now points to the development repository on GitHub, the author URI stays fines-finest.com. The plugin directory requires both to differ. No functional change.

= 1.2.5 =
* Buffers add up between two bookings: the buffer after the earlier appointment plus the buffer before the next one. Before, only the larger of the two counted. With the defaults (0 before, 15 after) nothing changes.
* Entries from Google Calendar keep the slot's own buffers around them: the buffer after in front of the entry, the buffer before behind it.
* New per appointment type: "Enter the buffers in Google Calendar as blocked time". Every booking then creates two extra entries, "Preparation" before and "Follow-up" after the appointment, marked as busy, without guests. They are deleted together with the appointment. If one of them cannot be created, the error log says so and the booking stays valid.
* The buffers are chosen from a list in 5-minute steps up to 120 minutes instead of typed.
* The double-booking check behind the booking lock respects the buffers of both sides.
* Database: two columns for the IDs of the blocked times and one for the new option. Existing data stays unchanged.

= 1.2.4 =
* Directory edition: the update texts of the self-hosted edition are no longer part of its code. No functional change.
* Changelog shortened to the 1.2 series. Older entries are listed in changelog.txt in the plugin folder.

= 1.2.3 =
* Self-hosted edition only: "Check for updates now" button in the settings, and "Check again" on the WordPress Updates page clears the plugin's update cache first. The edition in the WordPress plugin directory updates through wordpress.org and is not affected.

= 1.2.2 =
* Coffee fund: a link to say thank you to the developer, shown in the plugin directory and at the end of the settings page.
* Banner, icon and screenshots for the WordPress plugin directory.
* Settings and appointment types are linked from the plugin list.
* One click creates the booking page with the shortcode and saves its address, straight from the setup status.
* Copy button next to every shortcode.
* Resend the confirmation email with the calendar file from the bookings list.
* Duplicate an appointment type. The copy is inactive until you release it.
* If no privacy policy link is set, the consent checkbox links to the privacy policy page from Settings, Privacy.
* The day strip snaps into place when swiping on phones.

= 1.2.1 =
* The slot calculation steps through the wall-clock minutes of a day instead of PHP time intervals. In the repeated hour at the end of daylight saving time every time is offered exactly once, on every PHP version.
* Automated tests for the slot calculation (time windows, business hours, buffers, minimum notice, daily limit, blocked days, horizon, both daylight saving changes), the reminder window and the phone number normalisation. They run automatically for every commit and before every release.
* Cancellation links never dead-end. If the booking page is renamed or deleted, if none is set, or if the link matches no appointment, the plugin shows its own lean cancellation page with a clear message. On the booking page itself the cancellation stays in the design of your site.
* New in the setup status: a fifth row for the reminder task with last and next run. It warns when the task is not scheduled or more than an hour overdue, and explains the required server cron job when WP-Cron is disabled. A "Run now" button triggers the run by hand.
* Ready for the WordPress plugin directory: readme in English, Plugin Check without errors, setup guide moved to docs/INSTALL.md. Every release also builds a version without the self-hosted updater for the directory.

= 1.2.0 =
* The plugin is translatable. The source language is English, the German translation ships as a language pack, now with umlauts in all texts and emails.
* The text domain is fines-finest-booking instead of ff-booking, as required by the WordPress directory.
* Dates and times in emails and in the backend follow the language of the site.
* Block themes: the booking widget did not receive its configuration because such themes render the content before the head section. Scripts are now registered early.
* The success message after booking showed "Uhr" twice.
* The notice after activation wrongly said that nothing could be booked without Google.
* The stored default consent text without umlauts is replaced once by the new version. Custom wording stays untouched.

Older versions are listed in changelog.txt in the plugin folder.
