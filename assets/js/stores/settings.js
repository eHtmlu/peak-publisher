/* Settings Store for Peak Publisher */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var initialState = {
        server: null,
        isLoading: false,
        isSaving: false,
        error: null,
        lastFetch: 0,
    };
    var actions = {
        setLoading: function(flag) { return { type: 'SET_LOADING', flag: !!flag }; },
        setSaving: function(flag) { return { type: 'SET_SAVING', flag: !!flag }; },
        setServer: function(obj) { return { type: 'SET_SERVER', obj: obj }; },
        setError: function(message) { return { type: 'SET_ERROR', message: message }; },
    };
    function reducer(state, action) {
        if (!state) state = initialState;
        switch (action.type) {
            case 'SET_LOADING': return assign({}, state, { isLoading: !!action.flag });
            case 'SET_SAVING': return assign({}, state, { isSaving: !!action.flag });
            case 'SET_SERVER': return assign({}, state, { server: action.obj || null, lastFetch: Date.now(), error: null });
            case 'SET_ERROR': return assign({}, state, { error: action.message || 'Error' });
            default: return state;
        }
    }
    var selectors = {
        getServer: function(state) { return state.server; },
        isLoading: function(state) { return !!state.isLoading; },
        isSaving: function(state) { return !!state.isSaving; },
        getError: function(state) { return state.error; },
    };
    registerStore('pblsh/settings', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    window.Pblsh.Controllers.Settings = {
        fetch: async function() {
            var dispatch = wp.data.dispatch('pblsh/settings');
            try {
                dispatch.setLoading(true);
                var obj = await window.Pblsh.API.getSettings();
                dispatch.setServer(obj);
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to load settings');
            } finally {
                dispatch.setLoading(false);
            }
        },
        save: async function(obj) {
            var dispatch = wp.data.dispatch('pblsh/settings');
            try {
                dispatch.setSaving(true);
                var res = await window.Pblsh.API.saveSettings(obj);
                dispatch.setServer(res);
                return res;
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to save settings');
                throw e;
            } finally {
                dispatch.setSaving(false);
            }
        },
    };
})();

