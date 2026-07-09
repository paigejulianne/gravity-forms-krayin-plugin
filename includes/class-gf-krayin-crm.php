<?php
/**
 * Gravity Forms Krayin CRM Add-On.
 *
 * Sends form submissions to Krayin CRM as Leads (with an attached Person) or
 * as standalone Contacts, using Krayin's official REST API.
 *
 * @package GravityForms_Krayin_CRM
 */

defined( 'ABSPATH' ) || die();

GFForms::include_feed_addon_framework();

require_once __DIR__ . '/class-krayin-api-client.php';

class GF_Krayin_CRM extends GFFeedAddOn {

	protected $_version                    = GF_KRAYIN_CRM_VERSION;
	protected $_min_gravityforms_version    = '2.5';
	protected $_slug                        = 'gravityforms-krayin-crm';
	protected $_path                        = 'gravityforms-krayin-crm/gravityforms-krayin-crm.php';
	protected $_full_path                   = __FILE__;
	protected $_url                         = 'https://crm.devinsight.site';
	protected $_title                       = 'Gravity Forms Krayin CRM Add-On';
	protected $_short_title                 = 'Krayin CRM';
	protected $_enable_rg_autoupgrade       = false;
	protected $_async_feed_processing       = true;
	protected $_capabilities_settings_page  = 'gravityforms_krayin_crm';
	protected $_capabilities_form_settings  = 'gravityforms_krayin_crm';
	protected $_capabilities_uninstall      = 'gravityforms_krayin_crm_uninstall';
	protected $_capabilities                = array( 'gravityforms_krayin_crm', 'gravityforms_krayin_crm_uninstall' );

	/**
	 * @var GF_Krayin_CRM|null
	 */
	private static $_instance = null;

	/**
	 * @return GF_Krayin_CRM
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * @return string
	 */
	public function get_menu_icon() {
		return 'dashicons-groups';
	}

	// # PLUGIN SETTINGS (global Krayin connection) --------------------------------------------------------------

	/**
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => esc_html__( 'Krayin CRM Connection', 'gravityforms-krayin-crm' ),
				'description' => esc_html__( 'Connect to your Krayin CRM installation. We recommend creating a dedicated Krayin user for this integration: every time this add-on logs in (e.g. after the token expires or is revoked), Krayin invalidates that user\'s other active API tokens.', 'gravityforms-krayin-crm' ),
				'fields'      => array(
					array(
						'name'                => 'krayinUrl',
						'label'               => esc_html__( 'Krayin Base URL', 'gravityforms-krayin-crm' ),
						'type'                => 'text',
						'class'               => 'large',
						'required'            => true,
						'placeholder'         => 'https://crm.example.com',
						'validation_callback' => array( $this, 'validate_krayin_url' ),
						'tooltip'             => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Krayin Base URL', 'gravityforms-krayin-crm' ),
							esc_html__( 'The full URL of your Krayin CRM installation, without a trailing slash.', 'gravityforms-krayin-crm' )
						),
					),
					array(
						'name'     => 'krayinEmail',
						'label'    => esc_html__( 'API User Email', 'gravityforms-krayin-crm' ),
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
					),
					array(
						'name'                => 'krayinPassword',
						'label'               => esc_html__( 'API User Password', 'gravityforms-krayin-crm' ),
						'type'                => 'text',
						'input_type'          => 'password',
						'class'               => 'medium',
						'required'            => true,
						'validation_callback' => array( $this, 'validate_krayin_credentials' ),
						'tooltip'             => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'API User Password', 'gravityforms-krayin-crm' ),
							esc_html__( 'The connection is verified when you save these settings.', 'gravityforms-krayin-crm' )
						),
					),
				),
			),
		);
	}

	/**
	 * Requires the URL field to look like a URL.
	 *
	 * @param array  $field
	 * @param string $value
	 */
	public function validate_krayin_url( $field, $value ) {
		if ( rgar( $field, 'required' ) && rgblank( $value ) ) {
			$this->set_field_error( $field, esc_html__( 'Please enter your Krayin CRM URL.', 'gravityforms-krayin-crm' ) );

			return;
		}

		if ( ! rgblank( $value ) && ! preg_match( '#^https?://#i', $value ) ) {
			$this->set_field_error( $field, esc_html__( 'Please enter a full URL, including http:// or https://.', 'gravityforms-krayin-crm' ) );
		}
	}

	/**
	 * Live-tests the connection using the just-submitted URL/email/password before the settings are saved,
	 * so misconfigured credentials are caught immediately instead of failing silently on the next form submission.
	 *
	 * @param array  $field
	 * @param string $value
	 */
	public function validate_krayin_credentials( $field, $value ) {
		if ( rgar( $field, 'required' ) && rgblank( $value ) ) {
			$this->set_field_error( $field, esc_html__( 'Please enter the API user password.', 'gravityforms-krayin-crm' ) );

			return;
		}

		$url   = rgpost( '_gaddon_setting_krayinUrl' );
		$email = rgpost( '_gaddon_setting_krayinEmail' );

		if ( rgblank( $url ) || rgblank( $email ) || rgblank( $value ) ) {
			return;
		}

		$client = new Krayin_API_Client( $url, $email, $value );
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			$this->set_field_error( $field, sprintf(
				/* translators: %s: error message returned by Krayin */
				esc_html__( 'Could not connect to Krayin CRM: %s', 'gravityforms-krayin-crm' ),
				$result->get_error_message()
			) );

			return;
		}

		// Fresh, valid credentials: drop any cached source/type choice lists tied to the old credentials.
		delete_transient( $this->get_choices_cache_key( 'sources', $url, $email, $value ) );
		delete_transient( $this->get_choices_cache_key( 'types', $url, $email, $value ) );
	}

	// # FEED SETTINGS (per-form field mapping) -------------------------------------------------------------------

	/**
	 * @return array
	 */
	public function feed_settings_fields() {
		$action_dependency = array(
			'live'   => true,
			'fields' => array(
				array(
					'field'  => 'krayinAction',
					'values' => array( 'lead' ),
				),
			),
		);

		return array(
			array(
				'fields' => array(
					array(
						'label'    => esc_html__( 'Name', 'gravityforms-krayin-crm' ),
						'name'     => 'feedName',
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
						'tooltip'  => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Name', 'gravityforms-krayin-crm' ),
							esc_html__( 'Enter a feed name to uniquely identify this setup.', 'gravityforms-krayin-crm' )
						),
					),
					array(
						'label'         => esc_html__( 'Send to Krayin as', 'gravityforms-krayin-crm' ),
						'name'          => 'krayinAction',
						'type'          => 'radio',
						'horizontal'    => true,
						'default_value' => 'lead',
						'required'      => true,
						'choices'       => array(
							array(
								'label' => esc_html__( 'A Lead (with its Person)', 'gravityforms-krayin-crm' ),
								'value' => 'lead',
							),
							array(
								'label' => esc_html__( 'A Person only (no Lead)', 'gravityforms-krayin-crm' ),
								'value' => 'person',
							),
						),
					),
				),
			),
			array(
				'title'  => esc_html__( 'Contact Field Mapping', 'gravityforms-krayin-crm' ),
				'fields' => array(
					array(
						'name'      => 'krayinFieldMap',
						'label'     => esc_html__( 'Field Map', 'gravityforms-krayin-crm' ),
						'type'      => 'field_map',
						'field_map' => array(
							array(
								'name'     => 'personName',
								'label'    => esc_html__( 'Full Name', 'gravityforms-krayin-crm' ),
								'required' => false,
							),
							array(
								'name'     => 'personEmail',
								'label'    => esc_html__( 'Email', 'gravityforms-krayin-crm' ),
								'required' => false,
							),
							array(
								'name'     => 'personPhone',
								'label'    => esc_html__( 'Phone Number', 'gravityforms-krayin-crm' ),
								'required' => false,
							),
							array(
								'name'     => 'organizationName',
								'label'    => esc_html__( 'Company / Organization', 'gravityforms-krayin-crm' ),
								'required' => false,
							),
						),
						'tooltip'   => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Contact Field Mapping', 'gravityforms-krayin-crm' ),
							esc_html__( 'Map the fields used to identify the Person in Krayin. At least Full Name or Email should be mapped. Every submission creates a new Person record; use Krayin\'s duplicate management tools to merge repeat contacts. Company / Organization, if mapped, creates a new Organization record per submission.', 'gravityforms-krayin-crm' )
						),
					),
				),
			),
			array(
				'title'      => esc_html__( 'Lead Details', 'gravityforms-krayin-crm' ),
				'dependency' => $action_dependency,
				'fields'     => array(
					array(
						'name'      => 'krayinLeadFieldMap',
						'label'     => esc_html__( 'Lead Field Map', 'gravityforms-krayin-crm' ),
						'type'      => 'field_map',
						'field_map' => array(
							array(
								'name'     => 'leadTitle',
								'label'    => esc_html__( 'Lead Title', 'gravityforms-krayin-crm' ),
								'required' => false,
							),
							array(
								'name'     => 'leadDescription',
								'label'    => esc_html__( 'Lead Description', 'gravityforms-krayin-crm' ),
								'required' => false,
							),
							array(
								'name'     => 'leadValue',
								'label'    => esc_html__( 'Lead Value', 'gravityforms-krayin-crm' ),
								'required' => false,
							),
						),
						'tooltip'   => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Lead Field Map', 'gravityforms-krayin-crm' ),
							esc_html__( 'Optional. Leave a row unmapped to use a sensible default (the form title as the Lead Title, a summary of all submitted fields as the Description, 0 as the Value).', 'gravityforms-krayin-crm' )
						),
					),
					array(
						'name'       => 'leadSourceId',
						'label'      => esc_html__( 'Lead Source', 'gravityforms-krayin-crm' ),
						'type'       => 'select',
						'required'   => true,
						'choices'    => $this->get_krayin_choices( 'sources' ),
					),
					array(
						'name'       => 'leadTypeId',
						'label'      => esc_html__( 'Lead Type', 'gravityforms-krayin-crm' ),
						'type'       => 'select',
						'required'   => true,
						'choices'    => $this->get_krayin_choices( 'types' ),
					),
				),
			),
			array(
				'fields' => array(
					array(
						'name'           => 'feedCondition',
						'type'           => 'feed_condition',
						'label'          => esc_html__( 'Condition', 'gravityforms-krayin-crm' ),
						'checkbox_label' => esc_html__( 'Enable Condition', 'gravityforms-krayin-crm' ),
						'instructions'   => esc_html__( 'Send to Krayin CRM if', 'gravityforms-krayin-crm' ),
						'tooltip'        => sprintf(
							'<h6>%s</h6>%s',
							esc_html__( 'Conditional Logic', 'gravityforms-krayin-crm' ),
							esc_html__( 'When conditions are enabled, this feed will only be processed when the conditions are met. When disabled, it will be processed for every form submission.', 'gravityforms-krayin-crm' )
						),
					),
				),
			),
		);
	}

	/**
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'    => esc_html__( 'Name', 'gravityforms-krayin-crm' ),
			'krayinAction' => esc_html__( 'Sends To Krayin As', 'gravityforms-krayin-crm' ),
		);
	}

	/**
	 * @param array $feed
	 *
	 * @return string
	 */
	public function get_column_value_krayinAction( $feed ) {
		return 'person' === rgars( $feed, 'meta/krayinAction' )
			? esc_html__( 'Person only', 'gravityforms-krayin-crm' )
			: esc_html__( 'Lead', 'gravityforms-krayin-crm' );
	}

	/**
	 * @param int|array $id
	 *
	 * @return bool
	 */
	public function can_duplicate_feed( $id ) {
		return true;
	}

	// # FEED PROCESSING -------------------------------------------------------------------------------------------

	/**
	 * Sends the entry to Krayin CRM as a Lead or a Person, per the feed configuration.
	 *
	 * @param array $feed
	 * @param array $entry
	 * @param array $form
	 *
	 * @return array|WP_Error The (possibly unmodified) entry on success, WP_Error on failure.
	 */
	public function process_feed( $feed, $entry, $form ) {
		$client = $this->get_configured_client();

		if ( is_wp_error( $client ) ) {
			$this->add_feed_error( $client->get_error_message(), $feed, $entry, $form );

			return $client;
		}

		$contact_map      = $this->get_field_map_fields( $feed, 'krayinFieldMap' );
		$person_name      = $this->get_mapped_value( $form, $entry, $contact_map, 'personName' );
		$person_email     = $this->get_mapped_value( $form, $entry, $contact_map, 'personEmail' );
		$person_phone     = $this->get_mapped_value( $form, $entry, $contact_map, 'personPhone' );
		$organization_name = $this->get_mapped_value( $form, $entry, $contact_map, 'organizationName' );

		if ( '' === $person_name && '' === $person_email ) {
			$error = esc_html__( 'Krayin CRM: neither Full Name nor Email resolved to a value for this entry, so no Person could be created. Check the field mapping.', 'gravityforms-krayin-crm' );

			$this->add_feed_error( $error, $feed, $entry, $form );

			return new WP_Error( 'gfkrayincrm_missing_person_data', $error );
		}

		$organization_id = null;

		if ( '' !== $organization_name ) {
			$organization_result = $client->create_organization( array( 'name' => $organization_name ) );

			if ( is_wp_error( $organization_result ) ) {
				// Non-fatal: continue without linking an organization, but note it on the entry.
				$this->add_note(
					rgar( $entry, 'id' ),
					sprintf(
						/* translators: %s: error message returned by Krayin */
						esc_html__( 'Krayin CRM: could not create organization "%1$s": %2$s', 'gravityforms-krayin-crm' ),
						$organization_name,
						$organization_result->get_error_message()
					),
					'error'
				);
			} else {
				$organization_id = rgars( $organization_result, 'data/id' );
			}
		}

		$person = array(
			'name' => '' !== $person_name ? $person_name : $person_email,
		);

		if ( '' !== $person_email ) {
			$person['emails'] = array( array( 'value' => $person_email, 'label' => 'work' ) );
		}

		if ( '' !== $person_phone ) {
			$person['contact_numbers'] = array( array( 'value' => $person_phone, 'label' => 'work' ) );
		}

		if ( ! empty( $organization_id ) ) {
			$person['organization_id'] = $organization_id;
		}

		$action = rgars( $feed, 'meta/krayinAction', 'lead' );

		if ( 'person' === $action ) {
			$result = $client->create_person( $person );

			if ( is_wp_error( $result ) ) {
				$this->add_feed_error( $result->get_error_message(), $feed, $entry, $form );

				return $result;
			}

			$this->add_note(
				rgar( $entry, 'id' ),
				sprintf(
					/* translators: %d: Krayin person ID */
					esc_html__( 'Krayin CRM: contact created (Person #%d).', 'gravityforms-krayin-crm' ),
					(int) rgars( $result, 'data/id' )
				),
				'success'
			);

			return $entry;
		}

		$lead_source_id = (int) rgars( $feed, 'meta/leadSourceId' );
		$lead_type_id   = (int) rgars( $feed, 'meta/leadTypeId' );

		if ( empty( $lead_source_id ) || empty( $lead_type_id ) ) {
			$error = esc_html__( 'Krayin CRM: this feed is missing a Lead Source or Lead Type. Edit the feed and select both.', 'gravityforms-krayin-crm' );

			$this->add_feed_error( $error, $feed, $entry, $form );

			return new WP_Error( 'gfkrayincrm_missing_lead_settings', $error );
		}

		$lead_map         = $this->get_field_map_fields( $feed, 'krayinLeadFieldMap' );
		$lead_title       = $this->get_mapped_value( $form, $entry, $lead_map, 'leadTitle' );
		$lead_description = $this->get_mapped_value( $form, $entry, $lead_map, 'leadDescription' );
		$lead_value       = $this->get_mapped_value( $form, $entry, $lead_map, 'leadValue' );

		$lead = array(
			'title'           => '' !== $lead_title ? $lead_title : sprintf(
				/* translators: 1: form title, 2: entry ID */
				esc_html__( '%1$s (Entry #%2$d)', 'gravityforms-krayin-crm' ),
				rgar( $form, 'title' ),
				rgar( $entry, 'id' )
			),
			'description'     => '' !== $lead_description ? $lead_description : $this->build_entry_summary( $form, $entry ),
			'lead_value'      => '' !== $lead_value ? $lead_value : 0,
			'lead_source_id'  => $lead_source_id,
			'lead_type_id'    => $lead_type_id,
			'person'          => $person,
		);

		$result = $client->create_lead( $lead );

		if ( is_wp_error( $result ) ) {
			$this->add_feed_error( $result->get_error_message(), $feed, $entry, $form );

			return $result;
		}

		$this->add_note(
			rgar( $entry, 'id' ),
			sprintf(
				/* translators: %d: Krayin lead ID */
				esc_html__( 'Krayin CRM: lead created (Lead #%d).', 'gravityforms-krayin-crm' ),
				(int) rgars( $result, 'data/id' )
			),
			'success'
		);

		return $entry;
	}

	/**
	 * @param array  $form
	 * @param array  $entry
	 * @param array  $map   Result of get_field_map_fields().
	 * @param string $key
	 *
	 * @return string
	 */
	private function get_mapped_value( $form, $entry, $map, $key ) {
		$field_id = rgar( $map, $key );

		if ( rgblank( $field_id ) ) {
			return '';
		}

		return trim( (string) $this->get_field_value( $form, $entry, $field_id ) );
	}

	/**
	 * Builds a plain-text "Label: value" summary of every non-empty field in the entry,
	 * used as the Lead description when no field is explicitly mapped to it.
	 *
	 * @param array $form
	 * @param array $entry
	 *
	 * @return string
	 */
	private function build_entry_summary( $form, $entry ) {
		$lines = array();

		foreach ( $form['fields'] as $field ) {
			if ( in_array( $field->type, array( 'page', 'section', 'html' ), true ) ) {
				continue;
			}

			$value = $this->get_field_value( $form, $entry, $field->id );

			if ( rgblank( $value ) ) {
				continue;
			}

			$lines[] = GFCommon::get_label( $field ) . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	// # KRAYIN CONNECTION HELPERS ---------------------------------------------------------------------------------

	/**
	 * @return Krayin_API_Client|WP_Error
	 */
	private function get_configured_client() {
		$settings = $this->get_plugin_settings();
		$url      = rgar( $settings, 'krayinUrl' );
		$email    = rgar( $settings, 'krayinEmail' );
		$password = rgar( $settings, 'krayinPassword' );

		if ( rgblank( $url ) || rgblank( $email ) || rgblank( $password ) ) {
			return new WP_Error(
				'gfkrayincrm_not_configured',
				esc_html__( 'Krayin CRM connection is not configured. Go to Forms > Settings > Krayin CRM.', 'gravityforms-krayin-crm' )
			);
		}

		return new Krayin_API_Client( $url, $email, $password );
	}

	/**
	 * @param string $kind 'sources' or 'types'.
	 *
	 * @return array Select field choices.
	 */
	private function get_krayin_choices( $kind ) {
		$settings = $this->get_plugin_settings();
		$url      = rgar( $settings, 'krayinUrl' );
		$email    = rgar( $settings, 'krayinEmail' );
		$password = rgar( $settings, 'krayinPassword' );

		if ( rgblank( $url ) || rgblank( $email ) || rgblank( $password ) ) {
			return array(
				array(
					'label' => esc_html__( 'Configure the Krayin CRM connection first', 'gravityforms-krayin-crm' ),
					'value' => '',
				),
			);
		}

		$cache_key = $this->get_choices_cache_key( $kind, $url, $email, $password );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$client = new Krayin_API_Client( $url, $email, $password );
		$items  = 'types' === $kind ? $client->get_types() : $client->get_sources();

		if ( is_wp_error( $items ) ) {
			return array(
				array(
					/* translators: %s: error message returned by Krayin */
					'label' => sprintf( esc_html__( 'Unable to load from Krayin CRM: %s', 'gravityforms-krayin-crm' ), $items->get_error_message() ),
					'value' => '',
				),
			);
		}

		$choices = array(
			array(
				'label' => esc_html__( 'Select one', 'gravityforms-krayin-crm' ),
				'value' => '',
			),
		);

		foreach ( (array) $items as $item ) {
			$choices[] = array(
				'label' => rgar( $item, 'name' ),
				'value' => rgar( $item, 'id' ),
			);
		}

		set_transient( $cache_key, $choices, 5 * MINUTE_IN_SECONDS );

		return $choices;
	}

	/**
	 * @param string $kind
	 * @param string $url
	 * @param string $email
	 * @param string $password
	 *
	 * @return string
	 */
	private function get_choices_cache_key( $kind, $url, $email, $password ) {
		return 'gfkrayincrm_' . $kind . '_' . md5( $url . '|' . $email . '|' . $password );
	}
}
