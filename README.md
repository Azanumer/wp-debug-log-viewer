# WP Debug Log Viewer

Read, search and filter `wp-content/debug.log` from inside wp-admin — no more SSH tailing when something breaks on a client site.

## Features

- **Newest first** — the latest errors are on top; stack traces stay attached to their entry.
- **Severity filter** — Fatal Error, Parse Error, Warning, Notice, Deprecated, DB Error and more, with counts per severity and colour badges.
- **Search** — full-text search across messages and timestamps.
- **Paged** — 50 entries per page, so even a huge log stays fast.
- **One-click clear** — empties the log (nonce-protected, admins only).
- **Big-log safe** — only the last 512 KB of the file is read, so a runaway log can't hang the admin.
- **Setup hint** — warns you if `WP_DEBUG` or `WP_DEBUG_LOG` is off, with the exact `wp-config.php` lines to add.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Tools → Debug Log**.

## Requirements

- WordPress 5.0+
- `WP_DEBUG` and `WP_DEBUG_LOG` enabled in `wp-config.php` (the plugin tells you if they aren't):

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

## Notes

- The log file itself is never moved or renamed — clearing truncates it in place, so WordPress keeps writing to it.
- Only users with `manage_options` can open the page or clear the log.
- Uninstalling removes nothing (the plugin stores no options); delete `debug.log` yourself if you want it gone.

## License

MIT — see [LICENSE](LICENSE).
