/* wordpress.org accounts store for Peak Publisher — the connected accounts as the server
   holds them (never a password) and whether credential storage works. */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var initialState = {
        accounts: [],
        storage: null,
        loaded: false,
        // Why the list is not there: the error of the last failed fetch, for the hosts
        // that wait for the list to tell and to offer a retry; a fetch that succeeds clears it.
        loadError: null,
        isLoading: false,
        isSaving: false,
    };
    var actions = {
        setLoading: function(flag) { return { type: 'SET_LOADING', flag: !!flag }; },
        setSaving: function(flag) { return { type: 'SET_SAVING', flag: !!flag }; },
        setLoadError: function(error) { return { type: 'SET_LOAD_ERROR', error: error || null }; },
        setServer: function(payload) { return { type: 'SET_SERVER', payload: payload || {} }; },
    };
    function reducer(state, action) {
        if (!state) state = initialState;
        switch (action.type) {
            case 'SET_LOADING': return assign({}, state, { isLoading: !!action.flag });
            case 'SET_SAVING': return assign({}, state, { isSaving: !!action.flag });
            case 'SET_LOAD_ERROR': return assign({}, state, { loadError: action.error });
            case 'SET_SERVER': return assign({}, state, {
                accounts: Array.isArray(action.payload.accounts) ? action.payload.accounts : [],
                storage: action.payload.storage || null,
                loaded: true,
                loadError: null,
            });
            default: return state;
        }
    }
    var selectors = {
        getAccounts: function(state) { return state.accounts; },
        // "Usable" = the account can publish right now: its stored password still
        // decrypts (mirrors get_usable_wporg_account_usernames()).
        getUsableAccount: function(state) {
            return state.accounts.find(function(account) { return account && account.username && account.password_usable; }) || null;
        },
        getStorage: function(state) { return state.storage; },
        isLoaded: function(state) { return !!state.loaded; },
        getLoadError: function(state) { return state.loadError; },
        isLoading: function(state) { return !!state.isLoading; },
        isSaving: function(state) { return !!state.isSaving; },
    };
    registerStore('pblsh/wporg-accounts', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    // Every answer replaces the list. A request that fails rejects with the server's error —
    // the caller reports it; only a failed fetch is kept (loadError), for the hosts that
    // wait for the list.
    window.Pblsh.Controllers.WporgAccounts = {
        fetch: async function() {
            var dispatch = wp.data.dispatch('pblsh/wporg-accounts');
            try {
                dispatch.setLoading(true);
                dispatch.setServer(await window.Pblsh.API.getWporgAccounts());
            } catch (e) {
                dispatch.setLoadError(e);
                throw e;
            } finally {
                dispatch.setLoading(false);
            }
        },
        // Resolves with the server's answer: the list (in the store by then), account_id
        // and spelling — what the save found out about the username's spelling, for the
        // form to hand to its host.
        save: async function(id, body) {
            var dispatch = wp.data.dispatch('pblsh/wporg-accounts');
            try {
                dispatch.setSaving(true);
                var res = await window.Pblsh.API.saveWporgAccount(id, body);
                dispatch.setServer(res);
                return res;
            } finally {
                dispatch.setSaving(false);
            }
        },
        remove: async function(id) {
            var dispatch = wp.data.dispatch('pblsh/wporg-accounts');
            try {
                dispatch.setSaving(true);
                dispatch.setServer(await window.Pblsh.API.deleteWporgAccount(id));
            } finally {
                dispatch.setSaving(false);
            }
        },
        test: async function(id) {
            var dispatch = wp.data.dispatch('pblsh/wporg-accounts');
            try {
                dispatch.setSaving(true);
                dispatch.setServer(await window.Pblsh.API.testWporgAccount(id));
            } catch (e) {
                // A rejected probe is recorded on the account (credentials_rejected): the
                // list must show it although the request failed.
                try { dispatch.setServer(await window.Pblsh.API.getWporgAccounts()); } catch (ignored) {}
                throw e;
            } finally {
                dispatch.setSaving(false);
            }
        },
    };
})();
