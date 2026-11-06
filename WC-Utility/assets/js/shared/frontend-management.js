(function () {
    'use strict';

    var scrollKey = 'woodcrestFeScrollY';

    function rememberScroll() {
        try { sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0)); } catch (e) {}
    }

    function restoreScroll() {
        try {
            var saved = sessionStorage.getItem(scrollKey);
            if (saved !== null) {
                sessionStorage.removeItem(scrollKey);
                window.requestAnimationFrame(function () {
                    window.scrollTo(0, parseInt(saved, 10) || 0);
                });
            }
        } catch (e) {}
    }

    function setSearchableSelectLayer(control, active) {
        if (!control) return;
        var card = control.closest('.woodcrest-fe-panel, .woodcrest-fe-card, .woodcrest-card, .wc-tn-card');
        if (card) card.classList.toggle('wc-search-select-layer-active', !!active);
    }

    function closeSearchableSelect(control) {
        if (!control) return;
        control.classList.remove('is-open');
        setSearchableSelectLayer(control, false);
        var trigger = control.querySelector('.wc-search-select-trigger');
        var dropdown = control.querySelector('.wc-search-select-dropdown');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
        if (dropdown) dropdown.hidden = true;
    }

    function closeOtherSearchableSelects(except) {
        document.querySelectorAll('.wc-search-select.is-open').forEach(function (control) {
            if (control !== except) closeSearchableSelect(control);
        });
    }

    function enhanceSearchableSelect(select) {
        if (!select || select.dataset.wcSearchEnhanced === '1' || select.multiple || select.hasAttribute('data-wc-no-search')) return;

        select.dataset.wcSearchEnhanced = '1';
        if (select.required) {
            select.dataset.wcSearchRequired = '1';
            select.required = false;
        }
        select.classList.add('wc-search-select-native');

        var control = document.createElement('div');
        control.className = 'wc-search-select';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'wc-search-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        var triggerText = document.createElement('span');
        triggerText.className = 'wc-search-select-trigger-text';
        var arrow = document.createElement('span');
        arrow.className = 'wc-search-select-arrow';
        arrow.setAttribute('aria-hidden', 'true');
        arrow.textContent = '▾';
        trigger.appendChild(triggerText);
        trigger.appendChild(arrow);

        var dropdown = document.createElement('div');
        dropdown.className = 'wc-search-select-dropdown';
        dropdown.hidden = true;

        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'wc-search-select-input';
        search.placeholder = 'Search…';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Search options');

        var optionsBox = document.createElement('div');
        optionsBox.className = 'wc-search-select-options';
        optionsBox.setAttribute('role', 'listbox');

        var empty = document.createElement('div');
        empty.className = 'wc-search-select-empty';
        empty.textContent = 'No matching options';
        empty.hidden = true;

        dropdown.appendChild(search);
        dropdown.appendChild(optionsBox);
        dropdown.appendChild(empty);
        control.appendChild(trigger);
        control.appendChild(dropdown);
        select.insertAdjacentElement('afterend', control);

        function selectedLabel() {
            var selected = select.options[select.selectedIndex];
            return selected ? selected.textContent.trim() : 'Select';
        }

        function syncTrigger() {
            triggerText.textContent = selectedLabel() || 'Select';
            control.classList.toggle('has-value', !!select.value);
            control.classList.toggle('is-disabled', !!select.disabled);
            trigger.disabled = !!select.disabled;
            optionsBox.querySelectorAll('.wc-search-select-option').forEach(function (button) {
                var isSelected = button.dataset.value === select.value;
                button.classList.toggle('is-selected', isSelected);
                button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            });
        }

        function clearInvalidState() {
            if (select.value) {
                control.classList.remove('is-invalid');
                trigger.removeAttribute('aria-invalid');
            }
        }

        function chooseValue(value) {
            select.value = value;
            select.dispatchEvent(new Event('change', {bubbles: true}));
            syncTrigger();
            clearInvalidState();
            closeSearchableSelect(control);
            trigger.focus();
        }

        function buildOptions() {
            optionsBox.innerHTML = '';
            Array.prototype.forEach.call(select.options, function (option) {
                if (option.disabled) return;
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'wc-search-select-option';
                button.dataset.value = option.value;
                button.dataset.searchText = option.textContent.toLowerCase();
                button.textContent = option.textContent;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                button.addEventListener('click', function () { chooseValue(option.value); });
                optionsBox.appendChild(button);
            });
            syncTrigger();
        }

        function filterOptions() {
            var needle = search.value.trim().toLowerCase();
            var visibleCount = 0;
            optionsBox.querySelectorAll('.wc-search-select-option').forEach(function (button) {
                var match = !needle || button.dataset.searchText.indexOf(needle) !== -1;
                button.hidden = !match;
                if (match) visibleCount++;
            });
            empty.hidden = visibleCount !== 0;
        }

        function openControl() {
            if (select.disabled) return;
            closeOtherSearchableSelects(control);
            control.classList.add('is-open');
            setSearchableSelectLayer(control, true);
            trigger.setAttribute('aria-expanded', 'true');
            dropdown.hidden = false;
            search.value = '';
            filterOptions();
            window.requestAnimationFrame(function () {
                search.focus();
                search.select();
            });
        }

        trigger.addEventListener('click', function () {
            if (control.classList.contains('is-open')) closeSearchableSelect(control);
            else openControl();
        });

        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openControl();
            }
        });

        search.addEventListener('input', filterOptions);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeSearchableSelect(control);
                trigger.focus();
                return;
            }
            if (event.key === 'ArrowDown') {
                var first = Array.prototype.find.call(optionsBox.querySelectorAll('.wc-search-select-option'), function (button) { return !button.hidden; });
                if (first) {
                    event.preventDefault();
                    first.focus();
                }
                return;
            }
            if (event.key === 'Enter') {
                var visible = Array.prototype.find.call(optionsBox.querySelectorAll('.wc-search-select-option'), function (button) { return !button.hidden; });
                if (visible) {
                    event.preventDefault();
                    visible.click();
                }
            }
        });

        optionsBox.addEventListener('keydown', function (event) {
            var current = event.target.closest('.wc-search-select-option');
            if (!current) return;
            var visible = Array.prototype.filter.call(optionsBox.querySelectorAll('.wc-search-select-option'), function (button) { return !button.hidden; });
            var index = visible.indexOf(current);
            if (event.key === 'ArrowDown' && index < visible.length - 1) {
                event.preventDefault();
                visible[index + 1].focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (index > 0) visible[index - 1].focus();
                else search.focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeSearchableSelect(control);
                trigger.focus();
            }
        });

        select.addEventListener('change', function () {
            syncTrigger();
            clearInvalidState();
        });

        select.addEventListener('wc:options-changed', function () {
            buildOptions();
            clearInvalidState();
        });

        if (window.MutationObserver) {
            new MutationObserver(function () { buildOptions(); }).observe(select, {childList: true, subtree: true});
        }

        buildOptions();
    }

    function wcCsvHas(csv, value) {
        if (!csv || !value) return false;
        return String(csv).split(',').map(function (item) { return item.trim(); }).indexOf(String(value)) !== -1;
    }

    function refreshCascadeForm(form) {
        if (!form) return;
        var property = form.querySelector('select[data-wc-role="property"]');
        var tenant = form.querySelector('select[data-wc-role="tenant"]');
        var meter = form.querySelector('select[data-wc-role="meter"]');
        var propertyValue = property ? property.value : '';
        var tenantValue = tenant ? tenant.value : '';
        var meterValue = meter ? meter.value : '';

        form.querySelectorAll('select[data-wc-role]').forEach(function (select) {
            var role = select.dataset.wcRole;
            if (role === 'property') return;

            var requireProperty = select.dataset.wcRequireProperty === '1';
            var requireTenant = select.dataset.wcRequireTenant === '1';
            var requireMeter = select.dataset.wcRequireMeter === '1';
            var filterProperty = select.dataset.wcFilterProperty !== '0';
            var filterTenant = select.dataset.wcFilterTenant === '1';
            var propertyOptional = select.dataset.wcPropertyOptional === '1';
            var tenantOptional = select.dataset.wcTenantOptional === '1';
            var parentMissing = (requireProperty && !propertyValue) || (requireTenant && !tenantValue) || (requireMeter && !meterValue);
            var oldValue = select.value;

            Array.prototype.forEach.call(select.options, function (option, index) {
                if (index === 0 || option.value === '') {
                    option.disabled = false;
                    return;
                }

                var allowed = true;
                if (role === 'tenant' && filterProperty) {
                    if (propertyValue) allowed = wcCsvHas(option.dataset.wcProperties || '', propertyValue);
                    else allowed = propertyOptional ? true : !requireProperty;
                } else if (role === 'suite') {
                    if (propertyValue) allowed = String(option.dataset.wcProperty || '') === String(propertyValue);
                    else if (requireProperty) allowed = false;

                    if (allowed && filterTenant && tenantValue) {
                        allowed = wcCsvHas(option.dataset.wcTenants || '', tenantValue);
                    } else if (allowed && filterTenant && !tenantValue && requireTenant && !tenantOptional) {
                        allowed = false;
                    }
                } else if (role === 'meter' || role === 'account') {
                    if (propertyValue) allowed = String(option.dataset.wcProperty || '') === String(propertyValue);
                    else if (requireProperty) allowed = false;

                    if (allowed && role === 'account' && select.dataset.wcMatchMeterAccount === '1') {
                        if (!meterValue) {
                            allowed = false;
                        } else {
                            var meterOption = meter ? meter.options[meter.selectedIndex] : null;
                            allowed = !!meterOption && String(option.value) === String(meterOption.dataset.wcAccount || '');
                        }
                    }
                }

                option.disabled = parentMissing || !allowed;
            });

            select.disabled = !!parentMissing;
            if (oldValue) {
                var selected = Array.prototype.find.call(select.options, function (option) {
                    return String(option.value) === String(oldValue);
                });
                if (!selected || selected.disabled) select.value = '';
            }
            if (role === 'tenant') tenantValue = select.value;
            if (role === 'meter') meterValue = select.value;
            select.dispatchEvent(new Event('wc:options-changed'));
        });
    }

    function initCascadeForms(root) {
        var scope = root || document;
        var forms = [];
        scope.querySelectorAll('select[data-wc-role="property"]').forEach(function (propertySelect) {
            var form = propertySelect.closest('form');
            if (form && forms.indexOf(form) === -1) forms.push(form);
        });

        forms.forEach(function (form) {
            if (form.dataset.wcCascadeInitialized === '1') {
                refreshCascadeForm(form);
                return;
            }
            form.dataset.wcCascadeInitialized = '1';
            form.addEventListener('change', function (event) {
                if (event.target && event.target.matches('select[data-wc-role]')) {
                    refreshCascadeForm(form);
                }
            });
            refreshCascadeForm(form);
        });
    }

    function enhanceSearchableSelects(root) {
        var scope = root || document;
        scope.querySelectorAll('.woodcrest-fe-module select').forEach(enhanceSearchableSelect);
    }

    function validateSearchableSelects(form, event) {
        var missing = null;
        form.querySelectorAll('select.wc-search-select-native[data-wc-search-required="1"]').forEach(function (select) {
            if (select.disabled) return;
            var control = select.nextElementSibling;
            if (!select.value && control && control.classList.contains('wc-search-select')) {
                control.classList.add('is-invalid');
                var trigger = control.querySelector('.wc-search-select-trigger');
                if (trigger) trigger.setAttribute('aria-invalid', 'true');
                if (!missing) missing = control;
            }
        });
        if (missing) {
            event.preventDefault();
            var trigger = missing.querySelector('.wc-search-select-trigger');
            if (trigger) trigger.click();
            missing.scrollIntoView({behavior: 'smooth', block: 'center'});
            return false;
        }
        return true;
    }

    function initUtilityWorkflow(root) {
        var scope = root || document;

        scope.querySelectorAll('.woodcrest-utility-form').forEach(function (form) {
            var account = form.querySelector('select[name="utility_account_id"]');
            var rateUnit = form.querySelector('[data-wc-rate-unit]');
            if (account && rateUnit) {
                var syncRateUnit = function () {
                    var option = account.options[account.selectedIndex];
                    var utility = option ? String(option.dataset.wcUtility || '').toUpperCase() : '';
                    if (utility === 'WATER') rateUnit.textContent = '$ / gallon';
                    else if (utility === 'ELECTRIC') rateUnit.textContent = '$ / kWh';
                    else rateUnit.textContent = 'Choose an account to see $ / kWh or $ / gallon.';
                };
                account.addEventListener('change', syncRateUnit);
                syncRateUnit();
            }
        });

        scope.querySelectorAll('[data-wc-meter-entry-row]').forEach(function (row) {
            var current = row.querySelector('[data-wc-current-reading]');
            var preview = row.querySelector('[data-wc-usage-preview]');
            if (!current || !preview) return;

            var update = function () {
                var previousText = row.dataset.previous || '';
                if (!previousText) {
                    var previousInput = row.querySelector('input[name^="previous_read["]');
                    previousText = previousInput ? previousInput.value : '';
                }
                var currentText = current.value;
                if (previousText === '' || currentText === '' || Number.isNaN(parseFloat(previousText)) || Number.isNaN(parseFloat(currentText))) {
                    preview.textContent = '—';
                    return;
                }
                var usage = parseFloat(currentText) - parseFloat(previousText);
                if (usage < 0) {
                    preview.textContent = 'Check reading';
                    return;
                }
                var unit = row.dataset.unit || '';
                preview.textContent = usage.toLocaleString(undefined, {maximumFractionDigits: 4}) + (unit ? ' ' + unit : '');
            };

            current.addEventListener('input', update);
            var previousInput = row.querySelector('input[name^="previous_read["]');
            if (previousInput) previousInput.addEventListener('input', update);
            update();
        });
    }

    function wcNormalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function wcTableHeading(card) {
        var heading = card ? card.querySelector('.woodcrest-card-header h2') : null;
        return heading ? heading.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    function wcTableConfig(title) {
        var configs = {
            'Properties': {
                search: 'Search properties…',
                sortColumn: 'Property',
                filters: [{column: 'Status', label: 'Status'}]
            },
            'Property Manager Assignments': {
                search: 'Search managers or properties…',
                sortColumn: 'Manager Name',
                filters: [{column: 'Property', label: 'Property'}]
            },
            'Assigned Properties': {
                search: 'Search assigned properties…',
                sortColumn: 'Property',
                filters: [{column: 'Status', label: 'Status'}]
            },
            'My Assigned Properties': {
                search: 'Search assigned properties…',
                sortColumn: 'Property',
                filters: [{column: 'Status', label: 'Status'}]
            },
            'Tenants / Suite Occupancy': {
                search: 'Search tenants, suites, or properties…',
                sortColumn: 'Tenant Name',
                filters: [
                    {column: 'Property', label: 'Property'},
                    {column: 'End', label: 'Occupancy', mode: 'active-end'}
                ]
            },
            'Tenant Management Table': {
                search: 'Search tenants, properties, suites, or cities…',
                sortColumn: 'Tenant',
                filters: [
                    {column: 'Property', label: 'Property'}
                ]
            },
            'All System Users': {
                search: 'Search users by name or email…',
                sortColumn: 'Full Name',
                filters: [
                    {
                        column: 'Roles',
                        label: 'Role',
                        mode: 'contains',
                        options: [
                            {value: 'manager', label: 'Manager'},
                            {value: 'tenant', label: 'Tenant'},
                            {value: 'null', label: 'No portal role'}
                        ]
                    }
                ]
            },
            'Utility Rates': {
                search: 'Search tenants or rates…',
                sortColumn: 'Tenant',
                filters: [{column: 'Utility', label: 'Utility'}]
            },
            'Monthly Utility Costs': {
                search: 'Search providers, accounts, or properties…',
                sortColumn: 'Period',
                filters: [
                    {column: 'Property', label: 'Property'},
                    {column: 'Utility', label: 'Utility'}
                ]
            },
            'Recent Meter Readings': {
                search: 'Search meters, providers, or properties…',
                sortColumn: 'Period',
                filters: [
                    {column: 'Property', label: 'Property'},
                    {column: 'Utility', label: 'Utility'}
                ]
            },
            'Suites': {
                search: 'Search suites or properties…',
                sortColumn: 'Suite',
                filters: [
                    {column: 'Property', label: 'Property'},
                    {column: 'Status', label: 'Status'}
                ]
            },
            'Utility Accounts': {
                search: 'Search accounts, companies, or properties…',
                sortColumn: 'Account',
                filters: [
                    {column: 'Property', label: 'Property'},
                    {column: 'Utility', label: 'Utility'},
                    {column: 'Status', label: 'Status'}
                ]
            },
            'Meters': {
                search: 'Search meters, accounts, or properties…',
                sortColumn: 'Meter',
                filters: [
                    {column: 'Property', label: 'Property'},
                    {column: 'Utility', label: 'Utility'},
                    {column: 'Status', label: 'Status'}
                ]
            },
            'Meter / Suite Associations': {
                search: 'Search meter, suite, tenant, or property…',
                sortColumn: 'Meter',
                filters: [
                    {column: 'Property', label: 'Property'},
                    {column: 'Party', label: 'Party'}
                ]
            },
            'Estimated Invoices': {
                search: 'Search estimates, tenants, or properties…',
                sortColumn: 'Estimate',
                filters: [{column: 'Status', label: 'Status'}]
            },
            'My Estimated Invoices': {
                search: 'Search estimates or properties…',
                sortColumn: 'Estimate',
                filters: [{column: 'Status', label: 'Status'}]
            }
        };
        return configs[title] || null;
    }

    function wcTableColumnMap(table) {
        var map = {};
        var headerRow = table.tHead && table.tHead.rows.length ? table.tHead.rows[0] : null;
        if (!headerRow) return map;
        Array.prototype.forEach.call(headerRow.cells, function (th, index) {
            map[th.textContent.replace(/\s+/g, ' ').trim()] = index;
        });
        return map;
    }

    function wcManagerDetailRow(row) {
        var next = row && row.nextElementSibling;
        return next && (next.classList.contains('wc-manager-assignment-detail-row') || next.classList.contains('wc-assigned-property-detail')) ? next : null;
    }

    function wcManagerPropertyNames(row) {
        var detail = wcManagerDetailRow(row);
        if (!detail) return [];
        var table = detail.querySelector('.wc-manager-assignment-subtable .woodcrest-fe-table');
        if (!table || !table.tBodies.length) return [];
        return Array.prototype.map.call(table.tBodies[0].rows, function (propertyRow) {
            return propertyRow.cells.length ? propertyRow.cells[0].textContent.replace(/\s+/g, ' ').trim() : '';
        }).filter(Boolean);
    }

    function wcTableRows(table) {
        var tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
        if (!tbody) return [];
        return Array.prototype.filter.call(tbody.rows, function (row) {
            if (row.classList.contains('wc-manager-assignment-detail-row') || row.classList.contains('wc-manager-group-spacer') || row.classList.contains('wc-assigned-property-detail') || row.classList.contains('wc-assigned-property-spacer')) return false;
            var cells = row.cells;
            if (!cells.length) return false;
            return !(cells.length === 1 && wcNormalizeText(cells[0].textContent) === 'no records found.')
                && !(cells.length && wcNormalizeText(cells[0].textContent) === 'no records found.');
        });
    }

    function wcFilterDisplayValue(cellText, filter) {
        var value = String(cellText || '').replace(/\s+/g, ' ').trim();
        if (filter.mode === 'active-end') {
            return wcNormalizeText(value) === 'active' ? 'Active' : 'Ended';
        }
        return value;
    }

    function wcBuildTableFilter(filter, columnIndex, rows) {
        var select = document.createElement('select');
        select.className = 'wc-table-filter';
        select.setAttribute('data-wc-no-search', '1');
        select.dataset.columnIndex = String(columnIndex);
        select.dataset.mode = filter.mode || '';
        select.setAttribute('aria-label', 'Filter by ' + filter.label);

        var first = document.createElement('option');
        first.value = '';
        var allLabels = {
            'Property': 'All properties',
            'Status': 'All statuses',
            'Utility': 'All utilities',
            'Party': 'All parties',
            'Occupancy': 'All occupancies'
        };
        first.textContent = filter.allLabel || allLabels[filter.label] || ('All ' + filter.label.toLowerCase());
        select.appendChild(first);

        if (Array.isArray(filter.options) && filter.options.length) {
            filter.options.forEach(function (item) {
                var option = document.createElement('option');
                option.value = wcNormalizeText(item.value);
                option.textContent = item.label;
                select.appendChild(option);
            });
            return select;
        }

        var values = {};
        rows.forEach(function (row) {
            var candidates = columnIndex === -1
                ? wcManagerPropertyNames(row)
                : (row.cells[columnIndex] ? [row.cells[columnIndex].textContent] : []);
            candidates.forEach(function (candidate) {
                var display = wcFilterDisplayValue(candidate, filter);
                if (!display || display === '—') return;
                values[display] = true;
            });
        });

        Object.keys(values).sort(function (a, b) { return a.localeCompare(b); }).forEach(function (value) {
            var option = document.createElement('option');
            option.value = wcNormalizeText(value);
            option.textContent = value;
            select.appendChild(option);
        });
        return select;
    }

    function wcTableSortValue(row, columnIndex) {
        var cell = row && row.cells ? row.cells[columnIndex] : null;
        return cell ? cell.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    function wcCompareTableValues(a, b) {
        var aText = String(a || '').trim();
        var bText = String(b || '').trim();

        var numericPattern = /^[-+]?\$?\s*[0-9][0-9,]*(?:\.[0-9]+)?%?$/;
        if (numericPattern.test(aText) && numericPattern.test(bText)) {
            var aNum = parseFloat(aText.replace(/[$,%\s]/g, ''));
            var bNum = parseFloat(bText.replace(/[$,%\s]/g, ''));
            if (!Number.isNaN(aNum) && !Number.isNaN(bNum)) return aNum - bNum;
        }

        return aText.localeCompare(bText, undefined, {numeric: true, sensitivity: 'base'});
    }

    function wcInitAssignedPropertyDetails() {
        document.querySelectorAll('.wc-assigned-property-toggle').forEach(function(button) {
            if (button.dataset.wcInitialized) return;
            button.dataset.wcInitialized = '1';
            button.addEventListener('click', function() {
                var row = document.getElementById(button.getAttribute('aria-controls'));
                if (!row) return;
                var open = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                row.hidden = !open;
                var parent = button.closest('.wc-assigned-property-row');
                if (parent) parent.classList.toggle('is-expanded', open);
            });
        });
    }

    function wcInitTableTools(card) {
        if (!card || card.dataset.wcTableTools === '1') return;

        if (card.querySelector('.wc-property-groups')) return;
        var title = wcTableHeading(card);
        var config = wcTableConfig(title);
        if (!config) return;

        var table = card.querySelector('.woodcrest-fe-table');
        var wrap = card.querySelector('.woodcrest-table-wrap');
        if (!table || !wrap) return;

        var rows = wcTableRows(table);
        if (!rows.length) return;

        card.dataset.wcTableTools = '1';
        var columns = wcTableColumnMap(table);
        var toolbar = document.createElement('div');
        toolbar.className = 'wc-table-tools';

        var searchWrap = document.createElement('label');
        searchWrap.className = 'wc-table-search';
        var search = document.createElement('input');
        search.type = 'search';
        search.placeholder = config.search || 'Search…';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', config.search || 'Search table');
        searchWrap.appendChild(search);
        toolbar.appendChild(searchWrap);

        var filterWrap = document.createElement('div');
        filterWrap.className = 'wc-table-filters';
        (config.filters || []).forEach(function (filter) {
            var columnIndex = Object.prototype.hasOwnProperty.call(columns, filter.column)
                ? columns[filter.column]
                : (title === 'Property Manager Assignments' && filter.column === 'Property' ? -1 : null);
            if (columnIndex === null) return;
            var select = wcBuildTableFilter(filter, columnIndex, rows);
            select.dataset.filterLabel = filter.label;
            filterWrap.appendChild(select);
        });
        var sortColumnName = config.sortColumn || Object.keys(columns).find(function (name) {
            return wcNormalizeText(name) !== 'action';
        });
        var sortColumnIndex = Object.prototype.hasOwnProperty.call(columns, sortColumnName) ? columns[sortColumnName] : 0;
        var sortWrap = document.createElement('div');
        sortWrap.className = 'wc-table-sort';
        sortWrap.setAttribute('role', 'group');
        sortWrap.setAttribute('aria-label', 'Sort ' + (sortColumnName || 'table'));

        ['asc', 'desc'].forEach(function (direction) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'wc-table-sort-button';
            button.dataset.direction = direction;
            button.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-label', 'Sort ' + (sortColumnName || 'table') + ' ' + (direction === 'asc' ? 'ascending' : 'descending'));
            button.title = (direction === 'asc' ? 'Ascending' : 'Descending') + ' by ' + (sortColumnName || 'first column');
            button.textContent = direction === 'asc' ? '↑' : '↓';
            sortWrap.appendChild(button);
        });
        filterWrap.appendChild(sortWrap);
        toolbar.appendChild(filterWrap);

        var meta = document.createElement('div');
        meta.className = 'wc-table-tools-meta';
        toolbar.appendChild(meta);

        var empty = document.createElement('div');
        empty.className = 'wc-table-filter-empty';
        empty.textContent = 'No matching records.';
        empty.hidden = true;

        wrap.insertAdjacentElement('beforebegin', toolbar);
        wrap.insertAdjacentElement('afterend', empty);

        var tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
        var originalOrder = new Map();
        var detailRows = new Map();
        var spacerRows = new Map();
        rows.forEach(function (row, index) {
            originalOrder.set(row, index);
            var detail = wcManagerDetailRow(row);
            detailRows.set(row, detail);
            var spacer = detail && detail.nextElementSibling;
            spacerRows.set(row, spacer && (spacer.classList.contains('wc-manager-group-spacer') || spacer.classList.contains('wc-assigned-property-spacer')) ? spacer : null);
        });

        function updateManagerGroupAppearance() {
            if (title !== 'Property Manager Assignments') return;
            var visibleIndex = 0;
            rows.forEach(function (row) {
                var detail = detailRows.get(row);
                var isVisible = !row.hidden;
                var alternate = isVisible && visibleIndex % 2 === 1;
                if (isVisible) visibleIndex++;
                var expanded = isVisible && !!detail && !detail.hidden;
                row.classList.toggle('wc-manager-group-alt', alternate);
                row.classList.toggle('is-expanded', expanded);
                if (detail) {
                    detail.classList.toggle('wc-manager-group-alt', alternate);
                    detail.classList.toggle('is-expanded', expanded);
                }
                var spacer = spacerRows.get(row);
                if (spacer) spacer.hidden = !isVisible;
            });
        }

        if (title === 'Property Manager Assignments') {
            var feedback = document.createElement('div');
            feedback.className = 'wc-manager-assignment-feedback';
            feedback.setAttribute('role', 'status');
            feedback.setAttribute('aria-live', 'polite');
            feedback.hidden = true;
            wrap.insertAdjacentElement('beforebegin', feedback);

            function showManagerFeedback(message, isError) {
                feedback.textContent = message;
                feedback.classList.toggle('is-error', !!isError);
                feedback.hidden = false;
            }

            function refreshManagerPropertyFilter() {
                var select = filterWrap.querySelector('.wc-table-filter[data-column-index="-1"]');
                if (!select) return;
                var chosen = select.value;
                var replacement = wcBuildTableFilter({label: 'Property', mode: select.dataset.mode || ''}, -1, rows);
                while (select.firstChild) select.removeChild(select.firstChild);
                while (replacement.firstChild) select.appendChild(replacement.firstChild);
                select.value = chosen;
                if (select.value !== chosen) select.value = '';
            }

            table.addEventListener('submit', function (event) {
                var form = event.target.closest('.wc-manager-unassign-form');
                if (!form || !table.contains(form)) return;
                if (!window.woodcrestFe || !woodcrestFe.ajaxUrl || !woodcrestFe.unassignManagerNonce || !window.fetch) return;
                event.preventDefault();
                event.stopPropagation();
                var button = form.querySelector('.wc-manager-unassign-button');
                if (!button || button.disabled) return;
                var propertyRow = form.closest('tr[data-property-id]');
                var detailRow = form.closest('.wc-manager-assignment-detail-row');
                var managerRow = detailRow && detailRow.previousElementSibling;
                if (!propertyRow || !detailRow || !managerRow) return;
                var propertyName = propertyRow.cells[0].textContent.trim();
                var managerName = managerRow.cells[0].textContent.trim();
                var accessId = form.querySelector('[name="access_id"]');
                if (!accessId || !accessId.value) return;

                var request = new URLSearchParams();
                request.set('action', 'woodcrest_fe_unassign_manager_access');
                request.set('nonce', woodcrestFe.unassignManagerNonce);
                request.set('access_id', accessId.value);
                button.disabled = true;
                button.textContent = 'Unassigning…';
                feedback.hidden = true;

                fetch(woodcrestFe.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: request.toString()
                }).then(function (response) {
                    return response.text().then(function (text) {
                        if (text.trim() === '-1' || response.status === 403) {
                            throw new Error('Permission or session check failed. Reload this page and try again.');
                        }
                        var payload;
                        try { payload = JSON.parse(text); } catch (error) {
                            throw new Error('The server did not return a valid response. Refresh the page and try again.');
                        }
                        if (!response.ok || !payload.success) {
                            throw new Error((payload.data && payload.data.message) || 'Unable to unassign property manager.');
                        }
                        return payload.data;
                    });
                }).then(function (result) {
                    var subtable = detailRow.querySelector('.wc-manager-assignment-subtable .woodcrest-fe-table');
                    var subbody = subtable && subtable.tBodies[0];
                    if (!subbody) return;
                    Array.prototype.slice.call(subbody.rows).forEach(function (record) {
                        if (record.dataset.propertyId === String(result.property_id)) record.remove();
                    });
                    var remaining = subbody.rows.length;
                    if (remaining === 0) {
                        var spacer = spacerRows.get(managerRow);
                        var index = rows.indexOf(managerRow);
                        if (index !== -1) rows.splice(index, 1);
                        originalOrder.delete(managerRow);
                        detailRows.delete(managerRow);
                        spacerRows.delete(managerRow);
                        if (spacer) spacer.remove();
                        detailRow.remove();
                        managerRow.remove();
                    } else {
                        var count = managerRow.querySelector('.wc-manager-assignment-count');
                        if (count) count.textContent = remaining + ' propert' + (remaining === 1 ? 'y' : 'ies');
                    }
                    refreshManagerPropertyFilter();
                    applyFilters();
                    showManagerFeedback('Unassigned ' + managerName + ' from ' + propertyName + '.', false);
                }).catch(function (error) {
                    showManagerFeedback(String(error.message || 'Unable to unassign property manager.'), true);
                }).finally(function () {
                    button.disabled = false;
                    button.textContent = 'Unassign';
                });
            });

            rows.forEach(function (row) {
                var button = row.querySelector('.wc-manager-assignment-toggle');
                var detail = detailRows.get(row);
                if (!button || !detail) return;
                button.addEventListener('click', function () {
                    var expanded = button.getAttribute('aria-expanded') !== 'true';
                    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    detail.hidden = !expanded || row.hidden;
                    updateManagerGroupAppearance();
                });
            });
        }

        function applySort(direction) {
            if (!tbody) return;
            var sorted = rows.slice().sort(function (rowA, rowB) {
                var result = wcCompareTableValues(
                    wcTableSortValue(rowA, sortColumnIndex),
                    wcTableSortValue(rowB, sortColumnIndex)
                );
                if (result === 0) result = (originalOrder.get(rowA) || 0) - (originalOrder.get(rowB) || 0);
                return direction === 'desc' ? -result : result;
            });
            sorted.forEach(function (row) {
                tbody.appendChild(row);
                var detail = detailRows.get(row);
                if (detail) tbody.appendChild(detail);
                var spacer = spacerRows.get(row);
                if (spacer) tbody.appendChild(spacer);
            });
            sortWrap.querySelectorAll('.wc-table-sort-button').forEach(function (button) {
                var active = button.dataset.direction === direction;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function applyFilters() {
            var query = wcNormalizeText(search.value);
            var activeFilters = Array.prototype.map.call(filterWrap.querySelectorAll('.wc-table-filter'), function (select) {
                return {
                    columnIndex: parseInt(select.dataset.columnIndex, 10),
                    mode: select.dataset.mode || '',
                    value: select.value
                };
            }).filter(function (filter) { return !!filter.value; });

            var shown = 0;
            rows.forEach(function (row) {
                var detail = detailRows.get(row);
                var searchable = wcNormalizeText(row.textContent + (detail ? ' ' + detail.textContent : ''));
                var matches = !query || searchable.indexOf(query) !== -1;

                if (matches) {
                    matches = activeFilters.every(function (filter) {
                        var candidates = filter.columnIndex === -1
                            ? wcManagerPropertyNames(row)
                            : (row.cells[filter.columnIndex] ? [row.cells[filter.columnIndex].textContent] : []);
                        return candidates.some(function (candidate) {
                            var normalized = wcNormalizeText(wcFilterDisplayValue(candidate, {mode: filter.mode}));
                            return filter.mode === 'contains'
                                ? normalized.indexOf(filter.value) !== -1
                                : normalized === filter.value;
                        });
                    });
                }

                row.hidden = !matches;
                if (detail) {
                    var toggle = row.querySelector('.wc-manager-assignment-toggle, .wc-assigned-property-toggle');
                    detail.hidden = !matches || !toggle || toggle.getAttribute('aria-expanded') !== 'true';
                }
                var spacer = spacerRows.get(row);
                if (spacer) spacer.hidden = !matches;
                if (matches) shown++;
            });

            meta.textContent = shown === rows.length ? String(rows.length) + ' records' : String(shown) + ' of ' + String(rows.length);
            empty.textContent = rows.length ? 'No matching records.' : 'No active manager assignments.';
            empty.hidden = shown !== 0;
            updateManagerGroupAppearance();
        }

        search.addEventListener('input', applyFilters);
        filterWrap.querySelectorAll('.wc-table-filter').forEach(function (select) {
            select.addEventListener('change', applyFilters);
        });
        sortWrap.querySelectorAll('.wc-table-sort-button').forEach(function (button) {
            button.addEventListener('click', function () {
                applySort(button.dataset.direction || 'asc');
                applyFilters();
            });
        });
        applyFilters();
    }

    function initTableTools(root) {
        var scope = root || document;
        scope.querySelectorAll('.woodcrest-fe-module .woodcrest-card, .wc-standalone-table-tools').forEach(wcInitTableTools);
    }

    function wcSyncPageRoleClass() {
        if (!document.body) return;

        document.body.classList.remove('wc-page-role-admin', 'wc-page-role-manager', 'wc-page-role-tenant');
        var role = '';
        if (document.querySelector('.woodcrest-header-box.wc-role-manager, .wc-role-manager-content, .woodcrest-manager-page, .woodcrest-fe-manager-header')) {
            role = 'manager';
        } else if (document.querySelector('.woodcrest-header-box.wc-role-tenant, .wc-role-tenant')) {
            role = 'tenant';
        } else if (document.querySelector('.woodcrest-header-box.wc-role-admin, .wc-role-admin, .woodcrest-fe-admin-header')) {
            role = 'admin';
        }

        if (role) document.body.classList.add('wc-page-role-' + role);
    }

    function wcApplyThemeMode(mode) {
        mode = mode === 'dark' ? 'dark' : 'light';
        wcSyncPageRoleClass();
        if (document.body) {
            document.body.classList.toggle('wc-theme-dark', mode === 'dark');
            document.body.classList.toggle('wc-theme-light', mode !== 'dark');
        }
        document.documentElement.setAttribute('data-wc-theme', mode);

        document.querySelectorAll('[data-wc-theme-toggle]').forEach(function (button) {
            var dark = mode === 'dark';
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            button.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
            var icon = button.querySelector('.woodcrest-theme-toggle-icon');
            var label = button.querySelector('.woodcrest-theme-toggle-label');
            if (icon) icon.textContent = dark ? '☀' : '☾';
            if (label) label.textContent = dark ? 'Light' : 'Dark';
        });
    }

    function wcSaveThemeMode(mode) {
        if (!window.woodcrestFe || !woodcrestFe.ajaxUrl || !woodcrestFe.themeNonce) return;
        var body = new URLSearchParams();
        body.set('action', 'woodcrest_save_theme_mode');
        body.set('nonce', woodcrestFe.themeNonce);
        body.set('mode', mode);
        fetch(woodcrestFe.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).catch(function () {  });
    }

    function initThemeToggle() {
        var isLogin = !!document.querySelector('.woodcrest-login-container');
        var initial = (window.woodcrestFe && woodcrestFe.themeMode === 'dark') ? 'dark' : 'light';
        if (isLogin && !(document.body && document.body.classList.contains('logged-in'))) {
            try {
                var guestMode = window.localStorage.getItem('woodcrestGuestTheme');
                if (guestMode === 'dark' || guestMode === 'light') {
                    initial = guestMode;
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    initial = 'dark';
                }
            } catch (e) {}
        }
        wcApplyThemeMode(initial);
        document.querySelectorAll('[data-wc-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var next = document.body && document.body.classList.contains('wc-theme-dark') ? 'light' : 'dark';
                wcApplyThemeMode(next);
                if (isLogin && !(document.body && document.body.classList.contains('logged-in'))) {
                    try { window.localStorage.setItem('woodcrestGuestTheme', next); } catch (e) {}
                } else {
                    if (window.woodcrestFe) woodcrestFe.themeMode = next;
                    wcSaveThemeMode(next);
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initThemeToggle();
        restoreScroll();
        initCascadeForms(document);
        enhanceSearchableSelects(document);
        initUtilityWorkflow(document);
        initTableTools(document);
        wcInitAssignedPropertyDetails();

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.wc-search-select')) closeOtherSearchableSelects(null);

            var invoiceLink = event.target.closest('.woodcrest-invoice-view-link');
            if (invoiceLink && window.woodcrestFe && woodcrestFe.ajaxUrl) {
                event.preventDefault();
                var target = document.getElementById('wc-invoice-view-target');
                var invoiceId = invoiceLink.getAttribute('data-invoice-id');
                if (!target || !invoiceId) return;

                target.innerHTML = '<div class="woodcrest-fe-notice">Loading estimate…</div>';
                var body = new URLSearchParams();
                body.set('action', 'woodcrest_fe_view_invoice');
                body.set('invoice_id', invoiceId);
                body.set('nonce', woodcrestFe.invoiceViewNonce);
                if (woodcrestFe.effectiveManagerId) {
                    body.set('woodcrest_effective_manager_id', woodcrestFe.effectiveManagerId);
                }

                fetch(woodcrestFe.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload.success) throw new Error((payload.data && payload.data.message) || 'Unable to load estimate.');
                    target.innerHTML = payload.data.html;
                    initCascadeForms(target);
                    enhanceSearchableSelects(target);
                    target.scrollIntoView({behavior: 'smooth', block: 'start'});
                })
                .catch(function (error) {
                    target.innerHTML = '<div class="woodcrest-fe-notice error">' + String(error.message || error) + '</div>';
                });
                return;
            }

            var normalLink = event.target.closest('.woodcrest-fe-module a');
            if (normalLink && normalLink.getAttribute('href') && normalLink.getAttribute('href').charAt(0) !== '#') {
                rememberScroll();
            }
        });
        document.addEventListener('submit', function (event) {
            if (event.target && event.target.closest('.woodcrest-fe-module')) {
                if (!validateSearchableSelects(event.target, event)) return;
                rememberScroll();
            }
        });
    });
})();


document.addEventListener('change', function(event) {
    const business = event.target.closest('select[data-wc-business-select]');
    if (!business) return;
    const form = business.closest('form');
    const suite = form && form.querySelector('select[data-wc-business-suite-select]');
    if (!suite) return;
    suite.value = '';
    for (const option of suite.options) {
        option.hidden = option.value !== '' && option.dataset.businessId !== business.value;
        option.disabled = option.hidden;
    }
    suite.dispatchEvent(new Event('wc:options-changed'));
    suite.dispatchEvent(new Event('change', { bubbles: true }));
});
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('select[data-wc-business-select]').forEach(function(business) {
        const suite = business.closest('form')?.querySelector('select[data-wc-business-suite-select]');
        if (!suite) return;
        for (const option of suite.options) {
            option.hidden = option.value !== '' && option.dataset.businessId !== business.value;
            option.disabled = option.hidden;
        }
        suite.dispatchEvent(new Event('wc:options-changed'));
    });
});

(function () {
  'use strict';
  function refresh(form) {
    var party=form.querySelector('[data-wc-assignment-party]');
    var method=form.querySelector('[data-wc-assignment-method]');
    if (!party || !method) return;
    var tenant=party.value==='TENANT';
    var suite=form.querySelector('.wc-v251-suite-fields');
    var percent=form.querySelector('.wc-v251-percent-fields');
    if (suite) suite.hidden=!tenant;
    if (percent) percent.hidden=!tenant || method.value!=='FIXED_PERCENT';
    var select=suite && suite.querySelector('[name="suite_id"]');
    if (select) select.required=tenant;
    var input=percent && percent.querySelector('[name="fixed_percent"]');
    if (input) input.required=tenant && method.value==='FIXED_PERCENT';
  }
  document.addEventListener('change',function(e){
    if (!e.target.matches('[data-wc-assignment-party],[data-wc-assignment-method]')) return;
    var form=e.target.closest('form');if (form) refresh(form);
  });
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('form').forEach(function(form){if(form.querySelector('[data-wc-assignment-party]'))refresh(form);});
  });
})();
