/**
 * De detailpagina laat zien of een herbouw wacht, loopt of faalt.
 * Een toeschouwer mag de banner niet stil laten staan.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'maand-detail.js'), 'utf8');

function loadFunction(name) {
    const match = source.match(new RegExp('function ' + name + ' \\([\\s\\S]*?\\r?\\n\\}\\r?\\n'));
    if (!match) {
        console.error('FAIL: ' + name + ' staat niet in maand-detail.js');
        process.exit(1);
    }
    const context = {};
    vm.runInNewContext(match[0] + '\nthis.fn = ' + name + ';', context);
    return context.fn;
}

const phase = loadFunction('argusDetailRebuildPhase');
const notice = loadFunction('argusDetailRebuildNotice');
const stepNotice = loadFunction('argusDetailRebuildStepNotice');

let failures = 0;

function assertSame(expected, actual, message) {
    if (expected !== actual) {
        failures += 1;
        console.error('FAIL: ' + message + '\n  expected ' + expected + '\n  actual   ' + actual);
    }
}

const watcher = { ok: true, role: 'watcher', published: false, refresh_id: 'abcdef0123456789', generation: 1 };
assertSame('wait', phase(watcher), 'een bezette herbouw wacht in plaats van stil te blijven');
assertSame('run', phase({ ok: true, role: 'worker', published: false, refresh_id: 'abcdef0123456789', generation: 2 }), 'een vrije lock start de stappen');
assertSame('reload', phase({ ok: true, role: 'watcher', published: true, refresh_id: '', generation: 0 }), 'een afgeronde herbouw herlaadt');
assertSame('error', phase({ ok: false, error: 'bezet' }), 'een mislukte claim is een fout');
assertSame('error', phase({ ok: true, role: 'worker', published: false, refresh_id: '', generation: 1 }), 'zonder refresh-id start er geen stille herbouw');

const waiting = notice('wait', {});
assertSame(true, waiting.indexOf('wacht') !== -1, 'wachten vervangt de stille banner');
assertSame(true, waiting.indexOf('vorige snapshot') !== -1, 'wachten zegt dat de oude snapshot zichtbaar blijft');
assertSame(true, notice('run', {}).indexOf('OHW ophalen') !== -1, 'een eigen herbouw toont de eerste stap');
assertSame('OHW ophalen...', stepNotice('grootboekposten_ohw'), 'OHW heeft een voortgangstekst');
assertSame('Projectdetails ophalen...', stepNotice('project_details'), 'projectdetails hebben een voortgangstekst');
assertSame('Voorcalculatie ophalen...', stepNotice('planning'), 'voorcalculatie heeft een voortgangstekst');
assertSame('Snapshot opslaan...', stepNotice('build'), 'opslaan heeft een voortgangstekst');
const failure = notice('error', { failure_notice: 'Deze maand kon niet opnieuw worden opgebouwd.' });
assertSame(true, failure.indexOf('niet opnieuw') !== -1, 'een fout blijft in de banner staan');
assertSame(true, source.indexOf('rebuildLegacyMonth(rebuildRequest)') > source.indexOf('catch (renderError)'), 'een fout in de tabel slaat de herbouw niet over');

const followup = loadFunction('argusRebuildStepFollowup');
assertSame('join', followup({ ok: false, stale: true, error: 'Deze herbouw is vervangen door een nieuwere poging.' }), 'een vervangen herbouw wacht mee');
assertSame('fail', followup({ ok: false, error: 'HTTP 500' }), 'een echte stapfout blijft een fout');
assertSame(true, source.indexOf('error.stale') !== -1, 'de detailpagina behandelt een vervangen herbouw niet als fout');
assertSame(true, source.indexOf('argusDetailRebuildNotice(\'wait\', request)') !== -1, 'na een vervangen poging toont de pagina de wachttekst');
assertSame(-1, source.indexOf('primaryDepartmentFromBreakdown'), 'de detailpagina leidt de afdeling niet af uit grootboekposten');
assertSame(true, source.indexOf('LVS_Global_Dimension_1_Code') !== -1, 'de afdeling komt van de projectkaart');

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}

console.log('OK detail rebuild notice');
