# GeoIP setup (country / province / city)

`GeoIpManager` resolves visitor IPs to country, region (province/state) and
city using a local `.mmdb` database file. The reader itself is vendored in
`includes/GeoIP/Vendor/MaxMind/` (the official, dependency-free
`maxmind/MaxMind-DB-Reader-php`, Apache-2.0 license — no Composer required).
It reads the standard MaxMind DB binary format (v2.0), which multiple
providers publish — you are not locked into MaxMind specifically.

**The database file is not, and cannot be, bundled with this plugin** —
redistribution isn't permitted by either provider's license below, and the
data needs periodic updates anyway.

## Option A — DB-IP Lite (recommended: no signup required)

DB-IP publishes a free "IP to City Lite" database in the same `.mmdb`
format, no account or license key needed:

```bash
curl -o dbip-city-lite.mmdb.gz \
  https://download.db-ip.com/free/dbip-city-lite-YYYY-MM.mmdb.gz
  # check https://db-ip.com/db/download/ip-to-city-lite for the current
  # month's filename — it changes every release
gunzip dbip-city-lite.mmdb.gz
```

Place the resulting file at:
```
wp-content/plugins/visitor-intelligence/data/geoip/GeoLite2-City.mmdb
```
(the plugin only cares about the path, not the filename — DB-IP data
works exactly like MaxMind data, since `GeoIpManager` reads the same
generic fields: `country.iso_code`, `subdivisions[0].iso_code` for
province/state, `city.names.en`.)

**License condition:** DB-IP Lite is CC BY 4.0 — you must display an
attribution link (`<a href='https://db-ip.com'>IP Geolocation by
DB-IP</a>`) on pages where the geolocation results are shown (e.g. your
admin analytics dashboard). No attribution is required on public-facing
pages that don't display the geo data itself.

**Update cadence:** DB-IP Lite is republished monthly (1st of the month).
Re-run the download step monthly, e.g. via a server cron job.

Accuracy note: DB-IP Lite is a reduced-accuracy subset of their paid
database (their own published accuracy index: 77/100 for Lite vs 96/100
for the full commercial version) — noticeably good for country/region,
decent but not perfect for city-level precision.

## Option B — MaxMind GeoLite2 (more setup, comparable accuracy)

1. Sign up at https://www.maxmind.com/en/geolite2/signup (free, but as
   of 2024 requires phone verification and license keys expire every 90
   days unless reconfirmed).
2. Generate a license key under *My License Key*.
3. Download:
```bash
curl -o GeoLite2-City.mmdb.tar.gz \
  "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=YOUR_LICENSE_KEY&suffix=tar.gz"
tar xzf GeoLite2-City.mmdb.tar.gz --strip-components=1 --wildcards '*/GeoLite2-City.mmdb'
```
4. Place at the same path as above.

MaxMind republishes roughly weekly (Tuesdays/Fridays) — more frequent
than DB-IP, if that matters for your use case. GeoIP2-City (their paid
tier, same file format) is more accurate than either free option if
city-level precision is important.

## Custom path

Either option can live outside the plugin folder — define in
`wp-config.php`:
```php
define('VI_GEOIP_DB_PATH', '/absolute/path/outside/webroot/city.mmdb');
```
Keeping it outside the public web root is recommended (no visitor data
is in the file, but no reason to serve it publicly either).

## Verifying it's working

- `GeoIpManager::isAvailable()` returns `true` once the file is readable
  and parses correctly.
- `GeoIpManager::getVersion()` returns the file's own build date
  (`Y-m-d`), read from its embedded metadata.
- New visits populate `country_code`, `region_code` (ISO subdivision
  code, e.g. `MB` for Manitoba) and `city` on `wp_vi_visitors` and
  `wp_vi_sessions`. Existing rows are not backfilled retroactively.
- **A missing or unreadable database file does not break the plugin** —
  `lookup()` returns all-null geo fields and the rest of the tracking
  pipeline runs normally. If pages are erroring, the cause is something
  else — check `wp-content/debug.log` with `WP_DEBUG_LOG` enabled.

## Toggling off

Set the `geoip_enabled` config key to `false` to stop lookups without
removing the database file.
