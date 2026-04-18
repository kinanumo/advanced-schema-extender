<?php
/**
 * Plugin Name:       Yoast Schema Extender — Agency Pack
 * Plugin URI:        https://kinanumo.com
 * Description:       Extends Yoast SEO's schema graph with Organization enrichment, multi-location LocalBusiness support, and a per-post FAQ builder. Merges with Yoast — never replaces (unless explicitly overridden).
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
 */
final class YSE_Agency_UI {

    private static ?YSE_Agency_UI $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

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

    /* ================================================================== *
     *  Settings registration & defaults
     * ================================================================== */

    public function register_menu(): void {
        add_options_page(
            __( 'Yoast Schema Extender', 'yse-agency' ),
            __( 'Schema Extender', 'yse-agency' ),
            'manage_options',
            YSE_PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Per spec: option_group AND option_name are both YSE_OPTION_KEY.
     */
    public function register_settings(): void {
        register_setting(
            YSE_OPTION_KEY,
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
            // Organization
            'org_name'       => '',
            'org_url'        => '',
            'org_logo'       => '',
            'org_email'      => '',
            'telephone'      => '',
            'same_as'        => [],

            // LocalBusiness — single primary node
            'is_local'       => false,
            'lb_subtype'     => '',
            'lb_subtype2'    => '',
            'lb_subtype3'    => '',
            'addr_street'    => '',
            'addr_city'      => '',
            'addr_region'    => '',
            'addr_postal'    => '',
            'addr_country'   => '',
            'geo_lat'        => '',
            'geo_lng'        => '',
            'opening_hours'  => [],
            'service_area'   => [],

            // Multi-location (separate from primary LB)
            'ml_enabled'     => false,
            'ml_locations'   => [],

            // FAQ
            'faq_post_types' => [],

            // Compatibility
            'override_org'   => false,
        ];
    }

    /* ================================================================== *
     *  Sanitization
     * ================================================================== */

    /**
     * Robust sanitizer for the yse_settings option.
     *
     * - Accepts arrays from the settings form, arrays decoded from JSON imports,
     *   or already-sanitized arrays (re-save without form change).
     * - Defends against "Array to string conversion" by routing every textarea-
     *   style field through helpers that accept either string OR array input.
     * - Falls back to existing saved values for fields that fail validation
     *   (e.g. invalid JSON), and surfaces friendly notices via add_settings_error.
     * - Whitelists keys so only the documented v2.5.5 fields are persisted.
     */
    public function sanitize_settings( $raw ): array {
        if ( ! is_array( $raw ) ) {
            $existing = get_option( YSE_OPTION_KEY, $this->default_settings() );
            return is_array( $existing ) ? $existing : $this->default_settings();
        }

        // Existing values are the fallback target for fields that fail validation.
        $existing = get_option( YSE_OPTION_KEY, [] );
        $existing = is_array( $existing ) ? wp_parse_args( $existing, $this->default_settings() ) : $this->default_settings();

        $clean = $this->default_settings();

        // ---- Organization (scalar fields) -----------------------------------
        $clean['org_name']     = sanitize_text_field( (string) ( $raw['org_name']  ?? '' ) );
        $clean['org_url']      = esc_url_raw(         (string) ( $raw['org_url']   ?? '' ) );
        $clean['org_logo']     = esc_url_raw(         (string) ( $raw['org_logo']  ?? '' ) );
        $clean['org_email']    = sanitize_email(      (string) ( $raw['org_email'] ?? '' ) );
        $clean['telephone']    = sanitize_text_field( (string) ( $raw['telephone'] ?? '' ) );

        // ---- Multi-line / array fields --------------------------------------
        $clean['same_as']      = $this->sanitize_lines_as_urls( $raw['same_as']      ?? '' );
        $clean['service_area'] = $this->sanitize_lines_as_text( $raw['service_area'] ?? '' );
        $clean['opening_hours'] = $this->sanitize_json_field(
            $raw['opening_hours'] ?? '',
            $existing['opening_hours'] ?? [],
            'opening_hours',
            __( 'Opening Hours', 'yse-agency' )
        );

        // ---- LocalBusiness toggle + subtypes --------------------------------
        $clean['is_local']     = ! empty( $raw['is_local'] );
        $clean['lb_subtype']   = $this->sanitize_lb_subtype( $raw['lb_subtype']  ?? '' );
        $clean['lb_subtype2']  = $this->sanitize_lb_subtype( $raw['lb_subtype2'] ?? '' );
        $clean['lb_subtype3']  = $this->sanitize_lb_subtype( $raw['lb_subtype3'] ?? '' );

        // ---- Address --------------------------------------------------------
        $clean['addr_street']  = sanitize_text_field( (string) ( $raw['addr_street']  ?? '' ) );
        $clean['addr_city']    = sanitize_text_field( (string) ( $raw['addr_city']    ?? '' ) );
        $clean['addr_region']  = sanitize_text_field( (string) ( $raw['addr_region']  ?? '' ) );
        $clean['addr_postal']  = sanitize_text_field( (string) ( $raw['addr_postal']  ?? '' ) );
        $clean['addr_country'] = sanitize_text_field( (string) ( $raw['addr_country'] ?? '' ) );

        // ---- Geo ------------------------------------------------------------
        $clean['geo_lat']      = $this->sanitize_geo( $raw['geo_lat'] ?? '' );
        $clean['geo_lng']      = $this->sanitize_geo( $raw['geo_lng'] ?? '' );

        // ---- Multi-location -------------------------------------------------
        $clean['ml_enabled']   = ! empty( $raw['ml_enabled'] );
        if ( isset( $raw['ml_locations'] ) && is_array( $raw['ml_locations'] ) ) {
            $clean['ml_locations'] = array_values( array_map(
                [ $this, 'sanitize_location' ],
                $raw['ml_locations']
            ) );
        }

        // ---- FAQ post types -------------------------------------------------
        $clean['faq_post_types'] = $this->sanitize_post_types( $raw['faq_post_types'] ?? [] );

        // ---- Compatibility --------------------------------------------------
        $clean['override_org'] = ! empty( $raw['override_org'] );

        return $clean;
    }

    /**
     * Accept either a textarea string (one URL per line) or an existing array.
     * Returns an array of unique, validated URLs.
     */
    private function sanitize_lines_as_urls( $raw ): array {
        $items = $this->coerce_to_lines( $raw );
        $out   = [];
        foreach ( $items as $item ) {
            $url = esc_url_raw( trim( $item ) );
            if ( '' !== $url && ! in_array( $url, $out, true ) ) {
                $out[] = $url;
            }
        }
        return $out;
    }

    /**
     * Accept either a textarea string (one entry per line) or an existing array.
     * Returns an array of unique cleaned strings.
     */
    private function sanitize_lines_as_text( $raw ): array {
        $items = $this->coerce_to_lines( $raw );
        $out   = [];
        foreach ( $items as $item ) {
            $line = sanitize_text_field( trim( $item ) );
            if ( '' !== $line && ! in_array( $line, $out, true ) ) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Accept either a JSON string or an already-decoded array.
     * On invalid JSON: returns $fallback and registers a helpful settings error.
     *
     * @param mixed  $raw         JSON string or array.
     * @param array  $fallback    Value to keep on parse failure.
     * @param string $field_key   Internal key (used in the settings_error code).
     * @param string $human_label Label shown to the user.
     */
    private function sanitize_json_field( $raw, $fallback, string $field_key, string $human_label ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( ! is_string( $raw ) ) {
            return is_array( $fallback ) ? $fallback : [];
        }
        $raw = trim( $raw );
        if ( '' === $raw ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            $hint = json_last_error_msg();
            add_settings_error(
                YSE_OPTION_KEY,
                'yse_json_invalid_' . $field_key,
                sprintf(
                    /* translators: 1: human label, 2: parser message */
                    __( '%1$s could not be saved: invalid JSON (%2$s). Expected a JSON array, e.g. <code>[{"key":"value"}]</code>. Your previous value was kept.', 'yse-agency' ),
                    esc_html( $human_label ),
                    esc_html( $hint )
                ),
                'error'
            );
            return is_array( $fallback ) ? $fallback : [];
        }

        return $decoded;
    }

    /**
     * Coerce a textarea string OR an array into a flat list of scalar lines.
     * Never produces "Array to string conversion" warnings.
     */
    private function coerce_to_lines( $raw ): array {
        if ( is_array( $raw ) ) {
            $out = [];
            foreach ( $raw as $item ) {
                if ( is_scalar( $item ) ) {
                    $out[] = (string) $item;
                }
            }
            return $out;
        }
        if ( is_string( $raw ) ) {
            return preg_split( '/[\r\n]+/', $raw ) ?: [];
        }
        return [];
    }

    private function sanitize_geo( $raw ): string {
        if ( is_array( $raw ) ) {
            return '';
        }
        $raw = is_string( $raw ) ? trim( $raw ) : (string) $raw;
        if ( '' === $raw || ! is_numeric( $raw ) ) {
            return '';
        }
        return (string) (float) $raw;
    }

    private function sanitize_lb_subtype( $raw ): string {
        if ( is_array( $raw ) ) {
            return '';
        }
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' === $raw ) {
            return '';
        }
        return array_key_exists( $raw, $this->lb_subtypes() ) ? $raw : '';
    }

    private function sanitize_post_types( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $valid = array_keys( get_post_types( [ 'public' => true ] ) );
        $out   = [];
        foreach ( $raw as $slug ) {
            if ( ! is_string( $slug ) ) {
                continue;
            }
            $slug = sanitize_key( $slug );
            if ( '' !== $slug && in_array( $slug, $valid, true ) && ! in_array( $slug, $out, true ) ) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    public function sanitize_location( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return $this->blank_location();
        }
        return [
            'name'          => sanitize_text_field( (string) ( $raw['name']       ?? '' ) ),
            'enabled'       => ! empty( $raw['enabled'] ),
            'page_slug'     => sanitize_title(       (string) ( $raw['page_slug'] ?? '' ) ),
            'url'           => esc_url_raw(           (string) ( $raw['url']       ?? '' ) ),
            'image'         => esc_url_raw(           (string) ( $raw['image']     ?? '' ) ),
            'telephone'     => sanitize_text_field(  (string) ( $raw['telephone'] ?? '' ) ),
            'email'         => sanitize_email(       (string) ( $raw['email']      ?? '' ) ),
            'priceRange'    => sanitize_text_field(  (string) ( $raw['priceRange'] ?? '' ) ),
            'lb_subtype'    => $this->sanitize_lb_subtype( $raw['lb_subtype']  ?? '' ),
            'lb_subtype2'   => $this->sanitize_lb_subtype( $raw['lb_subtype2'] ?? '' ),
            'lb_subtype3'   => $this->sanitize_lb_subtype( $raw['lb_subtype3'] ?? '' ),
            'addr_street'   => sanitize_text_field( (string) ( $raw['addr_street']  ?? '' ) ),
            'addr_city'     => sanitize_text_field( (string) ( $raw['addr_city']    ?? '' ) ),
            'addr_region'   => sanitize_text_field( (string) ( $raw['addr_region']  ?? '' ) ),
            'addr_postal'   => sanitize_text_field( (string) ( $raw['addr_postal']  ?? '' ) ),
            'addr_country'  => sanitize_text_field( (string) ( $raw['addr_country'] ?? '' ) ),
            'geo_lat'       => $this->sanitize_geo( $raw['geo_lat'] ?? '' ),
            'geo_lng'       => $this->sanitize_geo( $raw['geo_lng'] ?? '' ),
            'service_area'  => $this->sanitize_lines_as_text( $raw['service_area']  ?? '' ),
            'opening_hours' => $this->sanitize_json_field(
                $raw['opening_hours'] ?? '',
                [],
                'location_opening_hours',
                __( 'Location Opening Hours', 'yse-agency' )
            ),
        ];
    }

    /* ================================================================== *
     *  Vocabularies / blank shapes
     * ================================================================== */

    public function blank_location(): array {
        return [
            'name'          => '',
            'enabled'       => true,
            'page_slug'     => '',
            'url'           => '',
            'image'         => '',
            'telephone'     => '',
            'email'         => '',
            'priceRange'    => '',
            'lb_subtype'    => '',
            'lb_subtype2'   => '',
            'lb_subtype3'   => '',
            'addr_street'   => '',
            'addr_city'     => '',
            'addr_region'   => '',
            'addr_postal'   => '',
            'addr_country'  => '',
            'geo_lat'       => '',
            'geo_lng'       => '',
            'service_area'  => [],
            'opening_hours' => [],
        ];
    }

    public function lb_subtypes(): array {
        return [
            'AnimalShelter'               => 'Animal Shelter',
            'AutoDealer'                  => 'Auto Dealer',
            'AutoRepair'                  => 'Auto Repair',
            'AutomotiveBusiness'          => 'Automotive Business',
            'Bakery'                      => 'Bakery',
            'BarOrPub'                    => 'Bar / Pub',
            'BeautySalon'                 => 'Beauty Salon',
            'ChildCare'                   => 'Child Care',
            'Dentist'                     => 'Dentist',
            'DryCleaningOrLaundry'        => 'Dry Cleaning / Laundry',
            'Electrician'                 => 'Electrician',
            'EmploymentAgency'            => 'Employment Agency',
            'EntertainmentBusiness'       => 'Entertainment Business',
            'FinancialService'            => 'Financial Service',
            'FoodEstablishment'           => 'Food Establishment',
            'GeneralContractor'           => 'General Contractor',
            'GroceryStore'                => 'Grocery Store',
            'HVACBusiness'                => 'HVAC Business',
            'HairSalon'                   => 'Hair Salon',
            'HealthAndBeautyBusiness'     => 'Health & Beauty Business',
            'HomeAndConstructionBusiness' => 'Home & Construction Business',
            'Hotel'                       => 'Hotel',
            'HousePainter'                => 'House Painter',
            'LegalService'                => 'Legal Service',
            'Library'                     => 'Library',
            'Locksmith'                   => 'Locksmith',
            'LodgingBusiness'             => 'Lodging Business',
            'MedicalBusiness'             => 'Medical Business',
            'MovingCompany'               => 'Moving Company',
            'Physician'                   => 'Physician',
            'Plumber'                     => 'Plumber',
            'ProfessionalService'         => 'Professional Service',
            'RealEstateAgent'             => 'Real Estate Agent',
            'Restaurant'                  => 'Restaurant',
            'RoofingContractor'           => 'Roofing Contractor',
            'SelfStorage'                 => 'Self Storage',
            'ShoppingCenter'              => 'Shopping Center',
            'SportsActivityLocation'      => 'Sports Activity Location',
            'Store'                       => 'Store',
            'TattooParlor'                => 'Tattoo Parlor',
            'TaxiService'                 => 'Taxi Service',
            'TouristInformationCenter'    => 'Tourist Information Center',
            'TravelAgency'                => 'Travel Agency',
            'VeterinaryCare'              => 'Veterinary Care',
        ];
    }

    /* ================================================================== *
     *  Display coercion helpers
     * ================================================================== */

    /** Turn an array (or string fallback) into a textarea-ready newline string. */
    private function lines_to_text( $value ): string {
        if ( is_array( $value ) ) {
            $items = [];
            foreach ( $value as $v ) {
                if ( is_scalar( $v ) ) {
                    $items[] = (string) $v;
                }
            }
            return implode( "\n", $items );
        }
        return is_string( $value ) ? $value : '';
    }

    /** Pretty-print an array as JSON for display in a textarea. */
    private function array_to_pretty_json( $value ): string {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                $value = $decoded;
            } else {
                return $value;
            }
        }
        if ( ! is_array( $value ) || empty( $value ) ) {
            return '';
        }
        $json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? $json : '';
    }

    /* ================================================================== *
     *  Asset enqueue
     * ================================================================== */

    public function enqueue_assets( string $hook ): void {
        $allowed = [
            'settings_page_' . YSE_PAGE_SLUG,
            'post.php',
            'post-new.php',
        ];
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }

        wp_enqueue_style( 'yse-admin', YSE_URL . 'assets/admin.css', [], YSE_VERSION );

        wp_enqueue_media();

        wp_register_script( 'yse-admin', '', [ 'jquery' ], YSE_VERSION, true );
        wp_enqueue_script( 'yse-admin' );
        wp_add_inline_script( 'yse-admin', $this->inline_admin_js() );
    }

    private function inline_admin_js(): string {
        return <<<'JS'
(function($){
    'use strict';

    /* 1. Copy export JSON */
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

    /* 2. WP Media — logo picker */
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
            $('#yse-logo-preview').attr('src', att.url).show();
            $('.yse-clear-logo').show();
        });
        logoFrame.open();
    });
    $(document).on('click', '.yse-clear-logo', function(e){
        e.preventDefault();
        $('#yse-logo-url').val('');
        $('#yse-logo-preview').attr('src', '').hide();
        $(this).hide();
    });

    /* 3. Multi-location cards */
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

    /* 4. FAQ rows */
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

    /* ================================================================== *
     *  Settings page render
     * ================================================================== */

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'yse-agency' ) );
        }

        // Surface post-redirect import status as a settings_error.
        if ( isset( $_GET['yse_import'] ) ) {
            $this->register_status_message( sanitize_key( wp_unslash( $_GET['yse_import'] ) ) );
        }

        $settings = wp_parse_args(
            (array) get_option( YSE_OPTION_KEY, [] ),
            $this->default_settings()
        );
        ?>
        <div class="wrap yse-wrap">
            <h1>
                <?php esc_html_e( 'Yoast Schema Extender — Agency Pack', 'yse-agency' ); ?>
                <span class="yse-version">v<?php echo esc_html( YSE_VERSION ); ?></span>
            </h1>

            <?php settings_errors(); ?>

            <?php $this->render_status_table( $settings ); ?>

            <h2 class="yse-section-h2"><?php esc_html_e( 'Organization & LocalBusiness', 'yse-agency' ); ?></h2>

            <form method="post" action="options.php">
                <?php settings_fields( YSE_OPTION_KEY ); ?>

                <div class="yse-card">
                    <div class="yse-card-body">
                        <p class="description">
                            <?php esc_html_e( 'These fields enrich Yoast’s Organization schema node. Empty fields are skipped — Yoast’s value (if any) passes through untouched. Toggle “Treat as LocalBusiness” to add address, hours, and geo data.', 'yse-agency' ); ?>
                        </p>

                        <?php $this->render_org_fields( $settings ); ?>

                        <h2 class="yse-section-h2 yse-section-h2--inner">
                            <?php esc_html_e( 'Multiple Locations (Optional)', 'yse-agency' ); ?>
                        </h2>
                        <?php $this->render_locations_section( $settings ); ?>

                        <h2 class="yse-section-h2 yse-section-h2--inner">
                            <?php esc_html_e( 'FAQ Builder (Per-Post)', 'yse-agency' ); ?>
                        </h2>
                        <?php $this->render_faq_settings( $settings ); ?>

                        <h2 class="yse-section-h2 yse-section-h2--inner">
                            <?php esc_html_e( 'Compatibility', 'yse-agency' ); ?>
                        </h2>
                        <?php $this->render_compat_fields( $settings ); ?>

                        <?php submit_button( __( 'Save Settings', 'yse-agency' ) ); ?>
                    </div>
                </div>
            </form>

            <?php $this->render_import_export_panel( $settings ); ?>
        </div>
        <?php
    }

    /* ------------------------ Status table ---------------------------- */

    /**
     * Build status rows comparing approximate Yoast/site defaults to Extender values.
     *
     * Yoast values are read directly from the wpseo_titles option and home_url(),
     * not from Yoast's runtime helpers — that means the table reflects the values
     * Yoast *would* normally publish if Extender were uninstalled.
     */
    private function build_status_rows( array $s ): array {
        $yoast_titles = get_option( 'wpseo_titles', [] );
        $yoast_titles = is_array( $yoast_titles ) ? $yoast_titles : [];

        $rows = [
            [
                'label'    => __( 'Organization Name', 'yse-agency' ),
                'yoast'    => (string) ( $yoast_titles['company_name'] ?? '' ),
                'extender' => (string) ( $s['org_name'] ?? '' ),
            ],
            [
                'label'    => __( 'Website URL', 'yse-agency' ),
                'yoast'    => (string) home_url(),
                'extender' => (string) ( $s['org_url'] ?? '' ),
            ],
            [
                'label'    => __( 'Logo', 'yse-agency' ),
                'yoast'    => (string) ( $yoast_titles['company_logo'] ?? '' ),
                'extender' => (string) ( $s['org_logo'] ?? '' ),
            ],
            [
                'label'    => __( 'Contact Email', 'yse-agency' ),
                'yoast'    => '',
                'extender' => (string) ( $s['org_email'] ?? '' ),
            ],
            [
                'label'    => __( 'Telephone', 'yse-agency' ),
                'yoast'    => '',
                'extender' => (string) ( $s['telephone'] ?? '' ),
            ],
            [
                'label'    => __( 'Schema Type', 'yse-agency' ),
                'yoast'    => __( 'Organization', 'yse-agency' ),
                'extender' => $this->describe_schema_type( $s ),
            ],
            [
                'label'    => __( 'Address', 'yse-agency' ),
                'yoast'    => '',
                'extender' => $this->describe_address( $s ),
            ],
            [
                'label'    => __( 'Additional Locations', 'yse-agency' ),
                'yoast'    => '',
                'extender' => $this->describe_locations( $s ),
            ],
        ];

        $override = ! empty( $s['override_org'] );

        foreach ( $rows as &$row ) {
            $y = trim( (string) $row['yoast'] );
            $e = trim( (string) $row['extender'] );

            if ( $override ) {
                // Replace mode: Yoast is suppressed, Extender is the only voice.
                $row['effective'] = $e;
                $row['source']    = ( '' !== $e ) ? 'extender' : '';
            } elseif ( '' !== $e && '' !== $y ) {
                $row['effective'] = $e;
                $row['source']    = 'merged';
            } elseif ( '' !== $e ) {
                $row['effective'] = $e;
                $row['source']    = 'extender';
            } elseif ( '' !== $y ) {
                $row['effective'] = $y;
                $row['source']    = 'yoast';
            } else {
                $row['effective'] = '';
                $row['source']    = '';
            }
        }
        unset( $row );

        return $rows;
    }

    private function render_status_table( array $s ): void {
        $rows = $this->build_status_rows( $s );
        ?>
        <h2 class="yse-status-title"><?php esc_html_e( 'Current Site Representation Status', 'yse-agency' ); ?></h2>
        <div class="yse-status-wrap">
            <table class="yse-status-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Field',          'yse-agency' ); ?></th>
                        <th><?php esc_html_e( 'Yoast Value',    'yse-agency' ); ?></th>
                        <th><?php esc_html_e( 'Extender Value', 'yse-agency' ); ?></th>
                        <th><?php esc_html_e( 'Effective',      'yse-agency' ); ?></th>
                        <th><?php esc_html_e( 'Source',         'yse-agency' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['label'] ); ?></td>
                            <td class="yse-val-yoast"><?php echo $this->cell( $row['yoast'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td class="yse-val-extender"><?php echo $this->cell( $row['extender'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td class="yse-val-effective"><?php echo $this->cell( $row['effective'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td><?php $this->render_source_badge( $row['source'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="yse-status-note">
                <?php esc_html_e( 'Yoast values shown here are read from saved Yoast settings — they reflect what Yoast would emit if this plugin were inactive.', 'yse-agency' ); ?>
            </p>
        </div>
        <?php
    }

    private function describe_schema_type( array $s ): string {
        if ( empty( $s['is_local'] ) ) {
            return '';
        }
        $types = array_filter( [
            (string) ( $s['lb_subtype']  ?? '' ),
            (string) ( $s['lb_subtype2'] ?? '' ),
            (string) ( $s['lb_subtype3'] ?? '' ),
        ] );
        return empty( $types ) ? 'LocalBusiness' : implode( ', ', $types );
    }

    private function describe_address( array $s ): string {
        $parts = array_filter( [
            (string) ( $s['addr_street']  ?? '' ),
            (string) ( $s['addr_city']    ?? '' ),
            (string) ( $s['addr_region']  ?? '' ),
            (string) ( $s['addr_postal']  ?? '' ),
            (string) ( $s['addr_country'] ?? '' ),
        ] );
        return implode( ', ', $parts );
    }

    private function describe_locations( array $s ): string {
        if ( empty( $s['ml_enabled'] ) ) {
            return '';
        }
        $locs = is_array( $s['ml_locations'] ?? null ) ? $s['ml_locations'] : [];
        $count = 0;
        foreach ( $locs as $loc ) {
            if ( is_array( $loc ) && ! empty( $loc['enabled'] ) && ! empty( $loc['name'] ) ) {
                $count++;
            }
        }
        if ( 0 === $count ) {
            return '';
        }
        /* translators: %d: number of additional location nodes */
        return sprintf( _n( '%d location', '%d locations', $count, 'yse-agency' ), $count );
    }

    private function cell( string $value ): string {
        if ( '' === $value ) {
            return '<span class="yse-val-empty">—</span>';
        }
        return esc_html( $this->shorten( $value ) );
    }

    private function shorten( string $value, int $max = 60 ): string {
        if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $max ) {
            return mb_substr( $value, 0, $max - 1 ) . '…';
        }
        return strlen( $value ) > $max ? substr( $value, 0, $max - 1 ) . '…' : $value;
    }

    private function render_source_badge( string $source ): void {
        if ( '' === $source ) {
            echo '<span class="yse-val-empty">—</span>';
            return;
        }
        $labels = [
            'yoast'    => __( 'Yoast',    'yse-agency' ),
            'extender' => __( 'Extender', 'yse-agency' ),
            'merged'   => __( 'Merged',   'yse-agency' ),
        ];
        printf(
            '<span class="yse-badge yse-badge--%1$s">%2$s</span>',
            esc_attr( $source ),
            esc_html( $labels[ $source ] ?? $source )
        );
    }

    /* ------------------------ Org fields ------------------------------ */

    private function render_org_fields( array $s ): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="yse-org-name"><?php esc_html_e( 'Organization Name', 'yse-agency' ); ?></label></th>
                <td><input id="yse-org-name" type="text" name="<?php echo $this->field_name( 'org_name' ); ?>" value="<?php echo esc_attr( (string) $s['org_name'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-org-url"><?php esc_html_e( 'Organization URL', 'yse-agency' ); ?></label></th>
                <td><input id="yse-org-url" type="url" name="<?php echo $this->field_name( 'org_url' ); ?>" value="<?php echo esc_attr( (string) $s['org_url'] ); ?>" class="regular-text" placeholder="https://example.com" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Organization Logo', 'yse-agency' ); ?></th>
                <td>
                    <div class="yse-logo-picker">
                        <img id="yse-logo-preview" src="<?php echo esc_url( (string) $s['org_logo'] ); ?>" alt="" style="<?php echo $s['org_logo'] !== '' ? '' : 'display:none;'; ?>" />
                        <div>
                            <input id="yse-logo-url" type="url" name="<?php echo $this->field_name( 'org_logo' ); ?>" value="<?php echo esc_attr( (string) $s['org_logo'] ); ?>" class="regular-text" />
                            <p>
                                <button type="button" class="button yse-pick-logo"><?php esc_html_e( 'Select / Upload Logo', 'yse-agency' ); ?></button>
                                <a href="#" class="yse-clear-logo" style="<?php echo $s['org_logo'] !== '' ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'yse-agency' ); ?></a>
                            </p>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-org-email"><?php esc_html_e( 'Contact Email', 'yse-agency' ); ?></label></th>
                <td><input id="yse-org-email" type="email" name="<?php echo $this->field_name( 'org_email' ); ?>" value="<?php echo esc_attr( (string) $s['org_email'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-telephone"><?php esc_html_e( 'Telephone', 'yse-agency' ); ?></label></th>
                <td><input id="yse-telephone" type="text" name="<?php echo $this->field_name( 'telephone' ); ?>" value="<?php echo esc_attr( (string) $s['telephone'] ); ?>" class="regular-text" placeholder="+1 555 123 4567" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-same-as"><?php esc_html_e( 'sameAs URLs', 'yse-agency' ); ?></label></th>
                <td>
                    <textarea id="yse-same-as" name="<?php echo $this->field_name( 'same_as' ); ?>" rows="5" class="large-text code"><?php echo esc_textarea( $this->lines_to_text( $s['same_as'] ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One URL per line. Social profiles, Wikipedia, Crunchbase, etc.', 'yse-agency' ); ?></p>
                </td>
            </tr>

            <tr class="yse-row-divider"><th colspan="2"><?php esc_html_e( 'LocalBusiness (optional)', 'yse-agency' ); ?></th></tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Treat as LocalBusiness', 'yse-agency' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo $this->field_name( 'is_local' ); ?>" value="1" <?php checked( ! empty( $s['is_local'] ) ); ?> />
                        <?php esc_html_e( 'Add LocalBusiness type, address, hours, and geo to the Organization node.', 'yse-agency' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'LocalBusiness Subtypes', 'yse-agency' ); ?></th>
                <td>
                    <div class="yse-subtype-grid">
                        <?php $this->render_subtype_select( [ 'lb_subtype'  ], (string) $s['lb_subtype']  ); ?>
                        <?php $this->render_subtype_select( [ 'lb_subtype2' ], (string) $s['lb_subtype2'] ); ?>
                        <?php $this->render_subtype_select( [ 'lb_subtype3' ], (string) $s['lb_subtype3'] ); ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Up to three Schema.org LocalBusiness subtypes. Most businesses only need the first.', 'yse-agency' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-street"><?php esc_html_e( 'Street Address', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-street" type="text" name="<?php echo $this->field_name( 'addr_street' ); ?>" value="<?php echo esc_attr( (string) $s['addr_street'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-city"><?php esc_html_e( 'City', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-city" type="text" name="<?php echo $this->field_name( 'addr_city' ); ?>" value="<?php echo esc_attr( (string) $s['addr_city'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-region"><?php esc_html_e( 'State / Region', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-region" type="text" name="<?php echo $this->field_name( 'addr_region' ); ?>" value="<?php echo esc_attr( (string) $s['addr_region'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-postal"><?php esc_html_e( 'Postal Code', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-postal" type="text" name="<?php echo $this->field_name( 'addr_postal' ); ?>" value="<?php echo esc_attr( (string) $s['addr_postal'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-country"><?php esc_html_e( 'Country', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-country" type="text" name="<?php echo $this->field_name( 'addr_country' ); ?>" value="<?php echo esc_attr( (string) $s['addr_country'] ); ?>" class="regular-text" placeholder="US, GB, AU…" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-geo-lat"><?php esc_html_e( 'Latitude / Longitude', 'yse-agency' ); ?></label></th>
                <td>
                    <input id="yse-geo-lat" type="text" name="<?php echo $this->field_name( 'geo_lat' ); ?>" value="<?php echo esc_attr( (string) $s['geo_lat'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Latitude', 'yse-agency' ); ?>" />
                    <input id="yse-geo-lng" type="text" name="<?php echo $this->field_name( 'geo_lng' ); ?>" value="<?php echo esc_attr( (string) $s['geo_lng'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Longitude', 'yse-agency' ); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-opening-hours"><?php esc_html_e( 'Opening Hours (JSON)', 'yse-agency' ); ?></label></th>
                <td>
                    <textarea id="yse-opening-hours" name="<?php echo $this->field_name( 'opening_hours' ); ?>" rows="6" class="large-text code"><?php echo esc_textarea( $this->array_to_pretty_json( $s['opening_hours'] ) ); ?></textarea>
                    <p class="description"><?php echo wp_kses(
                        __( 'JSON array of <code>OpeningHoursSpecification</code> objects. Example: <code>[{"dayOfWeek":"Monday","opens":"09:00","closes":"17:00"}]</code>', 'yse-agency' ),
                        [ 'code' => [] ]
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-service-area"><?php esc_html_e( 'Service Area', 'yse-agency' ); ?></label></th>
                <td>
                    <textarea id="yse-service-area" name="<?php echo $this->field_name( 'service_area' ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $this->lines_to_text( $s['service_area'] ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One city or region per line.', 'yse-agency' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * @param array  $path    Field path segments passed to field_name() — e.g. ['lb_subtype'] or ['ml_locations','0','lb_subtype'].
     * @param string $current Currently-selected value.
     */
    private function render_subtype_select( array $path, string $current ): void {
        ?>
        <select name="<?php echo $this->field_name( ...$path ); ?>">
            <option value=""><?php esc_html_e( '— None —', 'yse-agency' ); ?></option>
            <?php foreach ( $this->lb_subtypes() as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /* ------------------------ Locations section ----------------------- */

    private function render_locations_section( array $s ): void {
        $locations = is_array( $s['ml_locations'] ?? null ) ? $s['ml_locations'] : [];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Multi-Location', 'yse-agency' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo $this->field_name( 'ml_enabled' ); ?>" value="1" <?php checked( ! empty( $s['ml_enabled'] ) ); ?> />
                        <?php esc_html_e( 'Emit each enabled location below as its own LocalBusiness node in the schema graph.', 'yse-agency' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <div id="yse-locations-list">
            <?php foreach ( $locations as $i => $loc ) : ?>
                <?php $this->render_location_card( (string) $i, (array) $loc ); ?>
            <?php endforeach; ?>
        </div>

        <p>
            <button type="button" class="button yse-add-location">
                <?php esc_html_e( '+ Add Location', 'yse-agency' ); ?>
            </button>
        </p>

        <script type="text/template" id="yse-location-template"><?php
            $this->render_location_card( '__INDEX__', $this->blank_location() );
        ?></script>
        <?php
    }

    private function render_location_card( string $idx, array $loc ): void {
        $loc   = wp_parse_args( $loc, $this->blank_location() );
        $title = $loc['name'] !== '' ? $loc['name'] : __( 'New Location', 'yse-agency' );
        ?>
        <div class="yse-location-card">
            <div class="yse-location-head">
                <span class="yse-location-title"><?php echo esc_html( $title ); ?></span>
                <label class="yse-location-enabled">
                    <input type="checkbox" name="<?php echo $this->field_name( 'ml_locations', $idx, 'enabled' ); ?>" value="1" <?php checked( ! empty( $loc['enabled'] ) ); ?> />
                    <?php esc_html_e( 'Enabled', 'yse-agency' ); ?>
                </label>
                <button type="button" class="button-link yse-toggle-location"><?php esc_html_e( 'Collapse', 'yse-agency' ); ?></button>
                <button type="button" class="button-link yse-remove-location"><?php esc_html_e( 'Remove', 'yse-agency' ); ?></button>
            </div>
            <div class="yse-location-body">
                <table class="form-table" role="presentation">

                    <tr class="yse-row-divider"><th colspan="2"><?php esc_html_e( 'Identity', 'yse-agency' ); ?></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Name', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'name' ); ?>" value="<?php echo esc_attr( (string) $loc['name'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Page Slug', 'yse-agency' ); ?></th>
                        <td>
                            <input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'page_slug' ); ?>" value="<?php echo esc_attr( (string) $loc['page_slug'] ); ?>" class="regular-text" placeholder="locations/downtown" />
                            <p class="description"><?php esc_html_e( 'Used to build a fallback URL when Location URL is empty.', 'yse-agency' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Location URL', 'yse-agency' ); ?></th>
                        <td><input type="url" name="<?php echo $this->field_name( 'ml_locations', $idx, 'url' ); ?>" value="<?php echo esc_attr( (string) $loc['url'] ); ?>" class="regular-text" placeholder="https://example.com/location/" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Image URL', 'yse-agency' ); ?></th>
                        <td><input type="url" name="<?php echo $this->field_name( 'ml_locations', $idx, 'image' ); ?>" value="<?php echo esc_attr( (string) $loc['image'] ); ?>" class="regular-text" placeholder="https://example.com/location-photo.jpg" /></td>
                    </tr>

                    <tr class="yse-row-divider"><th colspan="2"><?php esc_html_e( 'Contact', 'yse-agency' ); ?></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Telephone', 'yse-agency' ); ?></th>
                        <td>
                            <input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'telephone' ); ?>" value="<?php echo esc_attr( (string) $loc['telephone'] ); ?>" class="regular-text" placeholder="+1 555 123 4567" />
                            <p class="description"><?php esc_html_e( 'Inherits global telephone if empty.', 'yse-agency' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Email', 'yse-agency' ); ?></th>
                        <td>
                            <input type="email" name="<?php echo $this->field_name( 'ml_locations', $idx, 'email' ); ?>" value="<?php echo esc_attr( (string) $loc['email'] ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Inherits global contact email if empty.', 'yse-agency' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Price Range', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'priceRange' ); ?>" value="<?php echo esc_attr( (string) $loc['priceRange'] ); ?>" class="small-text" placeholder="$$" /></td>
                    </tr>

                    <tr class="yse-row-divider"><th colspan="2"><?php esc_html_e( 'Schema Type', 'yse-agency' ); ?></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'LocalBusiness Subtypes', 'yse-agency' ); ?></th>
                        <td>
                            <div class="yse-subtype-grid">
                                <?php $this->render_subtype_select( [ 'ml_locations', $idx, 'lb_subtype'  ], (string) $loc['lb_subtype']  ); ?>
                                <?php $this->render_subtype_select( [ 'ml_locations', $idx, 'lb_subtype2' ], (string) $loc['lb_subtype2'] ); ?>
                                <?php $this->render_subtype_select( [ 'ml_locations', $idx, 'lb_subtype3' ], (string) $loc['lb_subtype3'] ); ?>
                            </div>
                        </td>
                    </tr>

                    <tr class="yse-row-divider"><th colspan="2"><?php esc_html_e( 'Address', 'yse-agency' ); ?></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Street Address', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'addr_street' ); ?>" value="<?php echo esc_attr( (string) $loc['addr_street'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'City', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'addr_city' ); ?>" value="<?php echo esc_attr( (string) $loc['addr_city'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'State / Region', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'addr_region' ); ?>" value="<?php echo esc_attr( (string) $loc['addr_region'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Postal Code', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'addr_postal' ); ?>" value="<?php echo esc_attr( (string) $loc['addr_postal'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Country', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'addr_country' ); ?>" value="<?php echo esc_attr( (string) $loc['addr_country'] ); ?>" class="regular-text" placeholder="US, GB, AU…" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Latitude / Longitude', 'yse-agency' ); ?></th>
                        <td>
                            <input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'geo_lat' ); ?>" value="<?php echo esc_attr( (string) $loc['geo_lat'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Latitude',  'yse-agency' ); ?>" />
                            <input type="text" name="<?php echo $this->field_name( 'ml_locations', $idx, 'geo_lng' ); ?>" value="<?php echo esc_attr( (string) $loc['geo_lng'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Longitude', 'yse-agency' ); ?>" />
                        </td>
                    </tr>

                    <tr class="yse-row-divider"><th colspan="2"><?php esc_html_e( 'Service Area &amp; Hours', 'yse-agency' ); ?></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Service Area', 'yse-agency' ); ?></th>
                        <td>
                            <textarea name="<?php echo $this->field_name( 'ml_locations', $idx, 'service_area' ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $this->lines_to_text( $loc['service_area'] ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'One city or region per line.', 'yse-agency' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Opening Hours (JSON)', 'yse-agency' ); ?></th>
                        <td>
                            <textarea name="<?php echo $this->field_name( 'ml_locations', $idx, 'opening_hours' ); ?>" rows="4" class="large-text code"><?php echo esc_textarea( $this->array_to_pretty_json( $loc['opening_hours'] ) ); ?></textarea>
                            <p class="description"><?php echo wp_kses(
                                __( 'JSON array of <code>OpeningHoursSpecification</code> objects. Example: <code>[{"dayOfWeek":"Monday","opens":"09:00","closes":"17:00"}]</code>', 'yse-agency' ),
                                [ 'code' => [] ]
                            ); ?></p>
                        </td>
                    </tr>

                </table>
            </div>
        </div>
        <?php
    }

    /* ------------------------ FAQ section ----------------------------- */

    private function render_faq_settings( array $s ): void {
        $selected = is_array( $s['faq_post_types'] ?? null ) ? $s['faq_post_types'] : [];
        $types    = get_post_types( [ 'public' => true ], 'objects' );
        ?>
        <p class="description">
            <?php esc_html_e( 'The FAQ Builder is a per-post tool. Enable the post types you want to use it on, then open a post or page to find the “FAQ Schema Builder” meta box. Saved FAQs are emitted as a FAQPage node on that post’s URL.', 'yse-agency' ); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable FAQ Builder On', 'yse-agency' ); ?></th>
                <td>
                    <div class="yse-faq-pt-list">
                        <?php foreach ( $types as $slug => $type ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo $this->field_name( 'faq_post_types' ); ?>[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, $selected, true ) ); ?> />
                                <?php echo esc_html( $type->labels->singular_name ); ?>
                                <code><?php echo esc_html( $slug ); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'No post types are enabled by default.', 'yse-agency' ); ?></p>
                </td>
            </tr>
        </table>
        <p>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>" class="button">
                <?php esc_html_e( 'Open Pages', 'yse-agency' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="button">
                <?php esc_html_e( 'Open Posts', 'yse-agency' ); ?>
            </a>
        </p>
        <?php
    }

    /* ------------------------ Compatibility --------------------------- */

    private function render_compat_fields( array $s ): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Override Yoast Organization', 'yse-agency' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo $this->field_name( 'override_org' ); ?>" value="1" <?php checked( ! empty( $s['override_org'] ) ); ?> />
                        <?php esc_html_e( 'Replace Yoast’s Organization fields entirely instead of merging.', 'yse-agency' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Off by default. Enable only if Yoast’s Organization data conflicts with what you want emitted.', 'yse-agency' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /* ------------------------ Import / Export panel ------------------- */

    private function render_import_export_panel( array $s ): void {
        $json = wp_json_encode( $s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
            $json = '{}';
        }
        ?>
        <div class="yse-ie-panel">
            <h2><?php esc_html_e( 'Import / Export', 'yse-agency' ); ?></h2>
            <div class="yse-ie-grid">
                <div class="yse-ie-col">
                    <h3><?php esc_html_e( 'Export', 'yse-agency' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Copy the JSON below to back up or transfer settings to another site.', 'yse-agency' ); ?></p>
                    <textarea id="yse-export-json" readonly><?php echo esc_textarea( $json ); ?></textarea>
                    <p>
                        <button type="button" class="button yse-copy-export" data-copied="<?php esc_attr_e( 'Copied!', 'yse-agency' ); ?>">
                            <?php esc_html_e( 'Copy to Clipboard', 'yse-agency' ); ?>
                        </button>
                    </p>
                </div>
                <div class="yse-ie-col">
                    <h3><?php esc_html_e( 'Import', 'yse-agency' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Paste a previously exported JSON or upload a .json file. Importing replaces current settings.', 'yse-agency' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="yse_import" />
                        <?php wp_nonce_field( 'yse_import' ); ?>
                        <textarea name="yse_import_json" rows="6" class="widefat code" placeholder='{"org_name":"…"}'></textarea>
                        <p><input type="file" name="yse_import_file" accept=".json,application/json" /></p>
                        <p>
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e( 'Import & Replace', 'yse-agency' ); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------ Helpers --------------------------------- */

    /**
     * Build a properly-escaped form field name like:
     *   yse_settings[org_name]
     *   yse_settings[ml_locations][0][name]
     */
    private function field_name( string ...$path ): string {
        $name = esc_attr( YSE_OPTION_KEY );
        foreach ( $path as $segment ) {
            $name .= '[' . esc_attr( $segment ) . ']';
        }
        return $name;
    }

    private function register_status_message( string $key ): void {
        $messages = [
            'ok'   => [ 'success', __( 'Settings imported successfully.', 'yse-agency' ) ],
            'fail' => [ 'error',   __( 'Import failed. Paste or upload a valid JSON export.', 'yse-agency' ) ],
        ];
        if ( ! isset( $messages[ $key ] ) ) {
            return;
        }
        [ $type, $text ] = $messages[ $key ];
        add_settings_error( YSE_OPTION_KEY, 'yse_import_' . $key, $text, $type );
    }

    /* ================================================================== *
     *  Import handler
     * ================================================================== */

    public function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'yse-agency' ) );
        }
        check_admin_referer( 'yse_import' );

        $result = $this->process_import_payload();

        // Persist any settings_errors raised during sanitize so they survive the redirect.
        $errors = get_settings_errors();
        if ( ! empty( $errors ) ) {
            set_transient( 'settings_errors', $errors, 30 );
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => YSE_PAGE_SLUG, 'yse_import' => $result ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /**
     * Reads file or pasted JSON, decodes, sanitizes, and stores.
     * Returns 'ok' on success, 'fail' on any failure mode.
     */
    private function process_import_payload(): string {
        $json = '';

        if (
            isset( $_FILES['yse_import_file']['tmp_name'], $_FILES['yse_import_file']['error'] )
            && UPLOAD_ERR_OK === (int) $_FILES['yse_import_file']['error']
            && '' !== $_FILES['yse_import_file']['tmp_name']
            && is_uploaded_file( $_FILES['yse_import_file']['tmp_name'] )
        ) {
            $contents = file_get_contents( $_FILES['yse_import_file']['tmp_name'] );
            if ( false !== $contents ) {
                $json = (string) $contents;
            }
        }

        if ( '' === $json && isset( $_POST['yse_import_json'] ) ) {
            $json = (string) wp_unslash( $_POST['yse_import_json'] );
        }

        $json = trim( $json );
        if ( '' === $json ) {
            return 'fail';
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) {
            return 'fail';
        }

        update_option( YSE_OPTION_KEY, $this->sanitize_settings( $decoded ) );
        return 'ok';
    }

    /* ================================================================== *
     *  FAQ meta box
     * ================================================================== */

    public function register_meta_boxes(): void {
        $settings   = (array) get_option( YSE_OPTION_KEY, [] );
        $post_types = is_array( $settings['faq_post_types'] ?? null ) ? $settings['faq_post_types'] : [];

        if ( empty( $post_types ) ) {
            return;
        }

        add_meta_box(
            'yse_faq_builder',
            __( 'Schema Extender FAQ', 'yse-agency' ),
            [ $this, 'render_faq_meta_box' ],
            $post_types,
            'normal',
            'default'
        );
    }

    public function render_faq_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'yse_save_faq_' . $post->ID, '_yse_faq_nonce' );

        $items = [];
        $raw   = get_post_meta( $post->ID, '_yse_faq_items', true );
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $items = $decoded;
            }
        }
        ?>
        <p class="description">
            <?php esc_html_e( 'Add up to 20 Q&A pairs. Each pair becomes a Question entry in the FAQPage schema node for this post.', 'yse-agency' ); ?>
        </p>
        <div id="yse-faq-list">
            <?php foreach ( $items as $item ) : ?>
                <?php $this->render_faq_row( (array) $item ); ?>
            <?php endforeach; ?>
        </div>
        <p>
            <button type="button" class="button yse-add-faq">
                <?php esc_html_e( '+ Add Question', 'yse-agency' ); ?>
            </button>
        </p>
        <script type="text/template" id="yse-faq-template"><?php
            $this->render_faq_row( [ 'q' => '', 'a' => '' ] );
        ?></script>
        <?php
    }

    /**
     * Render a single FAQ row for the metabox.
     *
     * Stored answers contain literal <br> tags; convert them back to real
     * newlines so the textarea displays them as line breaks for the editor.
     */
    private function render_faq_row( array $item ): void {
        $answer_display = preg_replace( '/<br\s*\/?>/i', "\n", (string) ( $item['a'] ?? '' ) );
        ?>
        <div class="yse-faq-row">
            <label>
                <?php esc_html_e( 'Question', 'yse-agency' ); ?>
                <input type="text"
                       name="yse_faq[q][]"
                       value="<?php echo esc_attr( (string) ( $item['q'] ?? '' ) ); ?>"
                       placeholder="<?php esc_attr_e( 'Enter question…', 'yse-agency' ); ?>" />
            </label>
            <label>
                <?php esc_html_e( 'Answer', 'yse-agency' ); ?>
                <textarea name="yse_faq[a][]"
                          rows="3"
                          placeholder="<?php esc_attr_e( 'Enter answer…', 'yse-agency' ); ?>"><?php echo esc_textarea( $answer_display ); ?></textarea>
            </label>
            <a href="#" class="yse-remove-faq"><?php esc_html_e( 'Remove', 'yse-agency' ); ?></a>
        </div>
        <?php
    }

    /**
     * Persist FAQ items from the metabox form.
     *
     * Newline handling (critical — must never produce "nn"):
     *   1. wp_unslash() the raw POST value first.
     *   2. Normalize \r\n and lone \r to \n via str_replace (not preg_replace).
     *   3. Convert each \n to <br> via str_replace.
     *   4. Store the <br>-encoded string in JSON.
     *
     * Loading back: render_faq_row() reverses <br> → \n for the textarea.
     */
    public function save_post_meta( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( wp_is_post_revision( $post_id ) )                { return; }
        if ( ! isset( $_POST['_yse_faq_nonce'] ) )            { return; }
        if ( ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['_yse_faq_nonce'] ) ),
                'yse_save_faq_' . $post_id
            ) ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) )    { return; }

        $questions = isset( $_POST['yse_faq']['q'] ) ? (array) $_POST['yse_faq']['q'] : [];
        $answers   = isset( $_POST['yse_faq']['a'] ) ? (array) $_POST['yse_faq']['a'] : [];

        $allowed_html = [
            'br'     => [],
            'strong' => [],
            'b'      => [],
            'em'     => [],
            'i'      => [],
            'a'      => [ 'href' => [], 'title' => [], 'target' => [], 'rel' => [] ],
            'code'   => [],
            'u'      => [],
        ];

        $items = [];
        foreach ( $questions as $i => $raw_q ) {
            if ( count( $items ) >= 20 ) {
                break;
            }

            $q = sanitize_text_field( wp_unslash( (string) $raw_q ) );

            // Skip blank rows.
            if ( '' === $q ) {
                continue;
            }

            // Enforce trailing question mark.
            if ( '?' !== substr( $q, -1 ) ) {
                $q .= '?';
            }

            // Unslash first, then normalise newlines to LF, then LF → <br>.
            $raw_a = wp_unslash( (string) ( $answers[ $i ] ?? '' ) );
            $raw_a = str_replace( "\r\n", "\n", $raw_a );
            $raw_a = str_replace( "\r",   "\n", $raw_a );
            $a     = str_replace( "\n", '<br>', $raw_a );

            // Sanitize inline HTML (the <br> tags we just created are preserved).
            $a = wp_kses( $a, $allowed_html );

            $items[] = [ 'q' => $q, 'a' => $a ];
        }

        update_post_meta( $post_id, '_yse_faq_items', wp_json_encode( $items ) );
    }

    /* ================================================================== *
     *  Schema filter hookup
     * ================================================================== */

    private function hook_schema_filters(): void {
        // Defer filter registration to wp_loaded so all Yoast classes are available.
        add_action( 'wp_loaded', [ $this, 'maybe_register_schema_filter' ] );
    }

    public function maybe_register_schema_filter(): void {
        if ( class_exists( '\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece' ) ) {
            // wpseo_schema_organization fires only when Yoast's "Site Representation"
            // is set to "Organization".  If it is set to "Person", this filter never
            // fires and Organization enrichment is silently skipped — which is correct
            // behaviour, not a bug.
            add_filter( 'wpseo_schema_organization', [ $this, 'filter_organization_node' ] );
            add_filter( 'wpseo_schema_graph',        [ $this, 'filter_schema_graph' ], 20, 2 );
        }
    }

    /* ================================================================== *
     *  Organization node enrichment
     * ================================================================== */

    /**
     * Enrich (not replace) Yoast's Organization node via wpseo_schema_organization.
     *
     * Merge strategy:
     *   override_org = false (default) — Extender value is written only when the
     *     Yoast field is absent or effectively empty.
     *   override_org = true            — Extender value always wins.
     *
     * sameAs is always merged + deduplicated regardless of override_org, because
     * adding social profiles alongside Yoast's is universally correct.
     */
    public function filter_organization_node( $node ): array {
        if ( ! is_array( $node ) ) {
            $node = [];
        }

        $s = wp_parse_args(
            (array) get_option( YSE_OPTION_KEY, [] ),
            $this->default_settings()
        );

        $override = ! empty( $s['override_org'] );

        // ---- Scalar fields --------------------------------------------------
        $scalar_map = [
            'name'      => (string) ( $s['org_name']  ?? '' ),
            'url'       => (string) ( $s['org_url']   ?? '' ),
            'email'     => (string) ( $s['org_email'] ?? '' ),
            'telephone' => (string) ( $s['telephone'] ?? '' ),
        ];
        foreach ( $scalar_map as $prop => $ext_val ) {
            if ( '' !== $ext_val && ( $override || $this->is_node_field_empty( $node, $prop ) ) ) {
                $node[ $prop ] = $ext_val;
            }
        }

        // ---- Logo -----------------------------------------------------------
        $ext_logo = trim( (string) ( $s['org_logo'] ?? '' ) );
        if ( '' !== $ext_logo && ( $override || $this->is_node_field_empty( $node, 'logo' ) ) ) {
            $node['logo'] = [ '@type' => 'ImageObject', 'url' => $ext_logo ];
        }

        // ---- sameAs — always merge + deduplicate ----------------------------
        $node = $this->merge_same_as( $node, is_array( $s['same_as'] ) ? $s['same_as'] : [] );

        // ---- Address --------------------------------------------------------
        $node = $this->maybe_inject_address( $node, $s, $override );

        // ---- GeoCoordinates -------------------------------------------------
        $node = $this->maybe_inject_geo( $node, $s, $override );

        // ---- OpeningHoursSpecification --------------------------------------
        $ext_oh = is_array( $s['opening_hours'] ?? null ) ? $s['opening_hours'] : [];
        if ( ! empty( $ext_oh ) && ( $override || $this->is_node_field_empty( $node, 'openingHoursSpecification' ) ) ) {
            $node['openingHoursSpecification'] = $ext_oh;
        }

        // ---- areaServed — each line becomes a City node ---------------------
        $node = $this->maybe_inject_area_served( $node, $s, $override );

        // ---- LocalBusiness @type injection ----------------------------------
        if ( ! empty( $s['is_local'] ) ) {
            $node = $this->inject_local_business_types( $node, $s );
        }

        return $node;
    }

    /**
     * Returns true when a given key is absent from the node, or its value is
     * an empty string / empty array.  Treats '0' and numeric zeros as non-empty.
     */
    private function is_node_field_empty( array $node, string $key ): bool {
        if ( ! array_key_exists( $key, $node ) ) {
            return true;
        }
        $val = $node[ $key ];
        if ( is_array( $val ) ) {
            return empty( $val );
        }
        return '' === $val;
    }

    /**
     * Merge Extender sameAs URLs into the node's existing sameAs array.
     * Deduplicates by exact URL string.  Always runs regardless of override_org
     * because adding profiles alongside Yoast's is safe by definition.
     */
    private function merge_same_as( array $node, array $ext_urls ): array {
        $existing = [];
        if ( isset( $node['sameAs'] ) && is_array( $node['sameAs'] ) ) {
            foreach ( $node['sameAs'] as $url ) {
                if ( is_string( $url ) && '' !== $url ) {
                    $existing[] = $url;
                }
            }
        }

        $merged = $existing;
        foreach ( $ext_urls as $url ) {
            $url = trim( (string) $url );
            if ( '' !== $url && ! in_array( $url, $merged, true ) ) {
                $merged[] = $url;
            }
        }

        if ( ! empty( $merged ) ) {
            $node['sameAs'] = array_values( $merged );
        }

        return $node;
    }

    /**
     * Inject a PostalAddress node when we have at least one non-empty address field.
     * Does nothing when both Extender has no address data AND override is off.
     */
    private function maybe_inject_address( array $node, array $s, bool $override ): array {
        $ext_parts = array_filter( [
            'streetAddress'   => trim( (string) ( $s['addr_street']  ?? '' ) ),
            'addressLocality' => trim( (string) ( $s['addr_city']    ?? '' ) ),
            'addressRegion'   => trim( (string) ( $s['addr_region']  ?? '' ) ),
            'postalCode'      => trim( (string) ( $s['addr_postal']  ?? '' ) ),
            'addressCountry'  => trim( (string) ( $s['addr_country'] ?? '' ) ),
        ] );

        if ( empty( $ext_parts ) ) {
            return $node;
        }

        if ( $override || $this->is_node_field_empty( $node, 'address' ) ) {
            $node['address'] = array_merge( [ '@type' => 'PostalAddress' ], $ext_parts );
        }

        return $node;
    }

    /**
     * Inject a GeoCoordinates node when both lat and lng are set.
     */
    private function maybe_inject_geo( array $node, array $s, bool $override ): array {
        $lat = trim( (string) ( $s['geo_lat'] ?? '' ) );
        $lng = trim( (string) ( $s['geo_lng'] ?? '' ) );

        if ( '' === $lat || '' === $lng ) {
            return $node;
        }

        if ( $override || $this->is_node_field_empty( $node, 'geo' ) ) {
            $node['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            ];
        }

        return $node;
    }

    /**
     * Inject areaServed, converting each service_area line to a City node.
     */
    private function maybe_inject_area_served( array $node, array $s, bool $override ): array {
        $sa = is_array( $s['service_area'] ?? null ) ? $s['service_area'] : [];
        if ( empty( $sa ) ) {
            return $node;
        }

        $areas = [];
        foreach ( $sa as $area ) {
            $area = trim( (string) $area );
            if ( '' !== $area ) {
                $areas[] = [ '@type' => 'City', 'name' => $area ];
            }
        }

        if ( ! empty( $areas ) && ( $override || $this->is_node_field_empty( $node, 'areaServed' ) ) ) {
            $node['areaServed'] = $areas;
        }

        return $node;
    }

    /**
     * Ensure the @type array includes LocalBusiness plus any configured subtypes.
     * Existing types are preserved; duplicates are removed.
     */
    private function inject_local_business_types( array $node, array $s ): array {
        $types = isset( $node['@type'] ) ? (array) $node['@type'] : [];

        if ( ! in_array( 'LocalBusiness', $types, true ) ) {
            $types[] = 'LocalBusiness';
        }

        foreach ( [ 'lb_subtype', 'lb_subtype2', 'lb_subtype3' ] as $key ) {
            $t = trim( (string) ( $s[ $key ] ?? '' ) );
            if ( '' !== $t && ! in_array( $t, $types, true ) ) {
                $types[] = $t;
            }
        }

        $node['@type'] = ( 1 === count( $types ) ) ? $types[0] : array_values( $types );

        return $node;
    }

    /* ================================================================== *
     *  Multi-location schema output
     * ================================================================== */

    public function filter_schema_graph( $graph, $context ) {
        if ( ! is_array( $graph ) ) {
            return $graph;
        }

        $s = wp_parse_args(
            (array) get_option( YSE_OPTION_KEY, [] ),
            $this->default_settings()
        );

        // FAQ injection — singular posts only.
        $graph = $this->inject_faq_node( $graph, $s );

        // Multi-location injection.
        if ( ! empty( $s['ml_enabled'] ) ) {
            $locations = is_array( $s['ml_locations'] ) ? $s['ml_locations'] : [];
            if ( ! empty( $locations ) ) {
                $org_id = $this->find_org_id( $graph );
                foreach ( $locations as $L ) {
                    if ( ! is_array( $L ) || empty( $L['enabled'] ) ) {
                        continue;
                    }
                    $node = $this->build_location_node( $L, $s, $org_id );
                    if ( null !== $node ) {
                        $graph[] = $node;
                    }
                }
            }
        }

        return $graph;
    }

    /* ================================================================== *
     *  FAQ schema injection
     * ================================================================== */

    /**
     * Inject a FAQPage node into the graph for enabled singular post types.
     *
     * Skips injection when:
     *  - Not a singular view.
     *  - Post type is not in the FAQ-enabled list.
     *  - Post has no saved FAQ items.
     *  - A FAQPage node already exists in the graph.
     */
    private function inject_faq_node( array $graph, array $s ): array {
        if ( ! is_singular() ) {
            return $graph;
        }

        $post = get_queried_object();
        if ( ! $post instanceof WP_Post ) {
            return $graph;
        }

        $enabled_types = is_array( $s['faq_post_types'] ) ? $s['faq_post_types'] : [];
        if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
            return $graph;
        }

        // Load stored items.
        $raw = get_post_meta( $post->ID, '_yse_faq_items', true );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return $graph;
        }
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) || empty( $items ) ) {
            return $graph;
        }

        // Bail if a FAQPage node already exists anywhere in the graph.
        foreach ( $graph as $gnode ) {
            if ( ! is_array( $gnode ) ) {
                continue;
            }
            $types = (array) ( $gnode['@type'] ?? [] );
            if ( in_array( 'FAQPage', $types, true ) ) {
                return $graph;
            }
        }

        $post_url  = (string) get_permalink( $post->ID );
        $faq_id    = $post_url . '#/schema/faq';
        $webpage_id = $this->find_webpage_id( $graph, $post_url );

        // Build mainEntity array.
        $main_entity = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || '' === trim( (string) ( $item['q'] ?? '' ) ) ) {
                continue;
            }
            $main_entity[] = [
                '@type'          => 'Question',
                'name'           => (string) $item['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => (string) ( $item['a'] ?? '' ),
                ],
            ];
        }

        if ( empty( $main_entity ) ) {
            return $graph;
        }

        $faq_node = [
            '@type'         => 'FAQPage',
            '@id'           => $faq_id,
            'url'           => $post_url,
            'headline'      => get_the_title( $post->ID ),
            'datePublished' => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $post->post_date_gmt ) ),
            'dateModified'  => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $post->post_modified_gmt ) ),
            'mainEntity'    => $main_entity,
        ];

        if ( '' !== $webpage_id ) {
            $faq_node['isPartOf'] = [ '@id' => $webpage_id ];
        }

        $graph[] = $faq_node;

        // Add hasPart pointer on the WebPage node (deduplicated).
        if ( '' !== $webpage_id ) {
            $graph = $this->inject_faq_has_part( $graph, $faq_id, $webpage_id );
        }

        return $graph;
    }

    /**
     * Find the WebPage node @id in the graph.
     * Falls back to the standard Yoast '#webpage' fragment.
     */
    private function find_webpage_id( array $graph, string $post_url ): string {
        foreach ( $graph as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $types = (array) ( $node['@type'] ?? [] );
            foreach ( $types as $t ) {
                // Match 'WebPage' and any subtype that ends in 'Page'.
                if ( 'WebPage' === $t || substr( (string) $t, -4 ) === 'Page' ) {
                    return (string) ( $node['@id'] ?? '' );
                }
            }
        }
        return $post_url . '#webpage';
    }

    /**
     * Add a hasPart pointer to the WebPage node, deduplicating existing entries.
     */
    private function inject_faq_has_part( array $graph, string $faq_id, string $webpage_id ): array {
        foreach ( $graph as &$node ) {
            if ( ! is_array( $node ) || (string) ( $node['@id'] ?? '' ) !== $webpage_id ) {
                continue;
            }

            // Normalise hasPart to a list of objects.
            $has_part = [];
            if ( isset( $node['hasPart'] ) ) {
                // Single object: { '@id': '...' } — convert to array.
                if ( is_array( $node['hasPart'] ) && array_key_exists( '@id', $node['hasPart'] ) ) {
                    $has_part = [ $node['hasPart'] ];
                } else {
                    $has_part = (array) $node['hasPart'];
                }
            }

            // Deduplicate: only append if not already present.
            $already_present = false;
            foreach ( $has_part as $part ) {
                if ( is_array( $part ) && (string) ( $part['@id'] ?? '' ) === $faq_id ) {
                    $already_present = true;
                    break;
                }
            }

            if ( ! $already_present ) {
                $has_part[] = [ '@id' => $faq_id ];
            }

            $node['hasPart'] = array_values( $has_part );
            break;
        }
        // Must unset the by-reference loop variable; without this, the last
        // $graph element would be aliased to $node and could be overwritten by
        // any subsequent code that reuses the variable name.
        unset( $node );

        return $graph;
    }

    /**
     * Walk the graph looking for an Organization or LocalBusiness @id.
     * Falls back to the standard Yoast pattern if none is found.
     */
    private function find_org_id( array $graph ): string {
        foreach ( $graph as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $types = (array) ( $node['@type'] ?? [] );
            foreach ( $types as $t ) {
                if ( 'Organization' === $t || 'LocalBusiness' === $t ) {
                    return (string) ( $node['@id'] ?? '' );
                }
            }
        }
        return trailingslashit( home_url() ) . '#organization';
    }

    /**
     * Build a single LocalBusiness schema node for one location entry.
     * Returns null when the location has no name (the minimum required field).
     *
     * Inheritance rules applied here:
     *   - telephone  → falls back to global settings telephone
     *   - email      → falls back to global settings org_email
     *   - opening_hours / service_area → location value only (no global fallback)
     *
     * @param array  $L        A single sanitized location array.
     * @param array  $settings The full plugin settings array.
     * @param string $org_id   The @id of the parent organization node.
     */
    private function build_location_node( array $L, array $settings, string $org_id ): ?array {
        $name = trim( (string) ( $L['name'] ?? '' ) );
        if ( '' === $name ) {
            return null;
        }

        // URL: explicit override → page_slug fallback → empty
        $url = trim( (string) ( $L['url'] ?? '' ) );
        if ( '' === $url ) {
            $slug = trim( (string) ( $L['page_slug'] ?? '' ) );
            if ( '' !== $slug ) {
                $url = trailingslashit( home_url() ) . ltrim( $slug, '/' );
            }
        }

        // @id derived from URL when available; otherwise slug from name
        $node_id = ( '' !== $url )
            ? trailingslashit( $url ) . '#localbusiness'
            : trailingslashit( home_url() ) . '#location-' . sanitize_title( $name );

        // @type — LocalBusiness + up to three optional subtypes
        $types = [ 'LocalBusiness' ];
        foreach ( [ 'lb_subtype', 'lb_subtype2', 'lb_subtype3' ] as $key ) {
            $t = trim( (string) ( $L[ $key ] ?? '' ) );
            if ( '' !== $t && ! in_array( $t, $types, true ) ) {
                $types[] = $t;
            }
        }

        $node = [
            '@type' => ( 1 === count( $types ) ) ? $types[0] : $types,
            '@id'   => $node_id,
            'name'  => $name,
        ];

        if ( '' !== $url ) {
            $node['url'] = $url;
        }

        // Image and priceRange (location-specific only)
        $image = trim( (string) ( $L['image'] ?? '' ) );
        if ( '' !== $image ) {
            $node['image'] = $image;
        }

        $price = trim( (string) ( $L['priceRange'] ?? '' ) );
        if ( '' !== $price ) {
            $node['priceRange'] = $price;
        }

        // Telephone — inherit global when location value is absent
        $phone = trim( (string) ( $L['telephone'] ?? '' ) );
        if ( '' === $phone ) {
            $phone = trim( (string) ( $settings['telephone'] ?? '' ) );
        }
        if ( '' !== $phone ) {
            $node['telephone'] = $phone;
        }

        // Email — inherit global when location value is absent
        $email = trim( (string) ( $L['email'] ?? '' ) );
        if ( '' === $email ) {
            $email = trim( (string) ( $settings['org_email'] ?? '' ) );
        }
        if ( '' !== $email ) {
            $node['email'] = $email;
        }

        // PostalAddress
        $addr_parts = array_filter( [
            'streetAddress'   => trim( (string) ( $L['addr_street']  ?? '' ) ),
            'addressLocality' => trim( (string) ( $L['addr_city']    ?? '' ) ),
            'addressRegion'   => trim( (string) ( $L['addr_region']  ?? '' ) ),
            'postalCode'      => trim( (string) ( $L['addr_postal']  ?? '' ) ),
            'addressCountry'  => trim( (string) ( $L['addr_country'] ?? '' ) ),
        ] );
        if ( ! empty( $addr_parts ) ) {
            $node['address'] = array_merge( [ '@type' => 'PostalAddress' ], $addr_parts );
        }

        // GeoCoordinates — only when both lat and lng are present
        $lat = trim( (string) ( $L['geo_lat'] ?? '' ) );
        $lng = trim( (string) ( $L['geo_lng'] ?? '' ) );
        if ( '' !== $lat && '' !== $lng ) {
            $node['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            ];
        }

        // Opening hours — location-specific value only
        $oh = is_array( $L['opening_hours'] ?? null ) ? $L['opening_hours'] : [];
        if ( ! empty( $oh ) ) {
            $node['openingHoursSpecification'] = $oh;
        }

        // Service area — location-specific value only; wrap each string as AdministrativeArea
        $sa = is_array( $L['service_area'] ?? null ) ? $L['service_area'] : [];
        if ( ! empty( $sa ) ) {
            $areas = [];
            foreach ( $sa as $area ) {
                $area = trim( (string) $area );
                if ( '' !== $area ) {
                    $areas[] = [ '@type' => 'AdministrativeArea', 'name' => $area ];
                }
            }
            if ( ! empty( $areas ) ) {
                $node['areaServed'] = $areas;
            }
        }

        // Link back to parent organization node
        if ( '' !== $org_id ) {
            $node['parentOrganization'] = [ '@id' => $org_id ];
        }

        return $node;
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
