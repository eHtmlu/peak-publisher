/* Assets Store for Peak Publisher — the editor's view of a plugin's assets (the server's
   describe() payload), one per plugin id. Loaded when the editor opens and reloaded with the
   plugin (refreshPlugin), like the releases, and set by every write's answer — never by an
   answer older than the one shown; it survives a switch to another tab, so the tab opens
   without a request. Every view shown carries its count of changes not on wordpress.org yet
   into the plugin's entry (assets_pending), so the editor's Assets tab and the list stay
   current without a request for the plugin. Beside it the Live Preview's draft per plugin — JSON typed in its editor
   and not saved yet —, kept and saved by Pblsh.Controllers.BlueprintDraft whether the section
   is shown or not, and dropped with the blueprint it edits; and whether a field of the Live
   Preview is being edited or saved, which the section tells while it is shown — what the
   commit waits for, beside the draft. */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var initialState = {
        byPluginId: {},
        loadingIds: [],
        blueprintDrafts: {},
        blueprintFieldStates: {},   // per plugin, while a field of the Live Preview is being saved: 'saving'
    };
    var actions = {
        setLoading: function(pluginId, flag) {
            return { type: 'SET_LOADING', pluginId: pluginId, flag: !!flag };
        },
        setView: function(pluginId, view) {
            return { type: 'SET_VIEW', pluginId: pluginId, view: view };
        },
        // null drops the draft
        setBlueprintDraft: function(pluginId, draft) {
            return { type: 'SET_BLUEPRINT_DRAFT', pluginId: pluginId, draft: draft };
        },
        // 'saving' while a field of the Live Preview is being saved; null when none is
        setBlueprintFieldState: function(pluginId, fieldState) {
            return { type: 'SET_BLUEPRINT_FIELD_STATE', pluginId: pluginId, fieldState: fieldState };
        },
    };
    function reducer(state, action) {
        if (!state) state = initialState;
        switch (action.type) {
            case 'SET_LOADING': {
                var exists = state.loadingIds.indexOf(action.pluginId) !== -1;
                var next = action.flag ? (exists ? state.loadingIds : state.loadingIds.concat([action.pluginId])) : state.loadingIds.filter(function(x){ return x !== action.pluginId; });
                return assign({}, state, { loadingIds: next });
            }
            case 'SET_VIEW': {
                var byPluginId = assign({}, state.byPluginId);
                byPluginId[action.pluginId] = action.view;
                return assign({}, state, { byPluginId: byPluginId });
            }
            case 'SET_BLUEPRINT_DRAFT': {
                var drafts = assign({}, state.blueprintDrafts);
                if (action.draft) { drafts[action.pluginId] = action.draft; } else { delete drafts[action.pluginId]; }
                return assign({}, state, { blueprintDrafts: drafts });
            }
            case 'SET_BLUEPRINT_FIELD_STATE': {
                if ((state.blueprintFieldStates[action.pluginId] || null) === (action.fieldState || null)) return state;
                var fieldStates = assign({}, state.blueprintFieldStates);
                if (action.fieldState) { fieldStates[action.pluginId] = action.fieldState; } else { delete fieldStates[action.pluginId]; }
                return assign({}, state, { blueprintFieldStates: fieldStates });
            }
            default:
                return state;
        }
    }
    var selectors = {
        // null = not loaded yet
        getForPlugin: function(state, pluginId) {
            return state.byPluginId[pluginId] || null;
        },
        isLoadingForPlugin: function(state, pluginId) {
            return state.loadingIds.indexOf(pluginId) !== -1;
        },
        // { text (null: nothing unsaved, the line tells the last save), saving, line { state, detail } | null, elsewhere } | null
        getBlueprintDraft: function(state, pluginId) {
            return state.blueprintDrafts[pluginId] || null;
        },
        hasUnsavedBlueprintDraft: function(state) {
            return Object.values(state.blueprintDrafts).some(isUnsaved);
        },
        // 'saving' while a field of the plugin's Live Preview is being saved, else null
        getBlueprintFieldState: function(state, pluginId) {
            return state.blueprintFieldStates[pluginId] || null;
        },
    };
    registerStore('pblsh/assets', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    // Which answer the store shows, per plugin (Pblsh.Utils.createAnswerOrder()). A load takes
    // its number when it is asked for, a write when it is answered: its answer is the state
    // after it, newer than any load asked for before. A load that was overtaken, by a later
    // load or by a write answered meanwhile, is not shown.
    var order = window.Pblsh.Utils.createAnswerOrder();
    function show(pluginId, number, view) {
        if (!order.claim(pluginId, number)) return;
        wp.data.dispatch('pblsh/assets').setView(pluginId, view);
        // The plugin's assets_pending is this view's count — the one place every new view passes.
        var plugin = wp.data.select('pblsh/plugins').getById(pluginId);
        if (plugin && view.wporg) wp.data.dispatch('pblsh/plugins').upsert({ id: pluginId, assets_pending: view.wporg.pending_count });
        // The Live Preview's draft edits the blueprint in effect. A view without one — removed
        // here, or in another tab — leaves it nothing to edit: it goes with the blueprint. The
        // empty state has no editor that could show it, and no dot that should count it.
        if (view.blueprint && view.blueprint.contents === null) window.Pblsh.Controllers.BlueprintDraft.drop(pluginId);
    }
    window.Pblsh.Controllers.Assets = {
        // Loads the view; one already there stays visible meanwhile. A failure is the caller's to report.
        fetchForPlugin: async function(pluginId) {
            var dispatch = wp.data.dispatch('pblsh/assets');
            var number = order.take();
            try {
                dispatch.setLoading(pluginId, true);
                show(pluginId, number, await window.Pblsh.API.getPluginAssets(pluginId));
            } finally {
                dispatch.setLoading(pluginId, false);
            }
        },
        // The view a write answered with.
        showAfterWrite: function(pluginId, view) {
            show(pluginId, order.take(), view);
        },
    };

    // The Live Preview's draft: the JSON typed in its editor, kept here so leaving the section
    // loses nothing. A valid draft is saved a second after the typing stops — one save at a time:
    // what is typed meanwhile follows when the answer is in, built on that answer's hash, so the
    // save never runs into itself. An invalid draft waits, said in its line, until it is fixed
    // or undone. drop() starts over from the server's view: answers of saves sent before it are
    // not shown. While a draft is not saved, closing the page asks first.
    var timers = {};
    var generations = {};
    var sending = {};
    var bases = {};
    function draftOf(pluginId) {
        return wp.data.select('pblsh/assets').getBlueprintDraft(pluginId);
    }
    function setDraft(pluginId, patch) {
        var empty = { text: null, saving: false, line: null, elsewhere: null };
        wp.data.dispatch('pblsh/assets').setBlueprintDraft(pluginId, patch === null ? null : assign(empty, draftOf(pluginId), patch));
    }
    function isUnsaved(draft) {
        return !!draft && (draft.text !== null || draft.saving);
    }
    // { ok } or { ok: false, detail } — JSON.parse is the editor's feedback; the server decides.
    function check(text) {
        var parsed;
        try {
            parsed = JSON.parse(text);
        } catch (e) {
            return { ok: false, detail: e.message };
        }
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? { ok: true } : { ok: false, detail: Pblsh.LivePreviewUtils.getNotObjectText() };
    }
    async function save(pluginId) {
        timers[pluginId] = null;
        var draft = draftOf(pluginId);
        if (!draft || draft.text === null || sending[pluginId] || !check(draft.text).ok) return;
        var generation = generations[pluginId] || 0;
        var text = draft.text;
        sending[pluginId] = true;
        setDraft(pluginId, { saving: true });
        try {
            var result = await window.Pblsh.API.changePluginBlueprint(pluginId, { change: 'contents', contents: text, expected: bases[pluginId] });
            if ((generations[pluginId] || 0) !== generation) return;
            sending[pluginId] = false;
            bases[pluginId] = result.assets.blueprint.hash;
            window.Pblsh.Controllers.Assets.showAfterWrite(pluginId, result.assets);
            var now = draftOf(pluginId);
            if (now && now.text === text) {
                setDraft(pluginId, { text: null, saving: false, line: { state: 'saved' } });
            } else {
                setDraft(pluginId, { saving: false });
                if (now && now.text !== null && !timers[pluginId] && check(now.text).ok) save(pluginId);
            }
        } catch (e) {
            if ((generations[pluginId] || 0) !== generation) return;
            sending[pluginId] = false;
            setDraft(pluginId, e && e.code === 'blueprint_changed' && e.assets
                ? { saving: false, line: { state: 'elsewhere' }, elsewhere: e.assets }
                : { saving: false, line: { state: 'refused', detail: (e && e.message) || '' } });
        }
    }
    window.addEventListener('beforeunload', function(event) {
        if (wp.data.select('pblsh/assets').hasUnsavedBlueprintDraft()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.Pblsh.Controllers.BlueprintDraft = {
        // A draft begins with the first keystroke, never through a button, on the view's hash:
        // the one the save builds on.
        edit: function(pluginId, text, viewHash) {
            var draft = draftOf(pluginId);
            if (!draft || draft.text === null) bases[pluginId] = viewHash;
            clearTimeout(timers[pluginId]);
            timers[pluginId] = null;
            var checked = check(text);
            setDraft(pluginId, { text: text, line: checked.ok ? null : { state: 'invalid', detail: checked.detail }, elsewhere: null });
            if (checked.ok) timers[pluginId] = setTimeout(function() { save(pluginId); }, 1000);
        },
        // A valid draft saved now, without waiting for the typing to pause — the section closes.
        flush: function(pluginId) {
            if (!timers[pluginId]) return;
            clearTimeout(timers[pluginId]);
            save(pluginId);
        },
        drop: function(pluginId) {
            clearTimeout(timers[pluginId]);
            timers[pluginId] = null;
            generations[pluginId] = (generations[pluginId] || 0) + 1;
            sending[pluginId] = false;
            setDraft(pluginId, null);
        },
        // The state elsewhere a refused save brought: shown, the own draft given up.
        loadElsewhere: function(pluginId) {
            var draft = draftOf(pluginId);
            if (draft && draft.elsewhere) window.Pblsh.Controllers.Assets.showAfterWrite(pluginId, draft.elsewhere);
            this.drop(pluginId);
        },
        isUnsaved: isUnsaved,
    };
})();
