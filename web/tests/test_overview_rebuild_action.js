/**
 * Het maandoverzicht pakt een lopende verversing weer op.
 * Een bezette stap wordt gevolgd. Na afloop claimt hetzelfde tabblad verder,
 * of de pagina herlaadt als de actuele snapshot al af is.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'maanden.js'), 'utf8');
const match = source.match(/function argusOverviewRebuildAction \(claim\)\r?\n\{[\s\S]*?\r?\n\}\r?\n/);
if (!match) {
    console.error('FAIL: argusOverviewRebuildAction staat niet in maanden.js');
    process.exit(1);
}

const context = {};
vm.runInNewContext(match[0] + '\nthis.action = argusOverviewRebuildAction;', context);
const action = context.action;

let failures = 0;

function assertSame(expected, actual, message) {
    if (expected !== actual) {
        failures += 1;
        console.error('FAIL: ' + message + '\n  expected ' + expected + '\n  actual   ' + actual);
    }
}

assertSame('error', action(null), 'een lege claim is een fout');
assertSame('error', action({ ok: false, error: 'bezet' }), 'een mislukte claim is een fout');
assertSame('poll', action({ ok: true, role: 'watcher', published: false, refresh_id: 'aaaaaaaaaaaaaaaa', generation: 1 }), 'een bezette stap blijft polllen');
assertSame('run', action({ ok: true, role: 'worker', published: false, refresh_id: 'aaaaaaaaaaaaaaaa', generation: 2 }), 'na de stap claimt het overzicht de pipeline');
assertSame('reload', action({ ok: true, role: 'watcher', published: true, refresh_id: '', generation: 0 }), 'een afgeronde herbouw herlaadt het overzicht');
assertSame('error', action({ ok: true, role: 'worker', published: false, refresh_id: '', generation: 2 }), 'zonder refresh-id start er geen tweede opbouw');

const statusMatch = source.match(/function argusOverviewRebuildStatusText \(action, monthLabel, errorText\)\r?\n\{[\s\S]*?\r?\n\}\r?\n/);
if (!statusMatch) {
    console.error('FAIL: argusOverviewRebuildStatusText staat niet in maanden.js');
    process.exit(1);
}
const statusContext = {};
vm.runInNewContext(statusMatch[0] + '\nthis.statusText = argusOverviewRebuildStatusText;', statusContext);
const statusText = statusContext.statusText;
const waiting = statusText('poll', 'september 2026', '');
assertSame(true, waiting.indexOf('wacht') !== -1, 'een bezette herbouw toont op de kaart dat er gewacht wordt');
assertSame(true, waiting.indexOf('september 2026') !== -1, 'de wachttekst noemt de maand');
assertSame(true, statusText('run', 'september 2026', '').indexOf('opgebouwd') !== -1, 'een eigen stap toont dat de opbouw loopt');
assertSame(true, statusText('error', 'september 2026', 'lock').indexOf('lock') !== -1, 'een fout blijft op de kaart zichtbaar');

const followupMatch = source.match(/function argusRebuildStepFollowup \(json\)\r?\n\{[\s\S]*?\r?\n\}\r?\n/);
if (!followupMatch) {
    console.error('FAIL: argusRebuildStepFollowup staat niet in maanden.js');
    process.exit(1);
}
const followupContext = {};
vm.runInNewContext(followupMatch[0] + '\nthis.followup = argusRebuildStepFollowup;', followupContext);
const followup = followupContext.followup;
assertSame('join', followup({ ok: false, stale: true, error: 'Deze herbouw is vervangen door een nieuwere poging.' }), 'een vervangen herbouw sluit aan');
assertSame('fail', followup({ ok: false, error: 'HTTP 500' }), 'een echte stapfout blijft een fout');
assertSame('continue', followup({ ok: true }), 'een geslaagde stap gaat door');
assertSame(true, source.indexOf('argusRebuildStepFollowup(json)') < source.indexOf("toast('Fout bij ' + step.label"), 'een vervangen OHW-stap toont geen fouttoast');
assertSame(true, source.indexOf('followup === \'join\'') !== -1, 'het overzicht volgt een vervangen stap');

if (failures > 0) {
    console.error(failures + ' assertion(s) failed');
    process.exit(1);
}

console.log('OK overview rebuild action');
