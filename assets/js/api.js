// API functions for Peak Publisher (wp.apiFetch-based)
lodash.set(window, 'Pblsh.API', {
    request: async (path, options) => {
        try {
            const apiFetch = wp.apiFetch;
            const resp = await apiFetch({
                path: 'pblsh-admin/v1/' + path.replace(/^\/+/, ''),
                method: options?.method || 'GET',
                data: options?.body,
                headers: options?.headers || {},
            });
            return resp;
        } catch (error) {
            console.error('Error fetching: ' + (error?.message || String(error)));
            throw error;
        }
    },
    // Get all plugins
    getPlugins: async () => {
        return await window.Pblsh.API.request('plugins');
    },
    // Get a plugin
    getPlugin: async (id) => {
        return await window.Pblsh.API.request('plugins/' + id);
    },
    // Get releases for a plugin
    getPluginReleases: async (id) => {
        return await window.Pblsh.API.request('plugins/' + id + '/releases');
    },
    getWporgDownloadUrl: async (id, version) => {
        return await window.Pblsh.API.request('plugins/' + id + '/wporg-download-url?version=' + encodeURIComponent(version || ''));
    },
    // Update a plugin
    updatePlugin: async (id, plugin) => {
        return await window.Pblsh.API.request('plugins/' + id, {
            method: 'PUT',
            body: plugin
        });
    },
    // Delete a plugin
    deletePlugin: async (id) => {
        return await window.Pblsh.API.request('plugins/' + id, {
            method: 'DELETE'
        });
    },
    // Delete a release
    deleteRelease: async (id) => {
        return await window.Pblsh.API.request('releases/' + id, {
            method: 'DELETE',
        });
    },
    // Make a release the plugin's current release; expected_pointer is the pointer the
    // editor showed, so a flip by someone else in the meantime is refused, not overwritten
    setCurrentRelease: async (pluginId, version, expectedPointer) => {
        return await window.Pblsh.API.request('plugins/' + pluginId + '/current-release', {
            method: 'POST',
            body: { version, expected_pointer: expectedPointer },
        });
    },
    // Fetch the due wordpress.org figures (every marker, or one); force skips the daily
    // cut-off. Answers { stats: { [id]: { installations, wporg_stats } } } for every marker touched.
    refreshWporgStats: async (pluginId = null, force = false) => {
        return await window.Pblsh.API.request('admin/wporg/refresh-stats', {
            method: 'POST',
            body: { plugin_id: pluginId, force },
        });
    },
    // Dismiss the one-time notice about the schema upgrade (includes/upgrade.php)
    dismissUpgradeNotice: async () => {
        return await window.Pblsh.API.request('admin/upgrade-notice', {
            method: 'DELETE',
        });
    },
    // Get code to embed
    getBootstrapCode: async () => {
        return await window.Pblsh.API.request('admin/get-bootstrap-code');
    },
    // Start upload workflow (phase: upload_prepare), with progress callback
    uploadStart: async (file, onProgress, opts = {}) => {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.wpApiSettings.root + 'pblsh-admin/v1/admin/upload');
            xhr.setRequestHeader('X-WP-Nonce', window.wpApiSettings.nonce);
            xhr.responseType = 'json';
            xhr.upload.onprogress = (e) => {
                if (!e.lengthComputable) return;
                const percent = e.loaded * 100 / e.total;
                if (typeof onProgress === 'function') onProgress(percent);
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(xhr.response || {});
                } else {
                    reject(new Error('Upload failed with status ' + xhr.status));
                }
            };
            xhr.onerror = () => reject(new Error('Network error during upload.'));
            const form = new FormData();
            form.append('file', file, file.name);
            form.append('phase', 'upload_prepare');
            form.append('built_in_browser', opts.built_in_browser || '');
            xhr.send(form);
        });
    },
    // Continue upload workflow by phase
    uploadContinue: async (upload_id, phase, body = {}) => {
        return await window.Pblsh.API.request('admin/upload', {
            method: 'POST',
            body: { ...body, upload_id, phase },
        });
    },
    // Finalize an uploaded ZIP (create plugin + release)
    finalizeUpload: async (upload_id, body = {}) => {
        return await window.Pblsh.API.request('admin/upload/finalize', {
            method: 'POST',
            body: { ...body, upload_id },
        });
    },
    // Discard an uploaded ZIP (cleanup temp data)
    discardUpload: async (upload_id) => {
        return await window.Pblsh.API.request('admin/upload/discard', {
            method: 'POST',
            body: { upload_id },
        });
    },
    // Settings
    getSettings: async () => {
        return await window.Pblsh.API.request('admin/settings');
    },
    saveSettings: async (settings) => {
        return await window.Pblsh.API.request('admin/settings', {
            method: 'POST',
            body: settings,
        });
    },
    testSvnCredentials: async (username, password) => {
        return await window.Pblsh.API.request('admin/svn/test-credentials', {
            method: 'POST',
            body: { username, password },
        });
    },
    lookupWporgPlugin: async (username, slug) => {
        return await window.Pblsh.API.request('admin/wporg/lookup-plugin', {
            method: 'POST',
            body: { username, slug },
        });
    },
    discoverWporgPlugins: async (username) => {
        return await window.Pblsh.API.request('admin/wporg/discover-plugins', {
            method: 'POST',
            body: { username },
        });
    },
    importWporgPlugins: async (username, slugs) => {
        return await window.Pblsh.API.request('admin/wporg/import-plugins', {
            method: 'POST',
            body: { username, slugs },
        });
    },
    // Get all assets for a plugin
    getPluginAssets: async (pluginId) => {
        return await window.Pblsh.API.request('plugins/' + pluginId + '/assets');
    },
    // Upload an asset file (slot: icon_128 | icon_256 | icon_svg | banner_sd | banner_hd | banner_svg | screenshot)
    // screenshotN: null = append new screenshot, number = replace specific screenshot
    uploadPluginAsset: async (pluginId, slot, screenshotN, file, onProgress) => {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.wpApiSettings.root + 'pblsh-admin/v1/plugins/' + pluginId + '/assets');
            xhr.setRequestHeader('X-WP-Nonce', window.wpApiSettings.nonce);
            xhr.responseType = 'json';
            xhr.upload.onprogress = (e) => {
                if (!e.lengthComputable) return;
                const percent = e.loaded * 100 / e.total;
                if (typeof onProgress === 'function') onProgress(percent);
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(xhr.response || {});
                } else {
                    // Like apiFetch: the REST error payload (code, message) is the rejection.
                    reject(xhr.response && xhr.response.code ? xhr.response : new Error('Upload failed (status ' + xhr.status + ')'));
                }
            };
            xhr.onerror = () => reject(new Error('Network error during asset upload.'));
            const form = new FormData();
            form.append('file', file, file.name);
            form.append('slot', slot);
            if (screenshotN !== null && screenshotN !== undefined) {
                form.append('screenshot_n', String(screenshotN));
            }
            xhr.send(form);
        });
    },
    // Delete an asset from a plugin slot
    deletePluginAsset: async (pluginId, slot, screenshotN) => {
        return await window.Pblsh.API.request('plugins/' + pluginId + '/assets', {
            method: 'DELETE',
            body: { slot, screenshot_n: screenshotN !== undefined ? screenshotN : null },
        });
    },
    // Move a screenshot to another position — onto an occupied one the server swaps the two
    // and answers mode 'swap'
    moveScreenshot: async (pluginId, fromN, toN) => {
        return await window.Pblsh.API.request('plugins/' + pluginId + '/assets/move', {
            method: 'POST',
            body: { from: fromN, to: toN },
        });
    },
});
