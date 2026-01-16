/* Upload utilities for Peak Publisher (zip creation, directory reading) */
(function() {
    'use strict';
    lodash.set(window, 'Pblsh.UploadUtils', {
        gatherFilesFromItems: async function(items) {
            const entries = [];
            for (let i = 0; i < items.length; i++) {
                const it = items[i];
                const entry = it.webkitGetAsEntry ? it.webkitGetAsEntry() : null;
                if (entry) entries.push(entry);
            }
            const list = [];
            const rootsSet = new Set();
            let hasDirectory = false;
            const readAllEntries = (reader) => {
                return new Promise((resolve) => {
                    const all = [];
                    const pump = () => {
                        reader.readEntries((ents) => {
                            if (ents.length === 0) {
                                resolve(all);
                                return;
                            }
                            for (const it of ents) {
                                all.push(it);
                            }
                            pump();
                        }, () => resolve(all));
                    };
                    pump();
                });
            };
            const traverse = async (entry, path) => {
                if (entry.isFile) {
                    return new Promise((resolve) => {
                        entry.file((file) => {
                            list.push({ file: file, relativePath: path + file.name });
                            const top = (path.split('/').filter(Boolean)[0]) || file.name;
                            if (top) rootsSet.add(top);
                            resolve();
                        }, () => resolve());
                    });
                } else if (entry.isDirectory) {
                    hasDirectory = true;
                    const top = (path.split('/').filter(Boolean)[0]) || entry.name;
                    if (top) rootsSet.add(top);
                    const reader = entry.createReader();
                    const ents = await readAllEntries(reader);
                    for (const e of ents) {
                        await traverse(e, path + entry.name + '/');
                    }
                    return;
                }
            };
            for (const entry of entries) {
                await traverse(entry, '');
            }
            return { list, roots: Array.from(rootsSet), hasDirectory };
        },
        createZipFromFiles: async function(filesWithPaths, onProgress) {
            const JSZip = window.JSZip;
            const zip = new JSZip();
            const rootName = filesWithPaths[0]?.relativePath?.split('/')?.[0] || 'folder';
            const addFilePromises = [];
            for (const item of filesWithPaths) {
                addFilePromises.push(new Promise((resolve) => {
                    const reader = new FileReader();
                    reader.onload = () => {
                        const arrayBuffer = reader.result;
                        const rel = item.relativePath || item.file.name;
                        zip.file(rel, arrayBuffer);
                        resolve();
                    };
                    reader.onerror = () => resolve();
                    reader.readAsArrayBuffer(item.file);
                }));
            }
            await Promise.all(addFilePromises);
            const content = await zip.generateAsync({ type: 'blob' }, (meta) => {
                if (typeof onProgress === 'function') {
                    onProgress(Math.min(100, Math.max(0, meta.percent)));
                }
            });
            return new File([content], (rootName || 'folder') + '.zip', { type: 'application/zip' });
        },
    });
})();

