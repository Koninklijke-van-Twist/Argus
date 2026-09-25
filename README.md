# Argus

Webapp voor maand-/projectoverzicht (OHW, planning, finance) uit Business Central op sleutels.kvt.nl/argus.

## Structuur

- `web/maanden.php` — maandoverzicht (UI)
- `web/maand-detail.php` — maanddetail
- `web/project_finance.php` — kosten/opbrengsten/facturen
- `web/bc_fetch/` — kolom-fetches (OHW, planning, projectdetails)
- `web/odata.php` — OData-client, lokale filecache-widget, optionele Mímir-proxy
- `web/auth.php` — credentials (niet in git)

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet zijn `$auth_list`, `$environment`, `$baseUrl` en `$auth` ongebruikt voor Business Central — company-discovery en alle OData-fetches (`odata_get_all` / `ProjectFinanceService` / `bc_fetch/*`) lopen via Mímir. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | niet aanwezig in Argus — constant `ARGUS_NIGHTLY_MAX_AGE` (**14400**, 4u) gereserveerd in `odata.php` |
| `hourly.php` | niet aanwezig in Argus |
| UI / on-demand | bestaande TTLs via `odata_ttl_for_month()` — huidige maand **1 dag**, vorige maand **1 week**, ouder **1 jaar** |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server de Mímir-sleutel zetten zoals hierboven; legacy BC-credentials alleen nodig zonder `$mimirApi`.

## Tests

```bash
php web/tests/test_opbrengst_meerwerk.php
php web/tests/test_opbrengst_vc.php
```
