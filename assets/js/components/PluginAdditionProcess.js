// PluginAdditionProcess Component
lodash.set(window, 'Pblsh.Components.PluginAdditionProcess', ({ onCreated } = {}) => {
    const { __ } = wp.i18n;
    const { useState, useEffect, useRef, createElement } = wp.element;
    const { Button } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;
    const hljs = window.hljs;

    const [bootstrapCode, setBootstrapCode] = useState('');
    const [step, setStep] = useState(1);

    const steps = [
        { id: 1, label: __('Header', 'peak-publisher'), description: __('Add required plugin headers', 'peak-publisher') },
        { id: 2, label: __('Code', 'peak-publisher'), description: __('Add the required bootstrap code', 'peak-publisher') },
        { id: 3, label: __('Upload', 'peak-publisher'), description: __('Upload to Peak Publisher', 'peak-publisher') },
    ];

    useEffect(() => {
        loadBootstrapCode();
    }, []);

    useEffect(() => {
        if (step < 3) {
            highlightCode();
        }
    }, [step]);

    const loadBootstrapCode = async () => {
        const response = await Pblsh.API.getBootstrapCode();
        setBootstrapCode(response.code);
    };

    const highlightCode = () => {
        setTimeout(() => {
            if (hljs && typeof hljs.highlightAll === 'function') {
                document.querySelectorAll('pre code[data-highlighted="yes"]').forEach(code => {
                    delete code.dataset.highlighted;
                });
            hljs.highlightAll();
                if (step === 1) {
                    const markColor = 'rgba(255, 0, 0, 0.3)';
                    hljs.highlightLinesAll([
                        [], // Highlight some lines in the first code block.
                        [{start: 2, end: 3, color: markColor},{start: 10, end: 10, color: markColor},], // Highlight some lines in the second code block.
                    ]);
                }
            }
        }, 0);
    };

    const renderStepper = () => {
        return createElement('div', { className: 'pblsh--stepper', role: 'navigation', 'aria-label': __('Setup steps', 'peak-publisher'), style: { '--steps': steps.length, '--step': step } },
            createElement('div', { className: 'pblsh--stepper__bar', 'aria-hidden': 'true' },
                createElement('div', { className: 'pblsh--stepper__bar-fill' }),
            ),
            createElement('ol', { className: 'pblsh--stepper__list' },
                steps.map((s, index) => {
                    const statusClass = s.id < step ? 'is-complete' : (s.id === step ? 'is-active' : 'is-upcoming');
                    const isActive = s.id === step;
                    return createElement('li', { key: s.id, className: 'pblsh--stepper__item ' + statusClass },
                        createElement('button', {
                            type: 'button',
                            className: 'pblsh--stepper__link',
                            'aria-current': isActive ? 'step' : undefined,
                            onClick: () => setStep(s.id),
                        },
                            createElement('span', { className: 'pblsh--stepper__index', 'aria-hidden': 'true' }, String(index + 1)),
                            createElement('span', { className: 'pblsh--stepper__text' },
                                createElement('span', { className: 'pblsh--stepper__label' }, s.label),
                                createElement('span', { className: 'pblsh--stepper__desc' }, s.description),
                            ),
                        ),
                    );
                }),
            ),
        );
    };

    const copyText = async (text) => {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (e) {}
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        return ok;
    };

    const renderHeaderTips = () => {
        const example = [
            '/**',
            ' * Plugin Name: Example Plugin',
            ' * Version: 1.0.0',
            ' * Description: Short description of what it does',
            ' * Author: Your Name',
            ' * Author URI: https://example.com/',
            ' * Requires at least: ' + PblshData.wpVersion.split('.').slice(0, 2).join('.'),
            ' * Tested up to: ' + PblshData.wpVersion.split('.').slice(0, 2).join('.'),
            ' * Requires PHP: ' + PblshData.phpVersion.split('.').slice(0, 2).join('.'),
            ' * Update URI: ' + PblshData.bootstrapUpdateURI,
            ' */',
        ].join('\n');

        return [
            createElement('h3', { className: 'pblsh--card__title' }, __('Required and recommended headers', 'peak-publisher')),
            createElement('p',
                null,
                __('The "Plugin Name," "Version," and "Update URI" headers are required. However, all other headers below are also highly recommended. Adjust the values ​​according to your individual needs.', 'peak-publisher'),
                ' ',
                __('A full list of headers can be found in the WordPress Documentation: ', 'peak-publisher'),
                createElement('a', { href: 'https://developer.wordpress.org/plugins/plugin-basics/header-requirements/', target: '_blank' }, 'https://developer.wordpress.org/plugins/plugin-basics/header-requirements/')
            ),
            createElement('div', { className: 'pblsh--snippet-wrapper' },
                createElement('div', { className: 'pblsh--snippet-toolbar' },
                    createElement('button', {
                        type: 'button',
                        className: 'button pblsh--copy-btn',
                        onClick: () => copyText(example),
                        'aria-label': __('Copy example header', 'peak-publisher'),
                    }, __('Copy', 'peak-publisher')),
                ),
                createElement('pre', null,
                    createElement('code', { className: 'language-plaintext' }, example),
                ),
            ),
            createElement('h3', null, __('3 facts about version numbers', 'peak-publisher')),
            createElement('ul', { className: 'pblsh--ul' },
                createElement('li', null, __('A plugin is never finished, so dare to name the first version of your plugin what it is → 1.0.0', 'peak-publisher')),
                createElement('li', null, __('A good and very common version number convention is the format "X.X.X" (major.minor.patch). Read more about it here: ', 'peak-publisher'), createElement('a', { href: 'https://semver.org/', target: '_blank' }, 'https://semver.org/')),
                createElement('li', null, __('Each integer part of the version number increments independently and can have more than one digit. So feel free to increment 1.9.0 to 1.10.0.', 'peak-publisher')),
            ),
        ];
    };

    const renderStepContent = () => {
        if (step === 1) {
            return createElement('div', { className: 'pblsh--step' },
                createElement('div', { className: 'pblsh--card' },
                    createElement('h3', { className: 'pblsh--card__title' }, __('Add this line to your plugin header', 'peak-publisher')),
                    createElement('p', null, __('This is the API endpoint that your plugin will use to check for updates.', 'peak-publisher')),
                    PblshData.bootstrapUpdateURI.match(/^http\:\/\//) && createElement('div', { className: 'pblsh--addition-process--protocol-warning' }, createElement('strong', null, __('WARNING: Your WordPress site is using an insecure connection. We strongly recommend switching to HTTPS before copying and using this update URI.', 'peak-publisher'))),
                    createElement('div', { className: 'pblsh--snippet-wrapper' },
                        createElement('div', { className: 'pblsh--snippet-toolbar' },
                            createElement('button', {
                                type: 'button',
                                className: 'button pblsh--copy-btn',
                                onClick: () => copyText('Update URI: ' + PblshData.bootstrapUpdateURI),
                                'aria-label': __('Copy Update URI', 'peak-publisher'),
                            }, __('Copy', 'peak-publisher')),
                        ),
                        createElement('pre', null,
                            createElement('code', { className: 'language-plaintext' }, 'Update URI: ' + PblshData.bootstrapUpdateURI),
                        ),
                    ),
                    renderHeaderTips(),
                ),
            );
        }

        if (step === 2) {
            return createElement('div', { className: 'pblsh--step' },
                createElement('div', { className: 'pblsh--card pblsh--card--bootstrap-code' },
                    createElement('div', null,
                        createElement('h3', { className: 'pblsh--card__title' }, __('Add this code to your plugin', 'peak-publisher')),
                        createElement('p', null, __('Add this code to your plugin\'s main file or to any other PHP file within your plugin. Just make sure it executes immediately when the plugin loads, so keep it outside of any additional action or filter hooks. Keep the code as it is, as it is optimized for several requirements.', 'peak-publisher')),
                    ),
                    createElement('div', { className: 'pblsh--snippet-wrapper' },
                        createElement('div', { className: 'pblsh--snippet-toolbar' },
                            createElement('button', {
                                type: 'button',
                                className: 'button pblsh--copy-btn',
                                onClick: () => copyText(bootstrapCode),
                                'aria-label': __('Copy bootstrap code', 'peak-publisher'),
                            }, __('Copy', 'peak-publisher')),
                        ),
                        createElement('pre', null,
                            createElement('code', { className: 'language-php' }, bootstrapCode),
                        ),
                    ),
                ),
            );
        }

        if (step === 3) {
            return createElement('div', { className: 'pblsh--step' },
                createElement('div', { className: 'pblsh--card pblsh--card--upload-zip' },
                    createElement('div', null,
                        createElement('h3', { className: 'pblsh--card__title' }, __('Upload your plugin', 'peak-publisher')),
                    ),
                    createElement('div', {
                        className: 'pblsh--dropzone',
                        onClick: () => {
                            // Delegate opening to global overlay
                            window.dispatchEvent(new CustomEvent('pblsh:open-overlay-file-picker'));
                        },
                        role: 'button',
                        tabIndex: 0,
                        onKeyDown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.dispatchEvent(new CustomEvent('pblsh:open-overlay-file-picker')); } },
                        'aria-label': __('Drop plugin or click to select', 'peak-publisher'),
                    },
                        createElement('div', { className: 'pblsh--dropzone__inner' },
                            createElement('div', { className: 'pblsh--dropzone__icon' }, getSvgIcon('cloud_upload', { size: 32 })),
                            createElement('div', { className: 'pblsh--dropzone__text' }, __('Drop plugin or click to select', 'peak-publisher')),
                            createElement('div', { className: 'pblsh--dropzone__desc' },
                                createElement('p', null, __('You can drop:', 'peak-publisher')),
                                createElement('ul', { className: 'pblsh--ul' },
                                    createElement('li', null, __('a zip file', 'peak-publisher')),
                                    createElement('li', null, __('a plugin folder', 'peak-publisher')),
                                    createElement('li', null, __('files of a plugin folder', 'peak-publisher')),
                                ),
                            ),
                        ),
                    ),
            ),
            );
        }

        return null;
    };

    return createElement('div', { className: 'pblsh--addition-process' },
        renderStepper(),
        renderStepContent(),
        createElement('div', { className: 'pblsh--controls' },
            createElement('div', null,
                step > 1 && createElement(Button, {
                isSecondary: true,
                    onClick: () => setStep(Math.max(1, step - 1)),
                    disabled: step === 1,
            }, __('Previous', 'peak-publisher')),
            ),
            createElement('div', null,
                step < steps.length && createElement(Button, {
                isPrimary: true,
                    onClick: () => setStep(Math.min(steps.length, step + 1)),
                    disabled: step === steps.length,
                }, __('Next', 'peak-publisher')),
            ),
        ),
    );
});