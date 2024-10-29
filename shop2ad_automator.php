<?php
/*
Plugin Name: Shop2Ad Automator
Description: Connect your WooCommerce Store with AIM3 (needs active WooCommerce)
Author: aim3media
Author URI: https://aim3.media/en/marketing-solution-for-online-shops/
Version: 1.0.0
Requires at least: 4.4
Tested up to: 6.0.2
*/

    //Add-Config-Data from Config-File / GET CONST AIM3CONFIG / GET Languages
    try {
        foreach(glob(plugin_dir_path( __FILE__ ).'config/*.php' ) as $file){
            require_once($file);
        }
    }catch(\Exception $e){
        exit;
    }

    //Check, if WooCommerce Plugin is active:
    if (in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ){

        //Check, if no Class Aim3connect already exists:
        if(! class_exists('Aim3connect')){

            //Create new Class 'Aim3connect':
            class Aim3connect{

                /**
                 * Constructor loads the init function
                 */
                public function __construct(){

                    $this->aim3_init_plugin();
                }


                /**
                 * Init-Function contains Hooks, Filter & Actions (WordPress)
                 * @return Void
                 */
                public function aim3_init_plugin(){

                    //Activation-Hook: Fires, if plugin was activated:
                    register_activation_hook(__FILE__, array($this, 'aim3_plugin_activated'));

                    //Deactivation-Hook: Fires, if plugin was deactivated:
                    register_deactivation_hook(__FILE__, array($this, 'aim3_plugin_deactivated'));

                    //Add Action for menupage to administrate options:
                    add_action('admin_menu', array($this, 'aim3_admin_menupage_setup'));

                    //Add Action for redirection to admin menu, after plugin was activated:
                    add_action('admin_init', array($this, 'aim3_redirect_after_loading'));
                }


                /**
                 * Plugin Activation-Routine
                 * @return Void
                 */
                public function aim3_plugin_activated(){

                    //Create new user-role:
                    $this->aim3_add_new_role();

                    //Create new user with created user-role:
                    $this->aim3_add_new_user();

                    //Create new REST API Key with permissions of created user-role:
                    $this->aim3_generate_api_key();

                    //Add Option to give information, that plugin was activated:
                    add_option('aim3_plugin_was_activated', true);
                }


                /**
                 * Plugin Deactivation-Routine
                 * @return Void
                 */
                public function aim3_plugin_deactivated(){

                    //Remove created REST API Key:
                    $this->aim3_remove_api_key();

                    //Remove created User:
                    $this->aim3_remove_new_user();

                    //Remove created User-Role:
                    $this->aim3_remove_new_role();
                }


                /**
                 * Create new user-role
                 * @return Void
                 */
                private function aim3_add_new_role(){

                    $role = AIM3CONFIG['role']; //Role-Name
                    $display_name = AIM3CONFIG['role_display_name']; //Role-Display-Name
                    $capabilities = array ( //Array of permissions
                        'manage_woocommerce' => true,
                        'view_woocommerce_reports' => false,
                        'read' => true,
                        'read_product' => true,
                        'manage_product_terms' => true,
                        'assign_product_terms' => true,
                        'read_private_products' => true
                    );

                    //Create the new role:
                    add_role($role, $display_name, $capabilities);
                }


                /**
                 * Create a new user / insert user to database
                 * @return Void
                 */
                private function aim3_add_new_user(){

                    //Generate Random User-Password via Hash:
                    $randombytes = random_bytes(16);
                    $converted_password = (bin2hex($randombytes));

                    //Set attributes for user-data and save into array:
                    $userdata = array(
                        'user_login' => AIM3CONFIG['user_login'], //login name
                        'user_email' => AIM3CONFIG['user_email'], //mail-adress
                        'user_pass' => $converted_password, //user password
                        'display_name' => AIM3CONFIG['display_name'], //display name
                        'description' => AIM3CONFIG['description'], //description
                        'role' => AIM3CONFIG['role'] //add availible role to user
                    );

                    //Insert the new user into database:
                    wp_insert_user($userdata);
                }


                /**
                 * Generate new REST-API Key with generated user-role permissons
                 * @return Void
                 */
                private function aim3_generate_api_key(){

                    //Get-user-ID by username:
                    $get_user_object = get_user_by('login', AIM3CONFIG['user_login']);
                    $user_id = $get_user_object->ID;

                    global $wpdb; //Variable to insert new data to wp-database

                    //Set-key-attributes:
                    $description = AIM3CONFIG['key_name']; //Key-description
                    $permissions = 'read'; //Key-permissions
                    $user_consumer_key = 'ck_' . wc_rand_hash(); //Generate new Random-Key
                    $user_consumer_secret = 'cs_' . wc_rand_hash(); //Generate new Random-Key

                    //Save Key-Attributes into array:
                    $keydata = array(
                        'user_id' => $user_id, //The user, who creates the key
                        'description' => $description, //Key-description
                        'permissions' => $permissions, //Key-permissions
                        'consumer_key' => wc_api_hash ($user_consumer_key), //Random consumer key
                        'consumer_secret' => $user_consumer_secret, //Random consumer secret
                        'nonces' => 'aim3api', //space
                        'truncated_key' => substr($user_consumer_key, -7), //short key
                    );

                    //Insert Key-Data into database:
                    $wpdb->insert(
                        $wpdb->prefix . 'woocommerce_api_keys', //Choose db wp_woocommerce_api_keys
                        $keydata, //The Key-Array
                        array(
                            '%d', //userID
                            '%s', //key-description
                            '%s', //key-permissions
                            '%s', //consumer key
                            '%s', //consumer secret
                            '%s', //space
                            '%s', //short key
                        )
                    );

                    //Save data to user meta:
                    add_user_meta($user_id, 'woocommerce_api_consumer_key', $user_consumer_key);
                    add_user_meta($user_id, 'woocommerce_api_consumer_secret', $user_consumer_secret);
                }


                /**
                 * Save the generated API-Key and all data to make a request:
                 * @return Array The Response Data for Request
                 */
                private function aim3_save_api_key(){

                    //Get-user-ID by username:
                    $get_user_object = get_user_by('login', AIM3CONFIG['user_login']);
                    $user_id = $get_user_object->ID;

                    //Generate Random User-Password via Hash:
                    $randombytes = random_bytes(16);
                    $converted_password = (bin2hex($randombytes));
                    $user_password = $converted_password;

                    //Set New User Password:
                    wp_set_password($user_password, $user_id);

                    //Get the WooCommerce Shop-URL:
                    $shop_page_url = home_url();

                    //Get Consumer Key:
                    $user_consumer_key = get_user_meta($user_id, 'woocommerce_api_consumer_key', true);

                    //Get Consumer Secret:
                    $user_consumer_secret = get_user_meta($user_id, 'woocommerce_api_consumer_secret', true);

                    //Get User Password:
                    $user_admin_password = $converted_password;


                    //Save all important data into an array:
                    $response = array(
                        'url' => $shop_page_url,
                        'provider' => AIM3CONFIG['provider'],
                        'login_name' => AIM3CONFIG['user_login'],
                        'login_password' => $user_admin_password,
                        'token' => $user_consumer_key,
                        'client' => NULL,
                        'secret' => $user_consumer_secret
                    );

                    return $response;
                }


                /**
                 * Request the API-Service with generated API and Userdata:
                 * @param Array The Body-Data (The Response Data for Request)
                 * @param String The User-Dashobard Login-Password to delete
                 * @return String The Login-Password for User-Dashboard
                 */
                private function aim3_make_http_request($body, $license_key ,$delete = false){

                    //Get-user-ID by username:
                    $get_user_object = get_user_by('login', AIM3CONFIG['user_login']);
                    $user_id = $get_user_object->ID;
                    $key = esc_attr($license_key);

                    //Check, if delete parameter is NULL:
                    if(! $delete){

                        //Prepare Post-Request:

                        //Prepare Array for Post-Method:
                        $args = array(
                            'method' => 'PATCH',
                            'body' => $body
                        );

                        //Prepare Endpoint for Post-Method:
                        $endpoint = (AIM3CONFIG['api_url'] . '/' . $key);
                    }else{

                        //Prepare Delete-Request:

                        //Prepare Array for Delete-Method:
                        $args = array(
                            'method' => 'DELETE'
                        );

                        //Prepare Endpoint for Delete-Method:
                        $endpoint = (AIM3CONFIG['api_url'] . '/' . $key);
                    }

                    //Request API-Service:
                    $apidata = wp_remote_request($endpoint, $args);

                    //Error-Handling:
                    if(is_wp_error($apidata)){

                        //Receive Error Message:
                        $response = $apidata->get_error_message();
                    }else{

                        //Check, if no Error availible (API returns [], if key is valid):
                        if(wp_remote_retrieve_body($apidata) == '[]'){

                            //Add meta, that License key is valid:
                            add_user_meta($user_id, 'shopconnector_key_valid', true);
                            $response = wp_remote_retrieve_body($apidata);

                            //Save license key in user meta:
                            add_user_meta($user_id, 'shopconnector_license_key', $key);
                        }else{

                            //Receive Error Message:
                            $response = 'Error: ' . wp_remote_retrieve_body($apidata);
                        }
                    }

                    return $response;
                }


                /**
                 * Delete the created role:
                 * @return Void
                 */
                private function aim3_remove_new_role(){

                    //Get Role by Name:
                    $role = AIM3CONFIG['role']; //Role-Name

                    //Remove the created role from database:
                    remove_role($role);
                }


                /**
                 * Delete the created user:
                 * @return Void
                 */
                private function aim3_remove_new_user(){

                    //Get-User-ID by Username:
                    $get_user_object = get_user_by('login', AIM3CONFIG['user_login']);
                    $user_id = $get_user_object->ID;

                    //Remove User From Database via User ID:
                    wp_delete_user($user_id);
                }



                /**
                 * Remove the api-key
                 * @return Void
                 */
                private function aim3_remove_api_key(){

                    global $wpdb;

                    //Get-user-ID by username:
                    $get_user_object = get_user_by('login', AIM3CONFIG['user_login']);
                    $user_id = $get_user_object->ID;

                    //Get User generated key from meta-data:
                    $user_key_delete = get_user_meta($user_id, 'woocommerce_api_consumer_key');

                    //Remove Key:
                    $wpdb->delete(
                        $wpdb->prefix . 'woocommerce_api_keys', //Choose db wp_woocommerce_api_keys
                        array('consumer_key' => $user_key_delete),
                        array('%s')
                    );

                    //Check, if Dashboard-Password availible:
                    if(! get_user_meta($user_id, 'shopconnector_license_key', true) == NULL){

                        //Check, if no Error occured:
                        if(! str_contains(get_user_meta($user_id, 'shopconnector_license_key', true), 'Error')){

                            //Delete Dashboard-Password (Delete Request):
                            $this->aim3_make_http_request(' ', get_user_meta($user_id, 'shopconnector_license_key', true), true);
                        }
                    }
                }

                /**
                 * Redirects User to Plugin-Page, after activation was succesfull
                 * @return Void
                 */
                public function aim3_redirect_after_loading(){

                    //Check, if plugin was activated via option:
                    if(get_option('aim3_plugin_was_activated', false)){

                        //Delete option:
                        delete_option('aim3_plugin_was_activated');

                        //Redirect User to Plugin-Page:
                        wp_redirect(admin_url('admin.php?page=aim3-shop2ad'));
                    }
                }



                /**
                 * Callback: Setup Admin-Menu
                 * @return Void
                 */
                public function aim3_admin_menupage_setup(){

                    //Add new Menu Page in Admin Panel:
                    add_submenu_page('woocommerce', 'AIM3 Shop2Ad', 'AIM3 Shop2Ad', 'manage_woocommerce', 'aim3-shop2ad', array($this , 'aim3_admin_menupage'));
                }



                /**
                 * The Admin-Menu
                 * @return Void
                 */
                public function aim3_admin_menupage(){

                    //Get User-ID by username:
                    $get_user_object = get_user_by('login', AIM3CONFIG['user_login']);
                    $user_id = $get_user_object->ID;

                    //Check current users (admin) locale:
                    if(get_user_locale(get_current_user_id()) == 'de_DE'){

                        //Set German language:
                        $current_user_locale = AIM3LANG_DE;
                    }else{

                        //Set Default (English) language:
                        $current_user_locale = AIM3LANG_EN;
                    }

                    //Heading for Plugin-Menupage:
                    echo(wp_kses_post(AIM3LANG_BASIC['menu_heading']));

                    //Check, if license key is valid:
                    if(! get_user_meta($user_id, 'shopconnector_key_valid', true)){

                        //Check, if user started key-submit request:
                        if(isset($_POST['submit'])){
                            // Get, sanitize and validate input field value
                            $key = sanitize_text_field($_POST['license_key']);
                            if(!isset($key) || $key == '' || strlen($key) != 24){
                                //Add Error Meta:
                                add_user_meta($user_id, 'shopconnector_error_occured', true);
                                add_user_meta($user_id, 'shopconnector_error_message', 'validation failed');
                                
                                //Back to Screen 1:
                                echo(wp_kses_post($current_user_locale['loading_text']));
                                header("Refresh:0");
                            }
                            
                            //Try to request API:

                            //Save API Data into Array:
                            $apidata = $this->aim3_save_api_key();

                            //Send API Data to external system and check, if license is valid:
                            $dashboard = $this->aim3_make_http_request($apidata, $key, false);

                            //Check, if license key is valid:
                            if(! str_contains($dashboard, 'Error')){

                                //Set Screen 2 as dashboard:
                                add_user_meta($user_id, 'shopconnector_key_valid', true);
                                add_user_meta($user_id, 'shopconnector_error_occured', false);

                                //Refresh page, after validation was succesfull:
                                echo(wp_kses_post($current_user_locale["loading_text"]));
                                header("Refresh:0");
                            }else{

                                //Add Error Meta:
                                add_user_meta($user_id, 'shopconnector_error_occured', true);
                                add_user_meta($user_id, 'shopconnector_error_message', $dashboard);

                                //Back to Screen 1:
                                echo(wp_kses_post($current_user_locale['loading_text']));
                                header("Refresh:0");
                            }
                        }else{

                            //Screen 1:

                            //Heading:
                            echo(wp_kses_post($current_user_locale['firstuse_heading']));

                            echo(wp_kses_post($current_user_locale['license_textfield']));

                            //Enter license key section:
                            echo('<form method="post">');
                            echo('<input type="text" size="50" name="license_key">');
                            submit_button(wp_kses_post($current_user_locale['submit_license']));
                            echo('</form>');

                            //Check, if there is an Error:
                            if(get_user_meta($user_id, 'shopconnector_error_occured', true)){

                                //Add Error-&Helpsection:
                                echo('<div class="notice  notice-error is-dismissible">');
                                echo(wp_kses_post($current_user_locale['error_description']));
                                //echo('<p> Details: <em> ' . wp_kses_post(get_user_meta($user_id, 'shopconnector_error_message', true)) . ' </em> </p>');
                                echo('</div>');
                            }

                            //Description with dashboard url:
                            echo(wp_kses_post($current_user_locale['description']));

                        }
                    }else{

                        //Check, if user started revoke request:
                        if(isset($_POST['disable_connection'])){

                            //Deactivate Plugin (remove all created data and connections):
                            deactivate_plugins(plugin_basename(__FILE__));

                            //Send user back to Admin panel:
                            $redirect = get_option('siteurl') . '/wp-admin/index.php';
                            header('Location: ' . "$redirect");
                            die();
                        }else{

                            //Screen 2:


                            //Description:
                            echo(wp_kses_post($current_user_locale['shop_is_connected']));

                            //Link to Dashboard:
                            echo(wp_kses_post($current_user_locale['connected_description']));

                            //Heading for revoke/delete section:
                            echo(wp_kses_post($current_user_locale['heading_revoke']));

                            //Delete Description:
                            echo(wp_kses_post($current_user_locale['delete_description']));

                            //Delete Button:
                            echo('<form method="post">');
                            submit_button($current_user_locale['submit_revoke'], 'primary', 'disable_connection');
                            echo('</form>');

                        }
                    }
                }
            }

            //Instance:
            $woocommerce_shop_connector = new Aim3connect();
        }
    }

?>
