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
 *
 * Owns hook registration, asset enqueue, settings UI, FAQ meta box,
 * and the schema filter hookup. Schema graph logic itself is still
 * a pass-through stub — to be implemented in the next step.
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
            // Organization
            'org_name'      => '',
            'org_url'       => '',
            'org_logo'      => '',
            'org_logo_id'   => 0,
            'org_email'     => '',
            'telephone'     => '',
            'same_as'       => '',

            // LocalBusiness
            'is_local'      => false,
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
            'opening_hours' => '',
            'service_area'  => '',

            // Multi-location
            'locations'     => [],

            // Compatibility
            'override_org'  => false,
        ];
    }

    /* ================================================================== *
     *  Sanitization
     * ================================================================== */

    /**
     * Full sanitize callback for the yse_settings option.
     * Returns the existing option on bad input so we never wipe data.
     */
    public function sanitize_settings( $raw ): array {
        if ( ! is_array( $raw ) ) {
            $existing = get_option( YSE_OPTION_KEY, $this->default_settings() );
            return is_array( $existing ) ? $existing : $this->default_settings();
        }

        $clean = $this->default_settings();

        // Strings
        $clean['org_name']     = sanitize_text_field( (string) ( $raw['org_name']     ?? '' ) );
        $clean['org_url']      = esc_url_raw(         (string) ( $raw['org_url']      ?? '' ) );
        $clean['org_logo']     = esc_url_raw(         (string) ( $raw['org_logo']     ?? '' ) );
        $clean['org_logo_id']  = absint(                         $raw['org_logo_id']  ?? 0   );
        $clean['org_email']    = sanitize_email(      (string) ( $raw['org_email']    ?? '' ) );
        $clean['telephone']    = sanitize_text_field( (string) ( $raw['telephone']    ?? '' ) );
        $clean['same_as']      = $this->sanitize_url_list(       $raw['same_as']      ?? '' );

        // Booleans
        $clean['is_local']     = ! empty( $raw['is_local'] );
        $clean['override_org'] = ! empty( $raw['override_org'] );

        // LocalBusiness subtypes
        $clean['lb_subtype']   = $this->sanitize_lb_subtype( $raw['lb_subtype']  ?? '' );
        $clean['lb_subtype2']  = $this->sanitize_lb_subtype( $raw['lb_subtype2'] ?? '' );
        $clean['lb_subtype3']  = $this->sanitize_lb_subtype( $raw['lb_subtype3'] ?? '' );

        // Address
        $clean['addr_street']  = sanitize_text_field( (string) ( $raw['addr_street']  ?? '' ) );
        $clean['addr_city']    = sanitize_text_field( (string) ( $raw['addr_city']    ?? '' ) );
        $clean['addr_region']  = sanitize_text_field( (string) ( $raw['addr_region']  ?? '' ) );
        $clean['addr_postal']  = sanitize_text_field( (string) ( $raw['addr_postal']  ?? '' ) );
        $clean['addr_country'] = sanitize_text_field( (string) ( $raw['addr_country'] ?? '' ) );

        // Geo
        $clean['geo_lat'] = $this->sanitize_geo( $raw['geo_lat'] ?? '' );
        $clean['geo_lng'] = $this->sanitize_geo( $raw['geo_lng'] ?? '' );

        // Opening hours (JSON validated)
        $clean['opening_hours'] = $this->sanitize_opening_hours( $raw['opening_hours'] ?? '' );

        // Service area (one per line)
        $clean['service_area']  = $this->sanitize_textarea_lines( $raw['service_area'] ?? '' );

        // Multi-location
        if ( isset( $raw['locations'] ) && is_array( $raw['locations'] ) ) {
            $clean['locations'] = array_values( array_map(
                [ $this, 'sanitize_location' ],
                $raw['locations']
            ) );
        }

        return $clean;
    }

    private function sanitize_url_list( $raw ): string {
        $raw = is_string( $raw ) ? $raw : '';
        $out = [];
        foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            $url = esc_url_raw( $line );
            if ( '' !== $url ) {
                $out[] = $url;
            }
        }
        return implode( "\n", $out );
    }

    private function sanitize_textarea_lines( $raw ): string {
        $raw = is_string( $raw ) ? $raw : '';
        $out = [];
        foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
            $line = sanitize_text_field( trim( $line ) );
            if ( '' !== $line ) {
                $out[] = $line;
            }
        }
        return implode( "\n", $out );
    }

    private function sanitize_geo( $raw ): string {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' === $raw || ! is_numeric( $raw ) ) {
            return '';
        }
        return (string) (float) $raw;
    }

    private function sanitize_lb_subtype( $raw ): string {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' === $raw ) {
            return '';
        }
        return array_key_exists( $raw, $this->lb_subtypes() ) ? $raw : '';
    }

    private function sanitize_opening_hours( $raw ): string {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' === $raw ) {
            return '';
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            add_settings_error(
                YSE_OPTION_KEY,
                'yse_opening_hours_invalid',
                __( 'Opening Hours could not be parsed as JSON and was discarded.', 'yse-agency' ),
                'warning'
            );
            return '';
        }
        $encoded = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : '';
    }

    public function sanitize_location( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return $this->blank_location();
        }
        return [
            'name'    => sanitize_text_field( (string) ( $raw['name']    ?? '' ) ),
            'enabled' => ! empty( $raw['enabled'] ),
            'subtype' => $this->sanitize_lb_subtype(    $raw['subtype'] ?? '' ),
            'street'  => sanitize_text_field( (string) ( $raw['street']  ?? '' ) ),
            'city'    => sanitize_text_field( (string) ( $raw['city']    ?? '' ) ),
            'region'  => sanitize_text_field( (string) ( $raw['region']  ?? '' ) ),
            'postal'  => sanitize_text_field( (string) ( $raw['postal']  ?? '' ) ),
            'country' => sanitize_text_field( (string) ( $raw['country'] ?? '' ) ),
            'phone'   => sanitize_text_field( (string) ( $raw['phone']   ?? '' ) ),
            'email'   => sanitize_email(      (string) ( $raw['email']   ?? '' ) ),
        ];
    }

    /* ================================================================== *
     *  Vocabularies / data sources
     * ================================================================== */

    public function blank_location(): array {
        return [
            'name'    => '',
            'enabled' => true,
            'subtype' => '',
            'street'  => '',
            'city'    => '',
            'region'  => '',
            'postal'  => '',
            'country' => '',
            'phone'   => '',
            'email'   => '',
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

    /**
     * Inline admin JS bundle.
     *   1. Copy export JSON
     *   2. WP Media frame for the Organization logo
     *   3. Add / remove multi-location cards
     *   4. Add / remove FAQ rows
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

    /* ================================================================== *
     *  Settings page render
     * ================================================================== */

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'yse-agency' ) );
        }

        // Surface any post-redirect import status as a settings_error.
        if ( isset( $_GET['yse_msg'] ) ) {
            $this->register_status_message( sanitize_key( wp_unslash( $_GET['yse_msg'] ) ) );
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
                <?php settings_fields( 'yse_settings_group' ); ?>

                <div class="yse-card">
                    <div class="yse-card-body">
                        <p class="description">
                            <?php esc_html_e( 'These fields enrich Yoast’s Organization schema node. Empty fields are skipped — Yoast’s value (if any) passes through untouched. Toggle “Treat as LocalBusiness” to add address and geo data.', 'yse-agency' ); ?>
                        </p>

                        <?php $this->render_org_fields( $settings ); ?>

                        <h2 class="yse-section-h2 yse-section-h2--inner">
                            <?php esc_html_e( 'Multiple Locations (Optional)', 'yse-agency' ); ?>
                        </h2>
                        <?php $this->render_locations_section( $settings ); ?>

                        <h2 class="yse-section-h2 yse-section-h2--inner">
                            <?php esc_html_e( 'FAQ Builder (Per-Post)', 'yse-agency' ); ?>
                        </h2>
                        <?php $this->render_faq_info_section(); ?>

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

    private function render_status_table( array $s ): void {
        $yoast_titles = get_option( 'wpseo_titles', [] );
        $yoast_titles = is_array( $yoast_titles ) ? $yoast_titles : [];

        $yoast_name = (string) ( $yoast_titles['company_name'] ?? '' );
        $yoast_logo = (string) ( $yoast_titles['company_logo'] ?? '' );
        $yoast_url  = (string) home_url();

        $override = ! empty( $s['override_org'] );

        $rows = [
            [ 'label' => __( 'Organization Name', 'yse-agency' ), 'yoast' => $yoast_name, 'extender' => (string) $s['org_name']  ],
            [ 'label' => __( 'Website URL',       'yse-agency' ), 'yoast' => $yoast_url,  'extender' => (string) $s['org_url']   ],
            [ 'label' => __( 'Logo',              'yse-agency' ), 'yoast' => $yoast_logo, 'extender' => (string) $s['org_logo']  ],
            [ 'label' => __( 'Contact Email',     'yse-agency' ), 'yoast' => '',          'extender' => (string) $s['org_email'] ],
            [ 'label' => __( 'Telephone',         'yse-agency' ), 'yoast' => '',          'extender' => (string) $s['telephone'] ],
            [
                'label'    => __( 'Schema Type', 'yse-agency' ),
                'yoast'    => __( 'Organization', 'yse-agency' ),
                'extender' => ! empty( $s['is_local'] )
                    ? ( $s['lb_subtype'] !== '' ? $s['lb_subtype'] : 'LocalBusiness' )
                    : '',
            ],
        ];
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
                    <?php foreach ( $rows as $row ) :
                        $y = trim( (string) $row['yoast'] );
                        $e = trim( (string) $row['extender'] );
                        $has_y = $y !== '';
                        $has_e = $e !== '';

                        if ( $has_e && $has_y ) {
                            $effective = $e;
                            $source    = $override ? 'extender' : 'merged';
                        } elseif ( $has_e ) {
                            $effective = $e;
                            $source    = 'extender';
                        } elseif ( $has_y ) {
                            $effective = $y;
                            $source    = $override ? '' : 'yoast';
                        } else {
                            $effective = '';
                            $source    = '';
                        }
                    ?>
                    <tr>
                        <td><?php echo esc_html( $row['label'] ); ?></td>
                        <td class="yse-val-yoast"><?php echo $this->cell( $y ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td class="yse-val-extender"><?php echo $this->cell( $e ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td class="yse-val-effective"><?php echo $this->cell( $effective ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td><?php $this->render_source_badge( $source ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
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
                <td><input id="yse-org-name" type="text" name="<?php echo $this->field_name( 'org_name' ); ?>" value="<?php echo esc_attr( $s['org_name'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-org-url"><?php esc_html_e( 'Organization URL', 'yse-agency' ); ?></label></th>
                <td><input id="yse-org-url" type="url" name="<?php echo $this->field_name( 'org_url' ); ?>" value="<?php echo esc_attr( $s['org_url'] ); ?>" class="regular-text" placeholder="https://example.com" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Organization Logo', 'yse-agency' ); ?></th>
                <td>
                    <div class="yse-logo-picker">
                        <img id="yse-logo-preview" src="<?php echo esc_url( $s['org_logo'] ); ?>" alt="" style="<?php echo $s['org_logo'] !== '' ? '' : 'display:none;'; ?>" />
                        <div>
                            <input id="yse-logo-url" type="url" name="<?php echo $this->field_name( 'org_logo' ); ?>" value="<?php echo esc_attr( $s['org_logo'] ); ?>" class="regular-text" />
                            <input id="yse-logo-id" type="hidden" name="<?php echo $this->field_name( 'org_logo_id' ); ?>" value="<?php echo esc_attr( (string) $s['org_logo_id'] ); ?>" />
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
                <td><input id="yse-org-email" type="email" name="<?php echo $this->field_name( 'org_email' ); ?>" value="<?php echo esc_attr( $s['org_email'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-telephone"><?php esc_html_e( 'Telephone', 'yse-agency' ); ?></label></th>
                <td><input id="yse-telephone" type="text" name="<?php echo $this->field_name( 'telephone' ); ?>" value="<?php echo esc_attr( $s['telephone'] ); ?>" class="regular-text" placeholder="+1 555 123 4567" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-same-as"><?php esc_html_e( 'sameAs URLs', 'yse-agency' ); ?></label></th>
                <td>
                    <textarea id="yse-same-as" name="<?php echo $this->field_name( 'same_as' ); ?>" rows="5" class="large-text code"><?php echo esc_textarea( $s['same_as'] ); ?></textarea>
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
                        <?php $this->render_subtype_select( 'lb_subtype',  $s['lb_subtype']  ); ?>
                        <?php $this->render_subtype_select( 'lb_subtype2', $s['lb_subtype2'] ); ?>
                        <?php $this->render_subtype_select( 'lb_subtype3', $s['lb_subtype3'] ); ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Up to three Schema.org LocalBusiness subtypes. Most businesses only need the first.', 'yse-agency' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-street"><?php esc_html_e( 'Street Address', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-street" type="text" name="<?php echo $this->field_name( 'addr_street' ); ?>" value="<?php echo esc_attr( $s['addr_street'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-city"><?php esc_html_e( 'City', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-city" type="text" name="<?php echo $this->field_name( 'addr_city' ); ?>" value="<?php echo esc_attr( $s['addr_city'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-region"><?php esc_html_e( 'State / Region', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-region" type="text" name="<?php echo $this->field_name( 'addr_region' ); ?>" value="<?php echo esc_attr( $s['addr_region'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-postal"><?php esc_html_e( 'Postal Code', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-postal" type="text" name="<?php echo $this->field_name( 'addr_postal' ); ?>" value="<?php echo esc_attr( $s['addr_postal'] ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-addr-country"><?php esc_html_e( 'Country', 'yse-agency' ); ?></label></th>
                <td><input id="yse-addr-country" type="text" name="<?php echo $this->field_name( 'addr_country' ); ?>" value="<?php echo esc_attr( $s['addr_country'] ); ?>" class="regular-text" placeholder="US, GB, AU…" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-geo-lat"><?php esc_html_e( 'Latitude / Longitude', 'yse-agency' ); ?></label></th>
                <td>
                    <input id="yse-geo-lat" type="text" name="<?php echo $this->field_name( 'geo_lat' ); ?>" value="<?php echo esc_attr( $s['geo_lat'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Latitude', 'yse-agency' ); ?>" />
                    <input id="yse-geo-lng" type="text" name="<?php echo $this->field_name( 'geo_lng' ); ?>" value="<?php echo esc_attr( $s['geo_lng'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Longitude', 'yse-agency' ); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-opening-hours"><?php esc_html_e( 'Opening Hours (JSON)', 'yse-agency' ); ?></label></th>
                <td>
                    <textarea id="yse-opening-hours" name="<?php echo $this->field_name( 'opening_hours' ); ?>" rows="6" class="large-text code"><?php echo esc_textarea( $s['opening_hours'] ); ?></textarea>
                    <p class="description"><?php echo wp_kses(
                        __( 'JSON array of <code>OpeningHoursSpecification</code> objects. Example: <code>[{"dayOfWeek":"Monday","opens":"09:00","closes":"17:00"}]</code>', 'yse-agency' ),
                        [ 'code' => [] ]
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="yse-service-area"><?php esc_html_e( 'Service Area', 'yse-agency' ); ?></label></th>
                <td>
                    <textarea id="yse-service-area" name="<?php echo $this->field_name( 'service_area' ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $s['service_area'] ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One city or region per line.', 'yse-agency' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_subtype_select( string $field, string $current ): void {
        ?>
        <select name="<?php echo $this->field_name( $field ); ?>">
            <option value=""><?php esc_html_e( '— None —', 'yse-agency' ); ?></option>
            <?php foreach ( $this->lb_subtypes() as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /* ------------------------ Locations section ----------------------- */

    private function render_locations_section( array $s ): void {
        $locations = is_array( $s['locations'] ?? null ) ? $s['locations'] : [];
        ?>
        <p class="description">
            <?php esc_html_e( 'Add additional physical locations. Each enabled location is emitted as its own LocalBusiness node in the schema graph.', 'yse-agency' ); ?>
        </p>

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
                    <input type="checkbox" name="<?php echo $this->field_name( 'locations', $idx, 'enabled' ); ?>" value="1" <?php checked( ! empty( $loc['enabled'] ) ); ?> />
                    <?php esc_html_e( 'Enabled', 'yse-agency' ); ?>
                </label>
                <button type="button" class="button-link yse-toggle-location"><?php esc_html_e( 'Collapse', 'yse-agency' ); ?></button>
                <button type="button" class="button-link yse-remove-location"><?php esc_html_e( 'Remove', 'yse-agency' ); ?></button>
            </div>
            <div class="yse-location-body">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Name', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'locations', $idx, 'name' ); ?>" value="<?php echo esc_attr( $loc['name'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Subtype', 'yse-agency' ); ?></th>
                        <td>
                            <select name="<?php echo $this->field_name( 'locations', $idx, 'subtype' ); ?>">
                                <option value=""><?php esc_html_e( '— LocalBusiness —', 'yse-agency' ); ?></option>
                                <?php foreach ( $this->lb_subtypes() as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $loc['subtype'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Street', 'yse-agency' ); ?></th>
                        <td><input type="text" name="<?php echo $this->field_name( 'locations', $idx, 'street' ); ?>" value="<?php echo esc_attr( $loc['street'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'City / Region', 'yse-agency' ); ?></th>
                        <td>
                            <input type="text" name="<?php echo $this->field_name( 'locations', $idx, 'city' ); ?>"   value="<?php echo esc_attr( $loc['city'] );   ?>" class="regular-text" placeholder="<?php esc_attr_e( 'City',   'yse-agency' ); ?>" />
                            <input type="text" name="<?php echo $this->field_name( 'locations', $idx, 'region' ); ?>" value="<?php echo esc_attr( $loc['region'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Region', 'yse-agency' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Postal / Country', 'yse-agency' ); ?></th>
                        <td>
                            <input type="text" name="<?php echo $this->field_name( 'locations', $idx, 'postal' ); ?>"  value="<?php echo esc_attr( $loc['postal'] );  ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Postal',  'yse-agency' ); ?>" />
                            <input type="text" name="<?php echo $this->field_name( 'locations', $idx, 'country' ); ?>" value="<?php echo esc_attr( $loc['country'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Country', 'yse-agency' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Phone / Email', 'yse-agency' ); ?></th>
                        <td>
                            <input type="text"  name="<?php echo $this->field_name( 'locations', $idx, 'phone' ); ?>" value="<?php echo esc_attr( $loc['phone'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Phone', 'yse-agency' ); ?>" />
                            <input type="email" name="<?php echo $this->field_name( 'locations', $idx, 'email' ); ?>" value="<?php echo esc_attr( $loc['email'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Email', 'yse-agency' ); ?>" />
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /* ------------------------ FAQ info section ------------------------ */

    private function render_faq_info_section(): void {
        ?>
        <p class="description">
            <?php esc_html_e( 'The FAQ Builder is a per-post tool. Open any post or page in the editor and look for the “FAQ Schema Builder” meta box to add FAQ items. Saved FAQs are emitted as a FAQPage node on that post’s URL.', 'yse-agency' ); ?>
        </p>
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
     *   yse_settings[locations][0][name]
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
            'import_ok'      => [ 'success', __( 'Settings imported successfully.', 'yse-agency' ) ],
            'import_invalid' => [ 'error',   __( 'Import failed: not valid JSON.', 'yse-agency' ) ],
            'import_empty'   => [ 'warning', __( 'Import was empty — no changes made.', 'yse-agency' ) ],
        ];
        if ( ! isset( $messages[ $key ] ) ) {
            return;
        }
        [ $type, $text ] = $messages[ $key ];
        add_settings_error( YSE_OPTION_KEY, 'yse_' . $key, $text, $type );
    }

    /* ================================================================== *
     *  Import handler
     * ================================================================== */

    public function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'yse-agency' ) );
        }
        check_admin_referer( 'yse_import' );

        $json = '';

        // Uploaded file takes precedence.
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

        // Fall back to pasted textarea content.
        if ( '' === $json && isset( $_POST['yse_import_json'] ) ) {
            $json = (string) wp_unslash( $_POST['yse_import_json'] );
        }

        $msg = 'import_empty';

        if ( '' !== trim( $json ) ) {
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) ) {
                update_option( YSE_OPTION_KEY, $this->sanitize_settings( $decoded ) );
                $msg = 'import_ok';
            } else {
                $msg = 'import_invalid';
            }
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => YSE_PAGE_SLUG, 'yse_msg' => $msg ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ================================================================== *
     *  FAQ meta box (scaffold — no fields yet)
     * ================================================================== */

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

    /* ================================================================== *
     *  Schema filter hookup (stub — pass-through only)
     * ================================================================== */

    private function hook_schema_filters(): void {
        add_filter( 'wpseo_schema_graph', [ $this, 'filter_schema_graph' ], 20, 2 );
    }

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
