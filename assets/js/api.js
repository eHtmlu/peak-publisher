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
    // Update a release (e.g., status)
    updateRelease: async (id, data) => {
        return await window.Pblsh.API.request('releases/' + id, {
            method: 'PUT',
            body: data,
        });
    },
    // Get code to embed
    getBootstrapCode: async () => {
        return await window.Pblsh.API.request('admin/get-bootstrap-code');
    },
    // Start upload workflow (phase: upload_prepare), with progress callback
    uploadStart: async (file, onProgress, opts) => {
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
            form.append('built_in_browser', opts?.builtInBrowserBy);
            form.append('phase', 'upload_prepare');
            xhr.send(form);
        });
    },
    // Continue upload workflow by phase
    uploadContinue: async (uploadId, phase) => {
        return await window.Pblsh.API.request('admin/upload', {
            method: 'POST',
            body: { upload_id: uploadId, phase },
        });
    },
    // Finalize an uploaded ZIP (create plugin + release)
    finalizeUpload: async (uploadId) => {
        return await window.Pblsh.API.request('admin/upload/finalize', {
            method: 'POST',
            body: { upload_id: uploadId },
        });
    },
    // Discard an uploaded ZIP (cleanup temp data)
    discardUpload: async (uploadId) => {
        return await window.Pblsh.API.request('admin/upload/discard', {
            method: 'POST',
            body: { upload_id: uploadId },
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
});