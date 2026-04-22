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
    const planningProjectListUrl = typeof payload.planning_project_list_url === 'string'
        ? payload.planning_project_list_url
        : 'maanden.php?action=planning_project_list';
    const planningProjectUrl = typeof payload.planning_project_url === 'string'
        ? payload.planning_project_url
        : 'maanden.php?action=fetch_sub_planning_project';
    const planningBatchUrl = typeof payload.planning_batch_url === 'string'
        ? payload.planning_batch_url
        : 'maanden.php?action=fetch_sub_planning_batch';
    const subPlanningUrl = typeof payload.sub_planning_url === 'string'
        ? payload.sub_planning_url
        : 'maanden.php?action=fetch_sub_planning';
    const columnSteps = Array.isArray(payload.column_steps) ? payload.column_steps : [];
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

        state.items[item.key] = { li, icon, pct, section: sectionKey };
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
        monthTitle.textContent = 'Maanden Laden';
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

    function runMonthBuildFlow (ym, successMessage)
    {
        const allBatchMonths = buildBatchMonths(ym); // 36 maanden incl. doelmaand
        const effectiveColumnSteps = columnSteps.length > 0
            ? columnSteps
            : [
                { key: 'workorders', label: 'Werkorders' },
                { key: 'projectposten', label: 'ProjectPosten' },
                { key: 'project_details', label: 'Projectdetails' },
                { key: 'invoices', label: 'Facturen' },
            ];

        const monthProgressItems = [];
        for (const batchYm of allBatchMonths)
        {
            monthProgressItems.push({
                key: batchYm + '::project_numbers',
                label: formatMonth(batchYm) + ' · Projectnummers',
            });
            for (const step of effectiveColumnSteps)
            {
                monthProgressItems.push({
                    key: batchYm + '::' + step.key,
                    label: formatMonth(batchYm) + ' · ' + step.label,
                });
            }
        }

        const progressState = initProgressList(monthProgressItems);
        let totalSteps = monthProgressItems.length;
        let completedSteps = 0;
        const planningQueue = [];
        const planningSeen = new Set();

        let batchIndex = 0;
        let columnIndex = -1;

        function queuePlanningProjects (projects)
        {
            if (!Array.isArray(projects) || projects.length === 0)
            {
                return;
            }

            const newProjects = [];
            for (const rawProjectNo of projects)
            {
                const projectNo = String(rawProjectNo || '').trim();
                if (projectNo === '' || planningSeen.has(projectNo))
                {
                    continue;
                }

                planningSeen.add(projectNo);
                planningQueue.push(projectNo);
                newProjects.push(projectNo);
            }

            if (newProjects.length === 0)
            {
                return;
            }
        }

        function buildPlanningBatches (projectNumbers, batchSize)
        {
            const safeBatchSize = Math.max(1, batchSize || 30);
            const sortedProjects = projectNumbers.slice().sort(function (left, right)
            {
                return String(left).localeCompare(String(right), 'nl', { numeric: true, sensitivity: 'base' });
            });

            const result = [];
            for (let i = 0; i < sortedProjects.length; i += safeBatchSize)
            {
                const projects = sortedProjects.slice(i, i + safeBatchSize);
                if (projects.length === 0)
                {
                    continue;
                }

                const from = projects[0];
                const to = projects[projects.length - 1];
                const label = projects.length === 1
                    ? ('Voorcalculatie ' + from)
                    : ('Voorcalculatie ' + from + ' - ' + to);

                result.push({
                    key: 'planning_batch::' + String(result.length).padStart(4, '0') + '::' + from + '::' + to,
                    label: label,
                    projects: projects,
                });
            }

            return result;
        }

        function runNextStep ()
        {
            if (batchIndex >= allBatchMonths.length)
            {
                fetchPlanningProjectsAndRun();
                return;
            }

            const batchYm = allBatchMonths[batchIndex];
            if (columnIndex === -1)
            {
                const progressKey = batchYm + '::project_numbers';
                markProgressLoading(progressState, progressKey, totalSteps, completedSteps);
                alignProgressWindow(progressState.orderedKeys, progressState, completedSteps);
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

                        queuePlanningProjects(json.project_numbers);
                        queuePlanningProjects(json.all_project_numbers);
                        markProgressDone(progressState, progressKey);
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
            markProgressLoading(progressState, progressKey, totalSteps, completedSteps);
            alignProgressWindow(progressState.orderedKeys, progressState, completedSteps);
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

                    markProgressDone(progressState, progressKey);
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

        function fetchPlanningProjectsAndRun ()
        {
            if (pageLoaderText)
            {
                pageLoaderText.textContent = 'Voorcalculatie-projecten voorbereiden...';
            }

            const body = new URLSearchParams({ target_month: ym, company: selectedCompany });
            fetch(planningProjectListUrl, { method: 'POST', body: body })
                .then(parseFetchResponse)
                .then(function (json)
                {
                    if (!json.ok)
                    {
                        hideLoader();
                        toast('Fout bij projectlijst voorcalculatie: ' + (json.error || 'Onbekende fout'), true);
                        return;
                    }

                    const projects = Array.isArray(json.projects) ? json.projects : [];
                    queuePlanningProjects(projects);
                    if (planningQueue.length === 0)
                    {
                        buildSnapshot();
                        return;
                    }

                    const planningBatches = buildPlanningBatches(planningQueue, 30);
                    appendPlanningProgressItems(progressState, planningBatches);
                    totalSteps += planningBatches.length;
                    runPlanningBatchStep(planningBatches, 0);
                })
                .catch(function (err)
                {
                    if (planningQueue.length === 0)
                    {
                        hideLoader();
                        toast('Netwerkfout bij projectlijst voorcalculatie: ' + err.message, true);
                        return;
                    }

                    const planningBatches = buildPlanningBatches(planningQueue, 30);
                    appendPlanningProgressItems(progressState, planningBatches);
                    totalSteps += planningBatches.length;
                    runPlanningBatchStep(planningBatches, 0);
                });
        }

        function runPlanningBatchStep (planningBatches, batchIndex)
        {
            if (!Array.isArray(planningBatches) || batchIndex >= planningBatches.length)
            {
                buildSnapshot();
                return;
            }

            const batch = planningBatches[batchIndex];
            if (!batch || !Array.isArray(batch.projects) || batch.projects.length === 0)
            {
                runPlanningBatchStep(planningBatches, batchIndex + 1);
                return;
            }

            const progressKey = String(batch.key || '');
            markProgressLoading(progressState, progressKey, totalSteps, completedSteps);
            alignProgressWindow(progressState.orderedKeys, progressState, completedSteps);
            if (pageLoaderText)
            {
                pageLoaderText.textContent = String(batch.label || 'Voorcalculatie') + ' (' + (completedSteps + 1) + '/' + totalSteps + ')';
            }

            const body = new URLSearchParams({
                target_month: ym,
                company: selectedCompany,
                project_numbers_json: JSON.stringify(batch.projects),
            });
            fetch(planningBatchUrl, { method: 'POST', body: body })
                .then(parseFetchResponse)
                .then(function (json)
                {
                    if (!json.ok)
                    {
                        hideLoader();
                        toast('Fout bij ' + (batch.label || 'voorcalculatie') + ': ' + (json.error || 'Onbekende fout'), true);
                        return;
                    }

                    markProgressDone(progressState, progressKey);
                    completedSteps++;
                    runPlanningBatchStep(planningBatches, batchIndex + 1);
                })
                .catch(function (err)
                {
                    hideLoader();
                    toast('Netwerkfout bij ' + (batch.label || 'voorcalculatie') + ': ' + err.message, true);
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
