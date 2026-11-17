/* global wp, SmartScheduleData */
(function (window, document) {
    'use strict';
    var cfg = window.SmartScheduleData;
    if (!cfg || !window.wp || !wp.apiFetch) { return; }
    // Gutenberg already installs a nonce middleware; only add one when missing
    // so apiFetch middleware does not stack duplicates.
    var hasNonce = false;
    try { hasNonce = (wp.apiFetch.nonceEndpoint || '').length > 0 || (wp.apiFetch.nonceMiddleware && wp.apiFetch.nonceMiddleware.nonce); } catch (e) { hasNonce = false; }
    if (!hasNonce) { wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(cfg.nonce)); }
    var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

    function elt(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }
    function pad(v) { return String(v).padStart(2, '0'); }
    function dateFormat(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
    function dateParts(value) {
        if (!/^\d{4}-\d\d-\d\d$/.test(value)) { return null; }
        var d = new Date(value + 'T12:00:00Z');
        return !isNaN(d.getTime()) && dateFormat(d) === value ? d : null;
    }
    function prettyZone(id) { return String(id).replace(/_/g, ' ').replace(/\//g, ' / '); }
    function labelTime(x, clock) {
        if (!x) { return __('Not set', 'smart-schedule'); }
        var bits = x.time.split(':'), hour = Number(bits[0]);
        return x.weekday + ', ' + x.date + ' ' + __('at', 'smart-schedule') + ' ' + (clock === '24' ? x.time : ((hour % 12) || 12) + ':' + bits[1] + ' ' + (hour < 12 ? 'AM' : 'PM')) + ' ' + x.abbrev + ' (UTC' + x.offset + ')';
    }
    function api(method, path, data) {
        var args = {path: '/smart-schedule/v1/' + path, method: method};
        if (method === 'GET') {
            args.path += '?' + new URLSearchParams(data).toString();
        } else { args.data = data; }
        return wp.apiFetch(args);
    }
    var monthNames = [__('January','smart-schedule'),__('February','smart-schedule'),__('March','smart-schedule'),__('April','smart-schedule'),__('May','smart-schedule'),__('June','smart-schedule'),__('July','smart-schedule'),__('August','smart-schedule'),__('September','smart-schedule'),__('October','smart-schedule'),__('November','smart-schedule'),__('December','smart-schedule')];
    var dayNames = [__('Sun','smart-schedule'),__('Mon','smart-schedule'),__('Tue','smart-schedule'),__('Wed','smart-schedule'),__('Thu','smart-schedule'),__('Fri','smart-schedule'),__('Sat','smart-schedule')];
    var aliases = {
        'america/los_angeles': 'los angeles la pacific pst pdt california',
        'america/new_york': 'new york ny eastern est edt',
        'america/toronto': 'toronto canada eastern est edt',
        'america/chicago': 'chicago central cst cdt',
        'europe/london': 'london uk british bst gmt',
        'asia/tokyo': 'tokyo japan jst',
        'australia/sydney': 'sydney australia aedt aest'
    };
    // Only one picker may exist at a time; the shared element IDs rely on this
    // invariant. Close any stale instance before opening a new one.
    var currentInstance = null;
    function open(opts) {
        opts = opts || {};
        if (currentInstance) { currentInstance.close(); }
        var originalFocus = document.activeElement;
        var state = {
            zone: cfg.zone || cfg.siteZone, clock: cfg.clock === '24' ? '24' : '12',
            date: '', hour: 12, minute: 0, choice: '', policy: 'instant',
            preview: null, request: 0, timer: null, month: null, busy: false,
            status: opts.status || 'draft', postId: opts.postId || cfg.postId || 0
        };
        var inline = !!opts.inline && !!opts.anchor && opts.anchor.getBoundingClientRect;
        var overlay = elt('div', inline ? 'ss-native-shell' : 'ss-overlay');
        var dialog = elt('section', 'ss-dialog');
        dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', inline ? 'false' : 'true');
        dialog.setAttribute('aria-labelledby', 'ss-title');
        overlay.appendChild(dialog);
        dialog.innerHTML = [
            '<header class="ss-dialog-header"><h2 id="ss-title">' + __('Publish', 'smart-schedule') + '</h2><button type="button" class="ss-close" aria-label="' + __('Close scheduler', 'smart-schedule') + '">×</button></header>',
            '<div class="ss-dialog-content">',
            '<div class="ss-layout">',
            '<div class="ss-main-column">',
            '<div class="ss-section-heading"><span id="ss-time-heading">' + __('TIME', 'smart-schedule') + '</span><div class="ss-clock-toggle" role="group" aria-label="' + __('Clock format', 'smart-schedule') + '"><button type="button" data-clock="12" aria-pressed="false">' + __('12-hour', 'smart-schedule') + '</button><button type="button" data-clock="24" aria-pressed="false">' + __('24-hour', 'smart-schedule') + '</button></div></div>',
            '<div class="ss-time-fields" role="group" aria-labelledby="ss-time-heading"><label class="ss-visually-hidden" for="ss-hour">' + __('Hour', 'smart-schedule') + '</label><input type="number" id="ss-hour" inputmode="numeric" aria-label="' + __('Hour', 'smart-schedule') + '" /><span class="ss-colon" aria-hidden="true">:</span><label class="ss-visually-hidden" for="ss-minute">' + __('Minute', 'smart-schedule') + '</label><input type="number" id="ss-minute" min="0" max="59" inputmode="numeric" aria-label="' + __('Minute', 'smart-schedule') + '" /><div class="ss-meridiem-wrap"><label class="ss-visually-hidden" for="ss-meridiem">' + __('AM or PM', 'smart-schedule') + '</label><select id="ss-meridiem" aria-label="' + __('AM or PM', 'smart-schedule') + '"><option value="AM">AM</option><option value="PM">PM</option></select></div></div>',
            '<div class="ss-section-heading ss-date-heading"><span id="ss-date-heading">' + __('DATE', 'smart-schedule') + '</span></div>',
            '<div class="ss-date-fields" role="group" aria-labelledby="ss-date-heading"><label class="ss-visually-hidden" for="ss-day">' + __('Day', 'smart-schedule') + '</label><input id="ss-day" type="number" inputmode="numeric" min="1" max="31" aria-label="' + __('Day', 'smart-schedule') + '" /><label class="ss-visually-hidden" for="ss-month">' + __('Month', 'smart-schedule') + '</label><select id="ss-month" aria-label="' + __('Month', 'smart-schedule') + '">' + monthNames.map(function(m,i){return '<option value="'+(i+1)+'">'+m+'</option>';}).join('') + '</select><label class="ss-visually-hidden" for="ss-year">' + __('Year', 'smart-schedule') + '</label><input id="ss-year" type="number" inputmode="numeric" min="1970" max="2100" aria-label="' + __('Year', 'smart-schedule') + '" /></div>',
            '<input id="ss-date" type="hidden" />',
            '<div class="ss-calendar"><div class="ss-monthbar"><button type="button" class="ss-month-prev" aria-label="' + __('Previous month', 'smart-schedule') + '">‹</button><strong class="ss-month-label"></strong><button type="button" class="ss-month-next" aria-label="' + __('Next month', 'smart-schedule') + '">›</button><button type="button" class="ss-month-today">' + __('Today', 'smart-schedule') + '</button></div><div class="ss-days" role="group" aria-label="' + __('Calendar dates', 'smart-schedule') + '"></div></div>',
            '<div class="ss-zone-field"><label for="ss-zone">' + __('Time shown in', 'smart-schedule') + '</label><select id="ss-zone" aria-label="' + __('Timezone for the entered date and time', 'smart-schedule') + '"></select><p class="ss-compact-conversion" aria-live="polite" hidden></p></div>',
            '<div class="ss-ambiguity" aria-live="polite"></div><p class="ss-warning" role="alert" hidden></p>',
            '</div>',
            '<aside class="ss-advanced-region" id="ss-advanced-area" hidden>',
            '<h3 class="ss-advanced-title">' + __('Advanced scheduling', 'smart-schedule') + '</h3>',
            '<p class="ss-zone-explain">' + __('Use another timezone, favourite shortcuts and publishing conversion details without changing the simple scheduler.', 'smart-schedule') + '</p>',
            '<label class="ss-search-label" for="ss-zone-search">' + __('Search timezones', 'smart-schedule') + '</label><input type="search" id="ss-zone-search" placeholder="' + __('Search city, region or UTC', 'smart-schedule') + '" autocomplete="off" aria-label="' + __('Search timezones', 'smart-schedule') + '" />',
            '<div class="ss-favorites"></div><button type="button" class="ss-toggle-fav"></button>',
            '<fieldset class="ss-policy"><legend>' + __('If you switch timezones', 'smart-schedule') + '</legend><label><input type="radio" name="ss-policy" value="instant" checked /> ' + __('Keep the publishing moment', 'smart-schedule') + '</label><label><input type="radio" name="ss-policy" value="clock" /> ' + __('Keep the entered clock time', 'smart-schedule') + '</label></fieldset>',
            '<section class="ss-conversion" aria-label="' + __('Timezone conversion', 'smart-schedule') + '"><h3>' + __('Conversion details', 'smart-schedule') + '</h3><div class="ss-preview" aria-live="polite">' + __('Calculating…', 'smart-schedule') + '</div></section>',
            '<button type="button" class="ss-reset">' + __('Reset to saved time', 'smart-schedule') + '</button>',
            '</aside>',
            '</div>',
            '</div>',
            '<footer class="ss-dialog-footer"><button type="button" class="ss-advanced-toggle" aria-expanded="false" aria-controls="ss-advanced-area">' + __('Advanced options', 'smart-schedule') + ' <span aria-hidden="true">⌄</span></button><div class="ss-footer-right"><button type="button" class="ss-cancel">' + __('Cancel', 'smart-schedule') + '</button><button type="button" class="ss-apply" disabled>' + __('Apply', 'smart-schedule') + '</button></div></footer>'
        ].join('');
        document.body.appendChild(overlay);
        if (!inline) { document.body.classList.add('ss-dialog-open'); }
        var expanded = !inline;
        if (!inline) { overlay.classList.add('ss-expanded'); dialog.classList.add('is-advanced-open'); dialog.querySelector('#ss-advanced-area').hidden = false; }
        function positionPopup() {
            if (!inline || !overlay.parentNode || !opts.anchor.isConnected) { return; }
            var rect = opts.anchor.getBoundingClientRect();
            var w = Math.min(window.innerWidth - 24, overlay.classList.contains('ss-expanded') ? 640 : 340);
            overlay.style.width = w + 'px';
            var h = Math.min(window.innerHeight - 24, dialog.getBoundingClientRect().height);
            // Match Gutenberg's left-start dropdown where there is room.
            var x = rect.left - w + rect.width;
            if (x < 12) { x = Math.min(window.innerWidth - w - 12, rect.right - 24); }
            if (x < 12 || x + w > window.innerWidth - 12) { x = Math.max(12, window.innerWidth - w - 12); }
            var spaceBelow = window.innerHeight - rect.bottom - 12;
            var spaceAbove = rect.top - 12;
            var y;
            if (spaceBelow >= h || spaceBelow >= spaceAbove) { y = Math.max(12, Math.min(window.innerHeight - h - 12, rect.bottom + 8)); }
            else { y = Math.max(12, rect.top - h - 8); }
            overlay.style.left = x + 'px';
            overlay.style.top = y + 'px';
        }
        if (inline) { positionPopup(); }
        function outsidePointer(event) {
            if (inline && !overlay.contains(event.target) && !opts.anchor.contains(event.target)) { close(); }
        }
        function reposition() {
            if (!inline) { return; }
            if (!opts.anchor.isConnected) { close(); return; }
            positionPopup();
        }
        var $ = function (sel) { return dialog.querySelector(sel); };
        var dateInput = $('#ss-date'), dayInput = $('#ss-day'), monthInput = $('#ss-month'), yearInput = $('#ss-year'), hourInput = $('#ss-hour'), minuteInput = $('#ss-minute'), meridiem = $('#ss-meridiem'), zoneInput = $('#ss-zone');
        var original = null;
        function close() {
            clearTimeout(state.timer);
            clearTimeout(zoneSearchTimer);
            state.request++;
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            if (!inline) { document.body.classList.remove('ss-dialog-open'); }
            document.removeEventListener('keydown', keyboard);
            document.removeEventListener('pointerdown', outsidePointer, true);
            window.removeEventListener('resize', reposition);
            window.removeEventListener('scroll', reposition, true);
            if (currentInstance === handle) { currentInstance = null; }
            if (typeof opts.onClose === 'function') { opts.onClose(); }
            if (originalFocus && typeof originalFocus.focus === 'function') { originalFocus.focus(); }
        }
        function keyboard(e) {
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            // Inline mode is a non-modal popover: keep Escape and outside-click
            // dismissal but do not trap Tab focus away from the editor.
            if (inline || e.key !== 'Tab') { return; }
            var nodes = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled])')).filter(function(n) { return n.offsetParent !== null; });
            if (!nodes.length) { return; }
            if (e.shiftKey && document.activeElement === nodes[0]) { nodes[nodes.length-1].focus(); e.preventDefault(); }
            else if (!e.shiftKey && document.activeElement === nodes[nodes.length-1]) { nodes[0].focus(); e.preventDefault(); }
        }
        document.addEventListener('keydown', keyboard);
        $('.ss-close').addEventListener('click', close);
        $('.ss-cancel').addEventListener('click', close);
        if (inline) {
            document.addEventListener('pointerdown', outsidePointer, true);
            window.addEventListener('resize', reposition);
            window.addEventListener('scroll', reposition, true);
            $('.ss-advanced-toggle').addEventListener('click', function () {
                expanded = !expanded;
                overlay.classList.toggle('ss-expanded', expanded);
                $('#ss-advanced-area').hidden = !expanded;
                this.setAttribute('aria-expanded', String(expanded));
                this.innerHTML = expanded ? __('Fewer options', 'smart-schedule') + ' <span aria-hidden="true">⌃</span>' : __('Advanced options', 'smart-schedule') + ' <span aria-hidden="true">⌄</span>'; dialog.classList.toggle('is-advanced-open', expanded);
                positionPopup();
            });
        } else { overlay.addEventListener('mousedown', function(e) { if (e.target === overlay) { close(); } }); }
        $('.ss-advanced-toggle').hidden = !inline;
        function error(message) {
            var warning = $('.ss-warning');
            warning.hidden = !message;
            warning.textContent = message || '';
        }
        function isPublished() { return state.status === 'publish' || state.status === 'private'; }
        function canApply(data) { return !!data && data.status === 'valid' && (isPublished() ? !data.future : data.eligible); }
        function setBusy(busy) {
            state.busy = busy;
            $('.ss-apply').disabled = busy || !canApply(state.preview);
            $('.ss-apply').textContent = busy ? __('Applying…', 'smart-schedule') : (isPublished() ? __('Apply date', 'smart-schedule') : __('Apply', 'smart-schedule'));
        }
        function renderClock() {
            Array.prototype.forEach.call(dialog.querySelectorAll('[data-clock]'), function(btn) {
                var on = btn.dataset.clock === state.clock;
                btn.classList.toggle('is-active', on);
                btn.setAttribute('aria-pressed', String(on));
            });
            var h = state.clock === '24' ? state.hour : (state.hour % 12 || 12);
            hourInput.min = state.clock === '24' ? '0' : '1';
            hourInput.max = state.clock === '24' ? '23' : '12';
            hourInput.value = pad(h);
            minuteInput.value = pad(state.minute);
            meridiem.value = state.hour >= 12 ? 'PM' : 'AM';
            $('.ss-meridiem-wrap').hidden = state.clock === '24';
        }
        function focusDay(str) {
            var btn = $('.ss-days').querySelector('button[data-date="' + str + '"]');
            if (btn) { btn.focus({preventScroll:true}); }
        }
        function renderCalendar() {
            var parsed = dateParts(state.date);
            if (!parsed) { return; }
            if (!state.month) { state.month = new Date(Date.UTC(parsed.getUTCFullYear(), parsed.getUTCMonth(), 1)); }
            var year = state.month.getUTCFullYear(), month = state.month.getUTCMonth();
            $('.ss-month-label').textContent = monthNames[month] + ' ' + year;
            var days = $('.ss-days'); days.textContent = '';
            var weekStart = Number(cfg.weekStart) || 0;
            for (var i = 0; i < 7; i++) {
                days.appendChild(elt('span', 'ss-day-name', dayNames[(i + weekStart) % 7]));
            }
            var first = (new Date(Date.UTC(year, month, 1)).getUTCDay() - weekStart + 7) % 7;
            var total = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
            for (var b = 0; b < first; b++) { days.appendChild(elt('span', 'ss-day-empty')); }
            for (var day = 1; day <= total; day++) {
                (function(d) {
                    var str = year + '-' + pad(month + 1) + '-' + pad(d);
                    var selected = str === state.date;
                    var btn = elt('button', 'ss-calendar-day' + (selected ? ' is-selected' : ''), String(d));
                    btn.type = 'button'; btn.setAttribute('aria-label', monthNames[month] + ' ' + d + ', ' + year);
                    btn.setAttribute('aria-pressed', String(selected));
                    btn.setAttribute('data-date', str);
                    // Roving tabindex: only the selected day is in the tab order.
                    btn.tabIndex = selected ? 0 : -1;
                    btn.addEventListener('click', function() { state.date = str; state.choice = ''; refreshInput(); schedule(); });
                    days.appendChild(btn);
                })(day);
            }
            if (!days.querySelector('button[tabindex="0"]')) {
                var firstBtn = days.querySelector('button.ss-calendar-day');
                if (firstBtn) { firstBtn.tabIndex = 0; }
            }
        }
        // Arrow-key navigation across the month grid, including month boundaries.
        $('.ss-days').addEventListener('keydown', function (e) {
            var keys = {ArrowLeft:-1, ArrowRight:1, ArrowUp:-7, ArrowDown:7};
            var btn = e.target.closest ? e.target.closest('button.ss-calendar-day') : null;
            if (!btn) { return; }
            var current = dateParts(btn.getAttribute('data-date'));
            if (!current) { return; }
            var target = null;
            if (keys[e.key] !== undefined) {
                target = new Date(current.getTime() + keys[e.key] * 86400000);
            } else if (e.key === 'Home') {
                target = new Date(current.getTime() - ((current.getUTCDay() - (Number(cfg.weekStart) || 0) + 7) % 7) * 86400000);
            } else if (e.key === 'End') {
                target = new Date(current.getTime() + (6 - (current.getUTCDay() - (Number(cfg.weekStart) || 0) + 7) % 7) * 86400000);
            } else if (e.key === 'PageUp' || e.key === 'PageDown') {
                var delta = e.key === 'PageUp' ? -1 : 1;
                var t = new Date(Date.UTC(current.getUTCFullYear(), current.getUTCMonth() + delta, 1));
                var maxDay = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)).getUTCDate();
                target = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), Math.min(current.getUTCDate(), maxDay)));
            } else { return; }
            e.preventDefault();
            var str = dateFormat(target);
            if (target.getUTCFullYear() !== state.month.getUTCFullYear() || target.getUTCMonth() !== state.month.getUTCMonth()) {
                state.month = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), 1));
                renderCalendar();
            }
            focusDay(str);
        });
        $('.ss-month-today').addEventListener('click', function () {
            state.date = dateFormat(new Date());
            state.choice = '';
            refreshInput(); schedule();
        });
        function refreshInput() {
            dateInput.value = state.date;
            var d = dateParts(state.date);
            if (d) {
                dayInput.value = pad(d.getUTCDate());
                monthInput.value = String(d.getUTCMonth() + 1);
                yearInput.value = String(d.getUTCFullYear());
                state.month = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
            }
            renderClock(); renderCalendar();
        }
        function zoneName(z) {
            if (z === cfg.siteZone) {
                if (cfg.siteFixed) { return __('Website time', 'smart-schedule') + ' (UTC' + z + ')'; }
                return __('Website time', 'smart-schedule') + ' (' + z.split('/').pop().replace(/_/g, ' ') + ')';
            }
            if (z === 'UTC') { return __('UTC (Coordinated Universal Time)', 'smart-schedule'); }
            return z.split('/').pop().replace(/_/g, ' ') + ' (' + z.replace(/_/g,' ') + ')';
        }
        function renderZones(query) {
            var val = state.zone;
            var q = String(query || '').toLowerCase().trim();
            var zones = [cfg.siteZone].concat(cfg.favorites || [], cfg.zones || []);
            if (zones.indexOf('UTC') < 0) { zones.push('UTC'); }
            if (zones.indexOf(val) < 0) { zones.unshift(val); }
            zoneInput.textContent = '';
            var included = {};
            zones.forEach(function(z) {
                if (!z || included[z]) { return; }
                var hay = (z + ' ' + prettyZone(z) + ' ' + zoneName(z) + ' ' + (aliases[z.toLowerCase()] || '')).toLowerCase();
                if (z !== val && q && hay.indexOf(q) < 0) { return; }
                included[z] = true;
                var opt = elt('option', '', zoneName(z));
                opt.value = z; opt.title = z; zoneInput.appendChild(opt);
            });
            zoneInput.value = val;
            var f = $('.ss-favorites'); f.textContent = '';
            (cfg.favorites || []).forEach(function(z) {
                var btn = elt('button', 'ss-fav' + (z === val ? ' is-active' : ''), z === cfg.siteZone ? __('Website', 'smart-schedule') : z.split('/').pop().replace(/_/g, ' '));
                btn.type = 'button'; btn.title = z;
                btn.addEventListener('click', function() { changeZone(z); });
                f.appendChild(btn);
            });
            var favToggle = $('.ss-toggle-fav');
            favToggle.hidden = !cfg.remember;
            favToggle.textContent = (cfg.favorites || []).indexOf(val) >= 0 ? __('★ Remove favourite', 'smart-schedule') : __('☆ Save as favourite', 'smart-schedule');
        }
        var zoneSearchTimer = null;
        function requestData() {
            var h = Number(hourInput.value), m = Number(minuteInput.value);
            var validHour = hourInput.value.trim() !== '' && Number.isInteger(h) && h >= (state.clock === '24' ? 0 : 1) && h <= (state.clock === '24' ? 23 : 12);
            var validMinute = minuteInput.value.trim() !== '' && Number.isInteger(m) && m >= 0 && m <= 59;
            if (!validHour || !validMinute || !dateParts(dateInput.value)) { return null; }
            state.hour = state.clock === '24' ? h : (h % 12 + (meridiem.value === 'PM' ? 12 : 0));
            state.minute = m; state.date = dateInput.value;
            return {date: state.date, time: pad(state.hour) + ':' + pad(state.minute), timezone: state.zone, choice: state.choice, post_id: state.postId};
        }
        function shortTime(row) {
            var hour = Number(row.time.slice(0,2)), minute = row.time.slice(3,5);
            return (state.clock === '24' ? row.time : ((hour % 12) || 12) + ':' + minute + (hour < 12 ? ' AM' : ' PM'));
        }
        function renderPreview(data) {
            var box = $('.ss-preview'), ambig = $('.ss-ambiguity'), compact = $('.ss-compact-conversion');
            box.textContent = ''; ambig.textContent = ''; compact.hidden = true; compact.textContent = '';
            if (!data || data.status !== 'valid') {
                state.preview = null;
                box.appendChild(elt('p', 'ss-preview-placeholder', data && data.message ? data.message : __('Enter a valid date and time.', 'smart-schedule')));
                if (data && data.status === 'ambiguous' && data.choices) {
                    ambig.appendChild(elt('strong', 'ss-ambiguity-label', __('This local time occurs twice. Choose one:', 'smart-schedule')));
                    data.choices.forEach(function(choice) {
                        var label = elt('label', 'ss-choice');
                        var radio = elt('input'); radio.type = 'radio'; radio.name = 'ss-fold'; radio.value = choice.value;
                        radio.checked = state.choice === choice.value;
                        label.appendChild(radio); label.appendChild(document.createTextNode(' ' + choice.label));
                        radio.addEventListener('change', function() { state.choice = choice.value; schedule(0); });
                        ambig.appendChild(label);
                    });
                }
                setBusy(false); return;
            }
            state.preview = data;
            if (state.zone !== cfg.siteZone) {
                compact.hidden = false;
                var dayParts = data.site.date.split('-');
                compact.textContent = __('WordPress will publish this as', 'smart-schedule') + ' ' + monthNames[Number(dayParts[1])-1] + ' ' + Number(dayParts[2]) + ', ' + shortTime(data.site) + ' ' + data.site.abbrev + ' ' + __('on the website.', 'smart-schedule');
            }
            [[__('Entered timezone', 'smart-schedule'), data.selected], [__('Website time', 'smart-schedule'), data.site]].concat(cfg.showUtc ? [['UTC', data.gmt]] : []).forEach(function(row) {
                var item = elt('div', 'ss-preview-row');
                item.appendChild(elt('span', 'ss-preview-name', row[0] + ' · ' + prettyZone(row[1].zone)));
                item.appendChild(elt('strong', 'ss-preview-value', labelTime(row[1], state.clock)));
                box.appendChild(item);
            });
            if (cfg.siteFixed) { box.appendChild(elt('p', 'ss-fixed-notice', __('Your website uses a fixed UTC offset and does not adjust automatically for daylight saving time.', 'smart-schedule'))); }
            if (data.fallback) { box.appendChild(elt('p', 'ss-fixed-notice', __('Offline preview: the server could not be reached. The time will be re-checked when you apply.', 'smart-schedule'))); }
            if (isPublished() && data.future) { error(__('This article is already published. Choose a past date to update its published date; rescheduling it in the future requires returning it to draft.', 'smart-schedule')); }
            else if (!isPublished() && !data.eligible) { error(__('Choose a future time at least', 'smart-schedule') + ' ' + cfg.minLead + ' ' + __('minute(s) from now.', 'smart-schedule')); }
            else { error(''); }
            setBusy(false);
        }
        function schedule(delay) {
            clearTimeout(state.timer);
            var params = requestData();
            var token = ++state.request;
            if (!params) { renderPreview({status:'invalid', message:__('Enter a real date, valid hour and minute.', 'smart-schedule')}); error(''); return; }
            // Keep the Apply control operable while a changed valid input is rechecked.
            // Blurring a focused date field triggers change before the button click;
            // disabling here would silently swallow that click. Apply always revalidates.
            if (!state.preview || state.preview.status !== 'valid') { state.preview = null; }
            setBusy(false);
            $('.ss-preview').textContent = __('Checking time…', 'smart-schedule');
            state.timer = setTimeout(function() {
                api('POST', 'convert', params).then(function(result) {
                    if (token === state.request) { renderPreview(result); }
                }).catch(function(err) {
                    if (token === state.request) { renderPreview({status:'invalid', message: err.message || __('Unable to validate the selected time.', 'smart-schedule')}); error(__('The conversion could not be checked. Check your connection and try again.', 'smart-schedule')); }
                });
            }, delay === undefined ? 180 : delay);
        }
        function applyFormat(result) {
            state.date = result.selected.date;
            state.hour = Number(result.selected.time.slice(0, 2));
            state.minute = Number(result.selected.time.slice(3, 5));
            state.choice = result.choice || '';
            refreshInput(); renderPreview(result);
        }
        function changeZone(next) {
            if (!next || next === state.zone) { return; }
            var previousInstant = state.preview && state.preview.utc;
            state.zone = next; cfg.zone = next; state.choice = '';
            $('#ss-zone-search').value = '';
            renderZones('');
            if (state.policy === 'instant' && previousInstant) {
                var token = ++state.request;
                state.preview = null; setBusy(false);
                $('.ss-preview').textContent = __('Converting the same publishing moment…', 'smart-schedule');
                api('GET', 'format', {utc:previousInstant, timezone:next, post_id:state.postId}).then(function(result) {
                    if (token === state.request) { applyFormat(result); }
                }).catch(function(err) { if (token === state.request) { renderPreview({message:err.message || __('Conversion failed.', 'smart-schedule')}); } });
            } else { schedule(); }
            if (cfg.remember) { api('POST','preferences',{timezone:next}).catch(function() {}); }
        }
        function reset() {
            if (!original) { return; }
            state.zone = original.zone;
            state.clock = original.clock;
            state.date = original.date;
            state.hour = original.hour;
            state.minute = original.minute;
            state.choice = original.choice;
            renderZones(''); refreshInput(); schedule(0);
        }
        dialog.querySelectorAll('[data-clock]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                // Retain the previous valid hour when switching representation.
                var data = requestData();
                if (!data) { error(__('Correct the hour or minute before switching formats.', 'smart-schedule')); return; }
                state.clock = btn.dataset.clock; cfg.clock = state.clock; renderClock();
                if (state.preview) { renderPreview(state.preview); } else { schedule(); }
                if (cfg.remember) { api('POST','preferences',{clock:state.clock}).catch(function() {}); }
            });
        });
        function dateFieldChanged() {
            state.choice = '';
            var year = yearInput.value.trim(), month = monthInput.value, day = dayInput.value.trim();
            var candidate = year.padStart(4, '0') + '-' + pad(month) + '-' + pad(day);
            if (/^\d{4}$/.test(year.padStart(4,'0')) && dateParts(candidate)) {
                state.date = candidate;
                dateInput.value = candidate;
                state.month = new Date(Date.UTC(Number(year), Number(month)-1, 1));
                renderCalendar();
            } else { dateInput.value = ''; }
            schedule();
        }
        [dayInput, monthInput, yearInput].forEach(function(field) {
            field.addEventListener('change', dateFieldChanged);
            field.addEventListener('input', dateFieldChanged);
        });
        [hourInput, minuteInput, meridiem].forEach(function(input) { input.addEventListener('change', function() { state.choice=''; schedule(); }); input.addEventListener('input', function() { state.choice=''; schedule(); }); });
        // Debounce the ~450-option rebuild so typing in the search box stays smooth.
        $('#ss-zone-search').addEventListener('input', function() {
            var q = this.value;
            clearTimeout(zoneSearchTimer);
            zoneSearchTimer = setTimeout(function() { renderZones(q); }, 120);
        });
        zoneInput.addEventListener('change', function() { changeZone(this.value); });
        $('.ss-toggle-fav').addEventListener('click', function() {
            if (!cfg.remember) { return; }
            var favs = (cfg.favorites || []).slice(), idx = favs.indexOf(state.zone);
            if (idx >= 0) { favs.splice(idx, 1); }
            else if (favs.length < 12) { favs.push(state.zone); }
            else { error(__('Only 12 favourite timezones are supported. Remove another favourite first.', 'smart-schedule')); return; }
            cfg.favorites = favs; renderZones('');
            api('POST', 'preferences', {favorites:favs}).catch(function(err) { error(__('Could not save favourites:', 'smart-schedule') + ' ' + (err.message || __('Network unavailable', 'smart-schedule'))); });
        });
        dialog.querySelectorAll('input[name="ss-policy"]').forEach(function(input) { input.addEventListener('change', function() { state.policy = this.value; }); });
        $('.ss-month-prev').addEventListener('click', function() { state.month = new Date(Date.UTC(state.month.getUTCFullYear(),state.month.getUTCMonth()-1,1)); renderCalendar(); });
        $('.ss-month-next').addEventListener('click', function() { state.month = new Date(Date.UTC(state.month.getUTCFullYear(),state.month.getUTCMonth()+1,1)); renderCalendar(); });
        $('.ss-reset').addEventListener('click', reset);
        $('.ss-apply').addEventListener('click', function() {
            var params = requestData();
            if (!params || state.busy) { return; }
            setBusy(true);
            // Re-validate on the server immediately before staging; a previously valid time may have become past.
            api('POST', 'convert', params).then(function(result) {
                if (!canApply(result)) { renderPreview(result); return; }
                return Promise.resolve(opts.onApply && opts.onApply(result)).then(function() { close(); }).catch(function(err) { setBusy(false); error(err && err.message ? err.message : __('Could not stage this schedule.', 'smart-schedule')); });
            }).catch(function(err) { setBusy(false); error(err.message || __('Could not validate the schedule.', 'smart-schedule')); });
        });
        renderZones('');
        // Unscheduled drafts can carry an old last-edited post_date_gmt. Do not
        // start a new schedule on a past date unless editing an existing
        // publication. Preserve dates of future/scheduled posts unchanged.
        var minimumStart = Date.now() + (Number(cfg.minLead) || 0) * 60000;
        var nextSlot = new Date(Math.ceil((minimumStart + 15 * 60000) / (15 * 60000)) * 15 * 60000);
        var candidateUtc = opts.utc && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(opts.utc) ? opts.utc : '';
        var initUtc = candidateUtc && (isPublished() || Date.parse(candidateUtc) > minimumStart)
            ? candidateUtc
            : nextSlot.toISOString().slice(0, 19) + 'Z';
        function initDone(result) {
            if (!overlay.parentNode) { return; }
            applyFormat(result);
            original = {zone:state.zone, clock:state.clock, date:state.date, hour:state.hour, minute:state.minute, choice:state.choice};
        }
        // Local Intl fallback so a REST outage does not leave the editor with
        // no scheduling UI at all. Apply still re-validates on the server.
        function formatLocal(instant, zone) {
            var parts = {};
            new Intl.DateTimeFormat('en-CA', {timeZone:zone, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23'}).formatToParts(instant).forEach(function(p) { if (p.type !== 'literal') { parts[p.type] = p.value; } });
            var date = parts.year + '-' + parts.month + '-' + parts.day, time = parts.hour + ':' + parts.minute;
            var offsetSecs = Math.round((Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second) - instant.getTime()) / 1000);
            var sign = offsetSecs < 0 ? '-' : '+', abs = Math.abs(offsetSecs);
            return {
                date: date, time: time, iso_local: date + 'T' + time + ':00',
                weekday: new Intl.DateTimeFormat('en-US', {timeZone:zone, weekday:'long'}).format(instant),
                abbrev: zone, zone: zone,
                offset: sign + pad(Math.floor(abs / 3600)) + ':' + pad(Math.floor((abs % 3600) / 60)),
                offset_secs: offsetSecs
            };
        }
        function localFallback(utcIso) {
            try {
                var instant = new Date(utcIso);
                if (isNaN(instant.getTime())) { return null; }
                var selected = formatLocal(instant, state.zone);
                return {
                    status: 'valid', choice: '', siteAmbiguous: false, fallback: true,
                    eligible: instant.getTime() > Date.now() + (Number(cfg.minLead) || 0) * 60000,
                    future: instant.getTime() > Date.now(),
                    utc: utcIso, selected: selected,
                    site: formatLocal(instant, cfg.siteZone),
                    gmt: formatLocal(instant, 'UTC')
                };
            } catch (e) { return null; }
        }
        api('GET', 'format', {utc:initUtc, timezone:state.zone, post_id:state.postId}).then(initDone).catch(function() {
            var fallback = localFallback(initUtc);
            if (fallback) { initDone(fallback); }
            else { error(__('Unable to load the initial time. Check your connection and try again.', 'smart-schedule')); }
        });
        if (inline) {
            hourInput.focus({preventScroll:true});
        } else { $('.ss-close').focus(); }
        var handle = {close:close, anchor:opts.anchor || null};
        currentInstance = handle;
        return handle;
    }
    window.SmartScheduleUI = {open:open, formatTime:labelTime, api:api};
})(window, document);