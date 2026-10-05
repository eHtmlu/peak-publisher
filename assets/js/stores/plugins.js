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
        nextWporgCheckAt: 0,   // when to ask the server again (this client's clock, ms); 0 = not asked yet
        wporgChanges: {},      // plugin id → what refreshes found changed on wordpress.org, until told
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
        // The answer of a directory refresh in one step: the figures and check of every marker
        // (stats) and the fresh row of each that moved (plugins) merge into the plugins the
        // store holds — unknown ids are ignored, an answer may name a plugin deleted
        // meanwhile —, what the refresh found changed on wordpress.org adds to what is not
        // told yet, and the next check is due when the server said.
        applyWporgRefresh: function(answer, now) {
            return { type: 'APPLY_WPORG_REFRESH', answer: answer, now: now };
        },
        setNextWporgCheckAt: function(at) {
            return { type: 'SET_NEXT_WPORG_CHECK_AT', at: at };
        },
        clearWporgChanges: function(id) {
            return { type: 'CLEAR_WPORG_CHANGES', id: id };
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
            case 'APPLY_WPORG_REFRESH': {
                var refreshedById = assign({}, state.byId);
                [ action.answer.stats, action.answer.plugins ].forEach(function(fieldsById) {
                    Object.keys(fieldsById).forEach(function(pluginId) {
                        if (refreshedById[pluginId]) refreshedById[pluginId] = assign({}, refreshedById[pluginId], fieldsById[pluginId]);
                    });
                });
                // Counts add up, facts stay: two refreshes before the plugin is looked at tell one story.
                var changesById = assign({}, state.wporgChanges);
                Object.keys(action.answer.changes).forEach(function(pluginId) {
                    var found = action.answer.changes[pluginId];
                    var told = assign({}, changesById[pluginId]);
                    Object.keys(found).forEach(function(fact) {
                        told[fact] = typeof found[fact] === 'number' ? (told[fact] || 0) + found[fact] : found[fact];
                    });
                    changesById[pluginId] = told;
                });
                return assign({}, state, {
                    byId: refreshedById,
                    wporgChanges: changesById,
                    nextWporgCheckAt: action.now + action.answer.next_check_in * 1000,
                });
            }
            case 'SET_NEXT_WPORG_CHECK_AT':
                return assign({}, state, { nextWporgCheckAt: action.at });
            case 'CLEAR_WPORG_CHANGES': {
                if (!state.wporgChanges[action.id]) return state;
                var remaining = assign({}, state.wporgChanges);
                delete remaining[action.id];
                return assign({}, state, { wporgChanges: remaining });
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
        getNextWporgCheckAt: function(state) {
            return state.nextWporgCheckAt;
        },
        // null = nothing to tell
        getWporgChanges: function(state, id) {
            return state.wporgChanges[id] || null;
        },
    };
    registerStore('pblsh/plugins', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    // The directory refreshes in the order they were asked for (see refreshWporg).
    var wporgRefreshes = Promise.resolve();
    async function runWporgRefresh(pluginId) {
        var dispatch = wp.data.dispatch('pblsh/plugins');
        // What this client's copies were made from — the server answers the row of every
        // plugin that moved since, whoever moved it (this refresh, another tab, a commit).
        var known = {};
        wp.data.select('pblsh/plugins').getPlugins().forEach(function(plugin) {
            if (plugin.hosting_type === 'wporg') known[plugin.id] = plugin.wporg_token;
        });
        dispatch.setRefreshingWporg(true);
        try {
            var answer = await window.Pblsh.API.refreshWporg(pluginId, known);
            dispatch.applyWporgRefresh(answer, Date.now());
            return answer;
        } catch (e) {
            // A question the server did not answer is asked again in a minute, not at once.
            dispatch.setNextWporgCheckAt(Date.now() + 60000);
            throw e;
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
                // The directory refresh (refreshWporg) is not triggered here: admin.js asks for it
                // whenever the list is shown, a plugin is opened or the browser tab comes back into
                // view — every look at wordpress.org data, not every load of the list.
            } catch (e) {
                dispatch.setError(e && e.message ? e.message : 'Failed to load plugins');
            } finally {
                dispatch.setLoadingList(false);
            }
        },
        // Brings the wordpress.org plugins up to date — every marker with whatever is due, or
        // one straight against SVN (the editor's Refresh) — and takes the answer into the
        // store (applyWporgRefresh). Resolves to the answer, whose `plugins` are the rows that
        // moved, so a caller can reload what else it holds of them. One at a time: a refresh
        // asked for while another runs starts once that one has settled, so the manual Refresh
        // is never refused and its answer, the later one, is applied last.
        refreshWporg: function(pluginId) {
            var refresh = wporgRefreshes.then(function() {
                return runWporgRefresh(pluginId || null);
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
