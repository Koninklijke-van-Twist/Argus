(function ()
{
    /**
     * Variabelen
     */
    const payload = window.maandenData || {};
    const companies = Array.isArray(payload.companies) ? payload.companies : [];
    const batchUrl = typeof payload.batch_url === 'string' ? payload.batch_url : 'maanden.php?action=fetch_workorders_batch';
    const projectNumbersUrl = typeof payload.project_numbers_url === 'string'
        ? payload.project_numbers_url
        : 'maanden.php?action=fetch_project_numbers_batch';
    const columnBatchUrl = typeof payload.column_batch_url === 'string'
        ? payload.column_batch_url
        : 'maanden.php?action=fetch_column_batch';
    const columnSteps = Array.isArray(payload.column_steps) ? payload.column_steps : [];
    const deleteUrl = typeof payload.delete_url === 'string' ? payload.delete_url : 'maanden.php?action=delete_month';
    const detailUrl = typeof payload.detail_url === 'string' ? payload.detail_url : 'maand-detail.php';
    const saveSettingsUrl = typeof payload.save_settings_url === 'string' ? payload.save_settings_url : 'maanden.php?action=save_user_settings';
    const pageLoader = document.getElementById('pageLoader');
    const pageLoaderText = document.getElementById('pageLoaderText');
    const batchProgressList = document.getElementById('batchProgressList');
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
        if (batchProgressList)
        {
            batchProgressList.innerHTML = '';
            batchProgressList.classList.remove('is-visible');
        }
    }

    function initProgressList (allItems)
    {
        if (!batchProgressList) { return {}; }
        batchProgressList.innerHTML = '';
        const items = {};
        for (const item of allItems)
        {
            const li = document.createElement('li');
            li.className = 'batch-progress-item';
            const icon = document.createElement('span');
            icon.className = 'batch-progress-icon';
            icon.textContent = '○';
            const label = document.createElement('span');
            label.textContent = item.label;
            const pct = document.createElement('span');
            pct.className = 'batch-progress-pct';
            pct.textContent = '';
            li.appendChild(icon);
            li.appendChild(label);
            li.appendChild(pct);
            batchProgressList.appendChild(li);
            items[item.key] = { li, icon, pct };
        }
        batchProgressList.classList.add('is-visible');
        return items;
    }

    function initBatchProgressList (allMonths)
    {
        return initProgressList(allMonths.map(function (bm) { return { key: bm, label: formatMonth(bm) }; }));
    }

    function markProgressLoading (items, bm, totalSteps, completedSteps)
    {
        const item = items[bm];
        if (!item) { return; }
        item.li.classList.add('is-loading');
        item.icon.innerHTML = '';
        const spinner = document.createElement('span');
        spinner.className = 'batch-progress-item-spinner';
        item.icon.appendChild(spinner);
        const basePct = totalSteps > 0 ? Math.floor((completedSteps / totalSteps) * 100) : 0;
        if (item.pct) { item.pct.textContent = String(basePct) + '%'; }
    }

    function markProgressDone (items, bm)
    {
        const item = items[bm];
        if (!item) { return; }
        item.li.classList.remove('is-loading');
        item.li.classList.add('is-done');
        item.icon.innerHTML = '✓';
        if (item.pct) { item.pct.textContent = ''; }
    }

    function alignProgressWindow (orderedMonths, items, currentIndex)
    {
        if (!batchProgressList) { return; }
        if (!Array.isArray(orderedMonths) || orderedMonths.length === 0) { return; }
        const safeIndex = Math.max(0, Math.min(currentIndex, orderedMonths.length - 1));
        const nextIndex = safeIndex + 1;
        const anchorMonth = nextIndex < orderedMonths.length ? orderedMonths[nextIndex] : orderedMonths[safeIndex];
        const anchorItem = items[anchorMonth];
        if (!anchorItem || !anchorItem.li) { return; }
        // Keep one upcoming month visible at the bottom whenever possible.
        anchorItem.li.scrollIntoView({ block: 'end', inline: 'nearest' });
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

    function createMonthSummary (ym, data, fallbackStartMonth)
    {
        const payloadData = data && typeof data === 'object' ? data : {};
        return {
            year_month: ym,
            data_start_month: typeof payloadData.data_start_month === 'string' ? payloadData.data_start_month : fallbackStartMonth,
            total_revenue: typeof payloadData.total_revenue === 'number' ? payloadData.total_revenue : 0,
            total_costs: typeof payloadData.total_costs === 'number' ? payloadData.total_costs : 0,
            fetched_at: payloadData.fetched_at || new Date().toISOString(),
        };
    }

    function upsertMonthSummary (summary)
    {
        const idx = monthSummaries.findIndex(function (item) { return item.year_month === summary.year_month; });
        if (idx >= 0)
        {
            monthSummaries[idx] = summary;
        }
        else
        {
            monthSummaries.push(summary);
        }
        monthSummaries.sort(function (a, b)
        {
            return b.year_month.localeCompare(a.year_month);
        });
    }

    function removeMonthSummary (ym)
    {
        monthSummaries = monthSummaries.filter(function (summary)
        {
            return summary.year_month !== ym;
        });
    }

    function ensureAddableMonth (ym)
    {
        if (addableMonths.indexOf(ym) === -1)
        {
            addableMonths.push(ym);
            addableMonths.sort(function (a, b)
            {
                return b.localeCompare(a);
            });
        }
    }

    function removeAddableMonth (ym)
    {
        addableMonths = addableMonths.filter(function (month)
        {
            return month !== ym;
        });
    }

    function buildMonthCard (summary)
    {
        const ym = summary.year_month;
        const dataStartMonth = typeof summary.data_start_month === 'string' && summary.data_start_month !== ''
            ? summary.data_start_month
            : ym;
        const revenue = typeof summary.total_revenue === 'number' ? summary.total_revenue : 0;
        const costs = typeof summary.total_costs === 'number' ? summary.total_costs : 0;
        const profit = revenue - costs;

        const card = document.createElement('div');
        card.className = 'month-card';
        card.dataset.ym = ym;

        const title = document.createElement('div');
        title.className = 'month-card-title';
        title.textContent = formatMonth(ym);
        title.title = 'Data vanaf ' + formatMonth(dataStartMonth);
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
                    refreshMonth(ym);
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

    function runMonthBuildFlow (ym, successMessage)
    {
        const allBatchMonths = buildBatchMonths(ym); // 36 maanden incl. doelmaand
        const effectiveColumnSteps = columnSteps.length > 0
            ? columnSteps
            : [
                { key: 'workorders', label: 'Werkorders' },
                { key: 'projectposten', label: 'ProjectPosten' },
                { key: 'project_details', label: 'Projectdetails' },
                { key: 'planning', label: 'Planningsregels' },
                { key: 'invoices', label: 'Facturen' },
            ];

        const allProgressItems = [];
        for (const batchYm of allBatchMonths)
        {
            allProgressItems.push({
                key: batchYm + '::project_numbers',
                label: formatMonth(batchYm) + ' · Projectnummers',
            });
            for (const step of effectiveColumnSteps)
            {
                allProgressItems.push({
                    key: batchYm + '::' + step.key,
                    label: formatMonth(batchYm) + ' · ' + step.label,
                });
            }
        }

        const allProgressKeys = allProgressItems.map(function (i) { return i.key; });
        const totalSteps = allProgressItems.length;
        const progressItems = initProgressList(allProgressItems);

        let batchIndex = 0;
        let columnIndex = -1;
        let completedSteps = 0;

        function runNextStep ()
        {
            if (batchIndex >= allBatchMonths.length)
            {
                buildSnapshot();
                return;
            }

            const batchYm = allBatchMonths[batchIndex];
            if (columnIndex === -1)
            {
                const progressKey = batchYm + '::project_numbers';
                markProgressLoading(progressItems, progressKey, totalSteps, completedSteps);
                alignProgressWindow(allProgressKeys, progressItems, completedSteps);
                if (pageLoaderText)
                {
                    pageLoaderText.textContent = 'Projectnummers ' + formatMonth(batchYm) + ' (' + (completedSteps + 1) + '/' + totalSteps + ')';
                }

                const body = new URLSearchParams({ target_month: ym, batch_month: batchYm, company: selectedCompany });
                fetch(projectNumbersUrl, { method: 'POST', body: body })
                    .then(parseFetchResponse)
                    .then(function (json)
                    {
                        if (!json.ok)
                        {
                            hideLoader();
                            toast('Fout bij projectnummers ' + formatMonth(batchYm) + ': ' + (json.error || 'Onbekende fout'), true);
                            return;
                        }

                        markProgressDone(progressItems, progressKey);
                        completedSteps++;
                        columnIndex = 0;
                        runNextStep();
                    })
                    .catch(function (err)
                    {
                        hideLoader();
                        toast('Netwerkfout bij projectnummers ' + formatMonth(batchYm) + ': ' + err.message, true);
                    });
                return;
            }

            const step = effectiveColumnSteps[columnIndex];
            const progressKey = batchYm + '::' + step.key;
            markProgressLoading(progressItems, progressKey, totalSteps, completedSteps);
            alignProgressWindow(allProgressKeys, progressItems, completedSteps);
            if (pageLoaderText)
            {
                pageLoaderText.textContent = step.label + ' ' + formatMonth(batchYm) + ' (' + (completedSteps + 1) + '/' + totalSteps + ')';
            }

            const body = new URLSearchParams({
                target_month: ym,
                batch_month: batchYm,
                company: selectedCompany,
                column_key: step.key,
            });
            fetch(columnBatchUrl, { method: 'POST', body: body })
                .then(parseFetchResponse)
                .then(function (json)
                {
                    if (!json.ok)
                    {
                        hideLoader();
                        toast('Fout bij ' + step.label.toLowerCase() + ' voor ' + formatMonth(batchYm) + ': ' + (json.error || 'Onbekende fout'), true);
                        return;
                    }

                    markProgressDone(progressItems, progressKey);
                    completedSteps++;
                    if (json.warning)
                    {
                        toast('Waarschuwing ' + step.label + ' (' + formatMonth(batchYm) + '): ' + json.warning, true);
                    }

                    columnIndex++;
                    if (columnIndex >= effectiveColumnSteps.length)
                    {
                        columnIndex = -1;
                        batchIndex++;
                    }
                    runNextStep();
                })
                .catch(function (err)
                {
                    hideLoader();
                    toast('Netwerkfout bij ' + step.label.toLowerCase() + ' voor ' + formatMonth(batchYm) + ': ' + err.message, true);
                });
        }

        function buildSnapshot ()
        {
            const body = new URLSearchParams({ target_month: ym, company: selectedCompany });
            fetch('maanden.php?action=build_month_snapshot', { method: 'POST', body: body })
                .then(parseFetchResponse)
                .then(function (json)
                {
                    hideLoader();
                    if (!json.ok)
                    {
                        toast('Fout: ' + (json.error || 'Onbekende fout'), true);
                        return;
                    }
                    const data = json.data || {};
                    const newSumm = createMonthSummary(ym, data, allBatchMonths[0]);
                    upsertMonthSummary(newSumm);
                    removeAddableMonth(ym);
                    renderGrid();
                    toast(formatMonth(ym) + ' ' + successMessage);
                })
                .catch(function (err)
                {
                    hideLoader();
                    toast('Netwerkfout: ' + err.message, true);
                });
        }

        showLoader('Bezig met ophalen...');
        runNextStep();
    }

    function refreshMonth (ym)
    {
        showLoader('Bestaande maand verwijderen...');

        const body = new URLSearchParams({ year_month: ym, company: selectedCompany });
        fetch(deleteUrl, { method: 'POST', body: body })
            .then(parseFetchResponse)
            .then(function (json)
            {
                if (!json.ok)
                {
                    hideLoader();
                    toast('Fout bij verwijderen van ' + formatMonth(ym) + ': ' + (json.error || 'Onbekende fout'), true);
                    return;
                }

                removeMonthSummary(ym);
                ensureAddableMonth(ym);
                renderGrid();
                runMonthBuildFlow(ym, 'is ververst.');
            })
            .catch(function (err)
            {
                hideLoader();
                toast('Netwerkfout bij verwijderen van ' + formatMonth(ym) + ': ' + err.message, true);
            });
    }

    function addMonth (ym)
    {
        runMonthBuildFlow(ym, 'is toegevoegd.');
    }

    function buildBatchMonths (targetYm)
    {
        const parts = targetYm.split('-');
        const targetYear = parseInt(parts[0], 10);
        const targetMonth = parseInt(parts[1], 10);
        // 36 months total: 35 before target + target itself (all fetched via batchUrl)
        const result = [];
        for (let i = 35; i >= 0; i--)
        {
            let m = targetMonth - i;
            let y = targetYear;
            while (m <= 0) { m += 12; y--; }
            const ym = y + '-' + String(m).padStart(2, '0');
            result.push(ym);
        }
        return result;
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
