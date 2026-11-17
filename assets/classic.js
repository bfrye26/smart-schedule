/* global SmartScheduleClassic, SmartScheduleUI, SmartScheduleData, jQuery */
/** Replace the native Classic Editor Publish -> Edit timestamp interaction. */
(function (window, document) {
    'use strict';
    if (!window.SmartScheduleUI) { return; }
    var __ = (window.wp && wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
    var info = window.SmartScheduleClassic || {}, $ = window.jQuery;
    var pendingUTC = '', active = null;
    // The page-load status goes stale after an in-page Publish/Save submit, so
    // always read the live status from the form before opening the picker.
    function liveStatus() {
        var field = document.getElementById('post_status');
        if (field && field.value) { return field.value; }
        var original = document.getElementById('original_post_status');
        if (original && original.value) { return original.value; }
        return info.status || 'draft';
    }
    function applySchedule(result) {
        if (result.siteAmbiguous) {
            throw new Error(__('This time repeats in the website timezone. Classic Editor cannot safely preserve the selected occurrence. Choose a different time or use Gutenberg.', 'smart-schedule'));
        }
        var fields = {
            aa: result.site.date.slice(0,4),
            mm: result.site.date.slice(5,7),
            jj: result.site.date.slice(8,10),
            hh: result.site.time.slice(0,2),
            mn: result.site.time.slice(3,5)
        };
        Object.keys(fields).forEach(function (id) {
            if (!document.getElementById(id)) { throw new Error(__('Missing WordPress timestamp field #', 'smart-schedule') + id); }
        });
        var nativeSave = document.querySelector('#timestampdiv .save-timestamp');
        if (!nativeSave) { throw new Error(__('WordPress timestamp Save control is unavailable.', 'smart-schedule')); }
        var form = document.getElementById('post');
        if (!form) { throw new Error(__('WordPress post edit form is unavailable.', 'smart-schedule')); }
        // Flag the date as edited BEFORE triggering the native save handler,
        // which reads edit_date while processing the click.
        var editDate = form.querySelector('input[name="edit_date"]');
        if (!editDate) {
            editDate = document.createElement('input');
            editDate.type = 'hidden'; editDate.name = 'edit_date';
            form.appendChild(editDate);
        }
        editDate.value = 'true';
        Object.keys(fields).forEach(function (id) {
            var input = document.getElementById(id);
            input.value = fields[id];
            $(input).trigger('change');
        });
        var stamp = document.getElementById('timestamp');
        if (stamp) { stamp.checked = true; $(stamp).trigger('change'); }
        $(nativeSave).trigger('click');
        pendingUTC = result.utc;
        var stampDisplay = document.getElementById('timestamp');
        if (stampDisplay && stampDisplay.parentElement) {
            // WordPress itself updates the timestamp display after .save-timestamp.
            stampDisplay.parentElement.setAttribute('data-smart-schedule-staged', 'true');
        }
    }
    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('a.edit-timestamp[href="#edit_timestamp"], a#edit_timestamp') : null;
        if (!button || !button.isConnected || !document.querySelector('#timestampdiv .save-timestamp')) { return; }
        event.preventDefault();
        event.stopPropagation();
        if (event.stopImmediatePropagation) { event.stopImmediatePropagation(); }
        if (active) {
            var previous = active; previous.close(); active = null;
            if (previous.anchor === button) { return; }
        }
        active = SmartScheduleUI.open({
            inline:true,
            anchor:button,
            postId:info.postId || 0,
            status:liveStatus(),
            utc:pendingUTC || info.dateGmt || '',
            onClose:function () { active = null; },
            onApply:applySchedule
        });
    }, true);
})(window, document);