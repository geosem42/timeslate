# Timeslate

Online booking for WordPress. People pick a time on your site, you get the booking in wp-admin.

You set the days and hours you are open, how long a booking lasts, and how many people you can take at the same time. Timeslate works out which times are still free and offers only those.

It does not care what is being booked. A clinic taking patients, a studio taking classes, a restaurant taking tables and a workshop taking repairs all work the same way: hours, a capacity, and a length of time.

## What it does

- Weekly hours, with more than one period a day. A morning session can take fewer people than an afternoon one.
- Dates you are closed, such as holidays or private events.
- An availability engine that answers one question: given this date and this many people, which times are still free.
- A booking form you add as a block. Date, people, time, details, review, confirm.
- Spam protection built in. A honeypot field and a soft limit of five attempts per ten minutes from one address. No CAPTCHA.
- A bookings list in wp-admin with date, time, people, status and contact details.
- A status for every booking: pending, confirmed, completed, cancelled or no show.
- Approve bookings yourself, or turn on auto approve.
- Email at every step, for the customer and for you.
- A cancel link in the customer's email that works without an account.
- Nothing is deleted on uninstall unless you ask for it.

Bookings live in your own database. Nothing is sent to a third party.

## Requirements

- WordPress 6.6 or newer
- PHP 8.1 or newer

## Install

Install from the WordPress plugin directory, or drop the `timeslate` folder into `wp-content/plugins/` and activate it.

Then set your hours under **Bookings > Settings** and add the **Timeslate Form** block to a page.

## Development

```bash
npm install
npm run build     # build the block bundle
npm run dev       # rebuild on change
```

The block source is in `assets/blocks/src/`; the compiled bundle in `assets/blocks/build/` is gitignored and produced by the build.

Before opening a pull request, run WordPress's own checker and keep it at zero errors:

```bash
wp plugin check timeslate
```

## Layout

```
timeslate.php          plugin header, constants, wiring
inc/                   one concern per file
  class-timeslate-availability.php   the slot engine, a pure function
  class-timeslate-rest.php           public availability and booking endpoints
  class-timeslate-cpt.php            the booking post type and its meta
  class-timeslate-admin.php          bookings list and status workflow
  class-timeslate-emails.php         transactional email
  class-timeslate-tokens.php         cancel links that need no account
assets/blocks/src/     the booking form block
templates/             email bodies, overridable from a theme
```

## Styling

The form ships with plain styles and reads every colour from a `--timeslate-*` custom property with a fallback. A theme can restyle it by declaring those properties and nothing else.

## Pro

[Timeslate Pro](https://logicvoid.dev/plugins/timeslate) adds named resources, so a customer can book a specific person, room or table rather than an anonymous place. It also adds the admin calendar, reminder emails, deposits, custom fields and reports.

## Licence

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html
