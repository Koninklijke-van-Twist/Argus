# Argus

Webapp voor maand-/projectoverzicht (OHW, planning, finance) uit Business Central op sleutels.kvt.nl/argus.

## Structuur

- `web/maanden.php` — maandoverzicht (UI)
- `web/maand-detail.php` — maanddetail
- `web/project_finance.php` — kosten/opbrengsten/facturen
- `web/bc_fetch/` — kolom-fetches (OHW, planning, projectdetails)
- `web/odata.php` — OData-client, lokale filecache-widget, optionele Mímir-proxy (alleen een hook naar de fallback)
- `web/mimir_fallback.php` — circuit breaker en directe BC-fallback als Mímir faalt
- `web/auth.php` — credentials (niet in git)

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// BC-credentials horen hiernaast te blijven (fallback als Mímir uitvalt):
$baseUrl = 'https://…:7148/';
$environment = 'Production'; // string of lijst
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => '…', 'pass' => '…'],
];
$auth = $auth_list['Production'];
```

Met `$mimirApi` gezet proberen company-discovery en alle OData-fetches (`odata_get_all` / `ProjectFinanceService` / `bc_fetch/*`, en `odata_mimir_companies_as_rows` vanuit `auth_helper.php`) eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Argus dezelfde data op via het directe Business Central-pad van vóór Mímir (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-verzoek over. Laat die BC-credentials in `auth.php` naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

Dat geldt voor live webverzoeken (`index.php`, `maanden.php`, `maand-detail.php`) en voor CLI. Argus heeft geen `nightly.php` of `hourly.php`; het CLI-script `web/tools/probe_ohw_gl_accounts.php` laadt wel `auth.php`. cURL naar Mímir: connect-timeout 10s, totale timeout 90s op web-SAPI's en 600s op CLI.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | niet aanwezig in Argus — constant `ARGUS_NIGHTLY_MAX_AGE` (**14400**, 4u) gereserveerd in `odata.php` |
| `hourly.php` | niet aanwezig in Argus |
| UI / on-demand | bestaande TTLs via `odata_ttl_for_month()` — huidige maand **1 dag**, vorige maand **1 week**, ouder **1 jaar** |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten, mét de BC-credentials ernaast; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server blijven `$mimirApi` en de BC-credentials (`$baseUrl`, `$auth`, `$auth_list`, `$environment`) naast elkaar staan. Die BC-gegevens zijn de automatische fallback als Mímir uitvalt; zonder `$mimirApi` zijn ze het enige pad.

## Change notes

- `web/odata.php`: goedgekeurde uitzondering op de regel dat dit bestand niet wijzigt. Alleen een minimale hook (timeouts, foutpad en doorgifte naar de fallback). De fallback-logica staat in `web/mimir_fallback.php`.
- `web/project_finance.php`: alleen commentaar bij de `mimir.invalid`-placeholder. Dat legt uit dat `odata_get_all` die URL naar `$baseUrl` herschrijft als Mímir uitvalt. Geen logicawijziging. Dezelfde opmerking kan nodig zijn in andere projecten die dit patroon kopiëren.

## Tests

```bash
php tests/mimir_fallback_test.php
php web/tests/test_opbrengst_meerwerk.php
php web/tests/test_opbrengst_vc.php
```
