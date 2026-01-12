<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class AdminUI {
    private static $instance = null;

    /**
     * Constructor.
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Initialize the admin class.
     */
    public static function init(): self {
        if (static::$instance === null) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('Peak Publisher', 'peak-publisher'),
            __('Peak Publisher', 'peak-publisher'),
            'manage_options',
            'pblsh-peak-publisher',
            [$this, 'render_peak_publisher'],
            'dashicons-cloud',
            58,
        );
    }

    /**
     * Render Peak Publisher page.
     */
    public function render_peak_publisher(): void {
        echo '<div class="wrap"><div id="pblsh-app" class="pblsh-app"></div></div>';
    }

    /**
     * Enqueue admin scripts.
     */
    public function enqueue_admin_scripts($hook): void {
        if ($hook !== 'toplevel_page_pblsh-peak-publisher' || !current_user_can('manage_options')) {
            return;
        }
        
        // Define all scripts to enqueue (slugs will be auto-generated from file paths)
        $script_files = [
            'utils.js',
            'utils-upload.js',
            'api.js',
            'stores/plugins.js',
            'stores/releases.js',
            'stores/settings.js',
            //'components/ListManager.js',
            //'components/ComboboxControl.js',
            //'components/TriStateCheckboxControl.js',
            'components/PluginList.js',
            //'components/SuccessMessage.js',
            'components/PluginEditor.js',
            'components/Settings.js',
            'components/PluginAdditionProcess.js',
            'components/GlobalDropOverlay.js',
            //'components/ThemeEditorContent/SettingsGeneral.js',
            //'components/ThemeEditorContent/SettingsColor.js',
            //'components/ThemeEditorContent/SettingsTypography.js',
            //'components/ThemeEditorContent/SettingsShadow.js',
            //'components/ThemeEditorContent/SettingsDimensions.js',
            //'components/ThemeEditorContent/SettingsLayout.js',
            //'components/ThemeEditorContent/SettingsBackground.js',
            //'components/ThemeEditorContent/SettingsBorder.js',
            //'components/ThemeEditorContent/SettingsPosition.js',
            //'components/ThemeEditorContent/SettingsSpacing.js',
            'admin.js',
            //'highlightjs/highlight.js',
            //'highlightjs-highlight-lines/highlightjs-highlight-lines.js',
        ];

        //$settings = wp_enqueue_code_editor([ 'type' => 'php' ]);
        /* wp_add_inline_script(
            'code-editor',
            'wp.codeEditor.initialize( document.getElementById("pblsh-code"), ' . wp_json_encode( $settings ) . ' );'
        ); */
        

        wp_enqueue_script(
            'pblsh-highlightjs',
            PBLSH_PLUGIN_URL . 'assets/libs/highlightjs/highlight.js',
            [],
            filemtime(PBLSH_PLUGIN_DIR . 'assets/libs/highlightjs/highlight.js'),
            true
        );
        wp_enqueue_script(
            'pblsh-highlightjs-highlight-lines',
            PBLSH_PLUGIN_URL . 'assets/libs/highlightjs-highlight-lines/highlightjs-highlight-lines.js',
            ['pblsh-highlightjs'],
            filemtime(PBLSH_PLUGIN_DIR . 'assets/libs/highlightjs-highlight-lines/highlightjs-highlight-lines.js'),
            true
        );
        // JSZip for client-side zipping of folders before upload
        wp_enqueue_script(
            'pblsh-jszip',
            PBLSH_PLUGIN_URL . 'assets/libs/jszip/jszip.js',
            [],
            filemtime(PBLSH_PLUGIN_DIR . 'assets/libs/jszip/jszip.js'),
            true
        );
        // Enqueue all scripts
        $previous_handle = null;
        foreach ($script_files as $file) {
            $handle = 'pblsh-' . str_replace(['.js', '/'], ['', '-'], $file);
            wp_enqueue_script(
                $handle,
                PBLSH_PLUGIN_URL . 'assets/js/' . $file,
                $previous_handle ? [$previous_handle] : ['wp-element', 'wp-components', 'wp-i18n', 'wp-data', 'wp-api', 'wp-api-fetch', 'lodash', 'pblsh-highlightjs', 'pblsh-highlightjs-highlight-lines', 'pblsh-jszip'],
                filemtime(PBLSH_PLUGIN_DIR . 'assets/js/' . $file),
                true
            );
            $previous_handle = $handle;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'pblsh-admin',
            PBLSH_PLUGIN_URL . 'assets/css/admin.css',
            ['wp-components'],
            filemtime(PBLSH_PLUGIN_DIR . 'assets/css/admin.css')
        );

        wp_enqueue_style(
            'pblsh-highlightjs',
            PBLSH_PLUGIN_URL . 'assets/libs/highlightjs/styles/atom-one-dark.css',
            [],
            filemtime(PBLSH_PLUGIN_DIR . 'assets/libs/highlightjs/styles/atom-one-dark.css')
        );
        
        wp_localize_script(
            'pblsh-admin',
            'PblshData',
            [
                'bootstrapUpdateURI' => get_update_uri(),
                'wpVersion' => function_exists('wp_get_wp_version') ? wp_get_wp_version() : $GLOBALS['wp_version'],
                'phpVersion' => PHP_VERSION,
            ]
        );
    }
}

AdminUI::init();
