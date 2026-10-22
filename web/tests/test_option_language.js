/**
 * BC-optiewaarden komen in de taal van de BC-gebruiker (NL/EN/DE), soms met nummerprefix.
 * Run: node web/tests/test_option_language.js
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

function loadNested(file, name, extra) {
    const source = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
    const match = source.match(new RegExp('    function ' + name + ' \\([\\s\\S]*?\\r?\\n    \\}\\r?\\n'));
    if (!match) {
        console.error('FAIL: ' + name + ' staat niet in ' + file);
        process.exit(1);
    }
    const context = {};
    vm.runInNewContext((extra || '') + match[0] + '\nthis.fn = ' + name + ';', context);
    return context.fn;
}

const normalizeStatus = loadNested('index.js', 'normalizeStatus');
const jobComplete = loadNested('maand-detail.js', 'formatJobCompleteLabel', 'function toTrimmedString (v) { return String(v == null ? "" : v).trim(); }\n');

let failures = 0;
function same(expected, actual, message) {
    if (expected !== actual) {
        failures += 1;
        console.error('FAIL: ' + message + ' (verwacht ' + JSON.stringify(expected) + ', kreeg ' + JSON.stringify(actual) + ')');
    }
}

const cases = {
    'Open': 'open', 'Afgesloten': 'closed', 'Geannuleerd': 'cancelled', 'Gecontroleerd': 'checked',
    'Gepland': 'planned', 'Onderhanden': 'in-progress', 'Ondertekend': 'signed', 'Uitgevoerd': 'completed',
    'Closed': 'closed', 'Cancelled': 'cancelled', 'Checked': 'checked', 'Planned': 'planned',
    'In Progress': 'in-progress', '02 IN PROGRESS': 'in-progress', 'In_Progress': 'in-progress',
    'Signed': 'signed', 'Completed': 'completed', '05 Completed': 'completed',
    'Geschlossen': 'closed', 'In Bearbeitung': 'in-progress', 'Iets anders': 'iets-anders', '': '',
};
Object.keys(cases).forEach(function (input) {
    same(cases[input], normalizeStatus(input), 'normalizeStatus(' + JSON.stringify(input) + ')');
});

['Ja', 'Yes', 'yes', true, 1, '1', 'true'].forEach(function (v) { same('Ja', jobComplete(v), 'Ja voor ' + v); });
['Nee', 'No', 'no', 'Nein', false, 0, '0', 'false'].forEach(function (v) { same('Nee', jobComplete(v), 'Nee voor ' + v); });

if (failures > 0) {
    process.exit(1);
}
console.log('OK test_option_language');
