/* global wp, SmartScheduleUI, SmartScheduleData */
/**
 * Gutenberg integration for the existing Publish date row.
 *
 * The editor has no supported SlotFill for replacing PostSchedulePanel's
 * internal Dropdown. Capture the documented component's rendered toggle
 * before React's delegated handler, and open our accessible anchored picker.
 * If WordPress changes the toggle markup, its own control remains intact.
 */
(function (window, document) {
    'use strict';
    if (!window.wp || !wp.data || !window.SmartScheduleUI) { return; }
    var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

    var SELECTOR = '.editor-post-schedule__dialog-toggle';
    var active = null;
    var pending = null;
    var awaitingSave = false;
    var lastSaving = false;
    var warnedMissingToggle = false;

    function editorStore() { return wp.data.select('core/editor'); }
    function utcString(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z?$/.test(value)) { return ''; }
        var clean = value.replace(/\.\d+Z?$/, '');
        return clean.endsWith('Z') ? clean : clean + 'Z';
    }
    function postState() {
        var store = editorStore();
        if (!store || !store.getEditedPostAttribute || !store.getCurrentPostId) { return null; }
        var status = store.getEditedPostAttribute('status') || 'draft';
        var editedUTC = utcString(store.getEditedPostAttribute('date_gmt'));
        var savedUTC = utcString(store.getCurrentPostAttribute('date_gmt'));
        return {
            id: store.getCurrentPostId() || SmartScheduleData.postId || 0,
            status: status,
            utc: (pending && pending.utc) || editedUTC || savedUTC,
            savedUTC: savedUTC
        };
    }
    function notice(level, text) {
        var store = wp.data.dispatch('core/notices');
        if (store && store.createNotice) {
            store.createNotice(level, text, {type:'snackbar', isDismissible:true});
        }
    }
    // The integration relies on Gutenberg's current toggle markup. If a future
    // editor release renames it, fail loudly (console + one admin notice)
    // instead of silently reverting to the native picker.
    function watchToggle() {
        if (warnedMissingToggle || document.querySelector(SELECTOR)) { return; }
        var editorReady = wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId;
        if (!editorReady || !wp.data.select('core/editor').getCurrentPostId()) { return; }
        warnedMissingToggle = true;
        if (window.console && console.warn) { console.warn('Smart Schedule: the Gutenberg publish-date toggle (' + SELECTOR + ') was not found. The native picker remains active; the plugin may need an update for this WordPress version.'); }
        notice('warning', __('Smart Schedule could not find the editor publish-date control. The native WordPress picker is being used instead.', 'smart-schedule'));
    }
    var watchTimer = window.setInterval(watchToggle, 2000);
    window.setTimeout(function () { window.clearInterval(watchTimer); }, 30000);
    function stopNative(event) {
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') { event.stopImmediatePropagation(); }
    }
    function clearActive() { active = null; }
    function openFor(button) {
        var post = postState();
        if (!post) { return false; }
        if (active) {
            var previous = active;
            previous.close();
            active = null;
            if (previous.anchor === button) { return true; }
        }
        active = SmartScheduleUI.open({
            inline: true,
            anchor: button,
            postId: post.id,
            status: post.status,
            utc: post.utc,
            onClose: clearActive,
            onApply: function (result) {
                var dispatch = wp.data.dispatch('core/editor');
                if (!dispatch || !dispatch.editPost) { throw new Error(__('WordPress editor is not ready.', 'smart-schedule')); }
                var latest = postState();
                if (latest && (latest.status === 'publish' || latest.status === 'private') && result.future) {
                    throw new Error(__('Choose a past publication date. To republish at a future date, first move this article to draft.', 'smart-schedule'));
                }
                // The REST intent resolves DST folds when site-local wall time repeats.
                dispatch.editPost({
                    date: result.site.iso_local,
                    date_gmt: result.utc.slice(0, 19),
                    smart_schedule_utc_intent: result.utc
                });
                pending = {utc:result.utc, site:result.site.iso_local};
                awaitingSave = true;
                notice('info', latest && (latest.status === 'publish' || latest.status === 'private') ? __('Publication date staged. Click Update to save the change.', 'smart-schedule') : __('Publishing time staged. Click Schedule or Update to save the post.', 'smart-schedule'));
            }
        });
        active.anchor = button;
        return true;
    }

    // WordPress renders this button dynamically. Delegating in capture phase
    // also covers toggles mounted after the plugin enqueues.
    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest(SELECTOR) : null;
        if (!button || !button.isConnected) { return; }
        var post = postState();
        if (!post) { return; }
        stopNative(event);
        try { openFor(button); }
        catch (err) { notice('error', __('Smart Schedule could not open:', 'smart-schedule') + ' ' + (err.message || __('Unknown error', 'smart-schedule'))); }
    }, true);

    // If a user edits the native date elsewhere after staging, do not let the
    // previous intent silently override that newer change.
    var unsubscribe = wp.data.subscribe(function () {
        var store = editorStore();
        if (!store || !store.getEditedPostAttribute) { return; }
        var editedDate = store.getEditedPostAttribute('date');
        if (pending && editedDate && editedDate.slice(0, 19) !== pending.site) {
            pending = null;
            awaitingSave = false;
            wp.data.dispatch('core/editor').editPost({smart_schedule_utc_intent:''});
        }
        if (!awaitingSave || !store.isSavingPost) { return; }
        var saving = !!store.isSavingPost();
        var autosaving = store.isAutosavingPost && store.isAutosavingPost();
        if (saving && !autosaving) { lastSaving = true; }
        else if (lastSaving && !saving && !autosaving) {
            lastSaving = false;
            var saved = utcString(store.getCurrentPostAttribute('date_gmt'));
            if (saved && pending && Date.parse(saved) === Date.parse(pending.utc)) {
                notice('success', __('Publication date saved and UTC time verified.', 'smart-schedule'));
                pending = null;
                awaitingSave = false;
                // REST's nonpersistent field reads back as empty on the next response.
                // Do not dispatch another edit after a successful save (would re-dirty the post).
            } else {
                notice('warning', __('WordPress saved, but Smart Schedule could not verify the UTC time. Review the post before leaving.', 'smart-schedule'));
            }
        }
    });
    // Keep subscription alive through the editor session; it is disposed on unload.
    window.addEventListener('beforeunload', function () { unsubscribe(); window.clearInterval(watchTimer); }, {once:true});
})(window, document);
