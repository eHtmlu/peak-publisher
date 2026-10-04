/* Assets Store for Peak Publisher — the editor's view of a plugin's assets (the server's
   describe() payload), one per plugin id. Loaded when the editor opens and reloaded with the
   plugin (refreshPlugin), like the releases, and set by every write's answer; it survives a
   switch to another tab, so the tab opens without a request. */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var initialState = {
        byPluginId: {},
        loadingIds: [],
    };
    var actions = {
        setLoading: function(pluginId, flag) {
            return { type: 'SET_LOADING', pluginId: pluginId, flag: !!flag };
        },
        setView: function(pluginId, view) {
            return { type: 'SET_VIEW', pluginId: pluginId, view: view };
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
    };
    registerStore('pblsh/assets', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    window.Pblsh.Controllers.Assets = {
        // Loads the view; one already there stays visible meanwhile. A failure is the caller's to report.
        fetchForPlugin: async function(pluginId) {
            var dispatch = wp.data.dispatch('pblsh/assets');
            try {
                dispatch.setLoading(pluginId, true);
                dispatch.setView(pluginId, await window.Pblsh.API.getPluginAssets(pluginId));
            } finally {
                dispatch.setLoading(pluginId, false);
            }
        },
    };
})();
