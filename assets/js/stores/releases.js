/* Releases Store for Peak Publisher */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var initialState = {
        byPluginId: {},
    };
    function ensurePlugin(state, pluginId) {
        var bucket = state.byPluginId[pluginId];
        if (bucket) return bucket;
        bucket = { ids: [], byId: {}, isLoading: false, lastFetch: 0 };
        state.byPluginId[pluginId] = bucket;
        return bucket;
    }
    var actions = {
        setLoading: function(pluginId, flag) {
            return { type: 'SET_LOADING', pluginId: pluginId, flag: !!flag };
        },
        setList: function(pluginId, items) {
            return { type: 'SET_LIST', pluginId: pluginId, items: items };
        },
    };
    function reducer(state, action) {
        if (!state) state = initialState;
        switch (action.type) {
            case 'SET_LOADING': {
                var st = assign({}, state);
                var b = ensurePlugin(st, action.pluginId);
                b.isLoading = !!action.flag;
                return st;
            }
            case 'SET_LIST': {
                var st2 = assign({}, state);
                var b2 = ensurePlugin(st2, action.pluginId);
                var by = {};
                var ids = [];
                (Array.isArray(action.items) ? action.items : []).forEach(function(it) {
                    by[it.id] = it;
                    ids.push(it.id);
                });
                b2.byId = by;
                b2.ids = ids;
                b2.lastFetch = Date.now();
                return st2;
            }
            default:
                return state;
        }
    }
    var selectors = {
        getForPlugin: function(state, pluginId) {
            var b = state.byPluginId[pluginId];
            if (!b) return [];
            return b.ids.map(function(id){ return b.byId[id]; });
        },
        isLoadingForPlugin: function(state, pluginId) {
            var b = state.byPluginId[pluginId];
            return !!(b && b.isLoading);
        },
        hasLoadedForPlugin: function(state, pluginId) {
            var b = state.byPluginId[pluginId];
            return !!(b && b.lastFetch);
        },
    };
    registerStore('pblsh/releases', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    // Which list the store shows, per plugin (Pblsh.Utils.createAnswerOrder()): of two loads
    // the one asked for later is the newer, whichever answers last.
    var order = window.Pblsh.Utils.createAnswerOrder();
    window.Pblsh.Controllers.Releases = {
        // Loads the list; one already there stays visible meanwhile. A failure is the caller's to report.
        fetchForPlugin: async function(pluginId) {
            var dispatch = wp.data.dispatch('pblsh/releases');
            var number = order.take();
            try {
                dispatch.setLoading(pluginId, true);
                var items = await window.Pblsh.API.getPluginReleases(pluginId);
                if (!Array.isArray(items)) items = [];
                if (order.claim(pluginId, number)) dispatch.setList(pluginId, items);
            } finally {
                dispatch.setLoading(pluginId, false);
            }
        },
    };
})();
