<?php

if ( ! class_exists( 'Salesforce_Rest_API' ) ) {
    die();
}

class Wordpress_Salesforce_Admin {

    protected $wpdb;
    protected $version;
    protected $login_credentials;
    protected $text_domain;
    protected $salesforce;
    protected $wordpress;
    protected $mappings;
    protected $push;
    protected $pull;
    protected $schedulable_classes;

    /**
    * Create default WordPress admin functionality for Salesforce
    *
    * @param object $wpdb
    * @param string $version
    * @param array $login_credentials
    * @param string $text_domain
    * @param object $wordpress
    * @param object $salesforce
    * @param object $mappings
    * @param object $logging
    * @param array $schedulable_classes
    * @throws \Exception
    */
    public function __construct( $wpdb, $version, $login_credentials, $text_domain, $wordpress, $salesforce, $mappings, $push, $pull, $logging, $schedulable_classes ) {
        $this->wpdb = &$wpdb;
        $this->version = $version;
        $this->login_credentials = $login_credentials;
        $this->text_domain = $text_domain;
        $this->wordpress = $wordpress;
        $this->salesforce = $salesforce;
        $this->mappings = $mappings;
        $this->push = $push;
        $this->pull = $pull;
        $this->logging = $logging;
        $this->schedulable_classes = $schedulable_classes;

        // todo: we should think about what kind of admin_notices to use, if any
        // https://codex.wordpress.org/Plugin_API/Action_Reference/admin_notices

        $this->add_actions();

    }

    /**
    * Create the action hooks to create the admin page(s)
    *
    */
    public function add_actions() {
        add_action( 'admin_init', array( &$this, 'salesforce_settings_forms' ) );
        add_action( 'admin_post_post_fieldmap', array( &$this, 'prepare_fieldmap_data' ) );
        add_action( 'admin_notices', array( &$this, 'fieldmap_error_notice' ) );
        add_action( 'admin_notices', array( $this, 'permission_error_notice' ) );
        add_action( 'admin_post_delete_fieldmap', array( &$this, 'delete_fieldmap' ) );
        add_action( 'wp_ajax_get_salesforce_object_description', array( $this, 'get_salesforce_object_description' ) );
        add_action( 'wp_ajax_get_wordpress_object_description', array( $this, 'get_wordpress_object_fields' ) );
        add_action( 'wp_ajax_get_wp_sf_object_fields', array( $this, 'get_wp_sf_object_fields' ) );
        add_action( 'wp_ajax_push_to_salesforce', array( $this, 'push_to_salesforce' ) );
        add_action( 'wp_ajax_pull_from_salesforce', array( $this, 'pull_from_salesforce' ) );
        add_action( 'wp_ajax_refresh_mapped_data', array( $this, 'refresh_mapped_data' ) );

        add_action( 'edit_user_profile', array( $this, 'show_salesforce_user_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_salesforce_user_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_salesforce_user_fields' ) );

    }

    /**
    * Create WordPress admin options page
    *
    */
    public function create_admin_menu() {
        $title = __('Salesforce','salesforce-api');
        add_options_page( $title, $title, 'manage_options', 'salesforce-api-admin', array( &$this, 'show_admin_page', ) );
    }

    /**
    * Render full admin pages in WordPress
    * This allows other plugins to add tabs to the Salesforce settings screen
    *
    * todo: better front end: html, organization of html into templates, css, js
    *
    */ 
    public function show_admin_page() {
        echo '<div class="wrap">';
            echo '<h1>' . get_admin_page_title() . '</h1>';
            $allowed = $this->check_wordpress_admin_permissions();
            if ( FALSE === $allowed ) {
                return;
            }
            $tabs = array(
                'settings' => 'Settings',
                'authorize' => 'Authorize',
                'fieldmaps' => 'Fieldmaps',
                'schedule' => 'Scheduling',
            ); // this creates the tabs for the admin

            // optionally make tab(s) for logging and log settings
            $logging_enabled = get_option( 'salesforce_api_enable_logging', FALSE );
            if ( $logging_enabled === '1' ) {
                $tabs['log_settings'] = 'Log Settings';
            }

            // filter for extending the tabs available on the page
            // currently it will go into the default switch case for $tab
            $tabs = apply_filters( 'salesforce_rest_api_settings_tabs', $tabs );

            $tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
            $this->tabs( $tabs, $tab );

            $consumer_key = $this->login_credentials['consumer_key'];
            $consumer_secret = $this->login_credentials['consumer_secret'];
            $callback_url = $this->login_credentials['callback_url'];
            $text_domain = $this->text_domain;

            try {
                switch ( $tab ) {
                    case 'authorize':
                        if ( isset( $_GET['code'] ) )  {
                            $is_authorized = $this->salesforce['sfapi']->request_token( esc_attr( $_GET['code'] ) );
                            echo "<script>window.location = '$callback_url';</script>";
                        } elseif ( $this->salesforce['is_authorized'] === true ) {
                            require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/authorized.php' );
                            $this->demo( $this->salesforce['sfapi'] );
                        } elseif ( isset( $consumer_key ) && isset( $consumer_secret ) ) {
                            echo '<p><a class="button-primary" href="' . $this->salesforce['sfapi']->get_authorization_code() . '">' . esc_html__( 'Connect to Salesforce', $this->text_domain ) . '</a></p>';
                        } // need to throw an error here if all the stuff is missing
                        break;
                    case 'fieldmaps':
                        if ( isset( $_GET['method'] ) ) {

                            $method = esc_attr( $_GET['method'] );
                            $error_url = get_admin_url( null, 'options-general.php?page=salesforce-api-admin&tab=fieldmaps&method=' . $method );
                            $success_url = get_admin_url( null, 'options-general.php?page=salesforce-api-admin&tab=fieldmaps' );

                            if ( isset( $_GET['transient'] ) ) {
                                $transient = esc_html( $_GET['transient'] );
                                $posted = get_transient( $transient );
                            }

                            if ( isset( $posted ) && is_array( $posted ) ) {
                                $map = $posted;
                            } elseif ( $method === 'edit' || $method === 'clone' || $method === 'delete' ) {
                                $map = $this->mappings->get_fieldmaps( $_GET['id'] );
                            }

                            if ( isset( $map ) && is_array( $map ) ) {
                                $label = $map['label'];
                                $salesforce_object = $map['salesforce_object'];
                                $salesforce_record_types_allowed = maybe_unserialize( $map['salesforce_record_types_allowed'] );
                                $salesforce_record_type_default = $map['salesforce_record_type_default'];
                                $wordpress_object = $map['wordpress_object'];
                                $pull_trigger_field = $map['pull_trigger_field'];
                                $fieldmap_fields = $map['fields'];
                                $sync_triggers = $map['sync_triggers'];
                                $push_async = $map['push_async'];
                                $ignore_drafts = $map['ignore_drafts'];
                                $weight = $map['weight'];
                            }
                            
                            if ( $method === 'add' || $method === 'edit' || $method === 'clone' ) {
                                require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/fieldmaps-add-edit-clone.php' );
                            } elseif ( $method === 'delete' ) {
                                require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/fieldmaps-delete.php' );
                            }

                        } else {
                            $fieldmaps = $this->mappings->get_fieldmaps();
                            require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/fieldmaps-list.php' );
                        }
                        break;
                    case 'logout':
                        $message = $this->logout();
                        echo '<p>' . $message . '</p>';
                        break;
                    case 'clear_schedule':
                        if ( isset( $_GET['schedule_name'] ) )  {
                            $schedule_name = urlencode( $_GET['schedule_name'] );
                        }
                        $message = $this->clear_schedule( $schedule_name );
                        echo '<p>' . $message . '</p>';
                        break;
                    case 'settings':
                        $consumer_key = $this->login_credentials['consumer_key'];
                        $consumer_secret = $this->login_credentials['consumer_secret'];
                        if ( $consumer_key && $consumer_secret ) {
                            if ( $this->salesforce['is_authorized'] === true ) {
                                require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/settings.php' );
                            } else {
                                $message = __( 'Salesforce needs to be authorized to connect to this website.', $this->text_domain );
                                require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/error.php' );
                            }
                        } else {
                            require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/settings.php' );
                        }
                        break;
                    default:
                        require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/settings.php' );
                        break;
                }

            }
            catch( SalesforceApiException $Ex ) {
                echo 'Error '.$Ex->getCode().', '.$Ex->getMessage();
            }
            catch( Exception $Ex ) {
                echo 'Error '.$Ex->getCode().', '.$Ex->getMessage();
            }
        echo '</div>';
    }

    /**
    * Create default WordPress admin settings form for salesforce
    * This is for the Settings page/tab
    *
    */
    public function salesforce_settings_forms() {
        $page = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
        $section = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
        
        $input_callback_default = array( &$this, 'display_input_field' );
        $input_checkboxes_default = array( &$this, 'display_checkboxes' );
        $input_select_default = array( &$this, 'display_select' );
        $link_default = array( &$this, 'display_link' );

        $all_field_callbacks = array(
            'text' => $input_callback_default,
            'checkboxes' => $input_checkboxes_default,
            'select' => $input_select_default,
            'link' => $link_default
        );

        $this->fields_settings( 'settings', 'settings', $all_field_callbacks );
        $this->fields_fieldmaps( 'fieldmaps', 'objects' );
        $this->fields_scheduling( 'schedule', 'schedule', $all_field_callbacks );
        $this->fields_log_settings( 'log_settings', 'log_settings', $all_field_callbacks );
    }

    /**
    * Fields for the Settings tab
    * This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
    *
    * @param string $page
    * @param string $section
    * @param string $input_callback
    */
    private function fields_settings( $page, $section, $callbacks ) {
        add_settings_section( $page, ucwords( $page ), null, $page );
        $salesforce_settings = array(
            'consumer_key' => array(
                'title' => 'Consumer Key',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'text',
                    'desc' => '',
                    'constant' => 'SALESFORCE_CONSUMER_KEY'
                ),
                
            ),
            'consumer_secret' => array(
                'title' => 'Consumer Secret',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'text',
                    'desc' => '',
                    'constant' => 'SALESFORCE_CONSUMER_SECRET'
                ),
            ),
            'callback_url' => array(
                'title' => 'Callback URL',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'url',
                    'desc' => '',
                    'constant' => 'SALESFORCE_CALLBACK_URL'
                ),
            ),
            'login_base_url' => array(
                'title' => 'Login Base URL',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'url',
                    'desc' => '',
                    'constant' => 'SALESFORCE_LOGIN_BASE_URL'
                ),
            ),
            'api_version' => array(
                'title' => 'Salesforce API Version',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'text',
                    'desc' => '',
                    'constant' => 'SALESFORCE_API_VERSION'
                ),
            ),
            'object_filters' => array(
                'title' => 'Limit Salesforce Objects',
                'callback' => $callbacks['checkboxes'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'checkboxes',
                    'desc' => 'Allows you to limit which Salesforce objects can be mapped',
                    'items' => array(
                        'triggerable' => array(
                            'text' => 'Only Triggerable objects',
                            'id' => 'triggerable',
                            'desc' => '',
                            'default' => TRUE
                        ),
                        'updateable' => array(
                            'text' => 'Only Updateable objects',
                            'id' => 'updateable',
                            'desc' => '',
                            'default' => TRUE
                        )
                    )
                )
            ),
            'pull_throttle' => array(
                'title' => 'Pull throttle (seconds)',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'text',
                    'desc' => 'Number of seconds to wait between repeated salesforce pulls.<br>Prevents the webserver from becoming overloaded in case of too many cron runs, or webhook usage.',
                    'constant' => ''
                ),
            ),
        );
        foreach ( $salesforce_settings as $key => $attributes ) {
            $id = 'salesforce_api_' . $key;
            $name = 'salesforce_api_' . $key;
            $title = $attributes['title'];
            $callback = $attributes['callback'];
            $page = $attributes['page'];
            $section = $attributes['section'];
            $args = array_merge(
                $attributes['args'],
                array(
                    'title' => $title,
                    'id' => $id,
                    'label_for' => $id,
                    'name' => $name
                )
            );
            add_settings_field( $id, $title, $callback, $page, $section, $args );
            register_setting( $page, $id );
        }
    }

    /**
    * Fields for the Fieldmaps tab
    * This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
    *
    * @param string $page
    * @param string $section
    * @param string $input_callback
    */
    private function fields_fieldmaps( $page, $section, $input_callback = '' ) {
        add_settings_section( $page, ucwords( $page ), null, $page );
    }

    /**
    * Fields for the Scheduling tab
    * This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
    *
    * @param string $page
    * @param string $section
    * @param string $input_callback
    */
    private function fields_scheduling( $page, $section, $callbacks ) {
        foreach ( $this->schedulable_classes as $key => $value ) {
            add_settings_section( $key, $value['label'], null, $page );
            $schedule_settings = array(
                $key . '_schedule_number' => array(
                    'title' => __( 'Run schedule every', $this->text_domain ),
                    'callback' => $callbacks['text'],
                    'page' => $page,
                    'section' => $key,
                    'args' => array(
                        'type' => 'text',
                        'desc' => '',
                        'constant' => ''
                    ),
                ),
                $key . '_schedule_unit' => array(
                    'title' => __( 'Time unit', $this->text_domain ),
                    'callback' => $callbacks['select'],
                    'page' => $page,
                    'section' => $key,
                    'args' => array(
                        'type' => 'select',
                        'desc' => '',
                        'items' => array(
                            'minutes' => array(
                                'text' => 'Minutes',
                                'value' => 'minutes',
                            ),
                            'hours' => array(
                                'text' => 'Hours',
                                'value' => 'hours',
                            ),
                            'days' => array(
                                'text' => 'Days',
                                'value' => 'days',
                            ),
                        )
                    )
                ),
                $key . '_clear_button' => array(
                    'title' => __( 'This queue has ' . $this->get_schedule_count( $key ) . ' ' . ( $this->get_schedule_count( $key ) === '1' ? 'item' : 'items' ), $this->text_domain ),
                    'callback' => $callbacks['link'],
                    'page' => $page,
                    'section' => $key,
                    'args' => array(
                        'label' => 'Clear this queue',
                        'desc' => '',
                        'url' => '?page=salesforce-api-admin&amp;tab=clear_schedule&amp;schedule_name=' . $key,
                        'link_class' => 'button button-secondary'
                    ),
                ),
            );
            foreach ( $schedule_settings as $key => $attributes ) {
                $id = 'salesforce_api_' . $key;
                $name = 'salesforce_api_' . $key;
                $title = $attributes['title'];
                $callback = $attributes['callback'];
                $page = $attributes['page'];
                $section = $attributes['section'];
                $args = array_merge(
                    $attributes['args'],
                    array(
                        'title' => $title,
                        'id' => $id,
                        'label_for' => $id,
                        'name' => $name
                    )
                );
                add_settings_field( $id, $title, $callback, $page, $section, $args );
                register_setting( $page, $id );
            }
        }
    }

    /**
    * Fields for the Log Settings tab
    * This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
    *
    * @param string $page
    * @param string $section
    * @param array $callbacks
    */
    private function fields_log_settings( $page, $section, $callbacks ) {
        add_settings_section( $page, ucwords( str_replace('_', ' ', $page) ), null, $page );
        $log_settings = array(
            'enable_logging' => array(
                'title' => 'Enable Logging?',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'checkbox',
                    'desc' => '',
                    'constant' => ''
                ),
            ),
            'statuses_to_log' => array(
                'title' => 'Statuses to log',
                'callback' => $callbacks['checkboxes'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'checkboxes',
                    'desc' => 'these are the statuses to log',
                    'items' => array(
                        'error' => array(
                            'text' => 'Error',
                            'id' => 'error',
                            'desc' => ''
                        ),
                        'success' => array(
                            'text' => 'Success',
                            'id' => 'success',
                            'desc' => ''
                        ),
                        'notice' => array(
                            'text' => 'Notice',
                            'id' => 'notice',
                            'desc' => ''
                        )
                    )
                )
            ),
            'prune_logs' => array(
                'title' => 'Automatically delete old log entries?',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'checkbox',
                    'desc' => '',
                    'constant' => ''
                ),
            ),
            'logs_how_old' => array(
                'title' => 'Age to delete log entries',
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'text',
                    'desc' => 'If automatic deleting is enabled, it will affect logs this old.',
                    'default' => '2 weeks',
                    'constant' => ''
                ),
            ),
            'logs_how_often_number' => array(
                'title' => __( 'Check for old logs every', $this->text_domain ),
                'callback' => $callbacks['text'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'text',
                    'desc' => '',
                    'default' => '1',
                    'constant' => ''
                ),
            ),
            'logs_how_often_unit' => array(
                'title' => __( 'Time unit', $this->text_domain ),
                    'callback' => $callbacks['select'],
                    'page' => $page,
                    'section' => $section,
                    'args' => array(
                        'type' => 'select',
                        'desc' => 'These two fields are how often the site will check for logs to delete.',
                        'items' => array(
                            'minutes' => array(
                                'text' => 'Minutes',
                                'value' => 'minutes',
                            ),
                            'hours' => array(
                                'text' => 'Hours',
                                'value' => 'hours',
                            ),
                            'days' => array(
                                'text' => 'Days',
                                'value' => 'days',
                            ),
                        )
                    )
            ),
            'triggers_to_log' => array(
                'title' => 'Triggers to log',
                'callback' => $callbacks['checkboxes'],
                'page' => $page,
                'section' => $section,
                'args' => array(
                    'type' => 'checkboxes',
                    'desc' => 'these are the triggers to log',
                    'items' => array(
                        $this->mappings->sync_wordpress_create => array(
                            'text' => 'WordPress create',
                            'id' => 'wordpress_create',
                            'desc' => ''
                        ),
                        $this->mappings->sync_wordpress_update => array(
                            'text' => 'WordPress update',
                            'id' => 'wordpress_update',
                            'desc' => ''
                        ),
                        $this->mappings->sync_wordpress_delete => array(
                            'text' => 'WordPress delete',
                            'id' => 'wordpress_delete',
                            'desc' => ''
                        ),
                        $this->mappings->sync_sf_create => array(
                            'text' => 'Salesforce create',
                            'id' => 'sf_create',
                            'desc' => ''
                        ),
                        $this->mappings->sync_sf_update => array(
                            'text' => 'Salesforce update',
                            'id' => 'sf_update',
                            'desc' => ''
                        ),
                        $this->mappings->sync_sf_delete => array(
                            'text' => 'Salesforce delete',
                            'id' => 'sf_delete',
                            'desc' => ''
                        )
                    )
                )
            ),
        );
        foreach ( $log_settings as $key => $attributes ) {
            $id = 'salesforce_api_' . $key;
            $name = 'salesforce_api_' . $key;
            $title = $attributes['title'];
            $callback = $attributes['callback'];
            $page = $attributes['page'];
            $section = $attributes['section'];
            $args = array_merge(
                $attributes['args'],
                array(
                    'title' => $title,
                    'id' => $id,
                    'label_for' => $id,
                    'name' => $name
                )
            );
            add_settings_field( $id, $title, $callback, $page, $section, $args );
            register_setting( $page, $id );
        }
    }

    /**
    * Get all the Salesforce object settings for fieldmapping
    * This takes either the $_POST array via ajax, or can be directly called with a $data array
    * 
    * @param array $data
    * data must contain a salesforce_object
    * can optionally contain a type
    * @return array $object_settings
    */
    public function get_salesforce_object_description( $data = array() ) {
        $ajax = false;
        if ( empty( $data ) ) {
            $data = $_POST;
            $ajax = true;
        }

        $object_description = array();

        if ( !empty( $data['salesforce_object'] ) ) {
            $object = $this->salesforce['sfapi']->object_describe( esc_attr( $data['salesforce_object'] ) );
            
            $object_fields = array();
            $include_record_types = array();

            // these can come from ajax
            $include = isset( $data['include'] ) ? (array) $data['include'] : array();
            $include = array_map( 'esc_attr', $include );
            
            if ( in_array( 'fields', $include ) || empty( $include ) ) {
                $type = isset( $data['field_type'] ) ? esc_attr( $data['field_type'] ) : ''; // can come from ajax
                foreach ( $object['data']['fields'] as $key => $value) {
                    if ( $type === '' || $type === $value['type'] ) {
                        $object_fields[$key] = $value;
                    }
                }
                $object_description['fields'] = $object_fields;
            }

            if ( in_array( 'recordTypeInfos', $include ) ) {
                if ( isset( $object['data']['recordTypeInfos'] ) && count( $object['data']['recordTypeInfos'] ) > 1 ) {
                    foreach ( $object['data']['recordTypeInfos'] as $type ) {
                        $object_record_types[$type['recordTypeId']] = $type['name'];
                    }
                    $object_description['recordTypeInfos'] = $object_record_types;
                }
            }
        }

        if ( $ajax === true ) {
            wp_send_json_success( $object_description );
        } else {
            return $object_description;
        }
    }

    /**
    * Get Salesforce object fields for fieldmapping
    * 
    * @param array $data
    * data must contain a salesforce_object
    * can optionally contain a type for the field
    * @return array $object_fields
    */
    public function get_salesforce_object_fields( $data = array() ) {

        if ( !empty( $data['salesforce_object'] ) ) {
            $object = $this->salesforce['sfapi']->object_describe( esc_attr( $data['salesforce_object'] ) );
            $object_fields = array();
            $type = isset( $data['type'] ) ? esc_attr( $data['type'] ) : '';
            $include_record_types = isset( $data['include_record_types'] ) ? esc_attr( $data['include_record_types'] ) : FALSE;
            foreach ( $object['data']['fields'] as $key => $value) {
                if ( $type === '' || $type === $value['type'] ) {
                    $object_fields[$key] = $value;
                }
            }
            if ( $include_record_types === TRUE ) {
                $object_record_types = array();
                if ( isset( $object['data']['recordTypeInfos'] ) && count( $object['data']['recordTypeInfos'] ) > 1 ) {
                    foreach ( $object['data']['recordTypeInfos'] as $type ) {
                        $object_record_types[$type['recordTypeId']] = $type['name'];
                    }
                }
            }
        }

        return $object_fields;

    }

    /**
    * Get WordPress object fields for fieldmapping
    * This takes either the $_POST array via ajax, or can be directly called with a $wordpress_object field
    * 
    * @param string $wordpress_object
    * @return array $object_fields
    */
    public function get_wordpress_object_fields( $wordpress_object = '' ) {
        $ajax = false;
        if ( empty( $wordpress_object ) ) {
            $wordpress_object = $_POST['wordpress_object'];
            $ajax = true;
        }
        
        $object_fields = $this->wordpress->get_wordpress_object_fields( $wordpress_object );
        
        if ( $ajax === true ) {
            wp_send_json_success( $object_fields );
        } else {
            return $object_fields;
        }
    }

    /**
    * Get WordPress and Salesforce object fields together for fieldmapping
    * This takes either the $_POST array via ajax, or can be directly called with $wordpress_object and $salesforce_object fields
    * 
    * @param string $wordpress_object
    * @param string $salesforce_object
    * @return array $object_fields
    */
    public function get_wp_sf_object_fields( $wordpress_object = '', $salesforce = '' ) {
        if ( empty( $wordpress_object ) ) {
            $wordpress_object = $_POST['wordpress_object'];
        }
        if ( empty( $salesforce_object ) ) {
            $salesforce_object = $_POST['salesforce_object'];
        }
        
        $object_fields['wordpress'] = $this->get_wordpress_object_fields( $wordpress_object );
        $object_fields['salesforce'] = $this->get_salesforce_object_fields( array( 'salesforce_object' => $salesforce_object ) );
        
        if ( !empty( $_POST ) ) {
            wp_send_json_success( $object_fields );
        } else {
            return $object_fields;
        }
    }

    /**
    * Manually push the WordPress object to Salesforce
    * This takes either the $_POST array via ajax, or can be directly called with $wordpress_object and $wordpress_id fields
    * 
    * @param string $wordpress_object
    * @param int $wordpress_id
    */
    public function push_to_salesforce( $wordpress_object = '', $wordpress_id = '' ) {
        if ( empty( $wordpress_object ) && empty( $wordpress_id ) ) {
            $wordpress_object = $_POST['wordpress_object'];
            $wordpress_id = $_POST['wordpress_id'];
        }
        $data = $this->wordpress->get_wordpress_object_data( $wordpress_object, $wordpress_id );
        $result = $this->push->manual_object_update( $data, $wordpress_object );
        if ( !empty( $_POST ) ) {
            wp_send_json_success( $result );
        } else {
            return $result;
        }
    }

    /**
    * Manually pull the Salesforce object into WordPress
    * This takes either the $_POST array via ajax, or can be directly called with $salesforce_id fields
    * 
    * @param string $salesforce_id
    * @param string $wordpress_object
    */
    public function pull_from_salesforce( $salesforce_id = '', $wordpress_object = '' ) {
        if ( empty( $wordpress_object ) && empty( $salesforce_id ) ) {
            $wordpress_object = $_POST['wordpress_object'];
            $salesforce_id = $_POST['salesforce_id'];
        }
        $type = $this->salesforce['sfapi']->get_sobject_type( $salesforce_id );
        $result = $this->pull->manual_pull( $type, $salesforce_id, $wordpress_object ); // we want the wp object to make sure we get the right fieldmap
        if ( !empty( $_POST ) ) {
            wp_send_json_success( $result );
        } else {
            return $result;
        }
    }

    /**
    * Manually pull the Salesforce object into WordPress
    * This takes either the $_POST array via ajax, or can be directly called with $salesforce_id fields
    * 
    * @param string $salesforce_id
    * @param string $wordpress_object
    */
    public function refresh_mapped_data( $mapping_id = '' ) {
        if ( empty( $mapping_id ) ) {
            $mapping_id = $_POST['mapping_id'];
        }
        $result = $this->mappings->get_object_maps( array( 'id' => $mapping_id ) );
        if ( !empty( $_POST ) ) {
            wp_send_json_success( $result );
        } else {
            return $result;
        }
    }

    /**
    * Prepare fieldmap data and redirect after processing
    * This runs when the create or update forms are submitted
    * It is public because it depends on an admin hook
    * It then calls the salesforce_mapping class and sends prepared data over to it, then redirects to the correct page
    * This method does include error handling, by loading the submission in a transient if there is an error, and then deleting it upon success
    *
    */
    public function prepare_fieldmap_data() {
        $error = false;
        $cachekey = md5( json_encode( $_POST ) );
        
        if ( !isset( $_POST['label'] ) || !isset( $_POST['salesforce_object'] ) || !isset( $_POST['wordpress_object'] ) ) {
            $error = true;
        }
        if ( $error === true ) {
            set_transient( $cachekey, $_POST, 0 );
            if ( $cachekey !== '' ) {
                $url = esc_url_raw( $_POST['redirect_url_error'] ) . '&transient=' . $cachekey;
            }
        } else { // there are no errors
            // send the row to the fieldmap class
            // if it is add or clone, use the create method
            $method = esc_attr( $_POST['method'] );
            $salesforce_fields = $this->get_salesforce_object_fields( array('salesforce_object' => $_POST['salesforce_object'] ) );
            $wordpress_fields = $this->get_wordpress_object_fields( $_POST['wordpress_object'] );
            if ( $method === 'add' || $method === 'clone' ) {
                $result = $this->mappings->create_fieldmap( $_POST, $wordpress_fields, $salesforce_fields );
            } elseif ( $method === 'edit' ) { // if it is edit, use the update method
                $id = esc_attr( $_POST['id'] );
                $result = $this->mappings->update_fieldmap( $_POST, $wordpress_fields, $salesforce_fields, $id );
            }
            if ( $result === false ) { // if the database didn't save, it's still ane rror
                set_transient( $cachekey, $_POST, 0 );
                if ( $cachekey !== '' ) {
                    $url = esc_url_raw( $_POST['redirect_url_error'] ) . '&transient=' . $cachekey;
                }
            } else {
                if ( isset( $_POST['transient'] ) ) { // there was previously an error saved. can delete it now.
                    delete_transient( esc_attr( $_POST['transient'] ) );
                }
                // then send the user to the list of fieldmaps
                $url = esc_url_raw( $_POST['redirect_url_success'] );
            }
        }
        wp_redirect( $url );
        exit();
    }

    /**
    * Delete fieldmap data and redirect after processing
    * This runs when the delete link is clicked, after the user confirms
    * It is public because it depends on an admin hook
    * It then calls the salesforce_mapping class and the delete method
    *
    */
    public function delete_fieldmap() {
        if ( $_POST['id'] ) {
            $result = $this->mappings->delete_fieldmap( $_POST['id'] );
            if ( $result === true ) {
                $url = esc_url_raw( $_POST['redirect_url_success'] );
            } else {
                $url = esc_url_raw( $_POST['redirect_url_error'] ) . '&id=' . $_POST['id'];
            }
            wp_redirect( $url );
            exit();
        }
    }

    /**
    * Fieldmap error notice
    * This runs if a mapping method has had an error.
    * It is public because it depends on the admin_notices hook
    * todo: better error messages
    *
    */
    public function fieldmap_error_notice() {
        if ( isset( $_GET['transient'] ) ) {
        ?>
        <div class="error notice">
            <p><?php _e( 'Errors kept this fieldmap from being saved.', $this->text_domain ); ?></p>
        </div>
        <?php
        }
    }

    /**
    * Default display for <input> fields
    *
    * @param array $args
    */
    public function display_input_field( $args ) {
        $type   = $args['type'];
        $id     = $args['label_for'];
        $name   = $args['name'];
        $desc   = $args['desc'];
        $checked = '';

        $class = 'regular-text';

        if ( $type === 'checkbox' ) {
            $class = 'checkbox';
        }

        if ( !defined( $args['constant'] ) ) {
            $value  = esc_attr( get_option( $id, '' ) );
            if ( $type === 'checkbox' ) {
                if ( $value === '1' ) {
                    $checked = 'checked ';
                }
                $value = 1;
            }
            if ( $value === '' && isset( $args['default'] ) && $args['default'] !== '' ) {
                $value = $args['default'];
            }
            echo '<input type="' . $type. '" value="' . $value . '" name="' . $name . '" id="' . $id . '"
            class="' . $class . ' code" ' . $checked . ' />';
            if ( $desc != '' ) {
                echo '<p class="description">' . $desc . '</p>';
            }
        } else {
            echo '<p><code>Defined in wp-config.php</code></p>';
        }
    }

    /**
    * Display for multiple checkboxes
    * Above method can handle a single checkbox as it is
    *
    * @param array $args
    */
    public function display_checkboxes( $args ) {
        $type = 'checkbox';
        $name = $args['name'];
        $options = get_option( $name );
        foreach ( $args['items'] as $key => $value ) {
            $text = $value['text'];
            $id = $value['id'];
            $desc = $value['desc'];
            $checked = '';
            if (is_array( $options ) && in_array( $key, $options ) ) {
                $checked = 'checked';
            }
            if ( isset( $value['default'] ) && $value['default'] === TRUE ) {
                $checked = 'checked';
            }
            echo '<div class="checkbox"><label><input type="' . $type. '" value="' . $key . '" name="' . $name . '[]" id="' . $id . '" ' . $checked . ' />' . $text . '</label></div>';
            if ( $desc != '' ) {
                echo '<p class="description">' . $desc . '</p>';
            }
        }
    }

    /**
    * Display for a dropdown
    *
    * @param array $args
    */
    public function display_select( $args ) {
        $type   = $args['type'];
        $id     = $args['label_for'];
        $name   = $args['name'];
        $desc   = $args['desc'];
        $current_value = get_option( $name );
        echo '<div><select id="' . $id . '" name="' . $name . '"><option value="">- Select one -</option>';
        foreach ( $args['items'] as $key => $value ) {
            $text = $value['text'];
            $value = $value['value'];
            $selected = '';
            if ( $key === $current_value ) {
                $selected = ' selected';
            }
            echo '<option value="' . $value . '"' . $selected . '>' . $text . '</option>';
        }
        echo '</select>';
        if ( $desc != '' ) {
            echo '<p class="description">' . $desc . '</p>';
        }
        echo '</div>';
    }

    /**
    * Default display for <a href> links
    *
    * @param array $args
    */
    public function display_link( $args ) {
        $label   = $args['label'];
        $desc   = $args['desc'];
        $url = $args['url'];
        if ( isset( $args['link_class'] ) ) {
            $class = ' class="' . $args['link_class'] . '"';
        } else {
            $class = '';
        }

        echo '<p><a' . $class . ' href="' . $url . '">' . $label . '</a></p>';

        if ( $desc != '' ) {
            echo '<p class="description">' . $desc . '</p>';
        }

    }

    /**
    * Run a demo of Salesforce API call on the authenticate tab after WordPress has authenticated with it
    *
    * @param object $sfapi
    */
    private function demo( $sfapi ) {

        $versions = $sfapi->get_api_versions();

        // format this array into html so users can see the versions
        $versions_is_cached = $versions['cached'] === true ? '' : 'not ';
        $versions_from_cache = $versions['from_cache'] === true ? 'were' : 'were not';
        $versions_is_redo = $versions['is_redo'] === true ? '' : 'not ';
        $versions_andorbut = $versions['from_cache'] === true ? 'and' : 'but';

        $contacts = $sfapi->query('SELECT Name, Id from Contact LIMIT 100');

        // format this array into html so users can see the contacts
        $contacts_is_cached = $contacts['cached'] === true ? '' : 'not ';
        $contacts_from_cache = $contacts['from_cache'] === true ? 'were' : 'were not';
        $contacts_andorbut = $contacts['from_cache'] === true ? 'and' : 'but';
        $contacts_is_redo = $contacts['is_redo'] === true ? '' : 'not ';

        require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/demo.php' );

    }
    
    /**
    * Deauthorize WordPress from Salesforce.
    * This deletes the tokens from the database; it does not currently do anything in Salesforce
    * For this plugin at this time, that is the decision we are making: don't do any kind of authorization stuff inside Salesforce
    */
    private function logout() {
        $this->access_token = delete_option( 'salesforce_api_access_token' );
        $this->instance_url = delete_option( 'salesforce_api_instance_url' );
        $this->refresh_token = delete_option( 'salesforce_api_refresh_token' );
        return 'You have been logged out. You can use use the connect button to log in again.';
    }

    /**
    * Check Wordpress Admin permissions
    * Check if the current user is allowed to access the Salesforce plugin options
    */
    private function check_wordpress_admin_permissions() {

        // todo: we are going to need a way to integrate this with roles, permissions, etc that are not built into wordpress

        if ( ! current_user_can('manage_options') ) {
            return FALSE;
        } else {
            return TRUE;
        }

    }

    /**
    * Notice for permission denied error
    * If an unauthorized user visits this plugin area in the admin, this message will show
    */
    public function permission_error_notice() {
        if ( $this->check_wordpress_admin_permissions() === FALSE ) {
        ?>
        <div class="notice notice-error">
            <p><?php _e( "Your account does not have permission to edit the Salesforce REST API plugin's settings.", $this->text_domain ); ?></p>
        </div>
        <?php
        }
    }

    /**
    * Show what we know about this user's relationship to a Salesforce object, if any
    * @param object $user
    *
    */
    public function show_salesforce_user_fields( $user ) {
        if ( $this->check_wordpress_admin_permissions() === TRUE ) {
            $mapping = $this->mappings->load_by_wordpress( 'user', $user->ID );
            ?>
            <h2>Salesforce</h2>
            <?php if ( isset( $mapping['id'] ) && !isset($_GET['edit_salesforce_mapping']) ) { ?>
                <input type="hidden" name="mapping_id" id="mapping_id_ajax" value="<?php echo $mapping['id']; ?>" />
                <input type="hidden" name="salesforce_id" id="salesforce_id_ajax" value="<?php echo $mapping['salesforce_id']; ?>" />
                <input type="hidden" name="wordpress_id" id="wordpress_id_ajax" value="<?php echo $mapping['wordpress_id']; ?>" />
                <input type="hidden" name="wordpress_object" id="wordpress_object_ajax" value="<?php echo $mapping['wordpress_object']; ?>" />
                <table class="wp-list-table widefat striped mapped-salesforce-user">
                    <caption>This user is mapped to a Salesforce object</caption>
                    <tbody>
                        <tr>
                            <th>Salesforce Id</th>
                            <td><a href="<?php echo $this->salesforce['sfapi']->get_instance_url() . '/' . $mapping['salesforce_id']; ?>"><?php echo $mapping['salesforce_id']; ?></a></td>
                            <td><a href="<?php echo get_admin_url( null, 'user-edit.php?user_id=' . $user->ID ) . '&amp;edit_salesforce_mapping=true'; ?>" class="edit-salesforce-mapping">Edit</a></td>
                        </tr>
                        <tr>
                            <th>Last Sync Message</th>
                            <td class="last_sync_message"><?php if ( isset( $mapping['last_sync_message'] ) ) { echo $mapping['last_sync_message']; } else { echo ''; } ?></td>
                            <td>&nbsp;</td>
                        </tr>
                        <tr>
                            <th>Last Sync Action</th>
                            <td class="last_sync_action"><?php if ( isset( $mapping['last_sync_action'] ) ) { echo $mapping['last_sync_action']; } else { echo ''; } ?></td>
                            <td>&nbsp;</td>
                        </tr>
                        <tr>
                            <th>Last Sync Status</th>
                            <td class="last_sync_status"><?php if ( isset( $mapping['last_sync_status'] ) && $mapping['last_sync_status'] === '1' ) { echo 'success'; } else if ( isset( $mapping['last_sync_status'] ) && $mapping['last_sync_status'] === '0' ) { echo 'error'; } else { echo ''; } ?></td>
                            <td>&nbsp;</td>
                        </tr>
                        <tr>
                            <th>Last Sync</th>
                            <td class="last_sync"><?php if ( isset( $mapping['last_sync'] ) ) { echo $mapping['last_sync']; } else { echo ''; } ?></td>
                            <td>&nbsp;</td>
                        </tr>
                        <tr>
                            <th>Actions</th>
                            <td>
                                <a href="#" class="button button-secondary push_to_salesforce_button">Push to Salesforce</a>
                                <a href="#" class="button button-secondary pull_from_salesforce_button">Pull from Salesforce</a>
                            </td>
                    </tbody>
                </table>
                <div class="salesforce_user_ajax_message"></div>
            <?php } else if ( isset($_GET['edit_salesforce_mapping']) && urlencode( $_GET['edit_salesforce_mapping'] ) === 'true' ) { ?>
                <input type="hidden" name="salesforce_update_mapped_user" value="1" />
                <p>You can change the Salesforce object that this WordPress user maps to by changing the ID and updating this user.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="salesforce_id">Salesforce ID</label></th>
                        <td>
                            <input type="text" name="salesforce_id" id="salesforce_id" value="<?php if ( isset( $mapping['id'] ) ) { echo $mapping['salesforce_id']; } ?>" class="regular-text" /><br />
                            <span class="description">Enter a Salesforce object ID.</span>
                        </td>
                    </tr>
                </table>
            <?php } else { ?>
                <input type="hidden" name="salesforce_create_mapped_user" value="1" />
                <p>This user is not mapped to an object in Salesforce. You can run a push to send this object to Salesforce, which will cause it to follow the plugin's normal mapping conventions, or you can create a manual link to a Salesforce object.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="salesforce_id">Salesforce ID</label></th>
                        <td>
                            <input type="text" name="salesforce_id" id="salesforce_id" value="" class="regular-text" /><br />
                            <span class="description">Enter a Salesforce object ID.</span>
                        </td>
                    </tr>
                </table>
            <?php }
        }
    }

    /**
    * If the user profile has been mapped to Salesforce, do it
    * @param int $user_id
    *
    */
    public function save_salesforce_user_fields( $user_id ) {
        if ( isset ( $_POST['salesforce_id'] ) ) {
            if ( isset( $_POST['salesforce_update_mapped_user'] ) && urlencode( $_POST['salesforce_update_mapped_user'] === '1' ) ) {
                $mapping_object = $this->mappings->get_object_maps( array( 'wordpress_id' => $user_id, 'wordpress_object' => 'user' ) );
                $mapping_object['salesforce_id'] = $_POST['salesforce_id'];
                $result = $this->mappings->update_object_map( $mapping_object, $mapping_object['id'] );
            } else if ( isset( $_POST['salesforce_create_mapped_user'] ) && urlencode( $_POST['salesforce_create_mapped_user'] === '1' ) ) {
                $mapping_object = $this->create_object_map( $user_id, 'user', $_POST['salesforce_id'] );
            }
        }
    }

    /**
    * Render tabs for settings pages in admin
    * @param array $tabs
    * @param string $tab
    */ 
    private function tabs( $tabs, $tab = '' ) {
        $current_tab = $tab;
        screen_icon();
        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $tab_key => $tab_caption ) {
            $active = $current_tab == $tab_key ? 'nav-tab-active' : '';
            echo '<a class="nav-tab ' . $active . '" href="?page=salesforce-api-admin&tab=' . $tab_key . '">' . $tab_caption . '</a>';
        }
        echo '</h2>';

        if ( isset( $_GET['tab'] ) ) {
            $tab = urlencode( $_GET['tab'] );   
        } else {
            $tab = '';
        }
    }

    /**
    * Clear schedule
    * This clears the schedule if the user clicks the button
    */ 
    private function clear_schedule( $schedule_name = '' ) {
        if ( $schedule_name !== '' ) {
            $schedule = $this->schedule( $schedule_name );
            $schedule->cancel_by_name( $schedule_name );
            return 'You have cleared the ' . $schedule_name . ' schedule.';
        } else {
            return 'You need to specify the name of the schedule you want to clear.';
        }
    }

    private function get_schedule_count( $schedule_name = '' ) {
        if ( $schedule_name !== '' ) {
            $schedule = $this->schedule( $schedule_name );
            return $this->schedule->count_queue_items( $schedule_name );
        } else {
            return 'unknown';
        }
    }

    /**
    * Load the schedule class
    */ 
    private function schedule( $schedule_name ) {
        if ( ! class_exists( 'Wordpress_Salesforce_Schedule' ) && file_exists( plugin_dir_path( __FILE__ ) . '../vendor/autoload.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . '../vendor/autoload.php';
            require_once plugin_dir_path( __FILE__ ) . '../classes/schedule.php';
        }
        $schedule = new Wordpress_Salesforce_Schedule( $this->wpdb, $this->version, $this->login_credentials, $this->text_domain, $this->wordpress, $this->salesforce, $this->mappings, $schedule_name, $this->logging, $this->schedulable_classes );
        $this->schedule = $schedule;
        return $schedule;
    }

    /**
    * Create an object map between a WordPress object and a Salesforce object
    *
    * @param int $wordpress_id
    *   Unique identifier for the WordPress object
    * @param string $wordpress_object
    *   What kind of object is it?
    * @param string $salesforce_id
    *   Unique identifier for the Salesforce object
    * @param string $action
    *   Did we push or pull?
    *
    * @return int $wpdb->insert_id
    *   This is the database row for the map object
    *
    */
    private function create_object_map( $wordpress_id, $wordpress_object, $salesforce_id, $action = '' ) {
        // Create object map and save it
        $mapping_object = $this->mappings->create_object_map(
            array(
                'wordpress_id' => $wordpress_id, // wordpress unique id
                'salesforce_id' => $salesforce_id, // salesforce unique id. we don't care what kind of object it is at this point
                'wordpress_object' => $wordpress_object, // keep track of what kind of wp object this is
                'last_sync' => current_time( 'mysql' ),
                'last_sync_action' => $action,
                'last_sync_status' => $this->mappings->status_success,
                'last_sync_message' => __( 'Mapping object updated via function: ' . __FUNCTION__, $this->text_domain )
            )
        );

        return $mapping_object;

    }

}