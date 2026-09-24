// PluginAdditionProcess Component - the guided "Add New Plugin" flow.
// The learning surface for first-time and occasional users: channel choice first
// (self-hosted preparation must happen BEFORE the upload), then one action per
// screen. The upload overlay's gate covers everything a dropped file still needs
// (account, import), so this flow carries no account step of its own. The
// wporg path starts with a situation triage — the plugin's standing (new,
// approved, or already published) dictates its whole route.
lodash.set(window, 'Pblsh.Components.PluginAdditionProcess', ({ onCreated, onFinished = () => {}, onStateChange = () => {}, setActiveUploadContext = () => {}, initialChannel = null, initialImport = false } = {}) => {
    const { __, sprintf } = wp.i18n;
    const { useState, useEffect, useRef, createElement, createInterpolateElement, Fragment } = wp.element;
    const { useSelect } = wp.data;
    const { Button } = wp.components;
    const { copyText, getSvgIcon } = Pblsh.Utils;
    const { ChannelChoiceCards, NoticeBox, TipLink, WporgAccountForm, WporgImportTable } = Pblsh.Components;
    const hljs = window.hljs;

    const [bootstrapCode, setBootstrapCode] = useState('');
    const [hostingType, setHostingType] = useState(initialChannel === 'wporg' || initialChannel === 'self_hosted' ? initialChannel : null);
    const [selfHostedStep, setSelfHostedStep] = useState(1);
    const [wporgStep, setWporgStep] = useState(1);
    // The wporg route depends on the plugin's standing (new | approved |
    // published) — asked in a triage before any step renders. The settings'
    // import deep link answers the triage upfront.
    const [wporgSituation, setWporgSituation] = useState(initialChannel === 'wporg' && initialImport ? 'published' : null);
    // Whether this visit's import screen has imported something — earns the
    // affirmative exit to the plugin list.
    const [hasImported, setHasImported] = useState(false);
    // Copy-button feedback, keyed per button: the label itself reports the
    // outcome — success and failure both, no silent copy either way.
    const [copyFeedback, setCopyFeedback] = useState(null); // { key, ok }
    const copyFeedbackTimerRef = useRef(null);
    const settingsFetchRequestedRef = useRef(false);

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);
    const wporgAccount = useSelect((select) => select('pblsh/settings').getUsableWporgAccount(), []);
    const wporgUsername = wporgAccount ? String(wporgAccount.username || '') : '';

    // Labels only — the active step's card title right below says the rest.
    const selfHostedSteps = [
        { id: 1, label: __('Header', 'peak-publisher') },
        { id: 2, label: __('Update URI', 'peak-publisher') },
        { id: 3, label: __('Code', 'peak-publisher') },
        { id: 4, label: __('Upload', 'peak-publisher') },
    ];

    const wporgSteps = [
        { id: 1, label: __('Header', 'peak-publisher') },
        { id: 2, label: __('Upload', 'peak-publisher') },
    ];

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
            }
        }, 0);
    };

    const resetToChannelChoice = () => {
        setHostingType(null);
        setSelfHostedStep(1);
        setWporgStep(1);
        setWporgSituation(null);
        setActiveUploadContext({});
    };

    useEffect(() => {
        loadBootstrapCode();
        return () => window.clearTimeout(copyFeedbackTimerRef.current);
    }, []);

    const handleCopy = async (key, text) => {
        const ok = await copyText(text);
        setCopyFeedback({ key, ok });
        window.clearTimeout(copyFeedbackTimerRef.current);
        copyFeedbackTimerRef.current = window.setTimeout(() => setCopyFeedback(null), 2000);
    };

    const renderCopyButton = (key, text, ariaLabel) => createElement('button', {
        type: 'button',
        className: 'button pblsh--copy-btn',
        onClick: () => handleCopy(key, text),
        'aria-label': ariaLabel,
    },
        copyFeedback?.key === key
            ? (copyFeedback.ok ? __('Copied!', 'peak-publisher') : __('Copy failed', 'peak-publisher'))
            : __('Copy', 'peak-publisher'),
    );

    useEffect(() => {
        if (hostingType === 'self_hosted') {
            setActiveUploadContext({
                hosting_type_intended: 'self_hosted',
            });
            return () => {
                setActiveUploadContext({});
            };
        }

        if (hostingType !== 'wporg') {
            setActiveUploadContext({});
            return () => {};
        }

        // The intent needs no account: a drop without one runs into the overlay's gate,
        // which collects the credentials in place.
        setActiveUploadContext({
            hosting_type_intended: 'wporg',
            ...(wporgUsername ? { wporg_username_intended: wporgUsername } : {}),
        });

        return () => {
            setActiveUploadContext({});
        };
    }, [hostingType, wporgUsername, setActiveUploadContext]);

    useEffect(() => {
        // The snippet steps: Update URI (2) and bootstrap code (3).
        if (hostingType === 'self_hosted' && (selfHostedStep === 2 || selfHostedStep === 3)) {
            highlightCode();
        }
    }, [hostingType, selfHostedStep, bootstrapCode]);

    useEffect(() => {
        if (hostingType === 'wporg' && !serverSettings && !settingsFetchRequestedRef.current) {
            settingsFetchRequestedRef.current = true;
            window.Pblsh.Controllers.Settings.fetch();
        }
    }, [hostingType, serverSettings]);

    // The URL mirrors the wizard's route (channel + import screen): reloads and
    // copied links land where the user actually is, and a consumed seed never
    // lingers as a stale deep link. The seed props only initialize.
    useEffect(() => {
        onStateChange({
            channel: hostingType,
            importOpen: hostingType === 'wporg' && wporgSituation === 'published',
        });
    }, [hostingType, wporgSituation]);

    // Compact wizard stepper — circle + inline label per step; past steps show
    // a check (passed, not verified — every step stays freely clickable).
    const renderStepper = (steps, currentStep, onStepClick) => {
        return createElement('nav', {
            className: 'pblsh--stepper',
            'aria-label': __('Setup steps', 'peak-publisher'),
        },
            createElement('ol', { className: 'pblsh--stepper__list' },
                steps.map((stepItem, index) => {
                    const isComplete = stepItem.id < currentStep;
                    const isActive = stepItem.id === currentStep;
                    const statusClass = isComplete ? 'is-complete' : (isActive ? 'is-active' : 'is-upcoming');
                    return createElement('li', { key: stepItem.id, className: 'pblsh--stepper__item ' + statusClass },
                        createElement('button', {
                            type: 'button',
                            className: 'pblsh--stepper__link',
                            'aria-current': isActive ? 'step' : undefined,
                            onClick: () => onStepClick(stepItem.id),
                        },
                            createElement('span', { className: 'pblsh--stepper__index', 'aria-hidden': 'true' },
                                isComplete ? getSvgIcon('check_bold', { size: 12 }) : String(index + 1)),
                            createElement('span', { className: 'pblsh--stepper__label' }, stepItem.label),
                        ),
                    );
                }),
            ),
            createElement('span', { className: 'pblsh--stepper__count' },
                /* translators: 1: current step number, 2: total number of steps */
                sprintf(__('Step %1$d of %2$d', 'peak-publisher'), currentStep, steps.length)),
        );
    };

    const renderChannelChoice = () => {
        return createElement('div', { className: 'pblsh--step pblsh--hosting-choice' },
            createElement(ChannelChoiceCards, {
                onSelect: setHostingType,
            }),
        );
    };

    const renderDropzone = () => {
        return createElement('div', {
            className: 'pblsh--dropzone',
            onClick: () => {
                window.dispatchEvent(new CustomEvent('pblsh:open-overlay-file-picker'));
            },
            role: 'button',
            tabIndex: 0,
            onKeyDown: (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    window.dispatchEvent(new CustomEvent('pblsh:open-overlay-file-picker'));
                }
            },
            'aria-label': __('Drop plugin or click to select', 'peak-publisher'),
        },
            createElement('div', { className: 'pblsh--dropzone__inner' },
                createElement('div', { className: 'pblsh--dropzone__icon' }, getSvgIcon('cloud_upload', { size: 32 })),
                createElement('div', { className: 'pblsh--dropzone__text' }, __('Drop plugin or click to select', 'peak-publisher')),
                createElement('div', { className: 'pblsh--dropzone__desc' },
                    createElement('p', null, __('You can drop:', 'peak-publisher')),
                    createElement('ul', { className: 'pblsh--content-list pblsh--arrow-list' },
                        createElement('li', null, __('a zip file', 'peak-publisher')),
                        createElement('li', null, __('a plugin folder', 'peak-publisher')),
                        createElement('li', null, __('files of a plugin folder', 'peak-publisher')),
                    ),
                ),
            ),
        );
    };

    // The required-headers rundown — one truth for both flows. The Update URI
    // (self-hosted only) has its own step right after; on wordpress.org it is
    // not needed, because wordpress.org delivers the updates there.
    const renderRequiredHeadersStep = () => {
        return createElement(Fragment, null,
            createElement('h3', { className: 'pblsh--wizard__step-title' }, __('Check the required plugin headers', 'peak-publisher')),
            createElement('p', null, __('Your plugin\'s main file must declare at least the following headers:', 'peak-publisher')),
            createElement('dl', { className: 'pblsh--plugin-headers' },
                createElement('div', { className: 'pblsh--plugin-headers__group' },
                    createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Plugin Name')),
                    createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                        createElement('p', null,
                            __('Certainly already in place.', 'peak-publisher'),
                            createElement('br'),
                            __('Without it, WordPress would not recognize your plugin at all.', 'peak-publisher'),
                        ),
                    ),
                ),
                createElement('div', { className: 'pblsh--plugin-headers__group' },
                    createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Version')),
                    createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                        createElement('p', null,
                            __('The current version of your plugin.', 'peak-publisher'),
                            createElement('br'),
                            __('Every update check builds on it, so make sure it is set.', 'peak-publisher'),
                        ),
                        createElement(TipLink, { tipKey: 'versionNumbers' }),
                    ),
                ),
            ),
            createElement('div', { className: 'pblsh--help-links pblsh--addition-process__tips' },
                createElement(TipLink, { tipKey: 'recommendedHeaders' }),
            ),
        );
    };

    // Self-hosted only: the header that connects the plugin to this site —
    // the sibling of the bootstrap-code step right after it.
    const renderUpdateUriStep = () => {
        return createElement(Fragment, null,
            createElement('h3', { className: 'pblsh--wizard__step-title' }, __('Add the Update URI header', 'peak-publisher')),
            createElement('p', null, createInterpolateElement(
                __('Self-hosted plugins additionally require the <code>Update URI</code> header.', 'peak-publisher'),
                { code: createElement('code') },
            )),
            createElement('p', null, __('It is the API endpoint your plugin will use to check for updates — it connects your plugin to this site.', 'peak-publisher')),
            createElement('p', null, __('Add this line:', 'peak-publisher')),
            PblshData.bootstrapUpdateURI.match(/^http:\/\//) && createElement(NoticeBox, {
                variant: 'warning',
                title: __('Insecure connection', 'peak-publisher'),
            },
                createElement('p', null, __('Your WordPress site runs on plain HTTP, and this URI gets built into every copy of your plugin. Switch to HTTPS before using it — otherwise every update check and download will run unencrypted and open to tampering.', 'peak-publisher')),
            ),
            createElement('div', { className: 'pblsh--snippet-wrapper' },
                createElement('div', { className: 'pblsh--snippet-toolbar' },
                    renderCopyButton('update-uri', 'Update URI: ' + PblshData.bootstrapUpdateURI, __('Copy Update URI', 'peak-publisher')),
                ),
                createElement('pre', null,
                    createElement('code', { className: 'language-plaintext' }, 'Update URI: ' + PblshData.bootstrapUpdateURI),
                ),
            ),
        );
    };

    const renderSelfHostedStepContent = () => {
        if (selfHostedStep === 1) {
            return renderRequiredHeadersStep();
        }

        if (selfHostedStep === 2) {
            return renderUpdateUriStep();
        }

        if (selfHostedStep === 3) {
            return createElement(Fragment, null,
                // One block on purpose: the body is a flex column here (the
                // snippet fills it), and flex children do not collapse margins
                // — ungrouped, heading and intro would drift apart.
                createElement('div', null,
                    createElement('h3', { className: 'pblsh--wizard__step-title' }, __('Add this code to your plugin', 'peak-publisher')),
                    createElement('p', null, __('Add this code to your plugin\'s main file or to any other PHP file within your plugin. Just make sure it executes immediately when the plugin loads, so keep it outside of any additional action or filter hooks. Keep the code as it is, as it is optimized for several requirements.', 'peak-publisher')),
                ),
                createElement('div', { className: 'pblsh--snippet-wrapper' },
                    createElement('div', { className: 'pblsh--snippet-toolbar' },
                        renderCopyButton('bootstrap-code', bootstrapCode, __('Copy bootstrap code', 'peak-publisher')),
                    ),
                    createElement('pre', null,
                        createElement('code', { className: 'language-php' }, bootstrapCode),
                    ),
                ),
            );
        }

        return renderUploadStep();
    };

    const renderWporgApproval = () => {
        // Deliberately a free-standing screen, not a wizard step: submitting is
        // followed by a days-to-weeks review pause, so the remaining steps only
        // make sense once the user returns approved — the closing line routes
        // that return through the triage. One link is enough: the submission
        // page bundles the requirements, the current review queue, and the
        // upload; the extra value lives in the tips.
        return createElement('div', { className: 'pblsh--step' },
            createElement('div', { className: 'pblsh--card' },
                createElement('h3', { className: 'pblsh--card__title' }, __('Get approved on wordpress.org', 'peak-publisher')),
                createElement('p', null, __('Every new plugin needs a one-time review by the WordPress.org plugin team. After that, Peak Publisher handles the rest.', 'peak-publisher')),
                createElement('p', null, __('The submission page has everything in one place — the requirements, the current review queue, and the upload itself:', 'peak-publisher')),
                createElement(Button, {
                    isSecondary: true,
                    href: 'https://wordpress.org/plugins/developers/add/',
                    target: '_blank',
                    rel: 'noreferrer',
                },
                    __('Open the submission page', 'peak-publisher'),
                    createElement('span', { className: 'pblsh--addition-process__approval-external', 'aria-hidden': 'true' }, getSvgIcon('open_in_new', { size: 14 })),
                ),
                createElement('p', null, __('Once the approval mail arrives, come back and choose "Approved, but nothing published yet" — the remaining steps take only minutes.', 'peak-publisher')),
                createElement('div', { className: 'pblsh--help-links pblsh--addition-process__tips' },
                    createElement(TipLink, { tipKey: 'wporgAcceptance' }),
                    createElement(TipLink, { tipKey: 'wporgReview' }),
                ),
            ),
        );
    };

    // The plugin's standing dictates the route entirely, so the wporg path
    // asks for it before any step renders.
    const selectWporgSituation = (situation) => {
        setWporgSituation(situation);
        setWporgStep(1);
        // Every entry into a situation screen is a fresh visit — an earlier
        // visit's import must not already show the affirmative exit.
        setHasImported(false);
    };

    // The icon illustrates the stage of the wordpress.org journey (it is no
    // identity like the channel icons): submission ahead, approval received,
    // already launched.
    const renderWporgSituationOption = (situation, icon, label, description) => {
        return createElement('button', {
            type: 'button',
            className: 'pblsh--option-card pblsh--wporg-situation__option',
            onClick: () => selectWporgSituation(situation),
        },
            createElement('span', { className: 'pblsh--wporg-situation__option-icon', 'aria-hidden': 'true' }, getSvgIcon(icon, { size: 24 })),
            createElement('span', { className: 'pblsh--wporg-situation__option-text' },
                createElement('strong', { className: 'pblsh--wporg-situation__option-label' }, label),
                createElement('span', { className: 'pblsh--wporg-situation__option-desc' }, description),
            ),
            createElement('span', { className: 'pblsh--wporg-situation__option-arrow', 'aria-hidden': 'true' }, getSvgIcon('arrow_right', { size: 20 })),
        );
    };

    const renderWporgSituationChoice = () => {
        return createElement('div', { className: 'pblsh--step' },
            createElement('div', { className: 'pblsh--wporg-situation' },
                createElement('div', { className: 'pblsh--choice-intro' },
                    createElement('h3', { className: 'pblsh--choice-intro__prompt' }, __('Where does your plugin stand on wordpress.org?', 'peak-publisher')),
                    createElement('p', { className: 'pblsh--choice-intro__subtitle' }, __('Your answer decides which steps are still needed.', 'peak-publisher')),
                ),
                createElement('div', { className: 'pblsh--wporg-situation__options' },
                    renderWporgSituationOption('new', 'send', __('Not submitted yet', 'peak-publisher'), __('The plugin still needs the one-time approval by the plugin team.', 'peak-publisher')),
                    renderWporgSituationOption('approved', 'check_bold', __('Approved, but nothing published yet', 'peak-publisher'), __('The approval mail is there — the SVN repository is still empty.', 'peak-publisher')),
                    renderWporgSituationOption('published', 'rocket_launch', __('Already published on wordpress.org', 'peak-publisher'), __('The plugin is live in the directory — import it to manage it here.', 'peak-publisher')),
                ),
            ),
        );
    };

    // The route for already published plugins: no stepper, the import is the
    // whole job.
    const renderWporgImport = () => {
        return createElement('div', { className: 'pblsh--step' },
            createElement('div', { className: 'pblsh--card' },
                createElement('h3', { className: 'pblsh--card__title' }, __('Import your plugins from wordpress.org', 'peak-publisher')),
                wporgUsername
                    ? createElement(WporgImportTable, {
                        onOpenPlugin: (pluginId) => {
                            if (typeof onCreated === 'function') {
                                onCreated(pluginId);
                            }
                        },
                        onImported: () => setHasImported(true),
                    })
                    : createElement(WporgAccountForm),
            ),
        );
    };

    // The final step of both flows — the drop that starts the upload dialog;
    // everything channel-specific happens in there.
    const renderUploadStep = () => {
        return createElement(Fragment, null,
            createElement('h3', { className: 'pblsh--wizard__step-title' }, __('Upload your plugin', 'peak-publisher')),
            renderDropzone(),
        );
    };

    const renderContent = () => {
        if (hostingType === null) {
            return renderChannelChoice();
        }
        if (hostingType === 'self_hosted') {
            return renderSelfHostedStepContent();
        }
        if (wporgSituation === null) {
            return renderWporgSituationChoice();
        }
        if (wporgSituation === 'new') {
            return renderWporgApproval();
        }
        if (wporgSituation === 'published') {
            return renderWporgImport();
        }
        if (wporgStep === 1) {
            return renderRequiredHeadersStep();
        }
        return renderUploadStep();
    };

    const renderControls = () => {
        if (hostingType === null) {
            return null;
        }

        // The wporg triage, approval, and import views sit outside the
        // stepper — their only control is the way back.
        if (hostingType === 'wporg' && wporgSituation !== 'approved') {
            return createElement('div', {
                // On the triage, the back control aligns with the decision column.
                className: 'pblsh--controls' + (wporgSituation === null ? ' pblsh--controls--situation' : ''),
            },
                createElement('div', null,
                    createElement(Button, {
                        isSecondary: true,
                        onClick: wporgSituation === null ? resetToChannelChoice : () => setWporgSituation(null),
                    },
                        createElement('span', { className: 'pblsh--button-icon', 'aria-hidden': 'true' }, getSvgIcon('arrow_back', { size: 16 })),
                        __('Back', 'peak-publisher'),
                    ),
                ),
                // The affirmative exit — appears once this visit has imported
                // something: finishing deserves a door that is labelled neither
                // "Cancel" nor "Back". In-app navigation, so it carries the arrow.
                wporgSituation === 'published' && hasImported ? createElement(Button, {
                    isSecondary: true,
                    onClick: onFinished,
                },
                    __('Go to plugin list', 'peak-publisher'),
                    createElement('span', { className: 'pblsh--button-icon', 'aria-hidden': 'true' }, getSvgIcon('arrow_right', { size: 16 })),
                ) : null,
            );
        }

        const isSelfHosted = hostingType === 'self_hosted';
        const currentStep = isSelfHosted ? selfHostedStep : wporgStep;
        const maxStep = isSelfHosted ? selfHostedSteps.length : wporgSteps.length;
        const setStep = isSelfHosted ? setSelfHostedStep : setWporgStep;

        const previous = () => {
            if (currentStep === 1) {
                if (isSelfHosted) {
                    resetToChannelChoice();
                } else {
                    setWporgSituation(null);
                }
                return;
            }
            setStep(Math.max(1, currentStep - 1));
        };

        const next = () => {
            setStep(Math.min(maxStep, currentStep + 1));
        };

        return createElement('footer', { className: 'pblsh--wizard__footer' },
            createElement(Button, {
                isSecondary: true,
                onClick: previous,
            },
                createElement('span', { className: 'pblsh--button-icon', 'aria-hidden': 'true' }, getSvgIcon('arrow_back', { size: 16 })),
                __('Back', 'peak-publisher'),
            ),
            currentStep < maxStep && createElement(Button, {
                isPrimary: true,
                onClick: next,
            },
                __('Next', 'peak-publisher'),
                createElement('span', { className: 'pblsh--button-icon', 'aria-hidden': 'true' }, getSvgIcon('arrow_right', { size: 16 })),
            ),
        );
    };

    // Steps render inside the wizard panel (stepper header, step body,
    // navigation footer — one card); decision screens render free-standing.
    const isWizard = hostingType === 'self_hosted'
        || (hostingType === 'wporg' && wporgSituation === 'approved');

    return createElement('div', { className: 'pblsh--addition-process' },
        isWizard
            ? createElement('div', { className: 'pblsh--wizard' },
                createElement('header', { className: 'pblsh--wizard__header' },
                    hostingType === 'self_hosted'
                        ? renderStepper(selfHostedSteps, selfHostedStep, setSelfHostedStep)
                        : renderStepper(wporgSteps, wporgStep, setWporgStep),
                ),
                createElement('div', { className: 'pblsh--wizard__body' }, renderContent()),
                renderControls(),
            )
            : createElement(Fragment, null,
                renderContent(),
                renderControls(),
            ),
    );
});
