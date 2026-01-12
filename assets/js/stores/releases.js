/* Releases Store for Peak Publisher */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var initialState = {
        byPluginId: {},
        pendingReleaseIds: [],
        error: null,
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
        upsert: function(pluginId, item) {
            return { type: 'UPSERT', pluginId: pluginId, item: item };
        },
        remove: function(pluginId, id) {
            return { type: 'REMOVE', pluginId: pluginId, id: id };
        },
        setPendingRelease: function(id, flag) {
            return { type: 'SET_PENDING_RELEASE', id: id, flag: !!flag };
        },
        setError: function(message) {
            return { type: 'SET_ERROR', message: message };
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
            case 'UPSERT': {
                var st3 = assign({}, state);
                var b3 = ensurePlugin(st3, action.pluginId);
                var id = action.item && action.item.id;
                if (!id) return state;
                b3.byId[id] = assign({}, b3.byId[id] || {}, action.item);
                if (b3.ids.indexOf(id) === -1) b3.ids.push(id);
                return st3;
            }
            case 'REMOVE': {
                var st4 = assign({}, state);
                var b4 = ensurePlugin(st4, action.pluginId);
                delete b4.byId[action.id];
                b4.ids = b4.ids.filter(function(x){ return x !== action.id; });
                return st4;
            }
            case 'SET_PENDING_RELEASE': {
                var exists = state.pendingReleaseIds.indexOf(action.id) !== -1;
                var next = action.flag ? (exists ? state.pendingReleaseIds : state.pendingReleaseIds.concat([action.id])) : state.pendingReleaseIds.filter(function(x){ return x !== action.id; });
                return assign({}, state, { pendingReleaseIds: next });
            }
            case 'SET_ERROR':
                return assign({}, state, { error: action.message || 'Error' });
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
        getPendingReleaseIds: function(state) {
            return state.pendingReleaseIds.slice();
        },
    };
    registerStore('pblsh/releases', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    window.Pblsh.Controllers.Releases = {
        fetchForPlugin: async function(pluginId) {
            var dispatch = wp.data.dispatch('pblsh/releases');
            try {
                dispatch.setLoading(pluginId, true);
                // The backend returns releases in get_plugin(id)
                var plugin = await window.Pblsh.API.getPlugin(pluginId);
                var items = Array.isArray(plugin && plugin.releases) ? plugin.releases : [];
                dispatch.setList(pluginId, items);
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to load releases');
            } finally {
                dispatch.setLoading(pluginId, false);
            }
        },
        toggleReleaseStatus: async function(pluginId, releaseId, nextStatus) {
            var dispatch = wp.data.dispatch('pblsh/releases');
            try {
                dispatch.setPendingRelease(releaseId, true);
                await window.Pblsh.API.updateRelease(releaseId, { status: nextStatus });
                var sel = wp.data.select('pblsh/releases');
                var list = sel.getForPlugin(pluginId);
                var current = (list || []).find(function(r){ return r.id === releaseId; });
                if (current) dispatch.upsert(pluginId, assign({}, current, { status: nextStatus }));
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to update release');
            } finally {
                dispatch.setPendingRelease(releaseId, false);
            }
        },
        deleteRelease: async function(pluginId, releaseId) {
            var dispatch = wp.data.dispatch('pblsh/releases');
            try {
                dispatch.setPendingRelease(releaseId, true);
                await window.Pblsh.API.deleteRelease(releaseId);
                dispatch.remove(pluginId, releaseId);
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to delete release');
            } finally {
                dispatch.setPendingRelease(releaseId, false);
            }
        },
    };
})();

