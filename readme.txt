=== Timeslate ===
Contributors: geosem
Tags: booking, appointments, reservations, scheduling, bookings
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Online booking for appointments, tables, classes and anything else you schedule. Set your hours and how many people you can take at once.

== Description ==

Timeslate lets people book a time with you from your own website. You set the days and hours you are open, how long a booking lasts, and how many people you can take at the same time. Timeslate works out which times are still free and shows only those.

It does not care what you are booking. A clinic taking patients, a studio taking classes, a restaurant taking tables and a workshop taking repairs all work the same way: hours, a capacity, and a length of time.

Features:

* Weekly hours, with more than one period a day. A morning session can take fewer people than an afternoon one.
* Dates you are closed, such as holidays or private events. Bookings are blocked on those days whatever your hours say.
* An availability engine that answers one question: given this date and this many people, which times are still free.
* A booking form you add as a block. It walks the visitor through date, people, time, details and a review before confirming.
* Spam protection built in. A honeypot field and a soft limit of five attempts per ten minutes from one address. No CAPTCHA.
* A bookings list in the admin with date, time, people, status and contact details, sortable and searchable.
* A status for every booking: pending, confirmed, completed, cancelled or no show.
* Approve bookings yourself, or turn on auto approve and let them confirm straight away.
* Email at every step. The customer gets a pending, confirmed or cancelled notice, and you get an alert for each new booking.
* A cancel link in the customer's email, so they can cancel without an account and free the slot for someone else.
* Nothing is deleted when you uninstall unless you ask for it. The setting is off by default.

Timeslate stores its data in your own database. It does not send anything to a third party service.

== Installation ==

1. Upload the `timeslate` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it through **Plugins** in WordPress.
3. Set your hours, capacity and booking rules under **Bookings > Settings**.
4. Add the **Timeslate Form** block to any page.

New bookings arrive under **Bookings** in the admin menu.

== Frequently Asked Questions ==

= Is this only for restaurants? =

No. It suits anything booked by time and headcount: clinics, salons, studios, tours, workshops, meeting rooms and equipment hire.

= How does capacity work? =

Each period of the day has a number of places. A booking takes up as many places as there are people, for as long as the booking lasts. A time is offered only if enough places are free for the whole of it.

= Can a customer pick a specific person or table? =

Not in this plugin. Timeslate counts places without naming them. Choosing a named person, room or table is part of Timeslate Pro.

= Can customers cancel? =

Yes. Every confirmation email carries a cancel link that works without an account. Cancelling frees the place straight away.

= Does it work with any theme? =

Yes. The form ships with plain styles and reads its colours from `--timeslate-*` custom properties, so a theme can restyle it by setting those.

= Does it need a page builder? =

No. The form is a block, so the built in editor is enough.

== Screenshots ==

1. The booking form on the front of the site.
2. Weekly hours and capacity in the settings screen.
3. The bookings list in the admin.

== Changelog ==

= 1.0.0 =
* First release.
