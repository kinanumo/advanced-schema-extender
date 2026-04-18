<?php
/**
 * Plugin Name:       Yoast Schema Extender — Agency Pack
 * Plugin URI:        https://kinanumo.com
 * Description:       Extends Yoast SEO's schema graph with Organization enrichment, multi-location LocalBusiness support, and a per-post FAQ builder. Merges with Yoast — never replaces.
 * Version:           2.5.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kendrick Omar Salting
 * Author URI:        https://kinanumo.com
 * License:           Proprietary
 * Text Domain:       yse-agency
 */

defined( 'ABSPATH' ) || exit;

define( 'YSE_VERSION',    '2.5.5' );
define( 'YSE_FILE',       __FILE__ );
define( 'YSE_DIR',        plugin_dir_path( __FILE__ ) );
define( 'YSE_URL',        plugin_dir_url( __FILE__ ) );
define( 'YSE_OPTION_KEY', 'yse_settings' );
define( 'YSE_PAGE_SLUG',  'yse-settings' );

/**
 * Main plugin singleton.
 *
 * Owns hook registration, asset enqueue, admin page render, FAQ meta box,
 * and the schema filter hookup. Schema/settings logic itself is intentionally
 * stubbed in this scaffold — to be implemented in follow-up steps.
 */
final class YSE_Agency_UI {

    /** Singleton instance. */
    private static ?YSE_Agency_UI $instance = null;

    /** Get / create the singleton. */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Wire all hooks. */
    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_yse_import', [ $this, 'handle_import' ] );
        add_action( 'add_meta_boxes',        [ $this, 'register_meta_boxes' ] );
        add_action( 'save_post',             [ $this, 'save_post_meta' ], 10, 2 );

        $this->hook_schema_filters();
    }

    private function __clone() {}
    public function __wakeup() {
        throw new \RuntimeException( 'YSE_Agency_UI cannot be unserialized.' );
    }

    /* ------------------------------------------------------------------ *
     *  Settings registration
     * ------------------------------------------------------------------ */

    public function register_menu(): void {
        add_options_page(
            __( 'Yoast Schema Extender', 'yse-agency' ),
            __( 'Schema Extender', 'yse-agency' ),
            'manage_options',
            YSE_PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting(
            'yse_settings_group',
            YSE_OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => $this->default_settings(),
                'show_in_rest'      => false,
            ]
        );
    }

    public function default_settings(): array {
        return [
            'org'       => [
                'name'        => '',
                'legal_name'  => '',
                'description' => '',
                'url'         => '',
                'logo_url'    => '',
                'logo_id'     => 0,
                'same_as'     => '',
            ],
            'locations' => [],
        ];
    }

    /**
     * Sanitization stub. Real implementation lands in the settings step.
     * Returns the current option on bad input so we never wipe data by accident.
     */
    public function sanitize_settings( $raw ) {
        if ( ! is_array( $raw ) ) {
            return get_option( YSE_OPTION_KEY, $this->default_settings() );
        }
        return $raw;
    }

    /* ------------------------------------------------------------------ *
     *  Asset enqueue (CSS only on whitelisted screens)
     * ------------------------------------------------------------------ */

    public function enqueue_assets( string $hook ): void {
        $allowed = [
            'settings_page_' . YSE_PAGE_SLUG,
            'post.php',
            'post-new.php',
        ];

        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }

        wp_enqueue_style(
            'yse-admin',
            YSE_URL . 'assets/admin.css',
            [],
            YSE_VERSION
        );

        // Media library is needed for the Organization logo picker.
        wp_enqueue_media();

        // Empty handle — all admin JS is attached inline.
        wp_register_script(
            'yse-admin',
            '',
            [ 'jquery' ],
            YSE_VERSION,
            true
        );
        wp_enqueue_script( 'yse-admin' );
        wp_add_inline_script( 'yse-admin', $this->inline_admin_js() );
    }

    /**
     * Inline admin JS bundle. Covers four interactions:
     *   1. Copy export JSON
     *   2. WP Media frame for the Organization logo
     *   3. Add / remove multi-location cards
     *   4. Add / remove FAQ rows in the per-post meta box
     */
    private function inline_admin_js(): string {
        return <<<'JS'
(function($){
    'use strict';

    /* 1. Copy export JSON --------------------------------------------- */
    $(document).on('click', '.yse-copy-export', function(e){
        e.preventDefault();
        var $btn  = $(this);
        var $text = $('#yse-export-json');
        if ( ! $text.length ) { return; }
        $text.trigger('select');
        try {
            document.execCommand('copy');
            var orig = $btn.text();
            $btn.text($btn.data('copied') || 'Copied!');
            setTimeout(function(){ $btn.text(orig); }, 1500);
        } catch (err) {
            window.console && console.warn('YSE copy failed', err);
        }
    });

    /* 2. WP Media — logo picker --------------------------------------- */
    var logoFrame;
    $(document).on('click', '.yse-pick-logo', function(e){
        e.preventDefault();
        var $btn = $(this);
        if ( logoFrame ) { logoFrame.open(); return; }
        if ( typeof wp === 'undefined' || ! wp.media ) { return; }
        logoFrame = wp.media({
            title:    $btn.data('title')  || 'Select organization logo',
            button:   { text: $btn.data('button') || 'Use this image' },
            library:  { type: 'image' },
            multiple: false
        });
        logoFrame.on('select', function(){
            var att = logoFrame.state().get('selection').first().toJSON();
            $('#yse-logo-url').val(att.url);
            $('#yse-logo-id').val(att.id);
            $('#yse-logo-preview').attr('src', att.url).show();
            $('.yse-clear-logo').show();
        });
        logoFrame.open();
    });
    $(document).on('click', '.yse-clear-logo', function(e){
        e.preventDefault();
        $('#yse-logo-url').val('');
        $('#yse-logo-id').val(0);
        $('#yse-logo-preview').attr('src', '').hide();
        $(this).hide();
    });

    /* 3. Multi-location cards ----------------------------------------- */
    $(document).on('click', '.yse-add-location', function(e){
        e.preventDefault();
        var $list = $('#yse-locations-list');
        var tpl   = $('#yse-location-template').html();
        if ( ! tpl ) { return; }
        var idx = $list.children('.yse-location-card').length;
        $list.append( tpl.replace(/__INDEX__/g, idx) );
    });
    $(document).on('click', '.yse-remove-location', function(e){
        e.preventDefault();
        $(this).closest('.yse-location-card').remove();
    });
    $(document).on('click', '.yse-toggle-location', function(e){
        e.preventDefault();
        $(this).closest('.yse-location-card').toggleClass('is-collapsed');
    });

    /* 4. FAQ rows ----------------------------------------------------- */
    $(document).on('click', '.yse-add-faq', function(e){
        e.preventDefault();
        var $list = $('#yse-faq-list');
        var tpl   = $('#yse-faq-template').html();
        if ( ! tpl ) { return; }
        var idx = $list.children('.yse-faq-row').length;
        $list.append( tpl.replace(/__INDEX__/g, idx) );
    });
    $(document).on('click', '.yse-remove-faq', function(e){
        e.preventDefault();
        $(this).closest('.yse-faq-row').remove();
    });

})(jQuery);
JS;
    }

    /* ------------------------------------------------------------------ *
     *  Admin page render (scaffold)
     * ------------------------------------------------------------------ */

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'yse-agency' ) );
        }
        ?>
        <div class="wrap yse-wrap">
            <h1>
                <?php esc_html_e( 'Yoast Schema Extender — Agency Pack', 'yse-agency' ); ?>
                <span class="yse-version">v<?php echo esc_html( YSE_VERSION ); ?></span>
            </h1>
            <p class="description">
                <?php esc_html_e( 'Scaffold loaded. Status table, settings cards, and import/export panel populate in the next build step.', 'yse-agency' ); ?>
            </p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *  Import handler (stub)
     * ------------------------------------------------------------------ */

    public function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'yse-agency' ) );
        }
        check_admin_referer( 'yse_import' );

        wp_safe_redirect(
            add_query_arg(
                [ 'page' => YSE_PAGE_SLUG, 'yse_msg' => 'import_pending' ],
                admin_url( 'options-general.php' )
            )
        );
        exit;
    }

    /* ------------------------------------------------------------------ *
     *  FAQ meta box (scaffold — no fields yet)
     * ------------------------------------------------------------------ */

    public function register_meta_boxes(): void {
        add_meta_box(
            'yse_faq_builder',
            __( 'FAQ Schema Builder', 'yse-agency' ),
            [ $this, 'render_faq_meta_box' ],
            [ 'post', 'page' ],
            'normal',
            'default'
        );
    }

    public function render_faq_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'yse_save_faq_' . $post->ID, '_yse_faq_nonce' );
        ?>
        <p class="description">
            <?php esc_html_e( 'FAQ builder will populate here. Scaffold only.', 'yse-agency' ); ?>
        </p>
        <div id="yse-faq-list"></div>
        <script type="text/template" id="yse-faq-template"></script>
        <p>
            <button type="button" class="button yse-add-faq">
                <?php esc_html_e( '+ Add FAQ Item', 'yse-agency' ); ?>
            </button>
        </p>
        <?php
    }

    public function save_post_meta( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( wp_is_post_revision( $post_id ) )                { return; }
        if ( ! isset( $_POST['_yse_faq_nonce'] ) )            { return; }
        if ( ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['_yse_faq_nonce'] ) ),
                'yse_save_faq_' . $post_id
            ) ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) )    { return; }

        // Real persistence implemented in the FAQ step.
    }

    /* ------------------------------------------------------------------ *
     *  Schema filter hookup (stub — pass-through only)
     * ------------------------------------------------------------------ */

    private function hook_schema_filters(): void {
        add_filter( 'wpseo_schema_graph', [ $this, 'filter_schema_graph' ], 20, 2 );
    }

    /**
     * Pass-through stub. Returns the graph unchanged so Yoast output is
     * preserved while the rest of the plugin is being built.
     */
    public function filter_schema_graph( $graph, $context ) {
        return $graph;
    }
}

/**
 * Bootstrap on plugins_loaded so Yoast SEO has time to register first.
 * If Yoast isn't active we still load — settings remain reachable and
 * the schema filter is a harmless no-op.
 */
function yse_bootstrap(): void {
    if ( is_admin() && ! class_exists( 'WPSEO_Options' ) ) {
        add_action( 'admin_notices', 'yse_yoast_missing_notice' );
    }
    YSE_Agency_UI::instance();
}
add_action( 'plugins_loaded', 'yse_bootstrap' );

function yse_yoast_missing_notice(): void {
    echo '<div class="notice notice-warning"><p><strong>Yoast Schema Extender — Agency Pack</strong> requires <strong>Yoast SEO</strong> to actually output schema. Install or activate Yoast SEO to see merged graph results.</p></div>';
}
