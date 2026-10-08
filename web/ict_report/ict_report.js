/**
 * "Rapporteer aan ICT" — herbruikbare browser-kant voor sleutels.kvt.nl-apps.
 *
 * Gebruik:
 *   KvtIctReport.configure({ endpoint: 'ict_report.php', csrfToken: '...' });
 *   KvtIctReport.report({ month, step, message, http_status, response_text }, statusEl, buttonEl);
 *
 * De API-key blijft op de server; deze code praat alleen met het eigen endpoint.
 */
(function ()
{
    'use strict';

    const config = { endpoint: 'ict_report.php', csrfToken: '' };

    function configure (options)
    {
        Object.assign(config, options || {});
    }

    function copyText (text)
    {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext)
        {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject)
        {
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            area.remove();
            if (ok) { resolve(); } else { reject(new Error('Kopiëren mislukt')); }
        });
    }

    function setStatus (statusEl, text, isError)
    {
        if (!statusEl) { return; }
        statusEl.textContent = text || '';
        statusEl.hidden = !text;
        statusEl.classList.toggle('is-error', !!isError);
    }

    /**
     * Moet direct vanuit de klik-handler worden aangeroepen: het nieuwe tabblad
     * wordt synchroon geopend (anders blokkeert de popup-blocker het).
     */
    function report (context, statusEl, buttonEl)
    {
        const ctx = context || {};
        const tab = window.open('', '_blank');
        if (tab)
        {
            try { tab.opener = null; } catch (e) { /* negeren */ }
            try
            {
                tab.document.title = 'Ticket aanmaken…';
                tab.document.body.style.fontFamily = 'sans-serif';
                tab.document.body.textContent = 'Ticket wordt aangemaakt in Asclepius…';
            } catch (e) { /* negeren */ }
        }

        if (buttonEl) { buttonEl.disabled = true; }
        setStatus(statusEl, 'Ticket wordt aangemaakt…', false);

        const payload = {
            month: ctx.month || '',
            step: ctx.step || '',
            message: ctx.message || '',
            http_status: ctx.http_status || '',
            response_text: ctx.response_text || '',
            page_url: window.location.href,
            user_agent: navigator.userAgent,
            occurred_at: ctx.occurred_at || Date.now(),
            csrf_token: config.csrfToken,
        };

        return fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': config.csrfToken,
            },
            body: JSON.stringify(payload),
        })
            .then(function (res)
            {
                return res.text().then(function (text)
                {
                    let json = null;
                    try { json = JSON.parse(text); } catch (e) { json = null; }
                    if (!json || typeof json !== 'object')
                    {
                        throw new Error('Server gaf geen geldige JSON terug (HTTP ' + res.status + ').');
                    }
                    if (!json.ok || !json.ticket_url)
                    {
                        throw new Error(json.error || 'Ticket aanmaken mislukt.');
                    }
                    return json;
                });
            })
            .then(function (json)
            {
                const label = 'Ticket #' + json.ticket_id + ' aangemaakt.';
                if (tab && !tab.closed)
                {
                    tab.location.href = json.ticket_url;
                    setStatus(statusEl, label + ' Geopend in een nieuw tabblad.', false);
                }
                else
                {
                    setStatus(statusEl, '', false);
                    if (statusEl)
                    {
                        statusEl.hidden = false;
                        statusEl.textContent = label + ' ';
                        const link = document.createElement('a');
                        link.href = json.ticket_url;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.textContent = 'Open ticket';
                        statusEl.appendChild(link);
                    }
                }
                if (buttonEl) { buttonEl.textContent = 'Gemeld (#' + json.ticket_id + ')'; }
                return json;
            })
            .catch(function (err)
            {
                if (tab && !tab.closed) { tab.close(); }
                if (buttonEl) { buttonEl.disabled = false; }
                setStatus(statusEl, 'Melden aan ICT mislukt: ' + (err && err.message ? err.message : err), true);
                return null;
            });
    }

    window.KvtIctReport = { configure: configure, report: report, copyText: copyText };
})();
