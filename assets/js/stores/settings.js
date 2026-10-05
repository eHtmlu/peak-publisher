/* Settings Store for Peak Publisher */
(function() {
    'use strict';
    var registerStore = wp.data.registerStore;
    var assign = Object.assign;
    var initialState = {
        server: null,
        isLoading: false,
        isSaving: false,
        lastFetch: 0,
    };
    var actions = {
        setLoading: function(flag) { return { type: 'SET_LOADING', flag: !!flag }; },
        setSaving: function(flag) { return { type: 'SET_SAVING', flag: !!flag }; },
        setServer: function(obj) { return { type: 'SET_SERVER', obj: obj }; },
    };
    function reducer(state, action) {
        if (!state) state = initialState;
        switch (action.type) {
            case 'SET_LOADING': return assign({}, state, { isLoading: !!action.flag });
            case 'SET_SAVING': return assign({}, state, { isSaving: !!action.flag });
            case 'SET_SERVER': return assign({}, state, { server: action.obj || null, lastFetch: Date.now() });
            default: return state;
        }
    }
    var selectors = {
        getServer: function(state) { return state.server; },
        // "Usable" = the account can publish right now: it has a stored password
        // and that password still decrypts (mirrors get_usable_wporg_account_usernames()).
        getUsableWporgAccount: function(state) {
            var accounts = state.server && Array.isArray(state.server.wporg_accounts) ? state.server.wporg_accounts : [];
            return accounts.find(function(account) {
                return account && account.username && account.has_password && account.password_usable;
            }) || null;
        },
        isLoading: function(state) { return !!state.isLoading; },
        isSaving: function(state) { return !!state.isSaving; },
    };
    registerStore('pblsh/settings', {
        reducer: reducer,
        actions: actions,
        selectors: selectors,
    });
    window.Pblsh = window.Pblsh || {};
    window.Pblsh.Controllers = window.Pblsh.Controllers || {};
    // A request that fails rejects with the server's error — the caller reports it, or decides
    // to stay quiet; the store keeps no error of its own.
    window.Pblsh.Controllers.Settings = {
        fetch: async function() {
            var dispatch = wp.data.dispatch('pblsh/settings');
            try {
                dispatch.setLoading(true);
                var obj = await window.Pblsh.API.getSettings();
                dispatch.setServer(obj);
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
            } finally {
                dispatch.setSaving(false);
            }
        },
    };
})();

