# Copilot-instructies voor Daedalus

## Scope en architectuur
- Deze applicatie draait volledig vanuit de map `/web/` en wordt gepubliceerd onder `https://sleutels.kvt.nl/daedalus`.
- Houd links daarom relatief (`index.php`, `odata.php?...`) of vanaf `/daedalus/...` als absolute paden nodig zijn.
- De app is mobile-first. Nieuwe UI-wijzigingen moeten eerst op telefoonscherm goed leesbaar zijn.

## Niet wijzigen
- Bestand `web/logincheck.php` niet aanpassen.
- Bestand `web/odata.php` niet aanpassen.
- Bestand `web/auth.php` alleen aanpassen na expliciete gebruikersvraag.

## Data en logica werkorders
- Hoofdflow staat in `web/index.php`.
- Helpers staan in hun eigen bestand.
- Gebruikerskoppeling loopt via `AppResource` (`E_Mail`) met fallback via `AppUserSetup` (`Email` -> `User_ID` -> `AppResource.KVT_User_ID`).
- Werkorders komen uit `AppWerkorders`.
- Detailregels komen uit `LVS_JobPlanningLinesSub` (functioneel: ProjectPlanningsRegels).

## UI-regels
- Geen zware frameworks introduceren zonder expliciet verzoek.
- Gebruik bestaande favicon/manifest-bestanden op elke nieuwe HTML-pagina.
- Gebruik op de hoofdpagina altijd `logo-website.png`.

## Veiligheid en kwaliteit
- Vang OData-fouten af en toon een korte gebruikersvriendelijke melding.
- Gebruik cache-widget via `injectTimerHtml(...)` uit `odata.php`; endpoint-acties blijven:
  - `odata.php?action=cache_status`
  - `odata.php?action=cache_delete`
  - `odata.php?action=cache_clear`

## Bij toekomstige uitbreidingen
- Extra velden eerst verifiëren in `BC Webservices.txt`.
- Alleen benodigde kolommen opvragen via `$select` voor performance.
- Gebruik `KVT_Extended_Text` als beschrijvingstekst in planningregels; `Description` blijft de naam.

## Code-structuur en refactorregels (PHP en JS)
- Pas bij refactors in PHP/JS altijd dezelfde sectievolgorde toe, en alleen als de sectie inhoud heeft:
  - `Includes/requires` (of vergelijkbare naam zoals `Imports`)
  - `Constants`
  - `Variabelen`
  - `Functies`
  - `Page load` (alle top-level uitvoerbare code die niet in functies staat)
- Gebruik voor secties een duidelijke blokcomment-stijl, bijvoorbeeld:
  - `/**` + `* Functies` + `*/`
- Voeg geen lege secties toe. Een ontbrekende sectie betekent: niet opnemen.
- Functioneel gedrag mag niet wijzigen door de refactor:
  - geen wijziging in logica, filters, output, routes, sessiegedrag of side-effects
  - alleen herordenen/annoteren en waar nodig veilig opsplitsen zonder gedragswijziging
- Houd top-level uitvoerbare code geconcentreerd in de `Page load`-sectie.
- Classes moeten altijd in een eigen bestand staan:
  - maximaal 1 class per bestand
  - bestandsnaam sluit aan op classnaam
  - geen class-definities tussen page-load code in gecombineerde scriptbestanden
- Respecteer altijd bestaande uitzonderingen uit deze instructies:
  - `web/logincheck.php` niet aanpassen
  - `web/odata.php` niet aanpassen
  - `web/auth.php` alleen aanpassen na expliciete gebruikersvraag

## Leidende Authoriteit Kosten/Opbrengsten
- Het bestand web/project_finance.php is de leidende authoriteit voor alle logica rond kosten, opbrengsten, resultaat en projectfacturen.
- Dergelijke data moet ALTIJD via functies in web/project_finance.php worden opgehaald.
- Het is NIET toegestaan om kosten/opbrengst/factuurdata buiten dit bestand om direct op te halen wanneer de data al binnen de scope van web/project_finance.php valt.
- Complexe financiele berekeningen, en met name berekende kolommen, moeten in web/finance_calculations.php als kolom-specifieke functies staan.
- Eenvoudige, lokale bewerkingen (zoals directe plus/min in een bestaande flow) hoeven niet geforceerd naar web/finance_calculations.php.
- Elke functie in web/finance_calculations.php moet een `/** */` summary block hebben dat uitlegt welke financiele berekening voor welke kolom of uitkomst wordt uitgevoerd; ontbreekt dit of klopt het niet, dan moet dit direct gecorrigeerd worden.

## Wijzigingsplicht
- Elke wijziging aan web/project_finance.php moet expliciet benoemd worden in communicatie en/of change notes.
- Bij zo'n melding moet expliciet vermeld worden dat dezelfde wijziging mogelijk ook doorgevoerd moet worden in andere implementaties in andere projecten die dit patroon kopieren.

## Views En CSV Export
- De applicatie ondersteunt minimaal deze uitwerkingen van dezelfde dataset:
- Tabel-view: standaard tabelweergave met kolommen per werkorderregel.
- Projectgroepen-view: gegroepeerde weergave op projectniveau met onderliggende werkorders.
- CSV export: export van zichtbare/actieve data op basis van kolomdefinities in de frontend.
- Plaatsingsregel kolommen:
- Werkorder-kolommen horen in tabel-view op de werkorderregel en in projectgroepen-view op de onderliggende werkorderregel.
- Project-kolommen mogen in tabel-view op elke werkorderregel zichtbaar zijn, maar horen in projectgroepen-view primair in de projectheader.
- Bij toevoegen/verwijderen/hernoemen van projectkolommen moet expliciet bepaald worden of de wijziging in de projectheader, werkorderregels of beide thuishoort.
- Elke wijziging aan kolommen (toevoegen, verwijderen, hernoemen, formattering of volgorde) moet in ALLE drie paden correct blijven werken: tabel-view, projectgroepen-view en CSV export.
- Een kolomwijziging is pas correct als rendering, sortering/filtering (waar van toepassing) en export-uitvoer onderling consistent zijn.
