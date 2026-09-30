# Advanced Schema Extender for Yoast

Copyright 2026 Kendrick Omar Salting (neirdkc.xyz).

Advanced Schema Extender enriches Yoast SEO's schema graph with Organization and LocalBusiness details, multiple business locations, and per-post FAQ schema.

## Requirements

- WordPress 6.0 or later.
- PHP 7.4 or later.
- Yoast SEO for schema output. The settings page remains available without Yoast, but schema filters are registered only when Yoast's schema API is available.

## Installation

1. Upload the `advanced-schema-extender` folder to `/wp-content/plugins/`, or install the plugin ZIP from **Plugins > Add New > Upload Plugin**.
2. Activate **Advanced Schema Extender for Yoast** in WordPress.
3. Open **Settings > Schema Extender** to configure the site data.

## Features

- **Organization enrichment:** Add organization name, URL, logo, email, telephone, social profile URLs, address, opening hours, service area, and geographic coordinates.
- **LocalBusiness schema:** Optionally add LocalBusiness type and one or more Schema.org subtypes.
- **Multiple locations:** Add enabled locations as separate LocalBusiness nodes. Location telephone and email can inherit the global values; hours and service areas are location-specific.
- **FAQ builder:** Enable the builder for selected public post types, then add up to 20 question-and-answer pairs on each post. Answers allow a limited set of inline HTML elements.
- **Status table:** Compare saved Yoast baseline values with the values provided by this plugin.
- **Import and export:** Copy a JSON export or import settings from a JSON file.

## Yoast Integration

The plugin extends Yoast's schema graph rather than emitting a separate Organization JSON-LD block. By default, it fills empty Organization fields and keeps existing Yoast values. Enable **Override Baseline Organization** to use the plugin's configured values instead; `sameAs` values are merged in either mode.

Organization enrichment runs when Yoast's Site Representation is set to **Organization**. If it is set to **Person**, Yoast does not invoke the Organization filter, so this plugin does not add Organization enrichment for that page configuration.

## Support

If this plugin is useful, you can support its development with the button below.

<a href="https://www.buymeacoffee.com/neirdkc" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-red.png" alt="Buy Me a Coffee" style="height: 60px !important;width: 217px !important;" ></a>

## License

You may copy, use, modify, and share this software, including modified versions,
at no charge. You may not sell the software or modified versions, or charge a
fee for access to or copies of the software. This restriction does not prohibit
using the software on a commercial website or providing paid services that use
it.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.
