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
    };
    function reducer(state, action) {
        if (!state) state = initialState;
        switch (action.type) {
            case 'SET_LIST': {
                var map = {};
                var ids = [];
                (Array.isArray(action.items) ? action.items : []).forEach(function(it) {
                    map[it.id] = it;
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
    };
    registerStore('pblsh/plugins', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    // Controllers (async helpers)
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    window.Pblsh.Controllers.Plugins = {
        fetchList: async function() {
            var dispatch = wp.data.dispatch('pblsh/plugins');
            try {
                dispatch.setLoadingList(true);
                var list = await window.Pblsh.API.getPlugins();
                dispatch.setList(Array.isArray(list) ? list : []);
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to load plugins');
            } finally {
                dispatch.setLoadingList(false);
            }
        },
        fetchById: async function(id) {
            var dispatch = wp.data.dispatch('pblsh/plugins');
            try {
                dispatch.setPending(id, true);
                var item = await window.Pblsh.API.getPlugin(id);
                if (item && item.id) dispatch.upsert(item);
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to load plugin');
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

