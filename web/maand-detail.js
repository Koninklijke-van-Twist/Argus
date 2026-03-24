(function ()
{
    /**
     * Variabelen
     */
    const payload = window.maandDetailData || {};
    const monthData = payload.month_data && typeof payload.month_data === 'object' ? payload.month_data : null;
    const projectColumnValuesByJob = payload.project_column_values_by_job && typeof payload.project_column_values_by_job === 'object'
        ? payload.project_column_values_by_job : {};
    const prevProfitByProject = typeof payload.prev_profit_by_project === 'object' && payload.prev_profit_by_project
        ? payload.prev_profit_by_project : {};
    const saveSettingsUrl = typeof payload.save_settings_url === 'string' ? payload.save_settings_url : 'maand-detail.php?action=save_user_settings';
    const defaultColumns = Array.isArray(payload.default_columns) ? payload.default_columns : [];
    const appEl = document.getElementById('app');
    const summaryBar = document.getElementById('summaryBar');
    const statusFilterBar = document.getElementById('statusFilterBar');
    const departmentFilterBar = document.getElementById('departmentFilterBar');
    const searchInput = document.getElementById('searchInput');
    const exportCsvBtn = document.getElementById('exportCsvBtn');
    const notesOverlay = document.getElementById('notesOverlay');
    const notesModalTitle = document.getElementById('notesModalTitle');
    const notesModalBody = document.getElementById('notesModalBody');
    const notesClose = document.getElementById('notesClose');
    const invoiceOverlay = document.getElementById('invoiceOverlay');
    const invoiceModalTitle = document.getElementById('invoiceModalTitle');
    const invoiceModalBody = document.getElementById('invoiceModalBody');
    const invoiceClose = document.getElementById('invoiceClose');
    const sourceOverlay = document.getElementById('sourceOverlay');
    const sourceModalTitle = document.getElementById('sourceModalTitle');
    const sourceModalBody = document.getElementById('sourceModalBody');
    const sourceClose = document.getElementById('sourceClose');
    const colReorderOverlay = document.getElementById('colReorderOverlay');
    const colReorderList = document.getElementById('colReorderList');
    const colReorderSave = document.getElementById('colReorderSave');
    const colReorderCancel = document.getElementById('colReorderCancel');
    const colReorderClose = document.getElementById('colReorderClose');
    const openColReorderBtn = document.getElementById('openColReorderBtn');
    const memoMenuTrigger = document.getElementById('memoMenuTrigger');
    const memoMenuPanel = document.getElementById('memoMenuPanel');
    const memoMenuWrap = document.getElementById('memoMenuWrap');
    const companySelect = document.getElementById('companySelect');
    const pageLoader = document.getElementById('pageLoader');

    const workorderRows = monthData && Array.isArray(monthData.workorder_rows) ? monthData.workorder_rows : [];
    const projectDetails = monthData && typeof monthData.project_details === 'object' ? monthData.project_details : {};
    const projectBreakdowns = monthData && typeof monthData.project_breakdowns === 'object' ? monthData.project_breakdowns : {};
    const projectSummaries = monthData && Array.isArray(monthData.project_summaries) ? monthData.project_summaries : [];
    const invoiceDetailsById = monthData && typeof monthData.invoice_details_by_id === 'object' ? monthData.invoice_details_by_id : {};

    const projectSummaryByJob = {};
    for (const summaryRow of projectSummaries)
    {
        if (!summaryRow || typeof summaryRow !== 'object')
        {
            continue;
        }
        const summaryJobNo = String(summaryRow.Job_No || '').trim().toLowerCase();
        if (summaryJobNo === '')
        {
            continue;
        }
        projectSummaryByJob[summaryJobNo] = summaryRow;
    }

    // Column order state (mutable)
    let columnOrder = Array.isArray(payload.column_order) ? payload.column_order.slice() : defaultColumns.slice();
    let hiddenColumns = new Set(Array.isArray(payload.hidden_columns) ? payload.hidden_columns : []);

    // Column label map
    const columnLabels = {
        workorders: 'Werkorder(s)',
        total_costs: 'Kosten t/m heden',
        total_revenue: 'Opbrengst. t/m heden',
        invoiced_total: 'Gefact. t/m heden',
        customer: 'Deb.',
        description: 'Beschr.',
        cost_center: 'Afd.',
        expected_revenue: 'Opbr. Ttl Verw.',
        extra_work: 'Opbr. MW',
        margin_total: 'Marge Ttl',
        pct_ready: '% Gereed',
        winst_ohw: 'Winst OHW',
        notes: 'Notities',
        project_manager: 'Projectmanager',
        invoices: 'Facturen',
    };

    // Status filter state
    const hiddenStatuses = new Set();
    const hiddenCostCenters = new Set();
    let appliedSearch = '';
    let sortKey = 'job_no';
    let sortDir = 'asc';

    // Group workorders by project
    const projectMap = {};
    for (const row of workorderRows)
    {
        const jNo = toTrimmedString(row.Job_No || '');
        const normJob = jNo.toLowerCase();
        if (!projectMap[normJob])
        {
            const projDetail = projectDetails[normJob] || {};
            const projSummary = projectSummaryByJob[normJob] || {};
            const breakdown = projectBreakdowns[normJob] || {};
            const expectedRevenueFromBreakdown = sumAmountField(breakdown.expected_revenue_lines, 'Line_Amount');
            const extraWorkFromBreakdown = sumAmountField(breakdown.extra_work_lines, 'Line_Amount');
            const expectedRevenueFallback = parseDecimal(projSummary.Expected_Revenue || projDetail.Recog_Sales_Amount || projDetail.Calc_Recog_Sales_Amount || projDetail.Total_WIP_Sales_Amount || 0);
            const extraWorkFallback = parseDecimal(projSummary.Extra_Work || computeExtraWork(projDetail) || 0);
            projectMap[normJob] = {
                job_no: jNo,
                description: toTrimmedString(projDetail.Description || row.Description || ''),
                customer_id: toTrimmedString(projDetail.Bill_to_Customer_No || row.Customer_Id || ''),
                customer_name: toTrimmedString(projDetail.Bill_to_Name || row.Customer_Name || ''),
                project_manager: toTrimmedString(projDetail.Project_Manager || projDetail.Person_Responsible || ''),
                cost_center: toTrimmedString(projDetail.LVS_Global_Dimension_1_Code || row.Cost_Center || ''),
                invoiced_total: parseDecimal(projSummary.Invoiced_Total || row.Invoiced_Total || 0),
                expected_revenue: expectedRevenueFromBreakdown !== 0 ? expectedRevenueFromBreakdown : expectedRevenueFallback,
                extra_work: extraWorkFromBreakdown !== 0 ? extraWorkFromBreakdown : extraWorkFallback,
                pct_completed: parseDecimal(projDetail.Percent_Completed),
                invoice_ids: (row.Invoice_Ids && Array.isArray(row.Invoice_Ids) ? row.Invoice_Ids : []),
                workorders: [],
                breakdown: {
                    total_costs_lines: Array.isArray(breakdown.total_costs_lines) ? breakdown.total_costs_lines : [],
                    total_revenue_lines: Array.isArray(breakdown.total_revenue_lines) ? breakdown.total_revenue_lines : [],
                    expected_revenue_lines: Array.isArray(breakdown.expected_revenue_lines) ? breakdown.expected_revenue_lines : [],
                    extra_work_lines: Array.isArray(breakdown.extra_work_lines) ? breakdown.extra_work_lines : [],
                },
            };
        }
        projectMap[normJob].workorders.push(row);
        // Merge invoice ids
        if (Array.isArray(row.Invoice_Ids))
        {
            const seen = new Set(projectMap[normJob].invoice_ids);
            for (const id of row.Invoice_Ids)
            {
                if (!seen.has(id))
                {
                    seen.add(id);
                    projectMap[normJob].invoice_ids.push(id);
                }
            }
        }
    }

    function parseDecimal (v)
    {
        return typeof v === 'number' ? v : (parseFloat(v) || 0);
    }

    function toTrimmedString (value)
    {
        return String(value === null || value === undefined ? '' : value).trim();
    }

    function computeExtraWork (projDetail)
    {
        // Change orders / extra werk is represented by LVS_No_Of_Job_Change_Orders > 0
        // We approximate by capturing additional invoiced revenue beyond base contract
        const changeOrders = parseInt(projDetail.LVS_No_Of_Job_Change_Orders || 0, 10);
        if (changeOrders === 0)
        {
            return 0;
        }
        // Best available field: difference between acc/calc sales and base contract price
        const recogSales = parseDecimal(projDetail.Recog_Sales_Amount || projDetail.Calc_Recog_Sales_Amount || 0);
        const contractPrice = parseDecimal(projDetail.Contract_Total_Price || 0);
        const diff = recogSales - contractPrice;
        return diff > 0 ? diff : 0;
    }

    function sumAmountField (rows, key)
    {
        if (!Array.isArray(rows))
        {
            return 0;
        }
        let total = 0;
        for (const row of rows)
        {
            if (!row || typeof row !== 'object')
            {
                continue;
            }
            total += parseDecimal(row[key]);
        }
        return total;
    }

    const currencyFmt = new Intl.NumberFormat('nl-NL', {
        style: 'currency', currency: 'EUR',
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
    const pctFmt = new Intl.NumberFormat('nl-NL', {
        style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1
    });

    function fmtCurrency (v)
    {
        return currencyFmt.format(typeof v === 'number' ? v : parseFloat(v) || 0);
    }

    function fmtPct (v)
    {
        const num = typeof v === 'number' ? v : parseFloat(v) || 0;
        return pctFmt.format(num / 100);
    }

    function escapeHtml (s)
    {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function amountClass (v)
    {
        if (v > 0.005) { return 'amount-pos'; }
        if (v < -0.005) { return 'amount-neg'; }
        return 'amount-neutral';
    }

    function statusCssClass (status)
    {
        const map = {
            open: 'status-open',
            signed: 'status-signed',
            completed: 'status-completed',
            checked: 'status-checked',
            cancelled: 'status-cancelled',
            closed: 'status-closed',
            planned: 'status-planned',
            'in-progress': 'status-in-progress',
        };
        const key = (status || '').toLowerCase().replace(/\s/g, '-');
        return map[key] || '';
    }

    /**
     * Functies: Status filters
     */
    function collectAllStatuses ()
    {
        const statuses = new Set();
        for (const row of workorderRows)
        {
            const s = toTrimmedString(row.Status || '');
            if (s !== '')
            {
                statuses.add(s);
            }
        }
        return [...statuses].sort();
    }

    function renderStatusFilterBar ()
    {
        if (!statusFilterBar)
        {
            return;
        }
        statusFilterBar.innerHTML = '';

        const statuses = collectAllStatuses();
        if (statuses.length === 0)
        {
            return;
        }

        const title = document.createElement('span');
        title.className = 'status-filter-title';
        title.textContent = 'Status:';
        statusFilterBar.appendChild(title);

        const toggleAll = document.createElement('button');
        toggleAll.type = 'button';
        toggleAll.className = 'status-toggle-all-btn';
        toggleAll.textContent = hiddenStatuses.size > 0 ? 'Alles aan' : 'Alles uit';
        toggleAll.addEventListener('click', function ()
        {
            if (hiddenStatuses.size > 0)
            {
                hiddenStatuses.clear();
            }
            else
            {
                for (const s of statuses)
                {
                    hiddenStatuses.add(s);
                }
            }
            renderStatusFilterBar();
            renderTable();
            updateSummary();
        });
        statusFilterBar.appendChild(toggleAll);

        for (const status of statuses)
        {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'status-filter-btn' + (hiddenStatuses.has(status) ? ' is-off' : '');
            btn.style.cssText = getStatusBtnStyle(status);
            btn.textContent = status;

            btn.addEventListener('click', function (e)
            {
                if (e.detail === 2)
                {
                    return; // dblclick handled separately
                }
                if (hiddenStatuses.has(status))
                {
                    hiddenStatuses.delete(status);
                } else
                {
                    hiddenStatuses.add(status);
                }
                renderStatusFilterBar();
                renderTable();
                updateSummary();
            });

            btn.addEventListener('dblclick', function ()
            {
                // Show only this status
                for (const s of statuses)
                {
                    if (s !== status)
                    {
                        hiddenStatuses.add(s);
                    }
                }
                hiddenStatuses.delete(status);
                renderStatusFilterBar();
                renderTable();
                updateSummary();
            });

            statusFilterBar.appendChild(btn);
        }
    }

    function getStatusBtnStyle (status)
    {
        const colMap = {
            open: '#ffffff',
            signed: '#f6f9e9',
            completed: '#e9f9ee',
            checked: '#fff1dd',
            cancelled: '#ffa7a7',
            closed: '#c5c5c5',
            planned: '#ddefff',
            'in-progress': '#ffe9e9',
        };
        const key = (status || '').toLowerCase().replace(/\s/g, '-');
        const bg = colMap[key] || '#f8fafc';
        return 'background:' + bg + ';';
    }

    function normalizeCostCenterToken (value)
    {
        const text = String(value === null || value === undefined ? '' : value).trim();
        return text === '' ? '__EMPTY__' : text;
    }

    function costCenterLabelFromToken (token)
    {
        return token === '__EMPTY__' ? '(Geen afdeling)' : token;
    }

    function collectAllCostCenters ()
    {
        const tokens = new Set();
        for (const proj of Object.values(projectMap))
        {
            if (!proj || typeof proj !== 'object')
            {
                continue;
            }
            tokens.add(normalizeCostCenterToken(proj.cost_center || ''));
        }
        return [...tokens].sort(function (a, b)
        {
            return costCenterLabelFromToken(a).localeCompare(costCenterLabelFromToken(b), 'nl');
        });
    }

    function renderDepartmentFilterBar ()
    {
        if (!departmentFilterBar)
        {
            return;
        }
        departmentFilterBar.innerHTML = '';

        const departments = collectAllCostCenters();
        if (departments.length === 0)
        {
            return;
        }

        const title = document.createElement('span');
        title.className = 'status-filter-title';
        title.textContent = 'Afdeling:';
        departmentFilterBar.appendChild(title);

        const toggleAll = document.createElement('button');
        toggleAll.type = 'button';
        toggleAll.className = 'status-toggle-all-btn';
        toggleAll.textContent = hiddenCostCenters.size > 0 ? 'Alles aan' : 'Alles uit';
        toggleAll.addEventListener('click', function ()
        {
            if (hiddenCostCenters.size > 0)
            {
                hiddenCostCenters.clear();
            }
            else
            {
                for (const deptToken of departments)
                {
                    hiddenCostCenters.add(deptToken);
                }
            }
            renderDepartmentFilterBar();
            renderTable();
            updateSummary();
        });
        departmentFilterBar.appendChild(toggleAll);

        for (const deptToken of departments)
        {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'status-filter-btn' + (hiddenCostCenters.has(deptToken) ? ' is-off' : '');
            btn.textContent = costCenterLabelFromToken(deptToken);

            btn.addEventListener('click', function (e)
            {
                if (e.detail === 2)
                {
                    return;
                }
                if (hiddenCostCenters.has(deptToken))
                {
                    hiddenCostCenters.delete(deptToken);
                }
                else
                {
                    hiddenCostCenters.add(deptToken);
                }
                renderDepartmentFilterBar();
                renderTable();
                updateSummary();
            });

            btn.addEventListener('dblclick', function ()
            {
                for (const d of departments)
                {
                    if (d !== deptToken)
                    {
                        hiddenCostCenters.add(d);
                    }
                }
                hiddenCostCenters.delete(deptToken);
                renderDepartmentFilterBar();
                renderTable();
                updateSummary();
            });

            departmentFilterBar.appendChild(btn);
        }
    }

    /**
     * Functies: Project filtering
     */
    function getVisibleProjects ()
    {
        const projects = Object.values(projectMap);
        const filtered = [];
        const search = appliedSearch.toLowerCase();

        for (const proj of projects)
        {
            const deptToken = normalizeCostCenterToken(proj.cost_center || '');
            if (hiddenCostCenters.has(deptToken))
            {
                continue;
            }

            const visibleWOs = proj.workorders.filter(function (wo)
            {
                const status = toTrimmedString(wo.Status || '');
                return !hiddenStatuses.has(status);
            });

            if (visibleWOs.length === 0)
            {
                continue; // all workorders filtered out → hide project
            }

            // Search filter
            if (search !== '')
            {
                const haystack = [
                    proj.job_no,
                    proj.description,
                    proj.customer_name,
                    proj.customer_id,
                    proj.project_manager,
                    proj.cost_center,
                ].concat(visibleWOs.map(function (w) { return [w.No || '', w.Description || '', w.Customer_Name || ''].join(' '); })).join(' ').toLowerCase();

                if (!haystack.includes(search))
                {
                    continue;
                }
            }

            filtered.push({ proj, visibleWOs });
        }

        // Sort
        filtered.sort(function (a, b)
        {
            const ap = a.proj;
            const bp = b.proj;

            function getVal (p)
            {
                switch (sortKey)
                {
                    case 'job_no': return p.job_no || '';
                    case 'description': return p.description || '';
                    case 'customer': return p.customer_name || '';
                    case 'total_costs': return computeProjectTotals(p).costs;
                    case 'total_revenue': return computeProjectTotals(p).revenue;
                    case 'invoiced_total': return parseDecimal(p.invoiced_total);
                    case 'margin_total':
                        {
                            const totals = computeProjectTotals(p);
                            return totals.revenue - totals.costs;
                        }
                    case 'project_manager': return p.project_manager || '';
                    case 'cost_center': return p.cost_center || '';
                    case 'pct_ready': return Math.max(0, parseDecimal(p.pct_completed));
                    default: return p.job_no || '';
                }
            }

            const av = getVal(ap);
            const bv = getVal(bp);
            let cmp = 0;
            if (typeof av === 'number' && typeof bv === 'number')
            {
                cmp = av - bv;
            }
            else
            {
                cmp = String(av).localeCompare(String(bv), 'nl');
            }
            return sortDir === 'asc' ? cmp : -cmp;
        });

        return filtered;
    }

    function computeProjectTotals (proj)
    {
        const wos = proj.workorders;
        let costs = 0, revenue = 0;
        for (const wo of wos)
        {
            if (!hiddenStatuses.has(toTrimmedString(wo.Status || '')))
            {
                costs += parseDecimal(wo.Actual_Costs || 0);
                revenue += parseDecimal(wo.Total_Revenue || 0);
            }
        }
        return { costs, revenue };
    }

    function getProjectComputedValues (proj)
    {
        const normJob = (proj.job_no || '').toLowerCase();
        const columnValues = projectColumnValuesByJob[normJob] && typeof projectColumnValuesByJob[normJob] === 'object'
            ? projectColumnValuesByJob[normJob]
            : null;
        const totals = computeProjectTotals(proj);
        const costs = columnValues ? parseDecimal(columnValues.total_costs) : totals.costs;
        const revenue = columnValues ? parseDecimal(columnValues.total_revenue) : totals.revenue;
        const expected = parseDecimal(proj.expected_revenue);
        const extraWork = parseDecimal(proj.extra_work);
        const invoicedTotal = parseDecimal(proj.invoiced_total);
        const pctRaw = parseDecimal(proj.pct_completed);
        const pctDisplay = Math.max(0, pctRaw);
        const isPctOverrun = pctDisplay > 100;
        const marginTotal = columnValues && columnValues.margin_total !== null && columnValues.margin_total !== undefined
            ? parseDecimal(columnValues.margin_total)
            : (revenue - costs);
        const winstOhw = columnValues
            ? parseDecimal(columnValues.winst_ohw)
            : (expected * (pctRaw / 100) - costs);

        return {
            costs,
            revenue,
            expected,
            extraWork,
            invoicedTotal,
            pctDisplay,
            isPctOverrun,
            marginTotal,
            winstOhw,
        };
    }

    function formatInvoicePreviewText (ids)
    {
        const safeIds = Array.isArray(ids) ? ids : [];
        if (safeIds.length === 0)
        {
            return '–';
        }
        const maxVisibleInvoiceIds = 3;
        const visibleIds = safeIds.slice(0, maxVisibleInvoiceIds);
        const remainingCount = safeIds.length - visibleIds.length;
        return visibleIds.join(', ') + (remainingCount > 0 ? ' +' + remainingCount : '');
    }

    function getVisibleColumnKeys ()
    {
        return columnOrder.filter(function (colKey) { return !hiddenColumns.has(colKey); });
    }

    function getProjectCellDisplayText (proj, visibleWOs, computed, colKey)
    {
        switch (colKey)
        {
            case 'workorders':
                return visibleWOs.length + ' WO' + (visibleWOs.length !== 1 ? '\'s' : '');
            case 'total_costs':
                return fmtCurrency(computed.costs);
            case 'total_revenue':
                return fmtCurrency(computed.revenue);
            case 'invoiced_total':
                return fmtCurrency(computed.invoicedTotal);
            case 'customer':
                return proj.customer_name || proj.customer_id || '';
            case 'description':
                return proj.description || '';
            case 'cost_center':
                return proj.cost_center || '';
            case 'expected_revenue':
                return fmtCurrency(computed.expected);
            case 'extra_work':
                return fmtCurrency(computed.extraWork);
            case 'margin_total':
                return fmtCurrency(computed.marginTotal);
            case 'pct_ready':
                return fmtPct(computed.pctDisplay);
            case 'winst_ohw':
                return fmtCurrency(computed.winstOhw);
            case 'notes':
                return 'Notities';
            case 'project_manager':
                return proj.project_manager || '';
            case 'invoices':
                return formatInvoicePreviewText(proj.invoice_ids || []);
            default:
                return '';
        }
    }

    /**
     * Functies: Table rendering
     */
    let tableScrollWrap = null;
    let tableEl = null;
    let tbodyEl = null;

    function renderTable ()
    {
        if (!appEl)
        {
            return;
        }

        // Clear existing table content
        if (tableScrollWrap)
        {
            tableScrollWrap.remove();
            tableScrollWrap = null;
        }

        const visibleProjects = getVisibleProjects();

        if (visibleProjects.length === 0)
        {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'Geen projecten gevonden.';
            appEl.appendChild(empty);
            return;
        }

        // Remove previous empty message if any
        const prevEmpty = appEl.querySelector('.empty');
        if (prevEmpty)
        {
            prevEmpty.remove();
        }

        tableScrollWrap = document.createElement('div');
        tableScrollWrap.className = 'table-scroll-wrap';
        appEl.appendChild(tableScrollWrap);

        tableEl = document.createElement('table');
        tableEl.className = 'projects-table';
        tableScrollWrap.appendChild(tableEl);

        // thead
        const thead = document.createElement('thead');
        const hRow = document.createElement('tr');

        // Project No (always first, fixed)
        const thProject = document.createElement('th');
        thProject.textContent = 'Project';
        thProject.className = 'sortable';
        thProject.dataset.sortKey = 'job_no';
        thProject.style.minWidth = '80px';
        hRow.appendChild(thProject);

        for (const colKey of columnOrder)
        {
            if (hiddenColumns.has(colKey)) { continue; }
            const th = document.createElement('th');
            const lbl = columnLabels[colKey] || colKey;
            th.textContent = lbl;

            // Sortable columns
            if (['description', 'customer', 'total_costs', 'total_revenue', 'invoiced_total', 'margin_total', 'project_manager', 'cost_center', 'pct_ready'].includes(colKey))
            {
                th.className = 'sortable';
                th.dataset.sortKey = colKey;
            }

            if (['total_costs', 'total_revenue', 'invoiced_total', 'expected_revenue', 'extra_work', 'margin_total', 'winst_ohw'].includes(colKey))
            {
                th.style.minWidth = '100px';
                th.style.textAlign = 'right';
            }
            if (colKey === 'pct_ready')
            {
                th.style.minWidth = '70px';
                th.style.textAlign = 'right';
            }
            if (colKey === 'workorders')
            {
                th.style.minWidth = '80px';
            }
            if (colKey === 'description')
            {
                th.style.width = '160px';
            }
            if (colKey === 'customer')
            {
                th.style.width = '130px';
            }

            hRow.appendChild(th);
        }

        thead.appendChild(hRow);
        tableEl.appendChild(thead);

        // Sort click on headers
        thead.addEventListener('click', function (e)
        {
            const th = e.target.closest('th[data-sort-key]');
            if (!th)
            {
                return;
            }
            const key = th.dataset.sortKey;
            if (sortKey === key)
            {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else
            {
                sortKey = key;
                sortDir = 'asc';
            }
            renderTable();
            updateSummary();
        });

        tbodyEl = document.createElement('tbody');
        tableEl.appendChild(tbodyEl);

        for (const { proj, visibleWOs } of visibleProjects)
        {
            renderProjectRow(proj, visibleWOs);
        }

        initTableDragScroll(tableScrollWrap);
        syncTableHeight(tableScrollWrap);
    }

    function renderProjectRow (proj, visibleWOs)
    {
        const normJob = toTrimmedString(proj.job_no || '').toLowerCase();
        const computed = getProjectComputedValues(proj);

        // Project main row
        const tr = document.createElement('tr');
        tr.className = 'project-row';
        tr.dataset.normJob = normJob;

        // Project No cell
        const tdNo = document.createElement('td');
        tdNo.style.fontWeight = '700';
        tdNo.style.whiteSpace = 'nowrap';
        tdNo.textContent = proj.job_no;
        tr.appendChild(tdNo);

        for (const colKey of columnOrder)
        {
            if (hiddenColumns.has(colKey)) { continue; }
            const td = document.createElement('td');

            switch (colKey)
            {
                case 'workorders':
                    {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'subtable-toggle-btn';
                        btn.textContent = visibleWOs.length + ' WO' + (visibleWOs.length !== 1 ? '\'s' : '');
                        btn.addEventListener('click', function ()
                        {
                            toggleSubtable(proj, visibleWOs, tr);
                        });
                        td.appendChild(btn);
                        break;
                    }
                case 'total_costs':
                    td.style.textAlign = 'right';
                    td.innerHTML = '<span class="aggregate-source-link ' + amountClass(-computed.costs) + '">' + escapeHtml(fmtCurrency(computed.costs)) + '</span>';
                    td.addEventListener('click', function ()
                    {
                        const costSourceRows = visibleWOs.map(function (wo)
                        {
                            return [
                                wo.No || '',
                                wo.Status || '',
                                wo.Description || '',
                                fmtCurrency(parseDecimal(wo.Actual_Costs || 0)),
                            ];
                        });
                        showSourceModal(
                            'Totale kosten werkorders – project ' + proj.job_no,
                            ['Werkorder', 'Status', 'Omschrijving', 'Bedrag'],
                            costSourceRows
                        );
                    });
                    break;
                case 'total_revenue':
                    td.style.textAlign = 'right';
                    td.innerHTML = '<span class="aggregate-source-link ' + amountClass(computed.revenue) + '">' + escapeHtml(fmtCurrency(computed.revenue)) + '</span>';
                    td.addEventListener('click', function ()
                    {
                        const revenueSourceRows = visibleWOs.map(function (wo)
                        {
                            return [
                                wo.No || '',
                                wo.Status || '',
                                wo.Description || '',
                                fmtCurrency(parseDecimal(wo.Total_Revenue || 0)),
                            ];
                        });
                        showSourceModal(
                            'Totale opbrengst werkorders – project ' + proj.job_no,
                            ['Werkorder', 'Status', 'Omschrijving', 'Bedrag'],
                            revenueSourceRows
                        );
                    });
                    break;
                case 'invoiced_total':
                    td.style.textAlign = 'right';
                    td.innerHTML = '<span class="' + amountClass(computed.invoicedTotal) + '">' + escapeHtml(fmtCurrency(computed.invoicedTotal)) + '</span>';
                    break;
                case 'customer':
                    td.textContent = proj.customer_name || proj.customer_id || '';
                    break;
                case 'description':
                    td.textContent = proj.description || '';
                    break;
                case 'cost_center':
                    td.textContent = proj.cost_center || '';
                    break;
                case 'expected_revenue':
                    td.style.textAlign = 'right';
                    td.innerHTML = '<span class="aggregate-source-link amount-neutral">' + escapeHtml(fmtCurrency(computed.expected)) + '</span>';
                    td.addEventListener('click', function ()
                    {
                        showSourceModal(
                            'Verwachte opbrengst – project ' + proj.job_no,
                            ['Taak', 'Regel', 'Type', 'Nr.', 'Omschrijving', 'Bedrag'],
                            (proj.breakdown && Array.isArray(proj.breakdown.expected_revenue_lines) ? proj.breakdown.expected_revenue_lines : []).map(function (line)
                            {
                                return [
                                    line.Job_Task_No || '',
                                    String(line.Line_No || ''),
                                    line.Type || '',
                                    line.No || '',
                                    line.Description || '',
                                    fmtCurrency(line.Line_Amount || 0),
                                ];
                            })
                        );
                    });
                    break;
                case 'extra_work':
                    td.style.textAlign = 'right';
                    td.innerHTML = '<span class="aggregate-source-link ' + (computed.extraWork > 0.005 ? 'amount-pos' : 'amount-neutral') + '">' + escapeHtml(fmtCurrency(computed.extraWork)) + '</span>';
                    td.addEventListener('click', function ()
                    {
                        showSourceModal(
                            'Meerwerk – project ' + proj.job_no,
                            ['Taak', 'Regel', 'Type', 'Change order', 'Omschrijving', 'Bedrag'],
                            (proj.breakdown && Array.isArray(proj.breakdown.extra_work_lines) ? proj.breakdown.extra_work_lines : []).map(function (line)
                            {
                                return [
                                    line.Job_Task_No || '',
                                    String(line.Line_No || ''),
                                    line.Type || '',
                                    line.Change_Order_No || '',
                                    line.Description || '',
                                    fmtCurrency(line.Line_Amount || 0),
                                ];
                            })
                        );
                    });
                    break;
                case 'margin_total':
                    td.style.textAlign = 'right';
                    td.innerHTML = '<span class="' + amountClass(computed.marginTotal) + '">' + escapeHtml(fmtCurrency(computed.marginTotal)) + '</span>';
                    break;
                case 'pct_ready':
                    td.style.textAlign = 'right';
                    if (computed.isPctOverrun)
                    {
                        td.innerHTML = '<span class="amount-neg" title="Let op: kosten zijn hoger dan oorspronkelijk gecalculeerd.">' + escapeHtml(fmtPct(computed.pctDisplay)) + '</span>';
                    }
                    else
                    {
                        td.textContent = fmtPct(computed.pctDisplay);
                    }
                    break;
                case 'winst_ohw':
                    td.style.textAlign = 'right';
                    td.innerHTML = '<span class="' + amountClass(computed.winstOhw) + '">' + escapeHtml(fmtCurrency(computed.winstOhw)) + '</span>';
                    break;
                case 'notes':
                    {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'notes-btn';
                        btn.textContent = 'Notities';
                        btn.addEventListener('click', function () { showNotesModal(proj); });
                        td.appendChild(btn);
                        break;
                    }
                case 'project_manager':
                    td.textContent = proj.project_manager || '';
                    break;
                case 'invoices':
                    {
                        const ids = proj.invoice_ids || [];
                        const maxVisibleInvoiceIds = 3;
                        if (ids.length === 0)
                        {
                            td.textContent = '–';
                            td.style.color = '#94a3b8';
                        }
                        else
                        {
                            const frag = document.createDocumentFragment();
                            const visibleIds = ids.slice(0, maxVisibleInvoiceIds);
                            for (let i = 0; i < visibleIds.length; i++)
                            {
                                if (i > 0)
                                {
                                    frag.appendChild(document.createTextNode(', '));
                                }
                                const link = document.createElement('span');
                                link.className = 'invoice-id-link';
                                link.textContent = visibleIds[i];
                                link.dataset.invoiceId = visibleIds[i];
                                link.addEventListener('click', function ()
                                {
                                    showInvoiceModal(ids, proj.job_no);
                                });
                                frag.appendChild(link);
                            }

                            const remainingCount = ids.length - visibleIds.length;
                            if (remainingCount > 0)
                            {
                                frag.appendChild(document.createTextNode(' +' + remainingCount));
                            }

                            td.appendChild(frag);
                        }
                        break;
                    }
                default:
                    td.textContent = '';
            }

            tr.appendChild(td);
        }

        tbodyEl.appendChild(tr);
    }

    function csvEscape (value)
    {
        const text = value === null || value === undefined ? '' : String(value);
        if (!/[";\r\n]/.test(text))
        {
            return text;
        }
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function exportVisibleTableToCsv ()
    {
        const visibleProjects = getVisibleProjects();
        const visibleColKeys = getVisibleColumnKeys();

        const headers = ['Project'].concat(visibleColKeys.map(function (colKey)
        {
            return columnLabels[colKey] || colKey;
        }));

        const lines = [];
        lines.push(headers.map(csvEscape).join(';'));

        for (const entry of visibleProjects)
        {
            const proj = entry.proj;
            const visibleWOs = entry.visibleWOs;
            const computed = getProjectComputedValues(proj);

            const row = [proj.job_no || ''];
            for (const colKey of visibleColKeys)
            {
                row.push(getProjectCellDisplayText(proj, visibleWOs, computed, colKey));
            }
            lines.push(row.map(csvEscape).join(';'));
        }

        const csvText = '\uFEFF' + lines.join('\r\n');
        const blob = new Blob([csvText], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const ym = String(payload.year_month || 'maanddetail');
        link.href = url;
        link.download = 'maanddetail_' + ym + '.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Functies: Subtable
     */
    function toggleSubtable (proj, visibleWOs, projectTr)
    {
        const existingSubRow = tbodyEl.querySelector('tr[data-subtable-for="' + CSS.escape(proj.job_no) + '"]');
        if (existingSubRow)
        {
            existingSubRow.remove();
            projectTr.classList.remove('project-row-expanded');
            return;
        }

        projectTr.classList.add('project-row-expanded');

        const subTr = document.createElement('tr');
        subTr.className = 'subtable-row';
        subTr.dataset.subtableFor = proj.job_no;

        const td = document.createElement('td');
        td.colSpan = columnOrder.length + 1; // +1 for project no column
        td.style.padding = '6px 10px 10px 24px';

        const wrap = document.createElement('div');
        wrap.className = 'subtable-wrap';

        const subTable = document.createElement('table');
        const subHead = document.createElement('thead');
        const subHRow = document.createElement('tr');

        for (const label of ['Werkorder', 'Status', 'Kosten', 'Opbrengst', 'Debiteur', 'Omschrijving'])
        {
            const th = document.createElement('th');
            th.textContent = label;
            if (['Kosten', 'Opbrengst'].includes(label))
            {
                th.style.textAlign = 'right';
            }
            subHRow.appendChild(th);
        }

        subHead.appendChild(subHRow);
        subTable.appendChild(subHead);

        const subBody = document.createElement('tbody');
        for (const wo of visibleWOs)
        {
            const woTr = document.createElement('tr');
            const woCosts = parseDecimal(wo.Actual_Costs || 0);
            const woRevenue = parseDecimal(wo.Total_Revenue || 0);

            const cells = [
                wo.No || '',
                wo.Status || '',
                null, // costs
                null, // revenue
                wo.Customer_Name || wo.Customer_Id || '',
                wo.Description || '',
            ];

            cells.forEach(function (val, idx)
            {
                const c = document.createElement('td');
                if (idx === 1)
                {
                    const cls = statusCssClass(val);
                    if (cls)
                    {
                        c.className = cls;
                    }
                    c.textContent = val;
                }
                else if (idx === 2)
                {
                    c.style.textAlign = 'right';
                    c.innerHTML = '<span class="' + amountClass(-woCosts) + '">' + escapeHtml(fmtCurrency(woCosts)) + '</span>';
                }
                else if (idx === 3)
                {
                    c.style.textAlign = 'right';
                    c.innerHTML = '<span class="' + amountClass(woRevenue) + '">' + escapeHtml(fmtCurrency(woRevenue)) + '</span>';
                }
                else
                {
                    c.textContent = val;
                }
                woTr.appendChild(c);
            });

            subBody.appendChild(woTr);
        }

        subTable.appendChild(subBody);
        wrap.appendChild(subTable);
        td.appendChild(wrap);
        subTr.appendChild(td);

        projectTr.insertAdjacentElement('afterend', subTr);
    }

    /**
     * Functies: Summary
     */
    function updateSummary ()
    {
        if (!summaryBar)
        {
            return;
        }
        summaryBar.innerHTML = '';

        const visible = getVisibleProjects();
        let totalRevenue = 0, totalCosts = 0;

        for (const { proj } of visible)
        {
            const t = computeProjectTotals(proj);
            totalRevenue += t.revenue;
            totalCosts += t.costs;
        }

        const profit = totalRevenue - totalCosts;

        function stat (label, value, cls)
        {
            const wrap = document.createElement('div');
            wrap.className = 'summary-stat';
            const lbl = document.createElement('div');
            lbl.className = 'summary-stat-label';
            lbl.textContent = label;
            const val = document.createElement('div');
            val.className = 'summary-stat-value' + (cls ? ' ' + cls : '');
            val.textContent = fmtCurrency(value);
            wrap.appendChild(lbl);
            wrap.appendChild(val);
            return wrap;
        }

        summaryBar.appendChild(stat('Projecten', visible.length));
        const pCountEl = summaryBar.lastElementChild;
        pCountEl.querySelector('.summary-stat-value').textContent = visible.length.toString();

        summaryBar.appendChild(stat('Omzet', totalRevenue, ''));
        summaryBar.appendChild(stat('Kosten', totalCosts, ''));
        summaryBar.appendChild(stat('Winst', profit, profit >= 0 ? 'stat-positive' : 'stat-negative'));
    }

    /**
     * Functies: Notes modal
     */
    function showNotesModal (proj)
    {
        if (!notesOverlay || !notesModalBody)
        {
            return;
        }

        if (notesModalTitle)
        {
            notesModalTitle.textContent = 'Notities – Project ' + proj.job_no;
        }

        notesModalBody.innerHTML = '';

        // All workorders under this project
        const wos = proj.workorders;
        if (wos.length === 0)
        {
            const p = document.createElement('p');
            p.textContent = 'Geen werkorders gevonden.';
            notesModalBody.appendChild(p);
        }
        else
        {
            for (const wo of wos)
            {
                const header = document.createElement('div');
                header.className = 'notes-workorder-header';
                header.textContent = 'Werkorder: ' + (wo.No || '') + (wo.Description ? ' – ' + wo.Description : '');
                notesModalBody.appendChild(header);

                const notes = Array.isArray(wo.Notes) ? wo.Notes : [];
                const nonEmpty = notes.filter(function (n) { return (n.value || '').trim() !== ''; });

                if (nonEmpty.length === 0)
                {
                    const empty = document.createElement('p');
                    empty.style.color = '#94a3b8';
                    empty.style.fontSize = '12px';
                    empty.textContent = 'Geen notities.';
                    notesModalBody.appendChild(empty);
                }
                else
                {
                    for (const note of nonEmpty)
                    {
                        const section = document.createElement('div');
                        section.className = 'notes-section';
                        const titleEl = document.createElement('div');
                        titleEl.className = 'notes-section-title';
                        titleEl.textContent = note.label || '';
                        const textEl = document.createElement('pre');
                        textEl.className = 'notes-section-text';
                        textEl.textContent = note.value || '';
                        section.appendChild(titleEl);
                        section.appendChild(textEl);
                        notesModalBody.appendChild(section);
                    }
                }
            }
        }

        notesOverlay.style.display = 'flex';
    }

    function closeNotesModal ()
    {
        if (notesOverlay)
        {
            notesOverlay.style.display = 'none';
        }
    }

    /**
     * Functies: Invoice modal
     */
    function showInvoiceModal (invoiceIds, projectNo)
    {
        if (!invoiceOverlay || !invoiceModalBody)
        {
            return;
        }

        const ids = Array.isArray(invoiceIds)
            ? invoiceIds.filter(function (id) { return typeof id === 'string' && id.trim() !== ''; })
            : [];

        if (invoiceModalTitle)
        {
            if (projectNo)
            {
                invoiceModalTitle.textContent = 'Facturen project ' + projectNo;
            }
            else
            {
                invoiceModalTitle.textContent = 'Facturen';
            }
        }

        invoiceModalBody.innerHTML = '';

        if (ids.length === 0)
        {
            const p = document.createElement('p');
            p.textContent = 'Geen facturen beschikbaar.';
            invoiceModalBody.appendChild(p);
        }
        else
        {
            const table = document.createElement('table');
            table.style.width = '100%';
            table.style.borderCollapse = 'collapse';
            table.style.fontSize = '12px';

            const thead = document.createElement('thead');
            const hRow = document.createElement('tr');
            for (const lbl of ['Factuur', 'Klant', 'Omschrijving', 'Bedrag', 'Korting %'])
            {
                const th = document.createElement('th');
                th.textContent = lbl;
                th.style.cssText = 'background:#f1f5fb;padding:6px 8px;text-align:left;border-bottom:1px solid #e7edf5;font-weight:700;';
                if (['Bedrag', 'Korting %'].includes(lbl))
                {
                    th.style.textAlign = 'right';
                }
                hRow.appendChild(th);
            }
            thead.appendChild(hRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            for (const invoiceId of ids)
            {
                const details = invoiceDetailsById[invoiceId];
                const lines = details && Array.isArray(details.Lines) ? details.Lines : [];

                if (lines.length === 0)
                {
                    const tr = document.createElement('tr');
                    const cells = [invoiceId, '', 'Geen details beschikbaar', fmtCurrency(0), '0.00%'];
                    cells.forEach(function (val, i)
                    {
                        const td = document.createElement('td');
                        td.textContent = val;
                        td.style.cssText = 'padding:5px 8px;border-bottom:1px solid #e7edf5;';
                        if (i >= 3)
                        {
                            td.style.textAlign = 'right';
                        }
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                    continue;
                }

                for (const line of lines)
                {
                    const tr = document.createElement('tr');
                    const cells = [
                        invoiceId,
                        line.Customer_No || '',
                        line.Description || '',
                        fmtCurrency(line.Amount || 0),
                        (line.Line_Discount_Percent || 0).toFixed(2) + '%',
                    ];
                    cells.forEach(function (val, i)
                    {
                        const td = document.createElement('td');
                        td.textContent = val;
                        td.style.cssText = 'padding:5px 8px;border-bottom:1px solid #e7edf5;';
                        if (i >= 3)
                        {
                            td.style.textAlign = 'right';
                        }
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                }
            }

            table.appendChild(tbody);
            invoiceModalBody.appendChild(table);
        }

        invoiceOverlay.style.display = 'flex';
    }

    function closeInvoiceModal ()
    {
        if (invoiceOverlay)
        {
            invoiceOverlay.style.display = 'none';
        }
    }

    function showSourceModal (title, headers, rows)
    {
        if (!sourceOverlay || !sourceModalBody)
        {
            return;
        }

        if (sourceModalTitle)
        {
            sourceModalTitle.textContent = title || 'Herkomst';
        }

        sourceModalBody.innerHTML = '';

        const safeHeaders = Array.isArray(headers) ? headers : [];
        const safeRows = Array.isArray(rows) ? rows : [];

        if (safeRows.length === 0)
        {
            const p = document.createElement('p');
            p.textContent = 'Geen bronregels gevonden.';
            sourceModalBody.appendChild(p);
            sourceOverlay.style.display = 'flex';
            return;
        }

        const table = document.createElement('table');
        table.style.width = '100%';
        table.style.borderCollapse = 'collapse';
        table.style.fontSize = '12px';

        const thead = document.createElement('thead');
        const hRow = document.createElement('tr');
        for (const header of safeHeaders)
        {
            const th = document.createElement('th');
            th.textContent = header;
            th.style.cssText = 'background:#f1f5fb;padding:6px 8px;text-align:left;border-bottom:1px solid #e7edf5;font-weight:700;';
            hRow.appendChild(th);
        }
        thead.appendChild(hRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        for (const row of safeRows)
        {
            const tr = document.createElement('tr');
            const cells = Array.isArray(row) ? row : [];
            for (let i = 0; i < safeHeaders.length; i++)
            {
                const td = document.createElement('td');
                td.textContent = cells[i] !== undefined && cells[i] !== null ? String(cells[i]) : '';
                td.style.cssText = 'padding:5px 8px;border-bottom:1px solid #e7edf5;';
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        }
        table.appendChild(tbody);
        sourceModalBody.appendChild(table);
        sourceOverlay.style.display = 'flex';
    }

    function closeSourceModal ()
    {
        if (sourceOverlay)
        {
            sourceOverlay.style.display = 'none';
        }
    }

    /**
     * Functies: Column reorder modal
     */
    let reorderDragSrcIndex = -1;

    function openColReorderModal ()
    {
        if (!colReorderOverlay || !colReorderList)
        {
            return;
        }

        colReorderList.innerHTML = '';

        for (let i = 0; i < columnOrder.length; i++)
        {
            const key = columnOrder[i];
            const li = document.createElement('li');
            li.className = 'col-reorder-item';
            li.draggable = true;
            li.dataset.index = String(i);
            li.dataset.colKey = key;

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'col-reorder-checkbox';
            checkbox.checked = !hiddenColumns.has(key);
            checkbox.setAttribute('aria-label', 'Kolom ' + (columnLabels[key] || key) + ' zichtbaar');
            checkbox.addEventListener('change', function ()
            {
                if (checkbox.checked)
                {
                    li.classList.remove('col-hidden');
                }
                else
                {
                    li.classList.add('col-hidden');
                }
            });
            checkbox.addEventListener('mousedown', function (e) { e.stopPropagation(); });

            const handle = document.createElement('span');
            handle.className = 'col-reorder-handle';
            handle.textContent = '⣿';
            handle.setAttribute('aria-hidden', 'true');

            const label = document.createElement('span');
            label.textContent = columnLabels[key] || key;

            if (!checkbox.checked)
            {
                li.classList.add('col-hidden');
            }

            li.appendChild(checkbox);
            li.appendChild(handle);
            li.appendChild(label);
            colReorderList.appendChild(li);

            li.addEventListener('dragstart', function (e)
            {
                reorderDragSrcIndex = i;
                li.classList.add('dragging');
                if (e.dataTransfer)
                {
                    e.dataTransfer.effectAllowed = 'move';
                }
            });

            li.addEventListener('dragend', function ()
            {
                li.classList.remove('dragging');
                reorderDragSrcIndex = -1;
                colReorderList.querySelectorAll('.col-reorder-item').forEach(function (el)
                {
                    el.classList.remove('drag-over');
                });
            });

            li.addEventListener('dragover', function (e)
            {
                e.preventDefault();
                if (e.dataTransfer)
                {
                    e.dataTransfer.dropEffect = 'move';
                }
            });

            li.addEventListener('drop', function (e)
            {
                e.preventDefault();
                const srcIdx = reorderDragSrcIndex;
                const destIdx = i;
                if (srcIdx < 0 || srcIdx === destIdx)
                {
                    return;
                }
                const items = Array.from(colReorderList.querySelectorAll('.col-reorder-item'));
                const srcEl = items[srcIdx];
                if (!srcEl)
                {
                    return;
                }
                if (destIdx < srcIdx)
                {
                    li.parentNode.insertBefore(srcEl, li);
                }
                else
                {
                    li.parentNode.insertBefore(srcEl, li.nextSibling);
                }
                reorderDragSrcIndex = -1;
            });
        }

        colReorderOverlay.style.display = 'flex';
    }

    function saveColReorder ()
    {
        if (!colReorderList)
        {
            return;
        }
        const items = Array.from(colReorderList.querySelectorAll('.col-reorder-item'));
        const newOrder = items.map(function (el) { return el.dataset.colKey; }).filter(Boolean);
        const newHidden = items
            .filter(function (el)
            {
                const cb = el.querySelector('.col-reorder-checkbox');
                return cb && !cb.checked;
            })
            .map(function (el) { return el.dataset.colKey; })
            .filter(Boolean);
        columnOrder = newOrder;
        hiddenColumns = new Set(newHidden);

        // Persist to server
        fetch(saveSettingsUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ detail_column_order: newOrder, detail_hidden_columns: newHidden }),
        }).catch(function () { }); // best-effort

        closeColReorder();
        renderTable();
        updateSummary();
    }

    function closeColReorder ()
    {
        if (colReorderOverlay)
        {
            colReorderOverlay.style.display = 'none';
        }
    }

    /**
     * Functies: Drag scroll
     */
    function initTableDragScroll (scrollEl)
    {
        if (!scrollEl)
        {
            return;
        }

        let isDown = false, hasDragged = false, suppressClick = false;
        let startX = 0, startY = 0, latX = 0, latY = 0;
        let startLeft = 0, startTop = 0, startWinY = 0;
        let canScrollY = false, rafId = 0;

        const interactive = 'button,a,input,select,textarea,[role="button"],.subtable-toggle-btn,.notes-btn,.invoice-id-link';

        function endDrag ()
        {
            if (!isDown) { return; }
            if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
            isDown = false;
            scrollEl.classList.remove('is-dragging-scroll');
            document.body.classList.remove('dragging-table-scroll');
            if (hasDragged)
            {
                suppressClick = true;
                window.setTimeout(function () { suppressClick = false; }, 0);
            }
        }

        function applyDrag ()
        {
            rafId = 0;
            if (!isDown) { return; }
            scrollEl.scrollLeft = startLeft - (latX - startX);
            if (canScrollY)
            {
                scrollEl.scrollTop = startTop - (latY - startY);
            } else
            {
                window.scrollTo(window.scrollX, startWinY - (latY - startY));
            }
        }

        scrollEl.addEventListener('mousedown', function (e)
        {
            if (e.button !== 0) { return; }
            const t = e.target;
            if (t instanceof Element && (t.closest('thead') || t.closest(interactive))) { return; }
            isDown = true; hasDragged = false;
            startX = latX = e.clientX; startY = latY = e.clientY;
            startLeft = scrollEl.scrollLeft; startTop = scrollEl.scrollTop;
            startWinY = window.scrollY || 0;
            canScrollY = scrollEl.scrollHeight > scrollEl.clientHeight;
            scrollEl.classList.add('is-dragging-scroll');
            document.body.classList.add('dragging-table-scroll');
            e.preventDefault();
        });

        window.addEventListener('mousemove', function (e)
        {
            if (!isDown) { return; }
            latX = e.clientX; latY = e.clientY;
            if (Math.abs(latX - startX) > 3 || Math.abs(latY - startY) > 3) { hasDragged = true; }
            if (!rafId) { rafId = requestAnimationFrame(applyDrag); }
        });

        window.addEventListener('mouseup', endDrag);
        window.addEventListener('blur', endDrag);

        scrollEl.addEventListener('click', function (e)
        {
            if (suppressClick) { e.preventDefault(); e.stopPropagation(); }
        }, true);
    }

    function syncTableHeight (scrollEl)
    {
        if (!scrollEl) { return; }
        const vp = (window.visualViewport && window.visualViewport.height) || window.innerHeight || 600;
        const rect = scrollEl.getBoundingClientRect();
        const avail = Math.floor(vp - rect.top - 16);
        scrollEl.style.maxHeight = Math.max(avail, 120) + 'px';
    }

    /**
     * Page load
     */
    if (pageLoader)
    {
        pageLoader.classList.remove('is-visible');
    }

    // Company select
    if (companySelect)
    {
        companySelect.addEventListener('change', function ()
        {
            const ym = (payload.year_month || '');
            window.location.href = 'maand-detail.php?company=' + encodeURIComponent(companySelect.value)
                + (ym ? '&year_month=' + encodeURIComponent(ym) : '');
        });
    }

    // Notes modal
    if (notesClose)
    {
        notesClose.addEventListener('click', closeNotesModal);
    }
    if (notesOverlay)
    {
        notesOverlay.addEventListener('click', function (e) { if (e.target === notesOverlay) { closeNotesModal(); } });
    }

    // Invoice modal
    if (invoiceClose)
    {
        invoiceClose.addEventListener('click', closeInvoiceModal);
    }
    if (invoiceOverlay)
    {
        invoiceOverlay.addEventListener('click', function (e) { if (e.target === invoiceOverlay) { closeInvoiceModal(); } });
    }

    if (sourceClose)
    {
        sourceClose.addEventListener('click', closeSourceModal);
    }
    if (sourceOverlay)
    {
        sourceOverlay.addEventListener('click', function (e) { if (e.target === sourceOverlay) { closeSourceModal(); } });
    }

    // Col reorder
    if (openColReorderBtn)
    {
        openColReorderBtn.addEventListener('click', function ()
        {
            if (memoMenuPanel) { memoMenuPanel.classList.remove('is-open'); }
            openColReorderModal();
        });
    }
    if (colReorderSave) { colReorderSave.addEventListener('click', saveColReorder); }
    if (colReorderCancel) { colReorderCancel.addEventListener('click', closeColReorder); }
    if (colReorderClose) { colReorderClose.addEventListener('click', closeColReorder); }
    if (colReorderOverlay)
    {
        colReorderOverlay.addEventListener('click', function (e) { if (e.target === colReorderOverlay) { closeColReorder(); } });
    }

    // Preferences menu toggle
    if (memoMenuTrigger && memoMenuPanel)
    {
        memoMenuTrigger.addEventListener('click', function ()
        {
            memoMenuPanel.classList.toggle('is-open');
        });
    }
    if (memoMenuWrap)
    {
        document.addEventListener('click', function (e)
        {
            if (memoMenuWrap && !memoMenuWrap.contains(e.target))
            {
                if (memoMenuPanel) { memoMenuPanel.classList.remove('is-open'); }
            }
        });
    }

    // Search
    if (searchInput)
    {
        let searchTimeout = 0;
        searchInput.addEventListener('input', function ()
        {
            clearTimeout(searchTimeout);
            searchTimeout = window.setTimeout(function ()
            {
                appliedSearch = searchInput.value.trim();
                renderTable();
                updateSummary();
            }, 220);
        });
    }

    if (exportCsvBtn)
    {
        exportCsvBtn.addEventListener('click', exportVisibleTableToCsv);
    }

    // Window resize: re-sync table height
    window.addEventListener('resize', function ()
    {
        if (tableScrollWrap) { syncTableHeight(tableScrollWrap); }
    }, { passive: true });

    // Initial render
    if (appEl && workorderRows.length > 0)
    {
        renderStatusFilterBar();
        renderDepartmentFilterBar();
        renderTable();
        updateSummary();
    }
    else if (appEl && !appEl.querySelector('.error-box'))
    {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = monthData ? 'Geen werkorders gevonden voor deze maand.' : 'Geen maanddata geladen.';
        appEl.appendChild(empty);
        if (summaryBar) { summaryBar.style.display = 'none'; }
    }
})();
