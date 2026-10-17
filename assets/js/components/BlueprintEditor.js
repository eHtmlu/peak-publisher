// BlueprintEditor Component - the JSON of the Live Preview in WordPress's code editor
// (CodeMirror with JSON lint, PblshData.codeEditor from wp_enqueue_code_editor()), a plain
// textarea when the user switched syntax highlighting off in their profile. It fills the box
// it is given (the caller's positioned box sets the height) and scrolls inside it. Uncontrolled
// while typing: it reports every change, and takes a new value only when that differs from
// what it holds — a value from the server (a field changed the JSON, a reload) replaces it,
// the echo of its own typing does not.
lodash.set(window, 'Pblsh.Components.BlueprintEditor', ({ value, onChange, readOnly = false, placeholder = '', label }) => {
    const { createElement, useEffect, useRef } = wp.element;
    const settings = window.PblshData && window.PblshData.codeEditor;
    const textareaRef = useRef(null);
    const codemirrorRef = useRef(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        if (!settings || !wp.codeEditor || !textareaRef.current) return undefined;
        // An empty editor is not wrong yet — the linter marks the first character, not the
        // placeholder. WordPress ships CodeMirror as wp.CodeMirror, the JSON linter as its helper.
        const json = settings.codemirror.lint && wp.CodeMirror && wp.CodeMirror.lint ? wp.CodeMirror.lint.json : null;
        const lint = json ? { getAnnotations: (text, options, cm) => text.trim() === '' ? [] : (json(text, options, cm) || []) } : settings.codemirror.lint;
        const instance = wp.codeEditor.initialize(textareaRef.current, {
            ...settings,
            codemirror: { ...settings.codemirror, readOnly: !!readOnly, placeholder, lint },
        });
        const codemirror = instance.codemirror;
        codemirrorRef.current = codemirror;
        codemirror.on('change', (editor, change) => {
            if (change.origin !== 'setValue') onChangeRef.current(editor.getValue());
        });
        return () => {
            codemirror.toTextArea();
            codemirrorRef.current = null;
        };
    }, []);

    useEffect(() => {
        const codemirror = codemirrorRef.current;
        if (codemirror && codemirror.getValue() !== value) codemirror.setValue(value);
    }, [value]);

    useEffect(() => {
        if (codemirrorRef.current) codemirrorRef.current.setOption('readOnly', !!readOnly);
    }, [readOnly]);

    return createElement('div', { className: 'pblsh--blueprint-editor' },
        settings
            ? createElement('textarea', { ref: textareaRef, defaultValue: value, placeholder, 'aria-label': label })
            : createElement('textarea', {
                className: 'pblsh--blueprint-editor__textarea',
                value,
                readOnly,
                placeholder,
                spellCheck: false,
                'aria-label': label,
                onChange: (e) => onChange(e.target.value),
            }),
    );
});
