# WP Varnish Cache Purger

Purge the Varnish cache that sits in front of your WordPress installation automatically.  
This plugin schedules full-site purges and clears individual pages whenever content is
published or updated, keeping visitors and search engines in sync with what is stored
in CloudPanel + Varnish.

## Features

- Sends HTTP `PURGE` requests to one or more Varnish endpoints.
- Schedules periodic purges (default hourly) and lets you choose from any registered cron schedule.
- Clears the permalink and homepage whenever posts are published or modified.
- Supports authenticated PURGE requests via a custom header (for protected Varnish instances).

## Installation

1. Copy the plugin folder into `wp-content/plugins/wp-varnish-cache-purger`.
2. Activate **WP Varnish Cache Purger** from **Plugins → Installed Plugins**.
3. Visit **Settings → Varnish Cache Purger** to configure at least one Varnish endpoint.

## Configuration

The settings screen exposes the following options:

- **Varnish Endpoints** – One base URL per Varnish server (e.g. `https://cache.example.com`).
- **Schedule Interval** – How often WordPress should trigger a full-site purge (defaults to hourly). When you choose the daily interval you can also pick the exact 24-hour time, and the weekly interval adds both a time and day-of-week selector.
- **Paths to Purge on Schedule** – Relative paths that will be purged on the schedule (default `/`).
- **Optional Authentication Header** – Header/value pair to send with PURGE requests when CloudPanel
  or your Varnish tier validates incoming purges.
- **Automatic Post Purge** – Toggle purging when a post is first published, on update, and whether the
  homepage should be included in those bursts.

## Notes

- WordPress cron depends on visits to the site. Make sure WP-Cron runs regularly (or trigger it from
  the system scheduler) to guarantee scheduled purges fire on time.
- The plugin issues HTTP `PURGE` requests. Verify that your CloudPanel/Varnish configuration listens
  for PURGE and routes the call to the correct cache node.
- No automated tests were executed in this environment because the local PHP binary is unavailable.
