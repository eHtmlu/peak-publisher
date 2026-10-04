/* Plugins Store for Peak Publisher */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var createSelector = function(fn) { return fn; };
    var initialState = {
        ids: [],
        byId: {},
        isLoadingList: false,
        loadingIds: [],
        pendingIds: [],
        error: null,
        lastFetch: 0,
        isRefreshingWporg: false,
    };
    var actions = {
        setList: function(items) {
            return { type: 'SET_LIST', items: items };
        },
        setLoadingList: function(flag) {
            return { type: 'SET_LOADING_LIST', flag: !!flag };
        },
        upsert: function(item) {
            return { type: 'UPSERT', item: item };
        },
        remove: function(id) {
            return { type: 'REMOVE', id: id };
        },
        setPending: function(id, flag) {
            return { type: 'SET_PENDING', id: id, flag: !!flag };
        },
        setError: function(message) {
            return { type: 'SET_ERROR', message: message };
        },
        // Merges fields into a plugin the store already holds; unknown ids are ignored
        // (a refresh answer may name a plugin deleted meanwhile). `upsert` is for
        // complete items.
        patch: function(id, fields) {
            return { type: 'PATCH', id: id, fields: fields };
        },
        setRefreshingWporg: function(flag) {
            return { type: 'SET_REFRESHING_WPORG_STATS', flag: !!flag };
        },
    };
    function reducer(state, action) {
        if (!state) state = initialState;
        switch (action.type) {
            case 'SET_LIST': {
                // The list refreshes the list fields and drops plugins it no longer names;
                // what the store already knows beyond the list (the detail's wporg_account)
                // stays, so a list refresh behind an open editor does not blank it.
                var map = {};
                var ids = [];
                (Array.isArray(action.items) ? action.items : []).forEach(function(it) {
                    map[it.id] = assign({}, state.byId[it.id] || {}, it);
                    ids.push(it.id);
                });
                return assign({}, state, { ids: ids, byId: map, lastFetch: Date.now(), error: null });
            }
            case 'SET_LOADING_LIST':
                return assign({}, state, { isLoadingList: !!action.flag });
            case 'UPSERT': {
                var id = action.item && action.item.id;
                if (!id) return state;
                var nextById = assign({}, state.byId, (function(){ var o={}; o[id]=assign({}, state.byId[id]||{}, action.item); return o; })());
                var nextIds = state.ids.indexOf(id) === -1 ? state.ids.concat([id]) : state.ids;
                return assign({}, state, { byId: nextById, ids: nextIds });
            }
            case 'REMOVE': {
                var idr = action.id;
                if (!idr) return state;
                var next = assign({}, state.byId);
                delete next[idr];
                return assign({}, state, { byId: next, ids: state.ids.filter(function(x){ return x !== idr; }) });
            }
            case 'SET_PENDING': {
                var exists = state.pendingIds.indexOf(action.id) !== -1;
                var nextPending = action.flag
                    ? (exists ? state.pendingIds : state.pendingIds.concat([action.id]))
                    : state.pendingIds.filter(function(x){ return x !== action.id; });
                return assign({}, state, { pendingIds: nextPending });
            }
            case 'SET_ERROR':
                return assign({}, state, { error: action.message || 'Error' });
            case 'PATCH': {
                if (!state.byId[action.id]) return state;
                var patched = {};
                patched[action.id] = assign({}, state.byId[action.id], action.fields);
                return assign({}, state, { byId: assign({}, state.byId, patched) });
            }
            case 'SET_REFRESHING_WPORG_STATS':
                return assign({}, state, { isRefreshingWporg: !!action.flag });
            default:
                return state;
        }
    }
    var selectors = {
        getPlugins: function(state) {
            return state.ids.map(function(id){ return state.byId[id]; });
        },
        isLoadingList: function(state) {
            return !!state.isLoadingList;
        },
        hasLoadedList: function(state) {
            return !!state.lastFetch;
        },
        getById: function(state, id) {
            return state.byId[id] || null;
        },
        isPending: function(state, id) {
            return state.pendingIds.indexOf(id) !== -1;
        },
        getPendingIds: function(state) {
            return state.pendingIds.slice();
        },
        isRefreshingWporg: function(state) {
            return !!state.isRefreshingWporg;
        },
    };
    registerStore('pblsh/plugins', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    // The directory refreshes in the order they were asked for (see refreshWporg).
    var wporgRefreshes = Promise.resolve();
    async function runWporgRefresh(pluginId, force) {
        var dispatch = wp.data.dispatch('pblsh/plugins');
        dispatch.setRefreshingWporg(true);
        try {
            var response = await window.Pblsh.API.refreshWporg(pluginId, force);
            var stats = response && response.stats ? response.stats : {};
            var plugins = response && response.plugins ? response.plugins : {};
            Object.keys(stats).forEach(function(id) { dispatch.patch(Number(id), stats[id]); });
            Object.keys(plugins).forEach(function(id) { dispatch.patch(Number(id), plugins[id]); });
            return { stats: stats, plugins: plugins };
        } finally {
            dispatch.setRefreshingWporg(false);
        }
    }
    // Controllers (async helpers)
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    window.Pblsh.Controllers.Plugins = {
        fetchList: async function() {
            var dispatch = wp.data.dispatch('pblsh/plugins');
            try {
                dispatch.setLoadingList(true);
                var list = await window.Pblsh.API.getPlugins();
                var items = Array.isArray(list) ? list : [];
                dispatch.setList(items);
                // Stale-while-revalidate: the list renders from the cache, then the server
                // refreshes against the directory whatever is due — the figures once a day per
                // marker, the stamp check every few minutes site-wide, which refreshes a changed
                // plugin against SVN and answers its fresh row — the one trigger for every caller
                // of fetchList. A failed automatic refresh leaves the cached state in place; the
                // editor's Refresh link reports its own failures.
                if (items.some(function(item) { return item.hosting_type === 'wporg'; })) {
                    window.Pblsh.Controllers.Plugins.refreshWporg().catch(function() {});
                }
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to load plugins');
            } finally {
                dispatch.setLoadingList(false);
            }
        },
        // Asks the server for the due directory refresh (every marker, or one; force skips
        // the daily cut-off and the check interval) and patches every marker it touched: the
        // figures, and the fresh row of a plugin that changed on wordpress.org. Resolves to
        // the answer ({ stats, plugins }) so a caller can react to a changed plugin. One at a
        // time: a refresh asked for while another runs starts once that one has settled, so
        // the manual Refresh is never refused and never runs beside the automatic check —
        // two refreshes of one changed plugin would sync its tags side by side.
        refreshWporg: function(pluginId, options) {
            var refresh = wporgRefreshes.then(function() {
                return runWporgRefresh(pluginId || null, !!(options && options.force));
            });
            wporgRefreshes = refresh.catch(function() {});
            return refresh;
        },
        fetchById: async function(id) {
            var dispatch = wp.data.dispatch('pblsh/plugins');
            try {
                dispatch.setPending(id, true);
                var item = await window.Pblsh.API.getPlugin(id);
                if (item && item.id) dispatch.upsert(item);
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to load plugin');
                throw e;
            } finally {
                dispatch.setPending(id, false);
            }
        },
        toggleStatus: async function(id, nextStatus) {
            var dispatch = wp.data.dispatch('pblsh/plugins');
            try {
                dispatch.setPending(id, true);
                await window.Pblsh.API.updatePlugin(id, { status: nextStatus });
                // optimistic: update local
                var current = wp.data.select('pblsh/plugins').getById(id);
                if (current) dispatch.upsert(assign({}, current, { status: nextStatus }));
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to update status');
            } finally {
                dispatch.setPending(id, false);
            }
        },
        delete: async function(id) {
            var dispatch = wp.data.dispatch('pblsh/plugins');
            try {
                dispatch.setPending(id, true);
                await window.Pblsh.API.deletePlugin(id);
                dispatch.remove(id);
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to delete plugin');
            } finally {
                dispatch.setPending(id, false);
            }
        },
    };
})();
