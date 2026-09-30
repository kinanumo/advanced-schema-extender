<?php
/**
 * Main plugin class file.
 *
 * @package AdvancedSchemaExtender
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class ASE_Agency_UI {

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static ?ASE_Agency_UI $instance = null;
	/**
	 * Pending invalid LocalBusiness subtype values, keyed by setting.
	 *
	 * @var array<string, array{label: string, value: string}>
	 */
	private array $pending_subtype_errors = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Initialize WordPress hooks. */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_advanced_schema_extender_import', array( $this, 'handle_import' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 1 );

		$this->hook_schema_filters();
	}

	/** Prevent cloning the singleton. */
	private function __clone() {}

	/**
	 * Prevent unserialization of the singleton.
	 *
	 * @throws \RuntimeException Always, to prevent duplicate instances.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'ASE_Agency_UI cannot be unserialized.' );
	}

	/** Register the plugin settings page. */
	public function register_menu(): void {
		add_options_page(
			__( 'Advanced Schema Extender for Yoast', 'advanced-schema-extender' ),
			__( 'Schema Extender', 'advanced-schema-extender' ),
			'manage_options',
			ASE_PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Per spec: option_group AND option_name are both ASE_OPTION_KEY.
	 */
	public function register_settings(): void {
		register_setting(
			ASE_OPTION_KEY,
			ASE_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->default_settings(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Get default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings(): array {
		return array(
			// Organization.
			'org_name'       => '',
			'org_url'        => '',
			'org_logo'       => '',
			'org_email'      => '',
			'telephone'      => '',
			'same_as'        => array(),

			// LocalBusiness: single primary node.
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
			'opening_hours'  => array(),
			'service_area'   => array(),

			// Multi-location (separate from primary LocalBusiness).
			'ml_enabled'     => false,
			'ml_locations'   => array(),

			// FAQ.
			'faq_post_types' => array(),

			// Compatibility.
			'override_org'   => false,
		);
	}

	/** Sanitize submitted plugin settings. */

	/**
	 * Robust sanitizer for the ase_settings option.
	 *
	 * - Accepts arrays from the settings form, arrays decoded from JSON imports,
	 *   or already-sanitized arrays (re-save without form change).
	 * - Defends against "Array to string conversion" by routing every textarea-
	 *   style field through helpers that accept either string OR array input.
	 * - Falls back to existing saved values for fields that fail validation
	 *   (e.g. invalid JSON), and surfaces friendly notices via add_settings_error.
	 * - Whitelists keys so only the documented v2.5.5 fields are persisted.
	 *
	 * @param mixed $raw Submitted settings, either as an array or another value.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize_settings( $raw ): array {
		$this->pending_subtype_errors = array();

		if ( ! is_array( $raw ) ) {
			$existing = get_option( ASE_OPTION_KEY, $this->default_settings() );
			return is_array( $existing ) ? $existing : $this->default_settings();
		}

		// Existing values are the fallback target for fields that fail validation.
		$existing = get_option( ASE_OPTION_KEY, array() );
		$existing = is_array( $existing ) ? wp_parse_args( $existing, $this->default_settings() ) : $this->default_settings();

		$clean = $this->default_settings();

		// ---- Organization (scalar fields) -----------------------------------
		$clean['org_name']  = sanitize_text_field( (string) ( $raw['org_name'] ?? '' ) );
		$clean['org_url']   = esc_url_raw( (string) ( $raw['org_url'] ?? '' ) );
		$clean['org_logo']  = esc_url_raw( (string) ( $raw['org_logo'] ?? '' ) );
		$clean['org_email'] = sanitize_email( (string) ( $raw['org_email'] ?? '' ) );
		$clean['telephone'] = sanitize_text_field( (string) ( $raw['telephone'] ?? '' ) );

		// ---- Multi-line / array fields --------------------------------------
		$clean['same_as']       = $this->sanitize_lines_as_urls( $raw['same_as'] ?? '' );
		$clean['service_area']  = $this->sanitize_lines_as_text( $raw['service_area'] ?? '' );
		$clean['opening_hours'] = $this->normalize_opening_hours_specifications(
			$this->sanitize_json_field(
				$raw['opening_hours'] ?? '',
				$existing['opening_hours'] ?? array(),
				'opening_hours',
				__( 'Opening Hours', 'advanced-schema-extender' )
			)
		);

		// ---- LocalBusiness toggle + subtypes --------------------------------
		$clean['is_local']    = ! empty( $raw['is_local'] );
		$clean['lb_subtype']  = $this->sanitize_lb_subtype(
			$raw['lb_subtype'] ?? '',
			(string) ( $existing['lb_subtype'] ?? '' ),
			'lb_subtype',
			__( 'LocalBusiness subtype #1', 'advanced-schema-extender' )
		);
		$clean['lb_subtype2'] = $this->sanitize_lb_subtype(
			$raw['lb_subtype2'] ?? '',
			(string) ( $existing['lb_subtype2'] ?? '' ),
			'lb_subtype2',
			__( 'LocalBusiness subtype #2', 'advanced-schema-extender' )
		);
		$clean['lb_subtype3'] = $this->sanitize_lb_subtype(
			$raw['lb_subtype3'] ?? '',
			(string) ( $existing['lb_subtype3'] ?? '' ),
			'lb_subtype3',
			__( 'LocalBusiness subtype #3', 'advanced-schema-extender' )
		);

		// ---- Address --------------------------------------------------------
		$clean['addr_street']  = sanitize_text_field( (string) ( $raw['addr_street'] ?? '' ) );
		$clean['addr_city']    = sanitize_text_field( (string) ( $raw['addr_city'] ?? '' ) );
		$clean['addr_region']  = sanitize_text_field( (string) ( $raw['addr_region'] ?? '' ) );
		$clean['addr_postal']  = sanitize_text_field( (string) ( $raw['addr_postal'] ?? '' ) );
		$clean['addr_country'] = sanitize_text_field( (string) ( $raw['addr_country'] ?? '' ) );

		// ---- Geo ------------------------------------------------------------
		$clean['geo_lat'] = $this->sanitize_geo( $raw['geo_lat'] ?? '' );
		$clean['geo_lng'] = $this->sanitize_geo( $raw['geo_lng'] ?? '' );

		// ---- Multi-location -------------------------------------------------
		$clean['ml_enabled'] = ! empty( $raw['ml_enabled'] );
		if ( isset( $raw['ml_locations'] ) && is_array( $raw['ml_locations'] ) ) {
			$existing_locations    = is_array( $existing['ml_locations'] ?? null ) ? $existing['ml_locations'] : array();
			$clean['ml_locations'] = array();

			foreach ( $raw['ml_locations'] as $location_key => $location_raw ) {
				$fallback_location = is_array( $existing_locations[ $location_key ] ?? null ) ? $existing_locations[ $location_key ] : array();

				$clean['ml_locations'][] = $this->sanitize_location(
					$location_raw,
					(string) $location_key,
					$fallback_location
				);
			}
		}

		// ---- FAQ post types -------------------------------------------------
		$clean['faq_post_types'] = $this->sanitize_post_types( $raw['faq_post_types'] ?? array() );

		// ---- Compatibility --------------------------------------------------
		$clean['override_org'] = ! empty( $raw['override_org'] );

		$this->flush_pending_subtype_errors();

		return $clean;
	}

	/**
	 * Accept either a textarea string (one URL per line) or an existing array.
	 * Returns an array of unique, validated URLs.
	 *
	 * @param mixed $raw Newline-delimited string or array of URLs.
	 * @return string[] Valid unique URLs.
	 */
	private function sanitize_lines_as_urls( $raw ): array {
		$items = $this->coerce_to_lines( $raw );
		$out   = array();
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
	 *
	 * @param mixed $raw Newline-delimited string or array of text.
	 * @return string[] Valid unique text lines.
	 */
	private function sanitize_lines_as_text( $raw ): array {
		$items = $this->coerce_to_lines( $raw );
		$out   = array();
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
	 * @return array Decoded JSON array, or the fallback value.
	 */
	private function sanitize_json_field( $raw, $fallback, string $field_key, string $human_label ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) ) {
			return is_array( $fallback ) ? $fallback : array();
		}
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$hint = json_last_error_msg();
			add_settings_error(
				ASE_OPTION_KEY,
				'ase_json_invalid_' . $field_key,
				sprintf(
					/* translators: 1: human label, 2: parser message */
					__( '%1$s could not be saved: invalid JSON (%2$s). Expected a JSON array, e.g. <code>[{"key":"value"}]</code>. Your previous value was kept.', 'advanced-schema-extender' ),
					esc_html( $human_label ),
					esc_html( $hint )
				),
				'error'
			);
			return is_array( $fallback ) ? $fallback : array();
		}

		return $decoded;
	}

	/**
	 * Ensure each opening-hours entry declares @type OpeningHoursSpecification.
	 *
	 * @param array $items Opening-hours entries.
	 * @return array Normalized entries.
	 */
	private function normalize_opening_hours_specifications( array $items ): array {
		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item['@type'] = 'OpeningHoursSpecification';
			$normalized[]  = $item;
		}

		return $normalized;
	}

	/**
	 * Coerce a textarea string OR an array into a flat list of scalar lines.
	 * Never produces "Array to string conversion" warnings.
	 *
	 * @param mixed $raw Newline-delimited string or array.
	 * @return string[] Scalar lines.
	 */
	private function coerce_to_lines( $raw ): array {
		if ( is_array( $raw ) ) {
			$out = array();
			foreach ( $raw as $item ) {
				if ( is_scalar( $item ) ) {
					$out[] = (string) $item;
				}
			}
			return $out;
		}
		if ( is_string( $raw ) ) {
			$lines = preg_split( '/[\r\n]+/', $raw );
			return false !== $lines ? $lines : array();
		}
		return array();
	}

	/**
	 * Sanitize a geographic coordinate.
	 *
	 * @param mixed $raw Coordinate input.
	 * @return string Sanitized coordinate, or an empty string.
	 */
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

	/**
	 * Sanitize a LocalBusiness subtype and retain a valid fallback.
	 *
	 * @param mixed  $raw         Submitted subtype.
	 * @param string $fallback    Previously saved subtype.
	 * @param string $field_key   Settings error key.
	 * @param string $human_label Label shown in settings errors.
	 * @return string Valid subtype or empty string.
	 */
	private function sanitize_lb_subtype( $raw, string $fallback = '', string $field_key = '', string $human_label = '' ): string {
		if ( is_array( $raw ) ) {
			$raw = '';
		}
		$raw = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
		if ( '' !== $raw && $this->is_valid_lb_subtype( $raw ) ) {
			return $raw;
		}

		if ( '' !== $raw && '' !== $field_key ) {
			$this->pending_subtype_errors[ $field_key ] = array(
				'label' => '' !== $human_label ? $human_label : $field_key,
				'value' => $raw,
			);
		}

		$fallback = trim( $fallback );
		if ( '' !== $fallback && $this->is_valid_lb_subtype( $fallback ) ) {
			return $fallback;
		}

		return '';
	}

	/** Add an admin settings error for invalid subtype values. */
	private function flush_pending_subtype_errors(): void {
		if ( empty( $this->pending_subtype_errors ) ) {
			return;
		}

		$parts = array();
		foreach ( $this->pending_subtype_errors as $item ) {
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';
			$value = isset( $item['value'] ) ? (string) $item['value'] : '';
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$parts[] = sprintf( '%1$s: "%2$s"', $label, $value );
		}

		if ( ! empty( $parts ) ) {
			add_settings_error(
				ASE_OPTION_KEY,
				'ase_invalid_lb_subtypes',
				sprintf(
					/* translators: %s: comma-separated list of invalid subtype fields and values. */
					__( 'Some LocalBusiness subtype values could not be saved and previous values were kept: %s', 'advanced-schema-extender' ),
					esc_html( implode( ', ', $parts ) )
				),
				'error'
			);
		}

		$this->pending_subtype_errors = array();
	}

	/**
	 * Check whether a subtype is in the built-in or custom schema format.
	 *
	 * @param string $value Subtype value to check.
	 * @return bool Whether the subtype is valid.
	 */
	private function is_valid_lb_subtype( string $value ): bool {
		if ( array_key_exists( $value, $this->lb_subtypes() ) ) {
			return true;
		}

		return 1 === preg_match( '/^[A-Z][A-Za-z0-9]+$/', $value );
	}

	/**
	 * Keep only public post types from submitted values.
	 *
	 * @param mixed $raw Submitted post type slugs.
	 * @return string[] Valid public post type slugs.
	 */
	private function sanitize_post_types( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$valid = array_keys( get_post_types( array( 'public' => true ) ) );
		$out   = array();
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

	/**
	 * Sanitize one multi-location settings entry.
	 *
	 * @param mixed  $raw           Submitted location values.
	 * @param string $location_key  Location index used in error keys.
	 * @param array  $fallback      Previously saved values.
	 * @return array Sanitized location settings.
	 */
	public function sanitize_location( $raw, string $location_key = '', array $fallback = array() ): array {
		if ( ! is_array( $raw ) ) {
			return $this->blank_location();
		}

		$fallback = wp_parse_args( $fallback, $this->blank_location() );

		$field_prefix = 'ml_location';
		if ( '' !== $location_key ) {
			$field_prefix .= '_' . sanitize_key( $location_key );
		}

		$location_label = __( 'Location', 'advanced-schema-extender' );
		if ( '' !== $location_key && is_numeric( $location_key ) ) {
			$location_label = sprintf(
				/* translators: %d: Location number. */
				__( 'Location #%d', 'advanced-schema-extender' ),
				( (int) $location_key ) + 1
			);
		}

		return array(
			'name'          => sanitize_text_field( (string) ( $raw['name'] ?? '' ) ),
			'enabled'       => ! empty( $raw['enabled'] ),
			'page_slug'     => sanitize_title( (string) ( $raw['page_slug'] ?? '' ) ),
			'url'           => esc_url_raw( (string) ( $raw['url'] ?? '' ) ),
			'image'         => esc_url_raw( (string) ( $raw['image'] ?? '' ) ),
			'telephone'     => sanitize_text_field( (string) ( $raw['telephone'] ?? '' ) ),
			'email'         => sanitize_email( (string) ( $raw['email'] ?? '' ) ),
			'priceRange'    => sanitize_text_field( (string) ( $raw['priceRange'] ?? '' ) ),
			'lb_subtype'    => $this->sanitize_lb_subtype(
				$raw['lb_subtype'] ?? '',
				(string) ( $fallback['lb_subtype'] ?? '' ),
				$field_prefix . '_lb_subtype',
				/* translators: %1$s: Location label. */
				sprintf( __( '%1$s subtype #1', 'advanced-schema-extender' ), $location_label )
			),
			'lb_subtype2'   => $this->sanitize_lb_subtype(
				$raw['lb_subtype2'] ?? '',
				(string) ( $fallback['lb_subtype2'] ?? '' ),
				$field_prefix . '_lb_subtype2',
				/* translators: %1$s: Location label. */
				sprintf( __( '%1$s subtype #2', 'advanced-schema-extender' ), $location_label )
			),
			'lb_subtype3'   => $this->sanitize_lb_subtype(
				$raw['lb_subtype3'] ?? '',
				(string) ( $fallback['lb_subtype3'] ?? '' ),
				$field_prefix . '_lb_subtype3',
				/* translators: %1$s: Location label. */
				sprintf( __( '%1$s subtype #3', 'advanced-schema-extender' ), $location_label )
			),
			'addr_street'   => sanitize_text_field( (string) ( $raw['addr_street'] ?? '' ) ),
			'addr_city'     => sanitize_text_field( (string) ( $raw['addr_city'] ?? '' ) ),
			'addr_region'   => sanitize_text_field( (string) ( $raw['addr_region'] ?? '' ) ),
			'addr_postal'   => sanitize_text_field( (string) ( $raw['addr_postal'] ?? '' ) ),
			'addr_country'  => sanitize_text_field( (string) ( $raw['addr_country'] ?? '' ) ),
			'geo_lat'       => $this->sanitize_geo( $raw['geo_lat'] ?? '' ),
			'geo_lng'       => $this->sanitize_geo( $raw['geo_lng'] ?? '' ),
			'service_area'  => $this->sanitize_lines_as_text( $raw['service_area'] ?? '' ),
			'opening_hours' => $this->normalize_opening_hours_specifications(
				$this->sanitize_json_field(
					$raw['opening_hours'] ?? '',
					array(),
					'location_opening_hours',
					__( 'Location Opening Hours', 'advanced-schema-extender' )
				)
			),
		);
	}


	/**
	 * Get a blank multi-location settings record.
	 *
	 * @return array<string, mixed> Default location values.
	 */
	public function blank_location(): array {
		return array(
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
			'service_area'  => array(),
			'opening_hours' => array(),
		);
	}

	/**
	 * Get supported LocalBusiness subtypes and their labels.
	 *
	 * @return array<string, string> Subtype names mapped to labels.
	 */
	public function lb_subtypes(): array {
		return array(
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
		);
	}


	/** Turn an array (or string fallback) into a textarea-ready newline string. */
	/**
	 * Convert text lines or an array into a textarea string.
	 *
	 * @param mixed $value Text lines or values.
	 * @return string Newline-delimited text.
	 */
	private function lines_to_text( $value ): string {
		if ( is_array( $value ) ) {
			$items = array();
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
	/**
	 * Convert an array or JSON string into formatted JSON for a textarea.
	 *
	 * @param mixed $value JSON string or array.
	 * @return string Formatted JSON, or an empty string.
	 */
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


	/**
	 * Enqueue admin assets on plugin and post-edit screens.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$allowed = array(
			'settings_page_' . ASE_PAGE_SLUG,
			'post.php',
			'post-new.php',
		);
		if ( ! in_array( $hook, $allowed, true ) ) {
			return;
		}

		wp_enqueue_style( 'ase-admin', ASE_URL . 'assets/admin.css', array(), ASE_VERSION );

		wp_enqueue_media();

		wp_register_script( 'ase-admin', '', array( 'jquery' ), ASE_VERSION, true );
		wp_enqueue_script( 'ase-admin' );
		wp_add_inline_script( 'ase-admin', $this->inline_admin_js() );
	}

	/**
	 * Get inline JavaScript used by the settings page.
	 *
	 * @return string Settings-page script.
	 */
	private function inline_admin_js(): string {
		return <<<'JS'
(function($){
    'use strict';

    function toggleLocalBusinessOnlyRows() {
        var enabled = $('#ase-is-local').is(':checked');
        $('.ase-localbusiness-only').toggleClass('ase-hidden-row', !enabled);
    }

    /* 1. Copy export JSON */
    $(document).on('click', '.ase-copy-export', function(e){
        e.preventDefault();
        var $btn  = $(this);
        var $text = $('#ase-export-json');
        if ( ! $text.length ) { return; }
        $text.trigger('select');
        try {
            document.execCommand('copy');
            var orig = $btn.text();
            $btn.text($btn.data('copied') || 'Copied!');
            setTimeout(function(){ $btn.text(orig); }, 1500);
        } catch (err) {
            window.console && console.warn('ASE copy failed', err);
        }
    });

    /* 2. WP Media — logo picker */
    var logoFrame;
    $(document).on('click', '.ase-pick-logo', function(e){
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
            $('#ase-logo-url').val(att.url);
            $('#ase-logo-preview').attr('src', att.url).show();
            $('.ase-clear-logo').show();
        });
        logoFrame.open();
    });
    $(document).on('click', '.ase-clear-logo', function(e){
        e.preventDefault();
        $('#ase-logo-url').val('');
        $('#ase-logo-preview').attr('src', '').hide();
        $(this).hide();
    });

    /* 3. Multi-location cards */
    $(document).on('click', '.ase-add-location', function(e){
        e.preventDefault();
        var $list = $('#ase-locations-list');
        var tpl   = $('#ase-location-template').html();
        if ( ! tpl ) { return; }
        var idx = $list.children('.ase-location-card').length;
        $list.append( tpl.replace(/__INDEX__/g, idx) );
    });
    $(document).on('click', '.ase-remove-location', function(e){
        e.preventDefault();
        $(this).closest('.ase-location-card').remove();
    });
    $(document).on('click', '.ase-toggle-location', function(e){
        e.preventDefault();
        $(this).closest('.ase-location-card').toggleClass('is-collapsed');
    });

    /* 4. FAQ rows */
    $(document).on('click', '.ase-add-faq', function(e){
        e.preventDefault();
        var $list = $('#ase-faq-list');
        var tpl   = $('#ase-faq-template').html();
        if ( ! tpl ) { return; }
        var idx = $list.children('.ase-faq-row').length;
        $list.append( tpl.replace(/__INDEX__/g, idx) );
    });
    $(document).on('click', '.ase-remove-faq', function(e){
        e.preventDefault();
        $(this).closest('.ase-faq-row').remove();
    });

    /* 5. LocalBusiness field visibility + opening-hours sample */
    $(document).on('change', '#ase-is-local', toggleLocalBusinessOnlyRows);

    $(document).on('click', '.ase-generate-opening-hours', function(e){
        e.preventDefault();
        var $target = $('#ase-opening-hours');
        if ( ! $target.length ) { return; }
        if ( $.trim($target.val()) !== '' ) { return; }

        var sample = [
            { '@type': 'OpeningHoursSpecification', dayOfWeek: 'Monday',    opens: '09:00', closes: '17:00' },
            { '@type': 'OpeningHoursSpecification', dayOfWeek: 'Tuesday',   opens: '09:00', closes: '17:00' },
            { '@type': 'OpeningHoursSpecification', dayOfWeek: 'Wednesday', opens: '09:00', closes: '17:00' },
            { '@type': 'OpeningHoursSpecification', dayOfWeek: 'Thursday',  opens: '09:00', closes: '17:00' },
            { '@type': 'OpeningHoursSpecification', dayOfWeek: 'Friday',    opens: '09:00', closes: '17:00' }
        ];

        $target.val(JSON.stringify(sample, null, 2)).trigger('change');
    });

    toggleLocalBusinessOnlyRows();

})(jQuery);

(function() {
    'use strict';

    function initStickySaveBar() {
        var form = document.getElementById('ase-settings-form');
        var stickyBar = document.getElementById('ase-sticky-save-bar');
        var originalSubmit = document.getElementById('ase-save-settings');
        var originalSubmitArea = originalSubmit ? originalSubmit.closest('p.submit') : null;
        var pageBody = stickyBar ? stickyBar.closest('.ase-page-body') : null;

        if ( ! form || ! stickyBar || ! originalSubmit || ! originalSubmitArea || ! pageBody ) {
            return;
        }

        var isDirty = false;
        var isOriginalVisible = false;

        function syncStickyBar() {
            updateStickyBarBounds();
            stickyBar.hidden = ! isDirty || isOriginalVisible;
        }

        function updateStickyBarBounds() {
            var rect = pageBody.getBoundingClientRect();
            var inset = window.innerWidth <= 782 ? 16 : 22;
            stickyBar.style.left = (rect.left + inset) + 'px';
            stickyBar.style.width = Math.max(rect.width - (inset * 2), 0) + 'px';
        }

        function updateOriginalVisibility() {
            var rect = originalSubmitArea.getBoundingClientRect();
            isOriginalVisible = rect.top < window.innerHeight && rect.bottom > 0;
            syncStickyBar();
        }

        function markDirty() {
            isDirty = true;
            syncStickyBar();
        }

        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);

        document.addEventListener('click', function(event) {
            if ( event.target.closest('.ase-add-location, .ase-remove-location, .ase-add-faq, .ase-remove-faq, .ase-generate-opening-hours, .ase-clear-logo, .ase-pick-logo') ) {
                markDirty();
            }
        });

        form.addEventListener('submit', function() {
            stickyBar.hidden = true;
        });

        if ( 'IntersectionObserver' in window ) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    isOriginalVisible = entry.isIntersecting;
                    syncStickyBar();
                });
            }, { threshold: 0.75 });

            observer.observe(originalSubmitArea);
            updateOriginalVisibility();
        } else {
            window.addEventListener('scroll', updateOriginalVisibility, { passive: true });
            window.addEventListener('resize', updateOriginalVisibility);
            updateOriginalVisibility();
        }

        window.addEventListener('resize', updateStickyBarBounds);
        window.addEventListener('scroll', updateStickyBarBounds, { passive: true });
        updateStickyBarBounds();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', initStickySaveBar);
    } else {
        initStickySaveBar();
    }
})();
JS;
	}


	/** Render the plugin settings page. */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'advanced-schema-extender' ) );
		}

		$import_status = filter_input( INPUT_GET, 'advanced_schema_extender_import', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( is_string( $import_status ) ) {
			$this->register_status_message( sanitize_key( $import_status ) );
		}

		$settings = wp_parse_args(
			(array) get_option( ASE_OPTION_KEY, array() ),
			$this->default_settings()
		);
		?>
		<?php $this->render_settings_notice_area(); ?>
		<div class="wrap ase-wrap">
			<div class="ase-page-shell">
				<div class="ase-topbar">
					<div class="ase-topbar-title">
						<span class="ase-page-title" role="heading" aria-level="1"><?php esc_html_e( 'Advanced Schema Extender for Yoast', 'advanced-schema-extender' ); ?></span>
					</div>
					<span class="ase-version">v<?php echo esc_html( ASE_VERSION ); ?></span>
				</div>

				<div class="ase-page-body">
					<?php $this->render_status_table( $settings ); ?>

					<h2 class="ase-section-h2"><?php esc_html_e( 'Organization & LocalBusiness', 'advanced-schema-extender' ); ?></h2>

					<form id="ase-settings-form" method="post" action="options.php">
						<?php settings_fields( ASE_OPTION_KEY ); ?>

						<div class="ase-card">
							<div class="ase-card-body">
								<p class="description">
									<?php esc_html_e( 'These fields enrich your Organization schema node. Empty fields are skipped. Toggle “Treat as LocalBusiness” to add address, hours, and geo data.', 'advanced-schema-extender' ); ?>
								</p>

								<?php $this->render_org_fields( $settings ); ?>

								<h2 class="ase-section-h2 ase-section-h2--inner">
									<?php esc_html_e( 'Multiple Locations (Optional)', 'advanced-schema-extender' ); ?>
								</h2>
								<?php $this->render_locations_section( $settings ); ?>

								<h2 class="ase-section-h2 ase-section-h2--inner">
									<?php esc_html_e( 'FAQ Builder (Per-Post)', 'advanced-schema-extender' ); ?>
								</h2>
								<?php $this->render_faq_settings( $settings ); ?>

								<h2 class="ase-section-h2 ase-section-h2--inner">
									<?php esc_html_e( 'Compatibility', 'advanced-schema-extender' ); ?>
								</h2>
								<?php $this->render_compat_fields( $settings ); ?>

								<?php submit_button( __( 'Save Settings', 'advanced-schema-extender' ), 'primary', 'ase-save-settings' ); ?>
							</div>
						</div>
					</form>

					<div id="ase-sticky-save-bar" class="ase-sticky-save-bar" hidden>
						<span class="ase-sticky-save-text"><?php esc_html_e( 'Unsaved changes', 'advanced-schema-extender' ); ?></span>
						<button type="submit" class="button button-primary" form="ase-settings-form">
							<?php esc_html_e( 'Save Settings', 'advanced-schema-extender' ); ?>
						</button>
					</div>

					<?php $this->render_import_export_panel( $settings ); ?>

					<p class="ase-credit">
						<?php esc_html_e( 'By ', 'advanced-schema-extender' ); ?>
						<a href="<?php echo esc_url( 'https://neirdkc.xyz' ); ?>" rel="sponsored"><?php esc_html_e( 'neirDKC', 'advanced-schema-extender' ); ?></a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/** Render saved-settings and validation notices. */
	private function render_settings_notice_area(): void {
		ob_start();

		$settings_updated = filter_input( INPUT_GET, 'settings-updated', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( 'true' === $settings_updated ) {
			?>
			<div class="notice notice-success settings-error is-dismissible ase-settings-updated">
				<p><strong><?php esc_html_e( 'Settings saved.', 'advanced-schema-extender' ); ?></strong></p>
			</div>
			<?php
		}

		settings_errors( ASE_OPTION_KEY );

		$notices = trim( (string) ob_get_clean() );
		if ( '' === $notices ) {
			return;
		}

		echo '<div class="ase-settings-notices">' . wp_kses_post( $notices ) . '</div>';
	}


	/**
	 * Build status rows comparing baseline/site defaults to Extender values.
	 *
	 * Baseline values are read directly from the wpseo_titles option and home_url().
	 *
	 * @param array $s Current plugin settings.
	 * @return array<int, array<string, string>> Status table rows.
	 */
	private function build_status_rows( array $s ): array {
		$baseline_titles = get_option( 'wpseo_titles', array() );
		$baseline_titles = is_array( $baseline_titles ) ? $baseline_titles : array();

		$rows = array(
			array(
				'label'    => __( 'Organization Name', 'advanced-schema-extender' ),
				'baseline' => (string) ( $baseline_titles['company_name'] ?? '' ),
				'extender' => (string) ( $s['org_name'] ?? '' ),
			),
			array(
				'label'    => __( 'Website URL', 'advanced-schema-extender' ),
				'baseline' => (string) home_url(),
				'extender' => (string) ( $s['org_url'] ?? '' ),
			),
			array(
				'label'    => __( 'Logo', 'advanced-schema-extender' ),
				'baseline' => (string) ( $baseline_titles['company_logo'] ?? '' ),
				'extender' => (string) ( $s['org_logo'] ?? '' ),
			),
			array(
				'label'    => __( 'Contact Email', 'advanced-schema-extender' ),
				'baseline' => '',
				'extender' => (string) ( $s['org_email'] ?? '' ),
			),
			array(
				'label'    => __( 'Telephone', 'advanced-schema-extender' ),
				'baseline' => '',
				'extender' => (string) ( $s['telephone'] ?? '' ),
			),
			array(
				'label'    => __( 'Schema Type', 'advanced-schema-extender' ),
				'baseline' => __( 'Organization', 'advanced-schema-extender' ),
				'extender' => $this->describe_schema_type( $s ),
			),
			array(
				'label'    => __( 'Address', 'advanced-schema-extender' ),
				'baseline' => '',
				'extender' => $this->describe_address( $s ),
			),
			array(
				'label'    => __( 'Additional Locations', 'advanced-schema-extender' ),
				'baseline' => '',
				'extender' => $this->describe_locations( $s ),
			),
		);

		$override = ! empty( $s['override_org'] );

		foreach ( $rows as &$row ) {
			$y = trim( (string) $row['baseline'] );
			$e = trim( (string) $row['extender'] );

			if ( $override ) {
				// Replace mode: baseline values are suppressed.
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
				$row['source']    = 'baseline';
			} else {
				$row['effective'] = '';
				$row['source']    = '';
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Render the comparison between baseline and Extender schema values.
	 *
	 * @param array $s Current plugin settings.
	 */
	private function render_status_table( array $s ): void {
		$rows = $this->build_status_rows( $s );
		?>
		<h2 class="ase-status-title"><?php esc_html_e( 'Current Site Representation Status', 'advanced-schema-extender' ); ?></h2>
		<div class="ase-status-wrap">
			<table class="ase-status-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Field', 'advanced-schema-extender' ); ?></th>
						<th><?php esc_html_e( 'Baseline Value', 'advanced-schema-extender' ); ?></th>
						<th><?php esc_html_e( 'Extender Value', 'advanced-schema-extender' ); ?></th>
						<th><?php esc_html_e( 'Effective', 'advanced-schema-extender' ); ?></th>
						<th><?php esc_html_e( 'Source', 'advanced-schema-extender' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td class="ase-val-baseline"><?php echo wp_kses_post( $this->cell( $row['baseline'] ) ); ?></td>
							<td class="ase-val-extender"><?php echo wp_kses_post( $this->cell( $row['extender'] ) ); ?></td>
							<td class="ase-val-effective"><?php echo wp_kses_post( $this->cell( $row['effective'] ) ); ?></td>
							<td><?php $this->render_source_badge( $row['source'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="ase-status-note">
				<?php esc_html_e( 'Baseline values shown here are read from saved SEO plugin settings — they reflect what would be emitted if this plugin were inactive.', 'advanced-schema-extender' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Describe the configured schema type for the status table.
	 *
	 * @param array $s Current plugin settings.
	 * @return string Schema type label.
	 */
	private function describe_schema_type( array $s ): string {
		if ( empty( $s['is_local'] ) ) {
			return '';
		}
		$types = array_filter(
			array(
				(string) ( $s['lb_subtype'] ?? '' ),
				(string) ( $s['lb_subtype2'] ?? '' ),
				(string) ( $s['lb_subtype3'] ?? '' ),
			)
		);
		return empty( $types ) ? 'LocalBusiness' : implode( ', ', $types );
	}

	/**
	 * Format configured address fields for the status table.
	 *
	 * @param array $s Current plugin settings.
	 * @return string Comma-separated address.
	 */
	private function describe_address( array $s ): string {
		$parts = array_filter(
			array(
				(string) ( $s['addr_street'] ?? '' ),
				(string) ( $s['addr_city'] ?? '' ),
				(string) ( $s['addr_region'] ?? '' ),
				(string) ( $s['addr_postal'] ?? '' ),
				(string) ( $s['addr_country'] ?? '' ),
			)
		);
		return implode( ', ', $parts );
	}

	/**
	 * Describe enabled locations for the status table.
	 *
	 * @param array $s Current plugin settings.
	 * @return string Location count label.
	 */
	private function describe_locations( array $s ): string {
		if ( empty( $s['ml_enabled'] ) ) {
			return '';
		}
		$locs  = is_array( $s['ml_locations'] ?? null ) ? $s['ml_locations'] : array();
		$count = 0;
		foreach ( $locs as $loc ) {
			if ( is_array( $loc ) && ! empty( $loc['enabled'] ) && ! empty( $loc['name'] ) ) {
				++$count;
			}
		}
		if ( 0 === $count ) {
			return '';
		}
		/* translators: %d: number of additional location nodes */
		return sprintf( _n( '%d location', '%d locations', $count, 'advanced-schema-extender' ), $count );
	}

	/**
	 * Format a status-table value for safe display.
	 *
	 * @param string $value Value to render.
	 * @return string Escaped table-cell content.
	 */
	private function cell( string $value ): string {
		if ( '' === $value ) {
			return '<span class="ase-val-empty">—</span>';
		}
		return esc_html( $this->shorten( $value ) );
	}

	/**
	 * Shorten a string for compact status-table display.
	 *
	 * @param string $value String to shorten.
	 * @param int    $max   Maximum output length.
	 * @return string Shortened string.
	 */
	private function shorten( string $value, int $max = 60 ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max - 1 ) . '…';
		}
		return strlen( $value ) > $max ? substr( $value, 0, $max - 1 ) . '…' : $value;
	}

	/**
	 * Render the status source badge.
	 *
	 * @param string $source Source identifier.
	 */
	private function render_source_badge( string $source ): void {
		if ( '' === $source ) {
			echo '<span class="ase-val-empty">—</span>';
			return;
		}
		$labels = array(
			'baseline' => __( 'Baseline', 'advanced-schema-extender' ),
			'extender' => __( 'Extender', 'advanced-schema-extender' ),
			'merged'   => __( 'Merged', 'advanced-schema-extender' ),
		);
		printf(
			'<span class="ase-badge ase-badge--%1$s">%2$s</span>',
			esc_attr( $source ),
			esc_html( $labels[ $source ] ?? $source )
		);
	}


	/**
	 * Render organization and primary LocalBusiness settings fields.
	 *
	 * @param array $s Current plugin settings.
	 */
	private function render_org_fields( array $s ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ase-org-name"><?php esc_html_e( 'Organization Name', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-org-name" type="text" name="<?php echo esc_attr( $this->field_name( 'org_name' ) ); ?>" value="<?php echo esc_attr( (string) $s['org_name'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-org-url"><?php esc_html_e( 'Organization URL', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-org-url" type="url" name="<?php echo esc_attr( $this->field_name( 'org_url' ) ); ?>" value="<?php echo esc_attr( (string) $s['org_url'] ); ?>" class="regular-text" placeholder="https://example.com" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Organization Logo', 'advanced-schema-extender' ); ?></th>
				<td>
					<div class="ase-logo-picker">
						<img id="ase-logo-preview" src="<?php echo esc_url( (string) $s['org_logo'] ); ?>" alt="" style="<?php echo '' !== $s['org_logo'] ? '' : 'display:none;'; ?>" />
						<div>
							<input id="ase-logo-url" type="url" name="<?php echo esc_attr( $this->field_name( 'org_logo' ) ); ?>" value="<?php echo esc_attr( (string) $s['org_logo'] ); ?>" class="regular-text" />
							<p>
								<button type="button" class="button ase-pick-logo"><?php esc_html_e( 'Select / Upload Logo', 'advanced-schema-extender' ); ?></button>
								<a href="#" class="ase-clear-logo" style="<?php echo '' !== $s['org_logo'] ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'advanced-schema-extender' ); ?></a>
							</p>
						</div>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-org-email"><?php esc_html_e( 'Contact Email', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-org-email" type="email" name="<?php echo esc_attr( $this->field_name( 'org_email' ) ); ?>" value="<?php echo esc_attr( (string) $s['org_email'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-telephone"><?php esc_html_e( 'Telephone', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-telephone" type="text" name="<?php echo esc_attr( $this->field_name( 'telephone' ) ); ?>" value="<?php echo esc_attr( (string) $s['telephone'] ); ?>" class="regular-text" placeholder="+1 555 123 4567" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-same-as"><?php esc_html_e( 'sameAs URLs', 'advanced-schema-extender' ); ?></label></th>
				<td>
					<textarea id="ase-same-as" name="<?php echo esc_attr( $this->field_name( 'same_as' ) ); ?>" rows="5" class="large-text code"><?php echo esc_textarea( $this->lines_to_text( $s['same_as'] ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One URL per line. Social profiles, Wikipedia, Crunchbase, etc.', 'advanced-schema-extender' ); ?></p>
				</td>
			</tr>

			<tr class="ase-row-divider"><th colspan="2"><?php esc_html_e( 'LocalBusiness (optional)', 'advanced-schema-extender' ); ?></th></tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Treat as LocalBusiness', 'advanced-schema-extender' ); ?></th>
				<td>
					<label>
						<input id="ase-is-local" type="checkbox" name="<?php echo esc_attr( $this->field_name( 'is_local' ) ); ?>" value="1" <?php checked( ! empty( $s['is_local'] ) ); ?> />
						<?php esc_html_e( 'Add LocalBusiness type, address, hours, and geo to the Organization node.', 'advanced-schema-extender' ); ?>
					</label>
				</td>
			</tr>
			<tr class="ase-localbusiness-only<?php echo empty( $s['is_local'] ) ? ' ase-hidden-row' : ''; ?>">
				<th scope="row"><?php esc_html_e( 'LocalBusiness Subtypes', 'advanced-schema-extender' ); ?></th>
				<td>
					<div class="ase-subtype-grid">
						<?php $this->render_subtype_select( array( 'lb_subtype' ), (string) $s['lb_subtype'] ); ?>
						<?php $this->render_subtype_select( array( 'lb_subtype2' ), (string) $s['lb_subtype2'] ); ?>
						<?php $this->render_subtype_select( array( 'lb_subtype3' ), (string) $s['lb_subtype3'] ); ?>
					</div>
					<p class="description"><?php esc_html_e( 'Up to three Schema.org LocalBusiness subtypes. Most businesses only need the first.', 'advanced-schema-extender' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-addr-street"><?php esc_html_e( 'Street Address', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-addr-street" type="text" name="<?php echo esc_attr( $this->field_name( 'addr_street' ) ); ?>" value="<?php echo esc_attr( (string) $s['addr_street'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-addr-city"><?php esc_html_e( 'City', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-addr-city" type="text" name="<?php echo esc_attr( $this->field_name( 'addr_city' ) ); ?>" value="<?php echo esc_attr( (string) $s['addr_city'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-addr-region"><?php esc_html_e( 'State / Region', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-addr-region" type="text" name="<?php echo esc_attr( $this->field_name( 'addr_region' ) ); ?>" value="<?php echo esc_attr( (string) $s['addr_region'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-addr-postal"><?php esc_html_e( 'Postal Code', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-addr-postal" type="text" name="<?php echo esc_attr( $this->field_name( 'addr_postal' ) ); ?>" value="<?php echo esc_attr( (string) $s['addr_postal'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-addr-country"><?php esc_html_e( 'Country', 'advanced-schema-extender' ); ?></label></th>
				<td><input id="ase-addr-country" type="text" name="<?php echo esc_attr( $this->field_name( 'addr_country' ) ); ?>" value="<?php echo esc_attr( (string) $s['addr_country'] ); ?>" class="regular-text" placeholder="US, GB, AU…" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-geo-lat"><?php esc_html_e( 'Latitude / Longitude', 'advanced-schema-extender' ); ?></label></th>
				<td>
					<input id="ase-geo-lat" type="text" name="<?php echo esc_attr( $this->field_name( 'geo_lat' ) ); ?>" value="<?php echo esc_attr( (string) $s['geo_lat'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Latitude', 'advanced-schema-extender' ); ?>" />
					<input id="ase-geo-lng" type="text" name="<?php echo esc_attr( $this->field_name( 'geo_lng' ) ); ?>" value="<?php echo esc_attr( (string) $s['geo_lng'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Longitude', 'advanced-schema-extender' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-opening-hours"><?php esc_html_e( 'Opening Hours (JSON)', 'advanced-schema-extender' ); ?></label></th>
				<td>
					<textarea id="ase-opening-hours" name="<?php echo esc_attr( $this->field_name( 'opening_hours' ) ); ?>" rows="6" class="large-text code"><?php echo esc_textarea( $this->array_to_pretty_json( $s['opening_hours'] ) ); ?></textarea>
					<p class="description">
					<?php
					echo wp_kses(
						__( 'JSON array of <code>OpeningHoursSpecification</code> objects.', 'advanced-schema-extender' ),
						array( 'code' => array() )
					);
					?>
					<a href="#" class="ase-generate-opening-hours"><?php esc_html_e( 'Generate sample', 'advanced-schema-extender' ); ?></a></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ase-service-area"><?php esc_html_e( 'Service Area', 'advanced-schema-extender' ); ?></label></th>
				<td>
					<textarea id="ase-service-area" name="<?php echo esc_attr( $this->field_name( 'service_area' ) ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $this->lines_to_text( $s['service_area'] ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One city or region per line.', 'advanced-schema-extender' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render a subtype select control.
	 *
	 * @param array  $path    Field path segments passed to field_name().
	 * @param string $current Currently-selected value.
	 */
	private function render_subtype_select( array $path, string $current ): void {
		?>
		<select name="<?php echo esc_attr( $this->field_name( ...$path ) ); ?>">
			<option value=""><?php esc_html_e( '— None —', 'advanced-schema-extender' ); ?></option>
			<?php foreach ( $this->lb_subtypes() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}


	/**
	 * Render multi-location settings and the location template.
	 *
	 * @param array $s Current plugin settings.
	 */
	private function render_locations_section( array $s ): void {
		$locations = is_array( $s['ml_locations'] ?? null ) ? $s['ml_locations'] : array();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Multi-Location', 'advanced-schema-extender' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'ml_enabled' ) ); ?>" value="1" <?php checked( ! empty( $s['ml_enabled'] ) ); ?> />
						<?php esc_html_e( 'Emit each enabled location below as its own LocalBusiness node in the schema graph.', 'advanced-schema-extender' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<div id="ase-locations-list">
			<?php foreach ( $locations as $i => $loc ) : ?>
				<?php $this->render_location_card( (string) $i, (array) $loc ); ?>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button ase-add-location">
				<?php esc_html_e( '+ Add Location', 'advanced-schema-extender' ); ?>
			</button>
		</p>

		<script type="text/template" id="ase-location-template">
		<?php
			$this->render_location_card( '__INDEX__', $this->blank_location() );
		?>
		</script>
		<?php
	}

	/**
	 * Render one multi-location settings card.
	 *
	 * @param string $idx Location index used in input names.
	 * @param array  $loc Location settings.
	 */
	private function render_location_card( string $idx, array $loc ): void {
		$loc   = wp_parse_args( $loc, $this->blank_location() );
		$title = '' !== $loc['name'] ? $loc['name'] : __( 'New Location', 'advanced-schema-extender' );
		?>
		<div class="ase-location-card">
			<div class="ase-location-head">
				<span class="ase-location-title"><?php echo esc_html( $title ); ?></span>
				<label class="ase-location-enabled">
					<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'enabled' ) ); ?>" value="1" <?php checked( ! empty( $loc['enabled'] ) ); ?> />
					<?php esc_html_e( 'Enabled', 'advanced-schema-extender' ); ?>
				</label>
				<button type="button" class="button-link ase-toggle-location"><?php esc_html_e( 'Collapse', 'advanced-schema-extender' ); ?></button>
				<button type="button" class="button-link ase-remove-location"><?php esc_html_e( 'Remove', 'advanced-schema-extender' ); ?></button>
			</div>
			<div class="ase-location-body">
				<table class="form-table" role="presentation">

					<tr class="ase-row-divider"><th colspan="2"><?php esc_html_e( 'Identity', 'advanced-schema-extender' ); ?></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Name', 'advanced-schema-extender' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'name' ) ); ?>" value="<?php echo esc_attr( (string) $loc['name'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page Slug', 'advanced-schema-extender' ); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'page_slug' ) ); ?>" value="<?php echo esc_attr( (string) $loc['page_slug'] ); ?>" class="regular-text" placeholder="locations/downtown" />
							<p class="description"><?php esc_html_e( 'Used to build a fallback URL when Location URL is empty.', 'advanced-schema-extender' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Location URL', 'advanced-schema-extender' ); ?></th>
						<td><input type="url" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'url' ) ); ?>" value="<?php echo esc_attr( (string) $loc['url'] ); ?>" class="regular-text" placeholder="https://example.com/location/" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Image URL', 'advanced-schema-extender' ); ?></th>
						<td><input type="url" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'image' ) ); ?>" value="<?php echo esc_attr( (string) $loc['image'] ); ?>" class="regular-text" placeholder="https://example.com/location-photo.jpg" /></td>
					</tr>

					<tr class="ase-row-divider"><th colspan="2"><?php esc_html_e( 'Contact', 'advanced-schema-extender' ); ?></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Telephone', 'advanced-schema-extender' ); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'telephone' ) ); ?>" value="<?php echo esc_attr( (string) $loc['telephone'] ); ?>" class="regular-text" placeholder="+1 555 123 4567" />
							<p class="description"><?php esc_html_e( 'Inherits global telephone if empty.', 'advanced-schema-extender' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'advanced-schema-extender' ); ?></th>
						<td>
							<input type="email" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'email' ) ); ?>" value="<?php echo esc_attr( (string) $loc['email'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Inherits global contact email if empty.', 'advanced-schema-extender' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Price Range', 'advanced-schema-extender' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'priceRange' ) ); ?>" value="<?php echo esc_attr( (string) $loc['priceRange'] ); ?>" class="small-text" placeholder="$$" /></td>
					</tr>

					<tr class="ase-row-divider"><th colspan="2"><?php esc_html_e( 'Schema Type', 'advanced-schema-extender' ); ?></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'LocalBusiness Subtypes', 'advanced-schema-extender' ); ?></th>
						<td>
							<div class="ase-subtype-grid">
								<?php $this->render_subtype_select( array( 'ml_locations', $idx, 'lb_subtype' ), (string) $loc['lb_subtype'] ); ?>
								<?php $this->render_subtype_select( array( 'ml_locations', $idx, 'lb_subtype2' ), (string) $loc['lb_subtype2'] ); ?>
								<?php $this->render_subtype_select( array( 'ml_locations', $idx, 'lb_subtype3' ), (string) $loc['lb_subtype3'] ); ?>
							</div>
						</td>
					</tr>

					<tr class="ase-row-divider"><th colspan="2"><?php esc_html_e( 'Address', 'advanced-schema-extender' ); ?></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Street Address', 'advanced-schema-extender' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'addr_street' ) ); ?>" value="<?php echo esc_attr( (string) $loc['addr_street'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'City', 'advanced-schema-extender' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'addr_city' ) ); ?>" value="<?php echo esc_attr( (string) $loc['addr_city'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'State / Region', 'advanced-schema-extender' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'addr_region' ) ); ?>" value="<?php echo esc_attr( (string) $loc['addr_region'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Postal Code', 'advanced-schema-extender' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'addr_postal' ) ); ?>" value="<?php echo esc_attr( (string) $loc['addr_postal'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Country', 'advanced-schema-extender' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'addr_country' ) ); ?>" value="<?php echo esc_attr( (string) $loc['addr_country'] ); ?>" class="regular-text" placeholder="US, GB, AU…" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Latitude / Longitude', 'advanced-schema-extender' ); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'geo_lat' ) ); ?>" value="<?php echo esc_attr( (string) $loc['geo_lat'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Latitude', 'advanced-schema-extender' ); ?>" />
							<input type="text" name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'geo_lng' ) ); ?>" value="<?php echo esc_attr( (string) $loc['geo_lng'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Longitude', 'advanced-schema-extender' ); ?>" />
						</td>
					</tr>

					<tr class="ase-row-divider"><th colspan="2"><?php esc_html_e( 'Service Area &amp; Hours', 'advanced-schema-extender' ); ?></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Service Area', 'advanced-schema-extender' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'service_area' ) ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $this->lines_to_text( $loc['service_area'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One city or region per line.', 'advanced-schema-extender' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Opening Hours (JSON)', 'advanced-schema-extender' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( $this->field_name( 'ml_locations', $idx, 'opening_hours' ) ); ?>" rows="4" class="large-text code"><?php echo esc_textarea( $this->array_to_pretty_json( $loc['opening_hours'] ) ); ?></textarea>
							<p class="description">
							<?php
							echo wp_kses(
								__( 'JSON array of <code>OpeningHoursSpecification</code> objects. Example: <code>[{"dayOfWeek":"Monday","opens":"09:00","closes":"17:00"}]</code>', 'advanced-schema-extender' ),
								array( 'code' => array() )
							);
							?>
							</p>
						</td>
					</tr>

				</table>
			</div>
		</div>
		<?php
	}


	/**
	 * Render FAQ post-type settings.
	 *
	 * @param array $s Current plugin settings.
	 */
	private function render_faq_settings( array $s ): void {
		$selected = is_array( $s['faq_post_types'] ?? null ) ? $s['faq_post_types'] : array();
		$types    = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<p class="description">
			<?php esc_html_e( 'The FAQ Builder is a per-post tool. Enable the post types you want to use it on, then open a post or page to find the “FAQ Schema Builder” meta box. Saved FAQs are emitted as a FAQPage node on that post’s URL.', 'advanced-schema-extender' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable FAQ Builder On', 'advanced-schema-extender' ); ?></th>
				<td>
					<div class="ase-faq-pt-list">
						<?php foreach ( $types as $slug => $type ) : ?>
							<label>
								<input type="checkbox"
										name="<?php echo esc_attr( $this->field_name( 'faq_post_types' ) ); ?>[]"
										value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( in_array( $slug, $selected, true ) ); ?> />
								<?php echo esc_html( $type->labels->singular_name ); ?>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'No post types are enabled by default.', 'advanced-schema-extender' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}


	/**
	 * Render compatibility settings.
	 *
	 * @param array $s Current plugin settings.
	 */
	private function render_compat_fields( array $s ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Override Baseline Organization', 'advanced-schema-extender' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'override_org' ) ); ?>" value="1" <?php checked( ! empty( $s['override_org'] ) ); ?> />
						<?php esc_html_e( 'Replace baseline Organization fields entirely instead of merging.', 'advanced-schema-extender' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off by default. Enable only if the baseline Organization data conflicts with what you want emitted.', 'advanced-schema-extender' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}


	/**
	 * Render settings import and export controls.
	 *
	 * @param array $s Current plugin settings.
	 */
	private function render_import_export_panel( array $s ): void {
		$json = wp_json_encode( $s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			$json = '{}';
		}
		?>
		<div class="ase-ie-panel">
			<h2><?php esc_html_e( 'Import / Export', 'advanced-schema-extender' ); ?></h2>
			<div class="ase-ie-grid">
				<div class="ase-ie-col">
					<h3><?php esc_html_e( 'Export', 'advanced-schema-extender' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Copy the JSON below to back up or transfer settings to another site.', 'advanced-schema-extender' ); ?></p>
					<textarea id="ase-export-json" readonly><?php echo esc_textarea( $json ); ?></textarea>
					<p>
						<button type="button" class="button ase-copy-export" data-copied="<?php esc_attr_e( 'Copied!', 'advanced-schema-extender' ); ?>">
							<?php esc_html_e( 'Copy to Clipboard', 'advanced-schema-extender' ); ?>
						</button>
					</p>
				</div>
				<div class="ase-ie-col">
					<h3><?php esc_html_e( 'Import', 'advanced-schema-extender' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Paste a previously exported JSON or upload a .json file. Importing replaces current settings.', 'advanced-schema-extender' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="advanced_schema_extender_import" />
						<?php wp_nonce_field( 'advanced_schema_extender_import' ); ?>
						<textarea name="ase_import_json" rows="6" class="widefat code" placeholder='{"org_name":"…"}'></textarea>
						<p><input type="file" name="ase_import_file" accept=".json,application/json" /></p>
						<p>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Import & Replace', 'advanced-schema-extender' ); ?>
							</button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}


	/**
	 * Build a properly-escaped form field name like:
	 *   ase_settings[org_name]
	 *   ase_settings[ml_locations][0][name]
	 *
	 * @param string ...$path Field path segments.
	 * @return string Escaped field name.
	 */
	private function field_name( string ...$path ): string {
		$name = esc_attr( ASE_OPTION_KEY );
		foreach ( $path as $segment ) {
			$name .= '[' . esc_attr( $segment ) . ']';
		}
		return $name;
	}

	/** Register the settings notice for an import result.
	 *
	 * @param string $key Import result key.
	 */
	private function register_status_message( string $key ): void {
		$messages = array(
			'ok'   => array( 'success', __( 'Settings imported successfully.', 'advanced-schema-extender' ) ),
			'fail' => array( 'error', __( 'Import failed. Paste or upload a valid JSON export.', 'advanced-schema-extender' ) ),
		);
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		[ $type, $text ] = $messages[ $key ];
		add_settings_error( ASE_OPTION_KEY, 'advanced_schema_extender_import_' . $key, $text, $type );
	}


	/** Handle an authorized settings import request. */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'advanced-schema-extender' ) );
		}
		check_admin_referer( 'advanced_schema_extender_import' );

		$uploaded_path = isset( $_FILES['ase_import_file']['tmp_name'] ) && is_string( $_FILES['ase_import_file']['tmp_name'] )
			? sanitize_text_field( wp_unslash( $_FILES['ase_import_file']['tmp_name'] ) )
			: '';
		$upload_error  = isset( $_FILES['ase_import_file']['error'] )
			? absint( wp_unslash( $_FILES['ase_import_file']['error'] ) )
			: UPLOAD_ERR_NO_FILE;

		$posted_json = isset( $_POST['ase_import_json'] ) && is_string( $_POST['ase_import_json'] )
			? sanitize_textarea_field( wp_unslash( $_POST['ase_import_json'] ) )
			: '';

		$result = $this->process_import_payload( $uploaded_path, $upload_error, $posted_json );

		// Persist any settings_errors raised during sanitize so they survive the redirect.
		$errors = get_settings_errors();
		if ( ! empty( $errors ) ) {
			set_transient( 'settings_errors', $errors, 30 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                            => ASE_PAGE_SLUG,
					'advanced_schema_extender_import' => $result,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Reads file or pasted JSON, decodes, sanitizes, and stores.
	 * Returns 'ok' on success, 'fail' on any failure mode.
	 *
	 * @param string $uploaded_path Temporary path of a validated upload.
	 * @param int    $upload_error  PHP upload error code.
	 * @param string $posted_json   Pasted JSON from the import form.
	 * @return string Import result key.
	 */
	private function process_import_payload( string $uploaded_path, int $upload_error, string $posted_json ): string {
		$json = '';

		if (
			UPLOAD_ERR_OK === $upload_error
			&& '' !== $uploaded_path
			&& is_uploaded_file( $uploaded_path )
		) {
			try {
				$file = new SplFileObject( $uploaded_path, 'rb' );
				$json = $file->fread( $file->getSize() );
			} catch ( RuntimeException $exception ) {
				$json = '';
			}
		}

		if ( '' === $json && '' !== $posted_json ) {
			$json = $posted_json;
		}

		$json = trim( $json );
		if ( '' === $json ) {
			return 'fail';
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return 'fail';
		}

		update_option( ASE_OPTION_KEY, $this->sanitize_settings( $decoded ) );
		return 'ok';
	}


	/** Register the FAQ editor meta box for configured post types. */
	public function register_meta_boxes(): void {
		$settings   = (array) get_option( ASE_OPTION_KEY, array() );
		$post_types = is_array( $settings['faq_post_types'] ?? null ) ? $settings['faq_post_types'] : array();

		if ( empty( $post_types ) ) {
			return;
		}

		add_meta_box(
			'ase_faq_builder',
			__( 'Schema Extender FAQ', 'advanced-schema-extender' ),
			array( $this, 'render_faq_meta_box' ),
			$post_types,
			'normal',
			'default'
		);
	}

	/**
	 * Render the FAQ editor for a post.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_faq_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'ase_save_faq_' . $post->ID, '_ase_faq_nonce' );

		$items = array();
		$raw   = get_post_meta( $post->ID, '_ase_faq_items', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$items = $decoded;
			}
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Add up to 20 Q&A pairs. Each pair becomes a Question entry in the FAQPage schema node for this post.', 'advanced-schema-extender' ); ?>
		</p>
		<div id="ase-faq-list">
			<?php foreach ( $items as $item ) : ?>
				<?php $this->render_faq_row( (array) $item ); ?>
			<?php endforeach; ?>
		</div>
		<p>
			<button type="button" class="button ase-add-faq">
				<?php esc_html_e( '+ Add Question', 'advanced-schema-extender' ); ?>
			</button>
		</p>
		<script type="text/template" id="ase-faq-template">
		<?php
			$this->render_faq_row(
				array(
					'q' => '',
					'a' => '',
				)
			);
		?>
		</script>
		<?php
	}

	/**
	 * Render a single FAQ row for the metabox.
	 *
	 * Stored answers contain literal <br> tags; convert them back to real
	 * newlines so the textarea displays them as line breaks for the editor.
	 *
	 * @param array $item FAQ question and answer.
	 */
	private function render_faq_row( array $item ): void {
		$answer_display = preg_replace( '/<br\s*\/?>/i', "\n", (string) ( $item['a'] ?? '' ) );
		?>
		<div class="ase-faq-row">
			<label>
				<?php esc_html_e( 'Question', 'advanced-schema-extender' ); ?>
				<input type="text"
						name="ase_faq[q][]"
						value="<?php echo esc_attr( (string) ( $item['q'] ?? '' ) ); ?>"
						placeholder="<?php esc_attr_e( 'Enter question…', 'advanced-schema-extender' ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Answer', 'advanced-schema-extender' ); ?>
				<textarea name="ase_faq[a][]"
							rows="3"
							placeholder="<?php esc_attr_e( 'Enter answer…', 'advanced-schema-extender' ); ?>"><?php echo esc_textarea( $answer_display ); ?></textarea>
			</label>
			<a href="#" class="ase-remove-faq"><?php esc_html_e( 'Remove', 'advanced-schema-extender' ); ?></a>
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
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save_post_meta( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return; }
		if ( wp_is_post_revision( $post_id ) ) {
			return; }
		if ( ! isset( $_POST['_ase_faq_nonce'] ) ) {
			return; }
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_ase_faq_nonce'] ) ),
			'ase_save_faq_' . $post_id
		) ) {
			return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return; }

		$allowed_html = array(
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'a'      => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'code'   => array(),
			'u'      => array(),
		);

		$questions = isset( $_POST['ase_faq']['q'] )
			? map_deep( wp_unslash( (array) $_POST['ase_faq']['q'] ), 'sanitize_text_field' )
			: array();
		$answers   = isset( $_POST['ase_faq']['a'] )
			? map_deep( wp_unslash( (array) $_POST['ase_faq']['a'] ), 'wp_kses_post' )
			: array();

		$items = array();
		foreach ( $questions as $i => $raw_q ) {
			if ( count( $items ) >= 20 ) {
				break;
			}

			$q = (string) $raw_q;

			// Skip blank rows.
			if ( '' === $q ) {
				continue;
			}

			// Enforce trailing question mark.
			if ( '?' !== substr( $q, -1 ) ) {
				$q .= '?';
			}

			// Unslash first, then normalise newlines to LF, then LF → <br>.
			$raw_a = (string) ( $answers[ $i ] ?? '' );
			$raw_a = str_replace( "\r\n", "\n", $raw_a );
			$raw_a = str_replace( "\r", "\n", $raw_a );
			$a     = str_replace( "\n", '<br>', $raw_a );

			// Sanitize inline HTML (the <br> tags we just created are preserved).
			$a = wp_kses( $a, $allowed_html );

			$items[] = array(
				'q' => $q,
				'a' => $a,
			);
		}

		update_post_meta( $post_id, '_ase_faq_items', wp_json_encode( $items ) );
	}


	/** Register Yoast schema callbacks after its classes are loaded. */
	private function hook_schema_filters(): void {
		// Defer filter registration to wp_loaded so SEO plugin classes are available.
		add_action( 'wp_loaded', array( $this, 'maybe_register_schema_filter' ) );
	}

	/** Register schema filters when Yoast's schema API is available. */
	public function maybe_register_schema_filter(): void {
		if ( class_exists( '\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece' ) ) {
			// wpseo_schema_organization fires only when Site Representation
			// is set to "Organization".  If it is set to "Person", this filter never
			// fires and Organization enrichment is silently skipped — which is correct
			// behaviour, not a bug.
			add_filter( 'wpseo_schema_organization', array( $this, 'filter_organization_node' ) );
			add_filter( 'wpseo_schema_graph', array( $this, 'filter_schema_graph' ), 20, 1 );
		}
	}


	/**
	 * Enrich (not replace) the Organization node via wpseo_schema_organization.
	 *
	 * Merge strategy:
	 *   override_org = false (default) — Extender value is written only when the
	 *     existing field is absent or effectively empty.
	 *   override_org = true            — Extender value always wins.
	 *
	 * sameAs is always merged + deduplicated regardless of override_org.
	 *
	 * @param mixed $node Original Yoast Organization node.
	 * @return array Enriched Organization node.
	 */
	public function filter_organization_node( $node ): array {
		if ( ! is_array( $node ) ) {
			$node = array();
		}

		$s = wp_parse_args(
			(array) get_option( ASE_OPTION_KEY, array() ),
			$this->default_settings()
		);

		$override = ! empty( $s['override_org'] );

		// ---- Scalar fields --------------------------------------------------
		$scalar_map = array(
			'name'      => (string) ( $s['org_name'] ?? '' ),
			'url'       => (string) ( $s['org_url'] ?? '' ),
			'email'     => (string) ( $s['org_email'] ?? '' ),
			'telephone' => (string) ( $s['telephone'] ?? '' ),
		);
		foreach ( $scalar_map as $prop => $ext_val ) {
			if ( '' !== $ext_val && ( $override || $this->is_node_field_empty( $node, $prop ) ) ) {
				$node[ $prop ] = $ext_val;
			}
		}

		// ---- Logo -----------------------------------------------------------
		$ext_logo = trim( (string) ( $s['org_logo'] ?? '' ) );
		if ( '' !== $ext_logo && ( $override || $this->is_node_field_empty( $node, 'logo' ) ) ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $ext_logo,
			);
		}

		// ---- sameAs — always merge + deduplicate ----------------------------
		$node = $this->merge_same_as( $node, is_array( $s['same_as'] ) ? $s['same_as'] : array() );

		// ---- Address --------------------------------------------------------
		$node = $this->maybe_inject_address( $node, $s, $override );

		// ---- GeoCoordinates -------------------------------------------------
		$node = $this->maybe_inject_geo( $node, $s, $override );

		// ---- OpeningHoursSpecification (LocalBusiness context only) ---------
		$is_local_business_context = ! empty( $s['is_local'] ) || $this->node_has_type( $node, 'LocalBusiness' );
		$ext_oh                    = is_array( $s['opening_hours'] ?? null ) ? $this->normalize_opening_hours_specifications( $s['opening_hours'] ) : array();
		if ( $is_local_business_context && ! empty( $ext_oh ) && ( $override || $this->is_node_field_empty( $node, 'openingHoursSpecification' ) ) ) {
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
	 *
	 * @param array  $node Schema node.
	 * @param string $key  Property name.
	 * @return bool Whether the property is empty or absent.
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
	 * Check whether a schema node declares a given @type value.
	 *
	 * @param array  $node Schema node.
	 * @param string $type Type name to find.
	 * @return bool Whether the type is present.
	 */
	private function node_has_type( array $node, string $type ): bool {
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
		return in_array( $type, $types, true );
	}

	/**
	 * Merge Extender sameAs URLs into the node's existing sameAs array.
	 * Deduplicates by exact URL string. Always runs regardless of override_org.
	 *
	 * @param array    $node     Schema node.
	 * @param string[] $ext_urls Extender URLs.
	 * @return array Updated schema node.
	 */
	private function merge_same_as( array $node, array $ext_urls ): array {
		$existing = array();
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
	 *
	 * @param array $node     Schema node.
	 * @param array $s        Plugin settings.
	 * @param bool  $override Whether Extender values override existing values.
	 * @return array Updated schema node.
	 */
	private function maybe_inject_address( array $node, array $s, bool $override ): array {
		$ext_parts = array_filter(
			array(
				'streetAddress'   => trim( (string) ( $s['addr_street'] ?? '' ) ),
				'addressLocality' => trim( (string) ( $s['addr_city'] ?? '' ) ),
				'addressRegion'   => trim( (string) ( $s['addr_region'] ?? '' ) ),
				'postalCode'      => trim( (string) ( $s['addr_postal'] ?? '' ) ),
				'addressCountry'  => trim( (string) ( $s['addr_country'] ?? '' ) ),
			)
		);

		if ( empty( $ext_parts ) ) {
			return $node;
		}

		if ( $override || $this->is_node_field_empty( $node, 'address' ) ) {
			$node['address'] = array_merge( array( '@type' => 'PostalAddress' ), $ext_parts );
		}

		return $node;
	}

	/**
	 * Inject a GeoCoordinates node when both lat and lng are set.
	 *
	 * @param array $node     Schema node.
	 * @param array $s        Plugin settings.
	 * @param bool  $override Whether Extender values override existing values.
	 * @return array Updated schema node.
	 */
	private function maybe_inject_geo( array $node, array $s, bool $override ): array {
		$lat = trim( (string) ( $s['geo_lat'] ?? '' ) );
		$lng = trim( (string) ( $s['geo_lng'] ?? '' ) );

		if ( '' === $lat || '' === $lng ) {
			return $node;
		}

		if ( $override || $this->is_node_field_empty( $node, 'geo' ) ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}

		return $node;
	}

	/**
	 * Inject areaServed, converting each service_area line to a City node.
	 *
	 * @param array $node     Schema node.
	 * @param array $s        Plugin settings.
	 * @param bool  $override Whether Extender values override existing values.
	 * @return array Updated schema node.
	 */
	private function maybe_inject_area_served( array $node, array $s, bool $override ): array {
		$sa = is_array( $s['service_area'] ?? null ) ? $s['service_area'] : array();
		if ( empty( $sa ) ) {
			return $node;
		}

		$areas = array();
		foreach ( $sa as $area ) {
			$area = trim( (string) $area );
			if ( '' !== $area ) {
				$areas[] = array(
					'@type' => 'City',
					'name'  => $area,
				);
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
	 *
	 * @param array $node Schema node.
	 * @param array $s    Plugin settings.
	 * @return array Updated schema node.
	 */
	private function inject_local_business_types( array $node, array $s ): array {
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();

		if ( ! in_array( 'LocalBusiness', $types, true ) ) {
			$types[] = 'LocalBusiness';
		}

		foreach ( array( 'lb_subtype', 'lb_subtype2', 'lb_subtype3' ) as $key ) {
			$t = trim( (string) ( $s[ $key ] ?? '' ) );
			if ( '' !== $t && ! in_array( $t, $types, true ) ) {
				$types[] = $t;
			}
		}

		$node['@type'] = ( 1 === count( $types ) ) ? $types[0] : array_values( $types );

		return $node;
	}


	/**
	 * Append location and FAQ nodes to Yoast's schema graph.
	 *
	 * @param array $graph Yoast schema graph.
	 * @return array Updated schema graph.
	 */
	public function filter_schema_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		$s = wp_parse_args(
			(array) get_option( ASE_OPTION_KEY, array() ),
			$this->default_settings()
		);

		// FAQ injection — singular posts only.
		$graph = $this->inject_faq_node( $graph, $s );

		// Multi-location injection.
		if ( ! empty( $s['ml_enabled'] ) ) {
			$locations = is_array( $s['ml_locations'] ) ? $s['ml_locations'] : array();
			if ( ! empty( $locations ) ) {
				$org_id = $this->find_org_id( $graph );
				foreach ( $locations as $location ) {
					if ( ! is_array( $location ) || empty( $location['enabled'] ) ) {
						continue;
					}
					$node = $this->build_location_node( $location, $s, $org_id );
					if ( null !== $node ) {
						$graph[] = $node;
					}
				}
			}
		}

		return $graph;
	}


	/**
	 * Inject a FAQPage node into the graph for enabled singular post types.
	 *
	 * Skips injection when:
	 *  - Not a singular view.
	 *  - Post type is not in the FAQ-enabled list.
	 *  - Post has no saved FAQ items.
	 *  - A FAQPage node already exists in the graph.
	 *
	 * @param array $graph Yoast schema graph.
	 * @param array $s     Plugin settings.
	 * @return array Updated schema graph.
	 */
	private function inject_faq_node( array $graph, array $s ): array {
		if ( ! is_singular() ) {
			return $graph;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return $graph;
		}

		$enabled_types = is_array( $s['faq_post_types'] ) ? $s['faq_post_types'] : array();
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return $graph;
		}

		// Load stored items.
		$raw = get_post_meta( $post->ID, '_ase_faq_items', true );
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
			$types = (array) ( $gnode['@type'] ?? array() );
			if ( in_array( 'FAQPage', $types, true ) ) {
				return $graph;
			}
		}

		$post_url   = (string) get_permalink( $post->ID );
		$faq_id     = $post_url . '#/schema/faq';
		$webpage_id = $this->find_webpage_id( $graph, $post_url );

		// Build mainEntity array.
		$main_entity = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || '' === trim( (string) ( $item['q'] ?? '' ) ) ) {
				continue;
			}
			$main_entity[] = array(
				'@type'          => 'Question',
				'name'           => (string) $item['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => (string) ( $item['a'] ?? '' ),
				),
			);
		}

		if ( empty( $main_entity ) ) {
			return $graph;
		}

		$faq_node = array(
			'@type'         => 'FAQPage',
			'@id'           => $faq_id,
			'url'           => $post_url,
			'headline'      => get_the_title( $post->ID ),
			'datePublished' => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $post->post_date_gmt ) ),
			'dateModified'  => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $post->post_modified_gmt ) ),
			'mainEntity'    => $main_entity,
		);

		if ( '' !== $webpage_id ) {
			$faq_node['isPartOf'] = array( '@id' => $webpage_id );
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
	 * Falls back to the standard '#webpage' fragment.
	 *
	 * @param array  $graph    Yoast schema graph.
	 * @param string $post_url Current post URL.
	 * @return string WebPage node identifier.
	 */
	private function find_webpage_id( array $graph, string $post_url ): string {
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$types = (array) ( $node['@type'] ?? array() );
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
	 *
	 * @param array  $graph      Yoast schema graph.
	 * @param string $faq_id     FAQ node identifier.
	 * @param string $webpage_id WebPage node identifier.
	 * @return array Updated schema graph.
	 */
	private function inject_faq_has_part( array $graph, string $faq_id, string $webpage_id ): array {
		foreach ( $graph as &$node ) {
			if ( ! is_array( $node ) || (string) ( $node['@id'] ?? '' ) !== $webpage_id ) {
				continue;
			}

			// Normalise hasPart to a list of objects.
			$has_part = array();
			if ( isset( $node['hasPart'] ) ) {
				// Single object: { '@id': '...' } — convert to array.
				if ( is_array( $node['hasPart'] ) && array_key_exists( '@id', $node['hasPart'] ) ) {
					$has_part = array( $node['hasPart'] );
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
				$has_part[] = array( '@id' => $faq_id );
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
	 * Falls back to the standard pattern if none is found.
	 *
	 * @param array $graph Yoast schema graph.
	 * @return string Organization node identifier.
	 */
	private function find_org_id( array $graph ): string {
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$types = (array) ( $node['@type'] ?? array() );
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
	 * @param array  $location        A single sanitized location array.
	 * @param array  $settings The full plugin settings array.
	 * @param string $org_id   The @id of the parent organization node.
	 */
	private function build_location_node( array $location, array $settings, string $org_id ): ?array {
		$name = trim( (string) ( $location['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}

		// URL: explicit override → page_slug fallback → empty.
		$url = trim( (string) ( $location['url'] ?? '' ) );
		if ( '' === $url ) {
			$slug = trim( (string) ( $location['page_slug'] ?? '' ) );
			if ( '' !== $slug ) {
				$url = trailingslashit( home_url() ) . ltrim( $slug, '/' );
			}
		}

		// @id derived from URL when available; otherwise slug from name
		$node_id = ( '' !== $url )
			? trailingslashit( $url ) . '#localbusiness'
			: trailingslashit( home_url() ) . '#location-' . sanitize_title( $name );

		// @type — LocalBusiness + up to three optional subtypes
		$types = array( 'LocalBusiness' );
		foreach ( array( 'lb_subtype', 'lb_subtype2', 'lb_subtype3' ) as $key ) {
			$t = trim( (string) ( $location[ $key ] ?? '' ) );
			if ( '' !== $t && ! in_array( $t, $types, true ) ) {
				$types[] = $t;
			}
		}

		$node = array(
			'@type' => ( 1 === count( $types ) ) ? $types[0] : $types,
			'@id'   => $node_id,
			'name'  => $name,
		);

		if ( '' !== $url ) {
			$node['url'] = $url;
		}

		// Image and priceRange (location-specific only).
		$image = trim( (string) ( $location['image'] ?? '' ) );
		if ( '' !== $image ) {
			$node['image'] = $image;
		}

		$price = trim( (string) ( $location['priceRange'] ?? '' ) );
		if ( '' !== $price ) {
			$node['priceRange'] = $price;
		}

		// Telephone — inherit global when location value is absent.
		$phone = trim( (string) ( $location['telephone'] ?? '' ) );
		if ( '' === $phone ) {
			$phone = trim( (string) ( $settings['telephone'] ?? '' ) );
		}
		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}

		// Email — inherit global when location value is absent.
		$email = trim( (string) ( $location['email'] ?? '' ) );
		if ( '' === $email ) {
			$email = trim( (string) ( $settings['org_email'] ?? '' ) );
		}
		if ( '' !== $email ) {
			$node['email'] = $email;
		}

		// PostalAddress.
		$addr_parts = array_filter(
			array(
				'streetAddress'   => trim( (string) ( $location['addr_street'] ?? '' ) ),
				'addressLocality' => trim( (string) ( $location['addr_city'] ?? '' ) ),
				'addressRegion'   => trim( (string) ( $location['addr_region'] ?? '' ) ),
				'postalCode'      => trim( (string) ( $location['addr_postal'] ?? '' ) ),
				'addressCountry'  => trim( (string) ( $location['addr_country'] ?? '' ) ),
			)
		);
		if ( ! empty( $addr_parts ) ) {
			$node['address'] = array_merge( array( '@type' => 'PostalAddress' ), $addr_parts );
		}

		// GeoCoordinates — only when both lat and lng are present.
		$lat = trim( (string) ( $location['geo_lat'] ?? '' ) );
		$lng = trim( (string) ( $location['geo_lng'] ?? '' ) );
		if ( '' !== $lat && '' !== $lng ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}

		// Opening hours — location-specific value only.
		$oh = is_array( $location['opening_hours'] ?? null ) ? $this->normalize_opening_hours_specifications( $location['opening_hours'] ) : array();
		if ( ! empty( $oh ) ) {
			$node['openingHoursSpecification'] = $oh;
		}

		// Service area — location-specific value only; wrap each string as AdministrativeArea.
		$sa = is_array( $location['service_area'] ?? null ) ? $location['service_area'] : array();
		if ( ! empty( $sa ) ) {
			$areas = array();
			foreach ( $sa as $area ) {
				$area = trim( (string) $area );
				if ( '' !== $area ) {
					$areas[] = array(
						'@type' => 'AdministrativeArea',
						'name'  => $area,
					);
				}
			}
			if ( ! empty( $areas ) ) {
				$node['areaServed'] = $areas;
			}
		}

		// Link back to parent organization node.
		if ( '' !== $org_id ) {
			$node['parentOrganization'] = array( '@id' => $org_id );
		}

		return $node;
	}
}


