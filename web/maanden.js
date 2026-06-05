(function ()
{
    /**
     * Variabelen
     */
    const payload = window.maandenData || {};
    const companies = Array.isArray(payload.companies) ? payload.companies : [];
    const projectNumbersUrl = typeof payload.project_numbers_url === 'string'
        ? payload.project_numbers_url
        : 'maanden.php?action=fetch_project_numbers_batch';
    const columnBatchUrl = typeof payload.column_batch_url === 'string'
        ? payload.column_batch_url
        : 'maanden.php?action=fetch_column_batch';
    const subPlanningUrl = typeof payload.sub_planning_url === 'string'
        ? payload.sub_planning_url
        : 'maanden.php?action=fetch_sub_planning';
    const columnSteps = Array.isArray(payload.column_steps) ? payload.column_steps : [];
    const targetColumnSteps = Array.isArray(payload.target_column_steps) ? payload.target_column_steps : [];
    const deleteUrl = typeof payload.delete_url === 'string' ? payload.delete_url : 'maanden.php?action=delete_month';
    const detailUrl = typeof payload.detail_url === 'string' ? payload.detail_url : 'maand-detail.php';
    const saveSettingsUrl = typeof payload.save_settings_url === 'string' ? payload.save_settings_url : 'maanden.php?action=save_user_settings';
    const currentMonth = typeof payload.current_month === 'string' ? payload.current_month : '';
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

    function createProgressItem (state, item, sectionKey)
    {
        if (!batchProgressList || !state || !item)
        {
            return;
        }

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

        if (sectionKey === 'planning' && state.planningAnchor)
        {
            batchProgressList.insertBefore(li, state.planningAnchor);
        }
        else
        {
            batchProgressList.appendChild(li);
        }

        const labelEl = li.querySelector('span:not(.batch-progress-icon):not(.batch-progress-pct)');
        state.items[item.key] = { li: li, icon: icon, pct: pct, labelEl: labelEl, section: sectionKey };
        state.orderedKeys.push(item.key);
    }

    function updateSectionProgress (section)
    {
        if (!section || !section.pctEl)
        {
            return;
        }

        const pct = section.total > 0 ? Math.floor((section.completed / section.total) * 100) : 0;
        section.pctEl.textContent = section.completed + '/' + section.total + ' (' + pct + '%)';
    }

    function initProgressList (monthItems)
    {
        if (!batchProgressList)
        {
            return { items: {}, orderedKeys: [], sections: {}, planningAnchor: null };
        }

        batchProgressList.innerHTML = '';

        const monthHeader = document.createElement('li');
        monthHeader.className = 'batch-progress-section';
        const monthTitle = document.createElement('span');
        monthTitle.textContent = 'Snapshot laden';
        const monthPct = document.createElement('span');
        monthPct.className = 'batch-progress-section-pct';
        monthHeader.appendChild(monthTitle);
        monthHeader.appendChild(monthPct);
        batchProgressList.appendChild(monthHeader);

        const state = {
            items: {},
            orderedKeys: [],
            sections: {
                month: { completed: 0, total: 0, pctEl: monthPct },
                planning: { completed: 0, total: 0, pctEl: null },
            },
            planningAnchor: null,
        };

        for (const item of monthItems)
        {
            createProgressItem(state, item, 'month');
            state.sections.month.total++;
        }

        const divider = document.createElement('li');
        divider.className = 'batch-progress-divider';
        batchProgressList.appendChild(divider);

        const planningHeader = document.createElement('li');
        planningHeader.className = 'batch-progress-section';
        const planningTitle = document.createElement('span');
        planningTitle.textContent = 'Voorcalculatie Projecten';
        const planningPct = document.createElement('span');
        planningPct.className = 'batch-progress-section-pct';
        planningHeader.appendChild(planningTitle);
        planningHeader.appendChild(planningPct);
        batchProgressList.appendChild(planningHeader);

        const planningAnchor = document.createElement('li');
        planningAnchor.style.display = 'none';
        batchProgressList.appendChild(planningAnchor);
        state.planningAnchor = planningAnchor;
        state.sections.planning.pctEl = planningPct;

        updateSectionProgress(state.sections.month);
        updateSectionProgress(state.sections.planning);
        batchProgressList.classList.add('is-visible');

        return state;
    }

    function appendPlanningProgressItems (state, planningBatches)
    {
        if (!state || !Array.isArray(planningBatches) || planningBatches.length === 0)
        {
            return;
        }

        for (const batch of planningBatches)
        {
            if (!batch || typeof batch !== 'object')
            {
                continue;
            }

            const key = String(batch.key || '').trim();
            const label = String(batch.label || '').trim();
            if (key === '' || label === '')
            {
                continue;
            }
            if (state.items[key])
            {
                continue;
            }

            createProgressItem(state, {
                key: key,
                label: label,
            }, 'planning');
            state.sections.planning.total++;
        }

        updateSectionProgress(state.sections.planning);
    }

    function markProgressLoading (state, key, totalSteps, completedSteps)
    {
        const item = state && state.items ? state.items[key] : null;
        if (!item) { return; }
        item.li.classList.add('is-loading');
        item.icon.innerHTML = '';
        const spinner = document.createElement('span');
        spinner.className = 'batch-progress-item-spinner';
        item.icon.appendChild(spinner);
        if (item.pct)
        {
            const section = state && state.sections ? state.sections[item.section] : null;
            const sectionPct = section && section.total > 0
                ? Math.floor((section.completed / section.total) * 100)
                : 0;
            item.pct.textContent = String(sectionPct) + '%';
        }
    }

    function markProgressDone (state, key)
    {
        const item = state && state.items ? state.items[key] : null;
        if (!item) { return; }
        item.li.classList.remove('is-loading');
        item.li.classList.add('is-done');
        item.icon.innerHTML = '✓';
        if (item.pct) { item.pct.textContent = ''; }

        const section = state.sections[item.section];
        if (section)
        {
            section.completed++;
            updateSectionProgress(section);
        }
    }

    function appendMonthProgressItems (state, items)
    {
        if (!batchProgressList || !state || !Array.isArray(items) || items.length === 0)
        {
            return 0;
        }

        const divider = batchProgressList.querySelector('.batch-progress-divider');
        let added = 0;

        for (const item of items)
        {
            if (!item || typeof item !== 'object')
            {
                continue;
            }

            const key = String(item.key || '').trim();
            const label = String(item.label || '').trim();
            if (key === '' || label === '' || state.items[key])
            {
                continue;
            }

            const li = document.createElement('li');
            li.className = 'batch-progress-item';

            const icon = document.createElement('span');
            icon.className = 'batch-progress-icon';
            icon.textContent = '○';

            const labelEl = document.createElement('span');
            labelEl.textContent = label;

            const pct = document.createElement('span');
            pct.className = 'batch-progress-pct';
            pct.textContent = '';

            li.appendChild(icon);
            li.appendChild(labelEl);
            li.appendChild(pct);

            if (divider)
            {
                batchProgressList.insertBefore(li, divider);
            }
            else
            {
                batchProgressList.appendChild(li);
            }

            state.items[key] = { li: li, icon: icon, pct: pct, labelEl: labelEl, section: 'month' };
            state.orderedKeys.push(key);
            added++;
        }

        state.sections.month.total += added;
        updateSectionProgress(state.sections.month);

        return added;
    }

    function projectDetailsProgressLabel (ym, chunkIndex, totalChunks)
    {
        const base = formatMonth(ym) + ' · Projectdetails';
        const total = Math.max(1, Number(totalChunks) || 1);
        if (total <= 1)
        {
            return base;
        }

        return base + ' (' + (Number(chunkIndex) + 1) + '/' + total + ')';
    }

    function alignProgressWindow (orderedKeys, state, currentIndex)
    {
        if (!batchProgressList) { return; }
        if (!Array.isArray(orderedKeys) || orderedKeys.length === 0) { return; }
        const safeIndex = Math.max(0, Math.min(currentIndex, orderedKeys.length - 1));
        const nextIndex = safeIndex + 1;
        const anchorKey = nextIndex < orderedKeys.length ? orderedKeys[nextIndex] : orderedKeys[safeIndex];
        const anchorItem = state && state.items ? state.items[anchorKey] : null;
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
            
            // Check if this is the current month
            if (ym === currentMonth)
            {
                opt.setAttribute('data-is-current-month', 'true');
                opt.title = 'Het rapport van deze maand is mogelijk incompleet. Ververs de gegevens als de maand volledig verstreken is.';
                opt.style.backgroundColor = '#fed7aa';
            }
            
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

    function persistSelectedCompany (company)
    {
        if (!saveSettingsUrl || !company)
        {
            return;
        }

        fetch(saveSettingsUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ selected_company: company }),
        }).catch(function () {});
    }

    function runMonthBuildFlow (ym, successMessage)
    {
        const effectiveTargetColumnSteps = targetColumnSteps.length > 0
            ? targetColumnSteps
            : [
                { key: 'grootboekposten_ohw', label: 'Grootboekposten OHW' },
                { key: 'project_details', label: 'Projectdetails' },
            ];

        const monthProgressItems = [];
        for (const targetStep of effectiveTargetColumnSteps)
        {
            if (targetStep.key === 'project_details')
            {
                monthProgressItems.push({
                    key: 'target::project_details::0',
                    label: formatMonth(ym) + ' · ' + targetStep.label,
                    stepKey: 'project_details',
                });
            }
            else
            {
                monthProgressItems.push({
                    key: 'target::' + targetStep.key,
                    label: formatMonth(ym) + ' · ' + targetStep.label,
                    stepKey: targetStep.key,
                });
            }
        }

        const progressState = initProgressList(monthProgressItems);
        appendPlanningProgressItems(progressState, [{
            key: 'planning::voorcalculatie',
            label: formatMonth(ym) + ' · Voorcalculatie',
        }]);
        let totalSteps = monthProgressItems.length + 1;
        let completedSteps = 0;

        let targetColumnIndex = 0;
        let projectDetailsChunkIndex = 0;
        let projectDetailsTotalChunks = 1;
        let projectDetailsChunksExpanded = false;

        function expandProjectDetailsProgressItems (totalChunks)
        {
            const safeTotal = Math.max(1, Number(totalChunks) || 1);
            projectDetailsTotalChunks = safeTotal;

            const firstItem = progressState.items['target::project_details::0'];
            if (firstItem && firstItem.labelEl)
            {
                firstItem.labelEl.textContent = projectDetailsProgressLabel(ym, 0, safeTotal);
            }

            if (safeTotal <= 1 || projectDetailsChunksExpanded)
            {
                return;
            }

            projectDetailsChunksExpanded = true;
            const newItems = [];
            for (let i = 1; i < safeTotal; i++)
            {
                newItems.push({
                    key: 'target::project_details::' + i,
                    label: projectDetailsProgressLabel(ym, i, safeTotal),
                });
            }

            totalSteps += appendMonthProgressItems(progressState, newItems);
        }

        function runVoorcalculatieStep ()
        {
            const progressKey = 'planning::voorcalculatie';
            markProgressLoading(progressState, progressKey, totalSteps, completedSteps);
            alignProgressWindow(progressState.orderedKeys, progressState, completedSteps);
            if (pageLoaderText)
            {
                pageLoaderText.textContent = 'Voorcalculatie ' + formatMonth(ym) + ' (' + (completedSteps + 1) + '/' + totalSteps + ')';
            }

            const body = new URLSearchParams({ target_month: ym, company: selectedCompany });
            fetch(subPlanningUrl, { method: 'POST', body: body })
                .then(parseFetchResponse)
                .then(function (json)
                {
                    if (!json.ok)
                    {
                        hideLoader();
                        toast('Fout bij voorcalculatie voor ' + formatMonth(ym) + ': ' + (json.error || 'Onbekende fout'), true);
                        return;
                    }

                    if (json.warning)
                    {
                        toast('Waarschuwing voorcalculatie (' + formatMonth(ym) + '): ' + json.warning, true);
                    }

                    markProgressDone(progressState, progressKey);
                    completedSteps++;
                    buildSnapshot();
                })
                .catch(function (err)
                {
                    hideLoader();
                    toast('Netwerkfout bij voorcalculatie voor ' + formatMonth(ym) + ': ' + err.message, true);
                });
        }

        function runTargetColumnStep ()
        {
            if (targetColumnIndex >= effectiveTargetColumnSteps.length)
            {
                runVoorcalculatieStep();
                return;
            }

            const step = effectiveTargetColumnSteps[targetColumnIndex];
            const isProjectDetails = step.key === 'project_details';
            const chunkIndex = isProjectDetails ? projectDetailsChunkIndex : 0;
            const progressKey = isProjectDetails
                ? ('target::project_details::' + chunkIndex)
                : ('target::' + step.key);

            markProgressLoading(progressState, progressKey, totalSteps, completedSteps);

            alignProgressWindow(progressState.orderedKeys, progressState, completedSteps);
            if (pageLoaderText)
            {
                let loaderLabel = step.label + ' ' + formatMonth(ym);
                if (isProjectDetails && projectDetailsTotalChunks > 1)
                {
                    loaderLabel += ' (' + (chunkIndex + 1) + '/' + projectDetailsTotalChunks + ')';
                }
                pageLoaderText.textContent = loaderLabel + ' (' + (completedSteps + 1) + '/' + totalSteps + ')';
            }

            const body = new URLSearchParams({
                target_month: ym,
                batch_month: ym,
                company: selectedCompany,
                column_key: step.key,
            });
            if (isProjectDetails)
            {
                body.set('chunk_index', String(chunkIndex));
            }

            fetch(columnBatchUrl, { method: 'POST', body: body })
                .then(parseFetchResponse)
                .then(function (json)
                {
                    if (!json.ok)
                    {
                        hideLoader();
                        toast('Fout bij ' + step.label.toLowerCase() + ' voor ' + formatMonth(ym) + ': ' + (json.error || 'Onbekende fout'), true);
                        return;
                    }

                    if (json.warning)
                    {
                        toast('Waarschuwing ' + step.label + ' (' + formatMonth(ym) + '): ' + json.warning, true);
                    }

                    if (isProjectDetails)
                    {
                        const totalChunks = Math.max(1, Number(json.total_chunks || 1));
                        if (chunkIndex === 0)
                        {
                            expandProjectDetailsProgressItems(totalChunks);
                        }

                        markProgressDone(progressState, progressKey);
                        completedSteps++;

                        if (!json.chunks_complete && (chunkIndex + 1) < totalChunks)
                        {
                            projectDetailsChunkIndex = chunkIndex + 1;
                            runTargetColumnStep();
                            return;
                        }

                        projectDetailsChunkIndex = 0;
                        projectDetailsTotalChunks = 1;
                        projectDetailsChunksExpanded = false;
                        targetColumnIndex++;
                        runTargetColumnStep();
                        return;
                    }

                    markProgressDone(progressState, progressKey);
                    completedSteps++;
                    targetColumnIndex++;
                    runTargetColumnStep();
                })
                .catch(function (err)
                {
                    hideLoader();
                    toast('Netwerkfout bij ' + step.label.toLowerCase() + ' voor ' + formatMonth(ym) + ': ' + err.message, true);
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
                    const newSumm = createMonthSummary(ym, data, ym);
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
        runTargetColumnStep();
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
        // 36 batch-maanden: projectnummers + kolommen per maand, daarna OHW en voorcalculatie
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
            selectedCompany = company;
            persistSelectedCompany(company);
            window.location.href = 'maanden.php';
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
