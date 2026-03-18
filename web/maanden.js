(function ()
{
    /**
     * Variabelen
     */
    const payload = window.maandenData || {};
    const companies = Array.isArray(payload.companies) ? payload.companies : [];
    const refreshUrl = typeof payload.refresh_url === 'string' ? payload.refresh_url : 'maanden.php?action=refresh_month';
    const deleteUrl = typeof payload.delete_url === 'string' ? payload.delete_url : 'maanden.php?action=delete_month';
    const detailUrl = typeof payload.detail_url === 'string' ? payload.detail_url : 'maand-detail.php';
    const saveSettingsUrl = typeof payload.save_settings_url === 'string' ? payload.save_settings_url : 'maanden.php?action=save_user_settings';
    const pageLoader = document.getElementById('pageLoader');
    const pageLoaderText = document.getElementById('pageLoaderText');
    const confirmOverlay = document.getElementById('confirmOverlay');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmText = document.getElementById('confirmText');
    const confirmCancel = document.getElementById('confirmCancel');
    const confirmOk = document.getElementById('confirmOk');
    const toastContainer = document.getElementById('toastContainer');
    const companySelect = document.getElementById('companySelect');
    const monthGrid = document.getElementById('monthGrid');

    let selectedCompany = typeof payload.selected_company === 'string' ? payload.selected_company : (companies[0] || '');
    let monthSummaries = Array.isArray(payload.month_summaries) ? payload.month_summaries.slice() : [];
    let addableMonths = Array.isArray(payload.addable_months) ? payload.addable_months.slice() : [];
    let confirmCallback = null;

    const currencyFormatter = new Intl.NumberFormat('nl-NL', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    /**
     * Functies
     */
    function showLoader (text)
    {
        if (pageLoaderText && text)
        {
            pageLoaderText.textContent = text;
        }
        if (pageLoader)
        {
            pageLoader.classList.add('is-visible');
        }
    }

    function hideLoader ()
    {
        if (pageLoader)
        {
            pageLoader.classList.remove('is-visible');
        }
    }

    function removeErrorToasts ()
    {
        if (!toastContainer)
        {
            return;
        }

        const errorToasts = toastContainer.querySelectorAll('.toast.is-error');
        for (const errorToast of errorToasts)
        {
            errorToast.remove();
        }
    }

    function toast (message, isError)
    {
        if (!toastContainer)
        {
            return;
        }

        const text = typeof message === 'string' ? message : String(message || '');

        if (isError)
        {
            removeErrorToasts();
        }

        const el = document.createElement('div');
        el.className = 'toast' + (isError ? ' is-error' : '');

        if (isError)
        {
            const previewLine = text.split(/\r?\n/, 1)[0] || 'Onbekende fout';
            const hint = document.createElement('div');
            hint.className = 'toast-hint';
            hint.textContent = 'Klik om volledige foutmelding te tonen';

            const preview = document.createElement('div');
            preview.className = 'toast-preview';
            preview.textContent = previewLine;

            const details = document.createElement('div');
            details.className = 'toast-details';
            details.textContent = text;

            el.appendChild(hint);
            el.appendChild(preview);
            el.appendChild(details);
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');
            el.setAttribute('aria-expanded', 'false');
            el.addEventListener('click', function ()
            {
                const isExpanded = el.classList.toggle('is-expanded');
                el.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            });
            el.addEventListener('keydown', function (event)
            {
                if (event.key !== 'Enter' && event.key !== ' ')
                {
                    return;
                }

                event.preventDefault();
                el.click();
            });
        }
        else
        {
            el.textContent = text;
        }

        toastContainer.appendChild(el);

        if (isError)
        {
            return;
        }

        window.setTimeout(function ()
        {
            el.classList.add('fade-out');
            window.setTimeout(function () { el.remove(); }, 450);
        }, 3500);
    }

    function parseFetchResponse (res)
    {
        return res.text().then(function (rawText)
        {
            let json = null;
            if (rawText !== '')
            {
                try
                {
                    json = JSON.parse(rawText);
                } catch (parseError)
                {
                    json = null;
                }
            }

            if (json && typeof json === 'object')
            {
                return json;
            }

            const bodyText = (rawText || '').trim();
            const message = bodyText !== ''
                ? 'Server gaf geen geldige JSON terug.\n\nHTTP-status: ' + res.status + ' ' + res.statusText + '\n\nRespons:\n' + bodyText
                : 'Server gaf geen geldige JSON terug.\n\nHTTP-status: ' + res.status + ' ' + res.statusText;
            const error = new Error(message);
            error.responseText = bodyText;
            error.httpStatus = res.status;
            error.httpStatusText = res.statusText;
            throw error;
        });
    }

    function isExecutionTimeoutError (error)
    {
        const text = [
            error && error.message ? String(error.message) : '',
            error && error.responseText ? String(error.responseText) : ''
        ].join('\n');

        return /maximum execution time/i.test(text);
    }

    function fetchMonthPayloadWithRetry (ym, loaderText, retryLoaderText)
    {
        const body = new URLSearchParams({ year_month: ym, company: selectedCompany });
        let attempt = 0;
        const maxAttempts = 3;

        function runAttempt ()
        {
            attempt += 1;
            showLoader(attempt === 1 ? loaderText : retryLoaderText);

            return fetch(refreshUrl, { method: 'POST', body: body })
                .then(parseFetchResponse)
                .then(function (json)
                {
                    if (json && json.ok === false && isExecutionTimeoutError({ message: json.error || '' }) && attempt < maxAttempts)
                    {
                        return runAttempt();
                    }

                    return json;
                })
                .catch(function (error)
                {
                    if (isExecutionTimeoutError(error) && attempt < maxAttempts)
                    {
                        return new Promise(function (resolve)
                        {
                            window.setTimeout(function ()
                            {
                                resolve(runAttempt());
                            }, 250);
                        });
                    }

                    throw error;
                });
        }

        return runAttempt();
    }

    function confirm (title, text, callback)
    {
        if (!confirmOverlay)
        {
            callback();
            return;
        }
        if (confirmTitle)
        {
            confirmTitle.textContent = title;
        }
        if (confirmText)
        {
            confirmText.textContent = text;
        }
        confirmCallback = callback;
        confirmOverlay.classList.remove('is-hidden');
    }

    function closeConfirm ()
    {
        if (confirmOverlay)
        {
            confirmOverlay.classList.add('is-hidden');
        }
        confirmCallback = null;
    }

    function formatMonth (ym)
    {
        const months = ['Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni',
            'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'];
        const parts = (ym || '').split('-');
        const year = parts[0] || '';
        const mIdx = parseInt(parts[1] || '0', 10) - 1;
        return (months[mIdx] || ym) + ' ' + year;
    }

    function formatCurrency (value)
    {
        return currencyFormatter.format(typeof value === 'number' ? value : parseFloat(value) || 0);
    }

    function formatDate (isoString)
    {
        if (!isoString)
        {
            return '';
        }
        const d = new Date(isoString);
        if (isNaN(d.getTime()))
        {
            return isoString;
        }
        return d.toLocaleDateString('nl-NL', {
            year: 'numeric', month: 'long', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    function buildMonthCard (summary)
    {
        const ym = summary.year_month;
        const revenue = typeof summary.total_revenue === 'number' ? summary.total_revenue : 0;
        const costs = typeof summary.total_costs === 'number' ? summary.total_costs : 0;
        const profit = revenue - costs;

        const card = document.createElement('div');
        card.className = 'month-card';
        card.dataset.ym = ym;

        const title = document.createElement('div');
        title.className = 'month-card-title';
        title.textContent = formatMonth(ym);
        card.appendChild(title);

        const stats = document.createElement('div');
        stats.className = 'month-card-stats';

        function statRow (label, value, colorClass)
        {
            const row = document.createElement('div');
            row.className = 'month-card-stat-row';
            const lbl = document.createElement('span');
            lbl.className = 'month-card-stat-label';
            lbl.textContent = label;
            const val = document.createElement('span');
            val.className = 'month-card-stat-value' + (colorClass ? ' ' + colorClass : '');
            val.textContent = formatCurrency(value);
            row.appendChild(lbl);
            row.appendChild(val);
            return row;
        }

        stats.appendChild(statRow('Omzet', revenue, ''));
        stats.appendChild(statRow('Kosten', costs, ''));
        stats.appendChild(statRow('Winst', profit, profit >= 0 ? 'stat-positive' : 'stat-negative'));
        card.appendChild(stats);

        if (summary.fetched_at)
        {
            const fetched = document.createElement('div');
            fetched.className = 'month-card-fetched';
            fetched.textContent = 'Ververst: ' + formatDate(summary.fetched_at);
            card.appendChild(fetched);
        }

        const actions = document.createElement('div');
        actions.className = 'month-card-actions';

        const viewBtn = document.createElement('button');
        viewBtn.type = 'button';
        viewBtn.className = 'btn btn-primary';
        viewBtn.textContent = 'Bekijken';
        viewBtn.addEventListener('click', function ()
        {
            const url = detailUrl + '?year_month=' + encodeURIComponent(ym) + '&company=' + encodeURIComponent(selectedCompany);
            window.location.href = url;
        });
        actions.appendChild(viewBtn);

        const refreshBtn = document.createElement('button');
        refreshBtn.type = 'button';
        refreshBtn.className = 'btn btn-secondary';
        refreshBtn.textContent = 'Verversen';
        refreshBtn.addEventListener('click', function ()
        {
            confirm(
                'Maand verversen',
                'Weet u zeker dat u ' + formatMonth(ym) + ' wilt verversen? De bestaande data wordt overschreven.',
                function ()
                {
                    refreshMonth(ym, card, summary);
                }
            );
        });
        actions.appendChild(refreshBtn);

        card.appendChild(actions);
        return card;
    }

    function buildAddCard ()
    {
        const card = document.createElement('div');
        card.className = 'add-card';
        card.id = 'addCard';

        const title = document.createElement('div');
        title.className = 'add-card-title';
        title.textContent = 'Nieuwe maand toevoegen';
        card.appendChild(title);

        if (addableMonths.length === 0)
        {
            const msg = document.createElement('p');
            msg.style.color = '#64748b';
            msg.style.margin = '0';
            msg.textContent = 'Geen maanden beschikbaar om toe te voegen.';
            card.appendChild(msg);
            return card;
        }

        const row = document.createElement('div');
        row.className = 'add-card-row';

        const sel = document.createElement('select');
        sel.id = 'addMonthSelect';
        for (const ym of addableMonths)
        {
            const opt = document.createElement('option');
            opt.value = ym;
            opt.textContent = formatMonth(ym);
            sel.appendChild(opt);
        }
        row.appendChild(sel);

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-primary';
        addBtn.textContent = 'Toevoegen';
        addBtn.addEventListener('click', function ()
        {
            const ym = sel.value;
            if (!ym)
            {
                return;
            }
            addMonth(ym);
        });
        row.appendChild(addBtn);
        card.appendChild(row);
        return card;
    }

    function renderGrid ()
    {
        if (!monthGrid)
        {
            return;
        }
        monthGrid.innerHTML = '';

        if (monthSummaries.length === 0)
        {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.style.gridColumn = '1 / -1';
            empty.textContent = 'Er zijn nog geen maanden opgeslagen voor dit bedrijf.';
            monthGrid.appendChild(empty);
        }
        else
        {
            for (const summary of monthSummaries)
            {
                monthGrid.appendChild(buildMonthCard(summary));
            }
        }

        monthGrid.appendChild(buildAddCard());
    }

    function refreshMonth (ym, cardEl, oldSummary)
    {
        fetchMonthPayloadWithRetry(
            ym,
            'Maand verversen...',
            'Het duurt iets langer dan verwacht. We proberen direct opnieuw met de deels opgebouwde cache...'
        )
            .then(function (json)
            {
                hideLoader();
                if (!json.ok)
                {
                    toast('Fout: ' + (json.error || 'Onbekende fout'), true);
                    return;
                }
                const data = json.data || {};
                const newSumm = {
                    year_month: ym,
                    total_revenue: typeof data.total_revenue === 'number' ? data.total_revenue : 0,
                    total_costs: typeof data.total_costs === 'number' ? data.total_costs : 0,
                    fetched_at: data.fetched_at || new Date().toISOString(),
                };
                // Update or insert in monthSummaries
                const idx = monthSummaries.findIndex(function (s) { return s.year_month === ym; });
                if (idx >= 0)
                {
                    monthSummaries[idx] = newSumm;
                }
                else
                {
                    // Insert sorted (newest first)
                    monthSummaries.push(newSumm);
                    monthSummaries.sort(function (a, b)
                    {
                        return b.year_month.localeCompare(a.year_month);
                    });
                    // Remove from addable months
                    addableMonths = addableMonths.filter(function (m) { return m !== ym; });
                }
                renderGrid();
                toast(formatMonth(ym) + ' is ververst.');
            })
            .catch(function (err)
            {
                hideLoader();
                toast('Netwerkfout: ' + err.message, true);
            });
    }

    function addMonth (ym)
    {
        fetchMonthPayloadWithRetry(
            ym,
            formatMonth(ym) + ' ophalen...',
            formatMonth(ym) + ' kost iets meer tijd. We proberen direct opnieuw met de deels opgebouwde cache...'
        )
            .then(function (json)
            {
                hideLoader();
                if (!json.ok)
                {
                    toast('Fout: ' + (json.error || 'Onbekende fout'), true);
                    return;
                }
                const data = json.data || {};
                const newSumm = {
                    year_month: ym,
                    total_revenue: typeof data.total_revenue === 'number' ? data.total_revenue : 0,
                    total_costs: typeof data.total_costs === 'number' ? data.total_costs : 0,
                    fetched_at: data.fetched_at || new Date().toISOString(),
                };
                monthSummaries.push(newSumm);
                monthSummaries.sort(function (a, b)
                {
                    return b.year_month.localeCompare(a.year_month);
                });
                addableMonths = addableMonths.filter(function (m) { return m !== ym; });
                renderGrid();
                toast(formatMonth(ym) + ' is toegevoegd.');
            })
            .catch(function (err)
            {
                hideLoader();
                toast('Netwerkfout: ' + err.message, true);
            });
    }

    /**
     * Page load
     */
    if (companySelect)
    {
        companySelect.addEventListener('change', function ()
        {
            const company = companySelect.value;
            window.location.href = 'maanden.php?company=' + encodeURIComponent(company);
        });
    }

    if (confirmCancel)
    {
        confirmCancel.addEventListener('click', closeConfirm);
    }

    if (confirmOk)
    {
        confirmOk.addEventListener('click', function ()
        {
            const cb = confirmCallback;
            closeConfirm();
            if (typeof cb === 'function')
            {
                cb();
            }
        });
    }

    if (confirmOverlay)
    {
        confirmOverlay.addEventListener('click', function (e)
        {
            if (e.target === confirmOverlay)
            {
                closeConfirm();
            }
        });
    }

    renderGrid();
})();
