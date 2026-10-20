# Northline Inventory

Vehicle inventory plugin for WordPress, built for car dealers and importers. No page builder, no external services except the free NHTSA VIN API.

**Live demo:** https://northline.clientus.vip · **Theme:** [northline-theme](https://github.com/AleksandrOleinik-git/northline-theme)

## Features

- `vehicle` post type with taxonomies: make, body type, fuel, transmission, drivetrain, status.
- Spec fields: model, trim, year, mileage, engine, power, colour, seats, VIN, stock no., auction grade, price, location, features.
- **CSV import** from the admin (dry run, update-by-VIN or stock number, photo download) and from WP-CLI:
  `wp northline import vehicles.csv --decode-vin`
- **VIN decoding** through the NHTSA vPIC API, as a button in the edit screen and as an import option. Results are cached for a week.
- Sortable photo gallery meta box; the first photo becomes the featured image.
- **REST API** for front-end filtering and "load more": `GET /wp-json/northline/v1/vehicles?make=toyota&price_max=20000&sort=price_asc`
- Filter query builder (`NL_Query`) shared by templates and the API: make, model, body, fuel, transmission, drivetrain, status, year, price and mileage ranges, keyword, six sort orders.
- **Lead forms** with nonce, honeypot, minimum fill time and per-IP rate limit. Leads are stored as a private post type and emailed to the admin; `nl_lead_created` action for CRM hooks.
- Template helpers (`nl_render_card()`, `nl_vehicle_specs()`, `nl_vehicle_jsonld()` …) and schema.org `Vehicle` + `Offer` JSON-LD.

## CSV format

Header row, comma or semicolon separated, UTF-8. Columns in any order:

```
title, make, model, trim, year, mileage_km, engine_cc, power_hp, color, seats, vin, stock_no, grade,
price_usd, location, body, fuel, transmission, drive, status, features, description, photos
```

`features` and `photos` take several values separated by `|`. Photos may be URLs or paths relative to the uploads folder. A sample file is in `sample-data/`.

## Requirements

WordPress 6.4+, PHP 8.0+. Works with any theme; the card template can be overridden by placing `northline/vehicle-card.php` in the theme.

## License

GPL-2.0-or-later.
