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

Dat geldt voor live webverzoeken (`index.php`, `maanden.php`, `maand-detail.php`), voor `web/nightly.php` en `web/hourly.php`, en voor CLI. Het CLI-script `web/tools/probe_ohw_gl_accounts.php` laadt ook `auth.php`. cURL naar Mímir: connect-timeout 10s, totale timeout 90s op web-SAPI's en 600s op CLI.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | **14400** (4 uur), `ARGUS_NIGHTLY_MAX_AGE` |
| `hourly.php` | **1800** (30 minuten), `ARGUS_HOURLY_MAX_AGE` |
| UI / on-demand | bestaande TTLs via `odata_ttl_for_month()` — huidige maand **1 dag**, vorige maand **1 week**, ouder **1 jaar** |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten, mét de BC-credentials ernaast; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server blijven `$mimirApi` en de BC-credentials (`$baseUrl`, `$auth`, `$auth_list`, `$environment`) naast elkaar staan. Die BC-gegevens zijn de automatische fallback als Mímir uitvalt; zonder `$mimirApi` zijn ze het enige pad.

## Change notes

- `web/odata.php`: goedgekeurde uitzondering op de regel dat dit bestand niet wijzigt. Alleen een minimale hook (timeouts, foutpad en doorgifte naar de fallback). De fallback-logica staat in `web/mimir_fallback.php`.
- `web/project_finance.php`: alleen commentaar bij de `mimir.invalid`-placeholder. Dat legt uit dat `odata_get_all` die URL naar `$baseUrl` herschrijft als Mímir uitvalt. Geen logicawijziging. Dezelfde opmerking kan nodig zijn in andere projecten die dit patroon kopiëren.
- `web/project_finance.php` en `web/finance_calculations.php`: Opbrengst is de som van planningsregels met Type G/L en No `800000`, zonder prognose (niet meer `LVS_Contract_Total_Price_2`, en factureerbaar is geen eis). Kosten VC leest `LVS_Baseline_Total_Cost` van taak `000`, anders `000-000-000`, anders de som van Boeking-taken. Dat taaktype staat niet op ProjectTaken; het komt uit `JobTaskLines` (`Job_Task_Type eq 'Boeking'`), in batches van hoogstens 25 projecten of 1200 tekens filter. Eén query voor alle fallback-projecten hing bij KvT: Mímir cachete die als één onaffe pagina en de directe BC-fallback had geen timeout. Een timeout of HTTP-fout op JobTaskLines stopt de voorcalculatie met een melding en publiceert de maand niet. Een onleesbaar antwoord houdt de som zonder typefilter en de melding `Veld Job_Task_Type bestaat niet op ProjectTaken`. Open projecten zonder posten in `Grootboekposten_OHW` komen mee via één `Projecten`-query (`Status eq 'Open'`) en tonen 0 bij de OHW-bedragen. Maandsnapshots blijven cacheversie 2 (`.v2.json`). Verversen verwijdert de oude snapshot niet meer vooraf; die blijft leesbaar tot de nieuwe via een atomische rename klaar is. Dezelfde wijziging kan nodig zijn in andere projecten die dit patroon kopiëren.
- `web/project_finance.php` en `web/finance_calculations.php`: Opbrengst VC leest `ProjectTaken.LVS_Contract_Total_Price_2` (taak `000`, anders `000-000-000`, anders de som van Boeking-taken), niet meer `LVS_Schedule_Total_Price_2` en niet `LVS_Baseline_Total_Price`. Het contracttotaal bevat het meerwerk; het hoofdproject in de kolom is dat totaal minus de opbrengst meerwerk die al apart wordt getoond. Maandsnapshots zijn cacheversie 3 (`.v3.json`). Dezelfde wijziging kan nodig zijn in andere projecten die dit patroon kopiëren.
- `web/finance_calculations.php`, `web/maanden.php` en `web/maand-detail.php`: de maandlijst herkent weer `.v3.json`, `.v2.json` en legacy `.json`. Een maand komt één keer voor; v3 wint van v2, v2 van legacy. Openen zonder v3 bouwt die maand opnieuw op met dezelfde peildatum en schrijft alleen een nieuw `.v3.json`. Lukt dat niet, dan blijft de oude snapshot zichtbaar met een melding. Oude bestanden worden niet verwijderd of overschreven. Een lopende herbouw wordt hervat: `begin_month_refresh` wist geen WIP, en een tweede tabblad volgt dezelfde refresh tot de v3 er is. Een herlaadbeurt tijdens een stap wacht tot die stap de WIP heeft weggeschreven en krijgt daarna een nieuwe generatie. Een stap schrijft de WIP alleen terug als die generatie nog klopt. Losse kaarten blijven staan; OHW-rijen en voorcalculatieregels worden vervangen. Het overzicht blijft pollen zolang een stap bezig is en pakt de opbouw daarna zelf weer op. Die herbouw wijzigt `web/project_finance.php` niet.
- `web/project_finance.php` en `web/finance_calculations.php`: de kolom Opbrengst t/m is de som van `ProjectTaken.Contract_Invoiced_Price` tot en met de laatste dag van de gekozen maand. Het OData-filter is `Posting_Date_Filter eq '..YYYY-MM-DD'` (datum als string tussen quotes, met `..` ervoor). Alleen taken met `Job_Task_Type` Boeking uit `JobTaskLines` tellen mee; kop- en totaaltaken niet, anders tel je dubbel. De 800000-planningsregels blijven de aanneemsom en opbrengst meerwerk. Opbrengst VC, Kosten VC en de snapshotversie (`.v3.json`) zijn niet gewijzigd. Dezelfde wijziging kan nodig zijn in andere projecten die dit patroon kopiëren.
- `web/nightly.php` en `web/hourly.php`: een maand zonder `.v3.json`, of met een open Verversen-WIP, wordt server-side opgebouwd met dezelfde stappen als de browser (OHW, projectdetails, voorcalculatie inclusief Opbrengst t/m, publicatie). Dezelfde lock en generatie gelden. Een verse lock van een browsertabblad blijft van dat tabblad. Nightly-fetches gebruiken max_age 4 uur, hourly 30 minuten. Oude `.json`- en `.v2.json`-bestanden worden niet verwijderd of overschreven. `web/project_finance.php` is hier niet gewijzigd.
- `web/finance_calculations.php`, `web/maand-detail.js` en `web/maanden.js`: een herbouw-lock zonder generatie, met een tijdstip in de toekomst of in milliseconden, of zonder gestarte stap (geen hartslag) blokkeert de maand niet meer. Legacy-WIP zonder refresh-id wordt opgeruimd; de geldige WIP en oude snapshots blijven staan. De detailbanner en de maandkaart tonen of de pagina wacht, welke stap loopt, of waarom de herbouw faalt. Een fout in de maandtabel slaat de herbouw niet meer over.
- `web/maanden.php` en `web/month_rebuild_steps.php`: een mislukte voorcalculatie die de ruwe BC-body in de fout zet (vaak geen geldige UTF-8) wordt weer als JSON teruggegeven. `json_encode` zonder die vervanging gaf `false`, de response bleef leeg en de browser toonde HTTP 500 zonder JSON.
- Voorcalculatie van een maand gaat in stappen van 40 projecten (`VOORCALCULATIE_PROJECT_CHUNK_SIZE`). Eén HTTP-request voor alle projecten werd na 10–15 minuten door de proxy afgekapt (`Netwerkfout`), terwijl de oude snapshot bleef staan. Elke stap vervangt de totalen van die projecten. De snapshot wordt pas gepubliceerd als elk project uit de maandlijst een totaal heeft. `maanden.php` en `maand-detail.php` laten het sessie-lock los en lezen de bedrijfslijst een uur uit `cache/companies/discovery.json`, zodat een andere pagina niet op Mímir wacht zolang een stap bezig is. `web/project_finance.php` is hier niet gewijzigd.
- Een pagina die laadt, herlaadt of meepolt (`resume=1` of `join=1`) volgt een verse herbouw en verhoogt de generatie niet. Alleen Verversen neemt een verse lock over; de vervangen poging krijgt geen fouttoast maar sluit weer aan. De server hervat wel de eigen vrijgegeven lock. Voorcalculatie gaat in stappen van 8 projecten. Business Central gaf HTTP 500 op een lange `Job_No`-filter of een zware pagina; de proxy maakte daar een lege 500 van (`Server gaf geen geldige JSON terug`). `JobTaskLines` blijft op hoogstens 8 projecten of 480 tekens, elke finance-query zet `$top=100`, HTTP 500 wordt kleiner opnieuw geprobeerd en een timeout niet. OHW en al opgeslagen voorcalculatiechunks blijven staan. Opbrengst t/m is niet gewijzigd.

## Tests

```bash
php tests/mimir_fallback_test.php
php web/tests/test_opbrengst_meerwerk.php
php web/tests/test_opbrengst_vc.php
php web/tests/test_contract_total_price.php
php web/tests/test_opbrengst_tm.php
php web/tests/test_odata_schema_error_once.php
php web/tests/test_projecttaken_tolerant_select.php
php web/tests/test_baseline_cost_schema.php
php web/tests/test_baseline_cost.php
php web/tests/test_job_task_lines_batches.php
php web/tests/test_project_detail_chunks.php
php web/tests/test_voorcalculatie_chunks.php
php web/tests/test_company_discovery_cache.php
php web/tests/test_odata_page_guard.php
php web/tests/test_open_projects.php
php web/tests/test_json_guard.php
php web/tests/test_month_snapshot_fallback.php
php web/tests/test_month_rebuild_lock.php
php web/tests/test_month_rebuild_schedule.php
php web/tests/test_planning_json_error.php
node web/tests/test_overview_rebuild_action.js
node web/tests/test_detail_rebuild_notice.js
```
