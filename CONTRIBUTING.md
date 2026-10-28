# Contributing

Thanks for helping. This repository is published from the Stackless for EDD source, so pull requests are read and applied there by hand rather than merged here. Small, focused changes land fastest.

## Reporting a bug

Use the bug report template and include your WordPress, PHP and Easy Digital Downloads versions, the module involved, the steps to reproduce, and what you expected. Turn on `WP_DEBUG_LOG` and paste any related lines from `wp-content/debug.log` and from **Downloads → Tools → Debug Log** (entries start with `stackless-edd-`).

Report security problems privately through **Security → Report a vulnerability** on this repository, not in a public issue.

## Code

- PHP 8.0 compatible, WordPress coding practices: nonces and capability checks on every action, sanitize input, escape output, `$wpdb->prepare()` for queries.
- Plain ES2017 JavaScript with WordPress globals and jQuery. No build step.
- Text domain `stackless-lite-edd`.
- Admin UI: border radius 4px or less, no colored left-stripe notices, 40px controls.
- Keep option names, meta keys, tables, hooks and REST routes (`stackless_edd_` prefix) as they are: Stackless for EDD reads the same data.

By contributing you agree your work is released under GPL-2.0-or-later.
