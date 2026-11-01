=== Fines Finest Booking ===
Contributors: finesfinest
Tags: booking, appointments, google calendar, google meet, gdpr
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Appointment booking in your own design: Google Calendar sync, Google Meet, confirmation emails and Brevo. All data stays in your own database.

== Description ==

A lean booking plugin for solo professionals. Any number of appointment types, two-way sync with Google Calendar, an automatically created Google Meet link, confirmation and reminder emails with a calendar file attached, a cancellation link for the client, and optional hand-over of contacts to Brevo.

All booking data lives in your own WordPress database. No third-party widget in the frontend, no consent banner needed, no iframe.

= Features =

* Several appointment types, each with its own duration, time windows and extra questions
* Buffer before and after the appointment, minimum notice, booking horizon, daily limit
* Availability is checked against any number of Google calendars
* Google Meet, phone or on site as the meeting place
* Confirmation and reminder emails in your own corporate design, ICS file attached
* Cancellation link in every email, the slot is released at once
* Brevo: every booking becomes a contact in a list of your choice
* GDPR: consent with timestamp, automatic deletion period, integration with the WordPress privacy tools

= Shortcodes =

`[ff_booking event="intro-call"]` embeds one appointment type.
`[ff_booking]` shows a choice when several appointment types are active.

= External services =

The plugin can talk to two external services. Nothing is sent until you connect them in the settings.

* **Google Calendar API** (Google LLC): reads busy times from the calendars you select, creates and deletes calendar events for bookings and creates Google Meet links. Sent per booking: date and time, the client's name and email address, the appointment type and the answers to extra questions as the event description. [Terms](https://developers.google.com/terms), [Privacy](https://policies.google.com/privacy).
* **Brevo API** (Brevo SAS): used only if you enter an API key. After each booking the client's name, email address and, if given, phone number are added to the list you select. [Terms](https://www.brevo.com/legal/termsofuse/), [Privacy](https://www.brevo.com/legal/privacypolicy/).

== Installation ==

1. Install the plugin from the directory or upload the ZIP under Plugins, Add New Plugin, Upload Plugin. Activate it.
2. Under Bookings, Settings enter your Google credentials and connect the account.
3. Under Bookings, Appointment types set up your times.
4. Put the shortcode on a page and enter that page's address in the settings.

A detailed setup guide in German, including the Google Cloud project, ships in the file docs/INSTALL.md. The same steps are shown in the help sections of the settings page.

== Frequently Asked Questions ==

= Does the plugin work without Google? =

Yes. Without a Google connection the plugin checks only its own bookings when it calculates free slots. Google adds the comparison with your calendars, the calendar entries and the Meet links.

= Where is the data stored? =

In two tables of your own WordPress database. Nothing is stored with the plugin author. Uninstalling deletes the tables and settings only if you tick that option in the settings first.

= What happens when a client cancels? =

The booking is marked as cancelled, the slot is free again, the Google Calendar event is deleted and a cancellation email goes out.

= Why do cancellation links still work after I renamed the booking page? =

The plugin answers every link with the cancellation parameter itself. If the booking page is missing, renamed or password protected, it shows its own lean cancellation page.

== Changelog ==

= 1.2.1 =
* The slot calculation steps through the wall-clock minutes of a day instead of PHP time intervals. In the repeated hour at the end of daylight saving time every time is offered exactly once, on every PHP version.
* Automated tests for the slot calculation (time windows, business hours, buffers, minimum notice, daily limit, blocked days, horizon, both daylight saving changes), the reminder window and the phone number normalisation. GitHub runs them for every commit and before every release.
* Cancellation links never dead-end. If the booking page is renamed or deleted, if none is set, or if the link matches no appointment, the plugin shows its own lean cancellation page with a clear message. On the booking page itself the cancellation stays in the design of your site.
* New in the setup status: a fifth row for the reminder task with last and next run. It warns when the task is not scheduled or more than an hour overdue, and explains the required server cron job when WP-Cron is disabled. A "Run now" button triggers the run by hand.
* Ready for the WordPress plugin directory: readme in English, Plugin Check without errors, setup guide moved to docs/INSTALL.md. Every release now also builds a ZIP without the GitHub updater for the directory.

= 1.2.0 =
* The plugin is translatable. The source language is English, the German translation ships as a language pack, now with umlauts in all texts and emails.
* The text domain is fines-finest-booking instead of ff-booking, as required by the WordPress directory.
* Dates and times in emails and in the backend follow the language of the site.
* Block themes: the booking widget did not receive its configuration because such themes render the content before the head section. Scripts are now registered early.
* The success message after booking showed "Uhr" twice.
* The notice after activation wrongly said that nothing could be booked without Google.
* The stored default consent text without umlauts is replaced once by the new version. Custom wording stays untouched.

= 1.1.2 =
* The protection against double bookings uses a database lock. The previous lock via an option was not atomic, two simultaneous bookings could both go through.
* Who books less than 25 hours before the appointment no longer gets an extra reminder email. It used to arrive right after the confirmation and spoke of "tomorrow".
* The reminder email says "today" when the appointment is on the same day, for example when the cron ran late.
* An appointment type with upcoming appointments can no longer be deleted. Those appointments used to lose their reminder silently.
* Brevo: phone numbers are converted to the international format. If Brevo still rejects the number, the contact is created without it instead of not at all.
* The Google connection reports when the login has expired or was aborted at Google, instead of returning silently to the settings page.

= 1.1.1 =
* The bundled sample appointment type is neutral, without preset description and without daily limit.
* The default colours are neutral instead of a specific corporate design. Fonts are set to "inherit" and take over those of the website.
* The setup guide no longer contains specific sample addresses and covers the new functions.
* Affects new installations only. Existing settings, colours and appointment types stay unchanged.

= 1.1.0 =
* New: setup status at the top of the settings page. Four items with traffic lights, each linking to the next action: Google connected, appointment type active, booking page known, email sending tested.
* New: "Send test email" button. Email sending can be checked without creating a real booking.
* New: expandable help texts behind the sections, including the complete guide for the Google Cloud project. Click to open instead of hover, so it works on phones and with screen readers.
* New: the redirect URI can be copied with one click.
* New: the site's time zone is shown. If it is a fixed offset instead of a city, the plugin warns because of daylight saving time.
* Notice right after activation that Google must be connected first.

= 1.0.5 =
* Time windows are selected instead of typed: two from-to pairs per weekday, in quarter-hour steps. Applies to the business hours and to every appointment type.
* Incomplete or reversed pairs are discarded and block the day instead of opening it by accident. A typo can no longer allow a night booking.
* Minimum notice can be given in minutes, hours or days. It is still stored in minutes.

= 1.0.4 =
* The target calendar is selected instead of typed. Only calendars the connected account may write to are offered.
* Additional calendars to check are ticked instead of typed. Only calendars the connected account can actually see appear.
* A configured calendar that Google does not deliver for this account is now clearly marked as ineffective instead of being ignored silently.
* Google reports access problems per calendar, not as HTTP errors. These messages now end up in the error log.

= 1.0.3 =
* New: limits for all appointment types in the settings. Business hours per weekday, minimum notice and blocked days apply globally and cannot be overridden by any appointment type.
* The global times are intersected with the times of the appointment type, so they only restrict further. An empty weekday blocks that day completely.
* The longer of the two minimum notices wins, global or per appointment type.

= 1.0.2 =
* Brevo: the list is now selected instead of typed as an ID once the API key is saved. If the list is missing, the plugin says so clearly instead of doing nothing silently.
* New: origin of a booking. The plugin remembers the page where the booking was made; in the shortcode this can be overridden with quelle="...". Visible in the backend, in the notification email, in the calendar entry and as the attribute FFB_QUELLE in Brevo.
* New: the title of the calendar entry can be set per appointment type, with the placeholders {name}, {termintyp}, {quelle}, {datum}, {uhrzeit} and {email}.
* Emails get an additional plain-text version. Pure HTML costs points with spam filters.

= 1.0.1 =
* Appointment type form: Cancel button next to Save, leads back to the list.
* Cancellation links also work when the booking page does not deliver its content, for example when it is password protected. In that case the plugin serves its own lean cancellation page.

= 1.0.0 =
* First version.
