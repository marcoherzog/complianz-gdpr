<?php
/*100% match*/

defined('ABSPATH') or die("you do not have acces to this page!");

if (!class_exists("cmplz_field")) {
    class cmplz_field
    {
        private static $_this;
        public $position;
        public $fields;
        public $default_args;
        public $form_errors = array();
        private $variation_id = '';

        function __construct()
        {
            if (isset(self::$_this))
                wp_die(sprintf(__('%s is a singleton class and you cannot create a second instance.', 'complianz'), get_class($this)));

            self::$_this = $this;

            add_action('plugins_loaded', array($this, 'process_save'), 10);
            add_action('complianz_before_label', array($this, 'before_label'), 10, 1);
            add_action('complianz_before_label', array($this, 'show_errors'), 10, 1);
            add_action('complianz_after_label', array($this, 'after_label'), 10, 1);
            add_action('complianz_after_field', array($this, 'after_field'), 10, 1);
            $this->load();
        }

        static function this()
        {
            return self::$_this;
        }


        public function set_variation_id($id){
            $this->variation_id = $id;

        }


        public function load()
        {
            $this->default_args = array(
                "fieldname" => '',
                "type" => 'text',
                "required" => false,
                'default' => '',
                'label' => '',
                'table' => false,
                'callback_condition' => false,
                'condition' => false,
                'callback' => false,
                'placeholder' => '',
                'optional' => false,
                'disabled' => false,
                'has_variations' => false,
                'hidden' => false,
                'region' => false,
                'media' => true,
                'first' => false,
                'warn' => false,
            );


        }

        public function process_save()
        {

            if (!current_user_can('manage_options')) return;

            if (isset($_POST['complianz_nonce'])) {
                //check nonce
                if (!isset($_POST['complianz_nonce']) || !wp_verify_nonce($_POST['complianz_nonce'], 'complianz_save')) return;

                $fields = COMPLIANZ()->config->fields();

                //remove multiple field
                if (isset($_POST['cmplz_remove_multiple'])) {
                    $fieldnames = array_map(function ($el) {
                        return sanitize_title($el);
                    }, $_POST['cmplz_remove_multiple']);

                    foreach ($fieldnames as $fieldname => $key) {

                        $page = $fields[$fieldname]['page'];
                        $options = get_option('complianz_options_' . $page);

                        $multiple_field = $this->get_value($fieldname, array());
                        //in case of cookies, store the deleted ones, otherwise
                        if ($fieldname === 'used_cookies') {

                            $cookie_key = $multiple_field[$key]['key'];
                            $deleted_cookies = get_option('cmplz_deleted_cookies');

                            if (!is_array($deleted_cookies)) $deleted_cookies = array();
                            if (!in_array($cookie_key, $deleted_cookies)) $deleted_cookies[] = $cookie_key;

                            update_option('cmplz_deleted_cookies', $deleted_cookies);
                        }
                        unset($multiple_field[$key]);

                        $options[$fieldname] = $multiple_field;
                        if (!empty($options)) update_option('complianz_options_' . $page, $options);
                    }
                }

                //add multiple field
                if (isset($_POST['cmplz_add_multiple'])) {
                    $fieldname = $this->sanitize_fieldname($_POST['cmplz_add_multiple']);
                    $this->add_multiple_field($fieldname);
                }

                //save multiple field
                if ((isset($_POST['cmplz-save']) || isset($_POST['cmplz-next'])) && isset($_POST['cmplz_multiple'])) {
                    $fieldnames = $this->sanitize_array($_POST['cmplz_multiple']);
                    $this->save_multiple($fieldnames);
                }

                //save data
                $posted_fields = array_filter($_POST, array($this, 'filter_complianz_fields'), ARRAY_FILTER_USE_KEY);

                foreach ($posted_fields as $fieldname => $fieldvalue) {
                    $this->save_field($fieldname, $fieldvalue);
                }

                //we're assuming the page is the same for all fields here, as it's all on the same page (or should be)

                //clear the cookie settings cache for each locale
                $variation_id = COMPLIANZ()->cookie->selected_variation_id();
                $locales = get_option('cmplz_supported_locales');
                foreach ($locales as $locale) {
                    delete_transient('cmplz_cookie_settings_cache_' . $locale . $variation_id);
                }
            }
        }

        public function sanitize_array($array)
        {
            foreach ($array as &$value) {
                if (!is_array($value))
                    $value = sanitize_text_field($value);
                    //if ($value === 'on') $value = true;
                else
                    $this->sanitize_array($value);
            }

            return $array;

        }

        public function is_conditional($fieldname)
        {
            $fields = COMPLIANZ()->config->fields();
            if (isset($fields[$fieldname]['condition']) && $fields[$fieldname]['condition']) return true;

            return false;
        }


        public function is_multiple_field($fieldname)
        {
            $fields = COMPLIANZ()->config->fields();
            if (isset($fields[$fieldname]['type']) && ($fields[$fieldname]['type'] == 'thirdparties')) return true;
            if (isset($fields[$fieldname]['type']) && ($fields[$fieldname]['type'] == 'processors')) return true;

            return false;
        }


        public function save_multiple($fieldnames)
        {
            if (!current_user_can('manage_options')) return;

            $fields = COMPLIANZ()->config->fields();
            foreach ($fieldnames as $fieldname => $saved_fields) {

                if (!isset($fields[$fieldname])) return;

                $page = $fields[$fieldname]['page'];
                $type = $fields[$fieldname]['type'];
                $options = get_option('complianz_options_' . $page);
                $multiple_field = $this->get_value($fieldname, array());


                foreach ($saved_fields as $key => $value) {
                    $value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
                    //store the fact that this value was saved from the back-end, so should not get overwritten.
                    $value['saved_by_user'] = TRUE;
                    $multiple_field[$key] = $value;

                    //make cookies and thirdparties translatable
                    if ($type==='cookies' || $type==='thirdparties' || $type==='processors' || $type==='editor'){
                        if (is_string($value) && isset($fields[$fieldname]['translatable']) && $fields[$fieldname]['translatable']) {
                            do_action('cmplz_register_translation', $fieldname . "_" . $key, $value);
                        }
                    }
                }

                $options[$fieldname] = $multiple_field;
                if (!empty($options)) update_option('complianz_options_' . $page, $options);
            }
        }


        public function save_field($fieldname, $fieldvalue)
        {
            if (!current_user_can('manage_options')) return;

            $fields = COMPLIANZ()->config->fields();
            $fieldname = str_replace("cmplz_", '', $fieldname);

            $variation_id = COMPLIANZ()->cookie->selected_variation_id();
            $variation_id = (!isset($fields[str_replace($variation_id, '', $fieldname)]['has_variations']) || !$fields[str_replace($variation_id, '', $fieldname)]['has_variations']) ? '' : COMPLIANZ()->cookie->selected_variation_id();

            $original_fieldname = str_replace($variation_id, '', $fieldname);
            $variation_fieldname = $original_fieldname.$variation_id;

            //do not save callback fields
            if (isset($fields[$original_fieldname]['callback'])) return;

            $type = $fields[$original_fieldname]['type'];
            $page = $fields[$original_fieldname]['page'];
            $required = isset($fields[$original_fieldname]['required']) ? $fields[$original_fieldname]['required'] : false;

            $fieldvalue = $this->sanitize($fieldvalue, $type);

            if (!$this->is_conditional($original_fieldname) && $required && empty($fieldvalue)) {
                $this->form_errors[] = $original_fieldname;
            }

            //make translatable
            if ($type == 'text' || $type == 'textarea' || $type == 'editor') {
                if (isset($fields[$original_fieldname]['translatable']) && $fields[$original_fieldname]['translatable']) {
                    do_action('cmplz_register_translation', $variation_fieldname, $fieldvalue);
                }
            }

            $options = get_option('complianz_options_' . $page);
            $prev_value = isset($options[$variation_fieldname]) ? $options[$variation_fieldname] : false;
            do_action("complianz_before_save_" . $page . "_option", $variation_fieldname, $fieldvalue, $prev_value, $type);
            $options[$variation_fieldname] = $fieldvalue;

            if (!empty($options)) update_option('complianz_options_' . $page, $options);

            do_action("complianz_after_save_" . $page . "_option", $variation_fieldname, $fieldvalue, $prev_value, $type);


        }


        public function add_multiple_field($fieldname, $cookie_type = false)
        {
            if (!current_user_can('manage_options')) return;

            $fields = COMPLIANZ()->config->fields();

            $page = $fields[$fieldname]['page'];
            $options = get_option('complianz_options_' . $page);

            $multiple_field = $this->get_value($fieldname, array());
            if ($fieldname === 'used_cookies' && !$cookie_type) $cookie_type = 'custom_' . time();
            if (!is_array($multiple_field)) $multiple_field = array($multiple_field);

            if ($cookie_type) {
                //prevent key from being added twice
                foreach ($multiple_field as $index => $cookie) {
                    if ($cookie['key'] === $cookie_type) {
                        return;
                    }
                }

                //don't add field if it was deleted previously
                $deleted_cookies = get_option('cmplz_deleted_cookies');
                if (($deleted_cookies && in_array($cookie_type, $deleted_cookies))) {
                    return;
                }

                //don't add default wordpress cookies
                if (strpos($cookie_type, 'wordpress_') !== false) {
                    return;
                }

                $multiple_field[] = array('key' => $cookie_type);
            } else {
                $multiple_field[] = array();
            }

            $options[$fieldname] = $multiple_field;

            if (!empty($options)) update_option('complianz_options_' . $page, $options);
        }

        public function sanitize($value, $type)
        {

            switch ($type) {
                case 'colorpicker':
                    return sanitize_hex_color($value);
                case 'text':
                    return sanitize_text_field($value);
                case 'multicheckbox':
                    if (!is_array($value)) $value = array($value);
                    return array_map('sanitize_text_field', $value);
                case 'phone':
                    $value = sanitize_text_field($value);
                    return $value;
                case 'email':
                    return sanitize_email($value);
                case 'css':
                    return $value;
                case 'url':
                    return esc_url_raw($value);
                case 'number':
                    return intval($value);
                case 'editor':
                    return wp_kses_post($value);
            }
            return sanitize_text_field($value);
        }

        /**/

        private
        function filter_complianz_fields($fieldname)
        {
            $fieldname = cmplz_strip_variation_id_from_string($fieldname);

            if (strpos($fieldname, 'cmplz_') !== FALSE && isset(COMPLIANZ()->config->fields[str_replace('cmplz_', '', $fieldname)])){
                return true;
            }

            return false;
        }

        public
        function before_label($args)
        {

            $condition = false;
            $condition_question = '';
            $condition_answer = '';

            if (!empty($args['condition'])) {
                $condition = true;
                $condition_answer = reset($args['condition']);
                $condition_question = key($args['condition']);
            }
            $condition_class = $condition ? 'condition-check' : '';

            $hidden_class =  ($args['hidden']) ? 'hidden' : '';
            $first_class =  ($args['first']) ? 'first' : '';

            $this->get_master_label($args);

            if ($args['table']) {
                echo '<tr class="cmplz-settings ' . esc_attr($hidden_class.' '.$condition_class) . '"';
                echo $condition ? 'data-condition-question="' . esc_attr($condition_question) . '" data-condition-answer="' . esc_attr($condition_answer) . '"' : '';
                echo '><th scope="row">';
            } else {
                echo '<div class="field-group ' .  esc_attr($hidden_class.''.$first_class.' '.$condition_class) . '" ';
                echo $condition ? 'data-condition-question="' . esc_attr($condition_question) . '" data-condition-answer="' . esc_attr($condition_answer) . '"' : '';
                echo '><div class="cmplz-label">';
            }
        }

        public function get_master_label($args)
        {
            if (!isset($args['master_label'])) return;
            ?>
            <div class="cmplz-master-label"><?php echo esc_html($args['master_label']) ?></div>
            <hr>
            <?php

        }

        public
        function show_errors($args)
        {
            if (in_array($args['fieldname'], $this->form_errors)) {
                ?>
                <div class="cmplz-form-errors">
                    <?php _e("This field is required. Please complete the question before continuing", 'complianz') ?>
                </div>
                <?php
            }
        }

        public
        function after_label($args)
        {
            if ($args['optional']) {
                echo '<span class="cmplz-optional">' . __("(Optional)", 'complianz') . '</span>';
            }
            if ($args['table']) {
                echo '</th><td>';
            } else {
                echo '</div><div class="cmplz-field">';
            }

            do_action('cmplz_notice_' . $args['fieldname'], $args);

        }

        public
        function after_field($args)
        {
            $this->get_comment($args);
            $this->get_help_tip($args);
            if ($args['table']) {
                echo '</td></tr>';
            } else {
                echo '</div></div>';
            }
        }


        public
        function text($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            if ($args['has_variations']) $fieldname .= $this->variation_id;

            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo $args['label'] ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <input <?php if ($args['required']) echo 'required'; ?>
                    class="validation <?php if ($args['required']) echo 'is-required'; ?>"
                    placeholder="<?php echo esc_html($args['placeholder']) ?>"
                    type="text"
                    value="<?php echo esc_html($value) ?>"
                    name="<?php echo esc_html($fieldname) ?>">
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function url($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo $args['label'] ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <input <?php if ($args['required']) echo 'required'; ?>
                    class="validation <?php if ($args['required']) echo 'is-required'; ?>"
                    placeholder="<?php echo esc_html($args['placeholder']) ?>"
                    type="text"
                    pattern="^(http(s)?(:\/\/))?(www\.)?[a-zA-Z0-9-_\.\/]+"
                    value="<?php echo esc_html($value) ?>"
                    name="<?php echo esc_html($fieldname)?>">
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function email($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo $args['label'] ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <input <?php if ($args['required']) echo 'required'; ?>
                    class="validation <?php if ($args['required']) echo 'is-required'; ?>"
                    placeholder="<?php echo esc_html($args['placeholder']) ?>"
                    type="email"
                    value="<?php echo esc_html($value) ?>"
                    name="<?php echo esc_html($fieldname)?>">
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function phone($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo $args['label'] ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <input autocomplete="tel" <?php if ($args['required']) echo 'required'; ?>
                   class="validation <?php if ($args['required']) echo 'is-required'; ?>"
                   placeholder="<?php echo esc_html($args['placeholder']) ?>"
                   type="text"
                   value="<?php echo esc_html($value) ?>"
                   name="<?php echo esc_html($fieldname) ?>">
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function number($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo $args['label'] ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <input <?php if ($args['required']) echo 'required'; ?>
                    class="validation <?php if ($args['required']) echo 'is-required'; ?>"
                    placeholder="<?php echo esc_html($args['placeholder']) ?>"
                    type="number"
                    value="<?php echo esc_html($value) ?>"
                    name="<?php echo esc_html($fieldname) ?>">
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }


        public
        function checkbox($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            if ($args['has_variations']) $fieldname .= $this->variation_id;

            $value = $this->get_value($args['fieldname'], $args['default']);

            if (!$this->show_field($args)) return;
            ?>
            <?php do_action('complianz_before_label', $args); ?>

            <label for="<?php echo esc_html($fieldname) ?>-label"><?php echo $args['label'] ?></label>

            <?php do_action('complianz_after_label', $args); ?>

            <label class="cmplz-switch">
            <input name="<?php echo esc_html($fieldname) ?>" type="hidden" value=""/>

            <input name="<?php echo esc_html($fieldname) ?>" size="40" type="checkbox"
                <?php if ($args['disabled']) echo 'disabled'; ?>
                   class="<?php if ($args['required']) echo 'is-required'; ?>"
                   value="1" <?php checked(1, $value, true) ?> />
            <span class="cmplz-slider cmplz-round"></span>
            </label>

            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function multicheckbox($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $value = $this->get_value($args['fieldname']);

            //if no value at all has been set, assign a default value
            $has_selection = false;
            foreach ($value as $key=>$index){
                if ($index==1) {
                    $has_selection = true;
                    break;
                }
            }

            $default_index = $args['default'];

            if (!$this->show_field($args)) return;

            ?>
            <?php do_action('complianz_before_label', $args); ?>

            <label for="<?php echo esc_html($fieldname) ?>"><?php echo $args['label'] ?></label>

            <?php do_action('complianz_after_label', $args); ?>
            <?php if (!empty($args['options'])) {?>
            <div class="<?php if ($args['required']) echo 'cmplz-validate-multicheckbox'?>">
            <?php foreach ($args['options'] as $option_key => $option_label) {
                $sel_key = false;
                if (!$has_selection) {
                    $sel_key = $default_index;
                } elseif(isset($value[$option_key]) && $value[$option_key]){
                    $sel_key = $option_key;
                }
            ?>
            <div>
                <input name="<?php echo esc_html($fieldname) ?>[<?php echo $option_key ?>]" type="hidden" value=""/>
                <input class="<?php if ($args['required']) echo 'is-required'; ?>"
                       name="<?php echo esc_html($fieldname) ?>[<?php echo $option_key ?>]" size="40" type="checkbox"
                       value="1" <?php echo ((string)($sel_key == (string)$option_key)) ? "checked" : "" ?> >
                <label>
                    <?php echo esc_html($option_label) ?>
                </label>
            </div>
            <?php } ?>
            </div>
        <?php } else {
                cmplz_notice(__('No options found', 'complianz'));
        } ?>

            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function radio($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $value = $this->get_value($args['fieldname'], $args['default']);
            $options = $args['options'];

            if (!$this->show_field($args)) return;

            ?>
            <?php do_action('complianz_before_label', $args); ?>

            <label for="<?php echo $args['fieldname'] ?>"><?php echo $args['label'] ?></label>

            <?php do_action('complianz_after_label', $args); ?>
            <div class="cmplz-validate-radio">
            <?php
            if (!empty($options)) {
                foreach ($options as $option_value => $option_label) {
                    ?>
                    <input <?php if ($args['required']) echo 'required'; ?>
                            type="radio"
                            id="<?php echo esc_html($fieldname) ?>"
                            name="<?php echo esc_html($fieldname) ?>"
                            value="<?php echo esc_html($option_value); ?>" <?php if ($value == $option_value) echo "checked" ?>>
                    <label class="">
                        <?php echo esc_html($option_label); ?>
                    </label>
                    <div class="clear"></div>
                <?php }
            }
            ?>
            </div>

            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function show_field($args)
        {
            return ($this->condition_applies($args, 'callback_condition'));
        }

        public
        function condition_applies($args, $type = false)
        {
            $default_args = $this->default_args;
            $args = wp_parse_args($args, $default_args);

            if (!$type) {
                if ($args['condition']) {
                    $type = 'condition';
                } elseif ($args['callback_condition']) {
                    $type = 'callback_condition';
                }
            }

            if (!$type || !is_array($args[$type])) {
                return true;
            }

            $condition = $args[$type];

            foreach ($condition as $c_fieldname => $c_value_content) {
                $c_values = array($c_value_content);
                if (strpos($c_value_content, ',') !== FALSE) {
                    $c_values = explode(',', $c_value_content);
                }

                foreach ($c_values as $c_value) {
                    $actual_value = cmplz_get_value($c_fieldname);

                    $fieldtype = $this->get_field_type($c_fieldname);

                    if ($fieldtype == 'multicheckbox') {

                        if (!is_array($actual_value)) $actual_value = array($actual_value);
                        //get all items that are set to true
                        $actual_value = array_filter($actual_value, function ($item) {
                            return $item == 1;
                        });
                        $actual_value = array_keys($actual_value);

                        if (strpos($c_value, 'NOT ') === FALSE) {
                            if (!in_array($c_value, $actual_value)) {
                                return false;
                            }
                        } else {
                            $c_value = str_replace("NOT ", "", $c_value);
                            if (in_array($c_value, $actual_value)) {
                                return false;
                            }
                        }
                    } else {
                        if (strpos($c_value, 'NOT ') === FALSE) {

                            if ($c_value !== $actual_value) {
                                return false;
                            }
                        } else {
                            $c_value = str_replace("NOT ", "", $c_value);
                            if ($c_value === $actual_value) {
                                return false;
                            }
                        }
                    }
                }
            }
            return true;
        }

        public function get_field_type($fieldname)
        {
            if (!isset(COMPLIANZ()->config->fields[$fieldname])) return false;

            return COMPLIANZ()->config->fields[$fieldname]['type'];
        }

        public
        function textarea($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            if ($args['has_variations']) $fieldname .= $this->variation_id;

            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <textarea name="<?php echo esc_html($fieldname) ?>"
                      placeholder="<?php echo esc_html($args['placeholder']) ?>"><?php echo esc_html($value) ?></textarea>
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        /*
         * Show field with editor
         *
         *
         * */

        public
        function editor($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $args['first'] = true;
            $media = $args['media'] ? true : false;
            if ($args['has_variations']) $fieldname .= $this->variation_id;

            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <?php
            $settings = array(
                'media_buttons' => $media,
                'editor_height' => 300, // In pixels, takes precedence and has no default value
                'textarea_rows' => 15,
            );
            wp_editor($value, $fieldname, $settings); ?>
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function javascript($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <div id="<?php echo esc_html($fieldname) ?>editor"
                 style="height: 200px; width: 100%"><?php echo $value ?></div>
            <?php do_action('complianz_after_field', $args); ?>
            <script>
                var <?php echo esc_html($fieldname)?> =
                ace.edit("<?php echo esc_html($fieldname)?>editor");
                <?php echo esc_html($fieldname)?>.setTheme("ace/theme/monokai");
                <?php echo esc_html($fieldname)?>.session.setMode("ace/mode/javascript");
                jQuery(document).ready(function ($) {
                    var textarea = $('textarea[name="<?php echo esc_html($fieldname)?>"]');
                    <?php echo esc_html($fieldname)?>.
                    getSession().on("change", function () {
                        textarea.val(<?php echo esc_html($fieldname)?>.getSession().getValue()
                    )
                    });
                });
            </script>
            <textarea style="display:none" name="<?php echo esc_html($fieldname) ?>"><?php echo $value ?></textarea>
            <?php
        }

        public
        function css($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            if ($args['has_variations']) $fieldname .= $this->variation_id;

            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo $args['fieldname'] ?>"><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <div id="<?php echo esc_html($fieldname) ?>editor"
                 style="height: 200px; width: 100%"><?php echo $value ?></div>
            <?php do_action('complianz_after_field', $args); ?>
            <script>
                var <?php echo esc_html($fieldname)?> =
                ace.edit("<?php echo esc_html($fieldname)?>editor");
                <?php echo esc_html($fieldname)?>.setTheme("ace/theme/monokai");
                <?php echo esc_html($fieldname)?>.session.setMode("ace/mode/css");
                jQuery(document).ready(function ($) {
                    var textarea = $('textarea[name="<?php echo esc_html($fieldname)?>"]');
                    <?php echo esc_html($fieldname)?>.
                    getSession().on("change", function () {
                        textarea.val(<?php echo esc_html($fieldname)?>.getSession().getValue()
                    )
                    });
                });
            </script>
            <textarea style="display:none" name="<?php echo esc_html($fieldname) ?>"><?php echo $value ?></textarea>
            <?php
        }


        public
        function colorpicker($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            if ($args['has_variations']) $fieldname .= $this->variation_id;

            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;


            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo esc_html($fieldname) ?>"><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <input type="hidden" name="<?php echo esc_html($fieldname) ?>" id="<?php echo esc_html($fieldname) ?>"
                   value="<?php echo esc_html($value) ?>" class="cmplz-color-picker-hidden">
            <input type="text" name="color_picker_container" data-hidden-input='<?php echo esc_html($fieldname) ?>'
                   value="<?php echo esc_html($value) ?>" class="cmplz-color-picker"
                   data-default-color="<?php echo esc_html($args['default']) ?>">
            <?php do_action('complianz_after_field', $args); ?>

            <?php
        }


        public
        function step_has_fields($page, $step = false, $section = false)
        {

            $fields = COMPLIANZ()->config->fields($page, $step, $section);
            foreach ($fields as $fieldname => $args) {
                $default_args = $this->default_args;
                $args = wp_parse_args($args, $default_args);

                $type = ($args['callback']) ? 'callback' : $args['type'];
                $args['fieldname'] = $fieldname;

                if ($type == 'callback') {
                    return true;
                } else {
                    if ($this->show_field($args)) {
                        return true;
                    }
                }
            }
            return false;
        }

        public
        function get_fields($page, $step = false, $section = false)
        {
            $fields = COMPLIANZ()->config->fields($page, $step, $section);
            $i = 0;
            foreach ($fields as $fieldname => $args) {
                if ($i === 0) $args['first']=true;
                $i++;
                $default_args = $this->default_args;
                $args = wp_parse_args($args, $default_args);

                $type = ($args['callback']) ? 'callback' : $args['type'];
                $args['fieldname'] = $fieldname;

                switch ($type) {
                    case 'callback':
                        $this->callback($args);
                        break;
                    case 'text':
                        $this->text($args);
                        break;
                    case 'button':
                        $this->button($args);
                        break;
                    case 'upload':
                        $this->upload($args);
                        break;
                    case 'url':
                        $this->url($args);
                        break;
                    case 'select':
                        $this->select($args);
                        break;
                    case 'colorpicker':
                        $this->colorpicker($args);
                        break;
                    case 'checkbox':
                        $this->checkbox($args);
                        break;
                    case 'textarea':
                        $this->textarea($args);
                        break;
                    case 'cookies':
                        $this->cookies($args);
                        break;
                    case 'multiple':
                        $this->multiple($args);
                        break;
                    case 'radio':
                        $this->radio($args);
                        break;
                    case 'multicheckbox':
                        $this->multicheckbox($args);
                        break;
                    case 'javascript':
                        $this->javascript($args);
                        break;
                    case 'css':
                        $this->css($args);
                        break;
                    case 'email':
                        $this->email($args);
                        break;
                    case 'phone':
                        $this->phone($args);
                        break;
                    case 'thirdparties':
                        $this->thirdparties($args);
                        break;
                    case 'processors':
                        $this->processors($args);
                        break;
                    case 'number':
                        $this->number($args);
                        break;
                    case 'notice':
                        $this->notice($args);
                        break;
                    case 'editor':
                        $this->editor($args);
                        break;
                    case 'label':
                        $this->label($args);
                        break;
                }
            }

        }

        public
        function callback($args)
        {
            $callback = $args['callback'];
            do_action("cmplz_$callback", $args);
        }

        public
        function notice($args)
        {
            if (!$this->show_field($args)) return;


            do_action('complianz_before_label', $args);
            cmplz_notice($args['label']);
            do_action('complianz_after_label', $args);
            do_action('complianz_after_field', $args);
        }

        public
        function select($args)
        {

            $fieldname = 'cmplz_' . $args['fieldname'];
            if ($args['has_variations']) $fieldname .= $this->variation_id;

            $value = $this->get_value($args['fieldname'], $args['default']);
            if (!$this->show_field($args)) return;

            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo esc_html($fieldname) ?>"><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <select <?php if ($args['required']) echo 'required'; ?> name="<?php echo esc_html($fieldname) ?>">
                <option value=""><?php _e("Choose an option", 'complianz') ?></option>
                <?php foreach ($args['options'] as $option_key => $option_label) { ?>
                    <option value="<?php echo esc_html($option_key) ?>" <?php echo ($option_key == $value) ? "selected" : "" ?>><?php echo esc_html($option_label) ?></option>
                <?php } ?>
            </select>

            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function label($args)
        {

            $fieldname = 'cmplz_' . $args['fieldname'];
            if (!$this->show_field($args)) return;

            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label for="<?php echo esc_html($fieldname) ?>"><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>

            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function button($args)
        {
            $fieldname = 'cmplz_' . $args['fieldname'];
            if (!$this->show_field($args)) return;

            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <?php if ($args['post_get']==='get'){ ?>
                <a <?if ($args['disabled']) echo "disabled"?> href="<?php echo $args['disabled'] ? "#" : admin_url('admin.php?page=cmplz-settings&action='.$args['action'])?>" class="button"><?php echo esc_html($args['label']) ?></a>
            <?php } else { ?>
                <input <?if ($args['warn']) echo 'onclick="return confirm(\''.$args['warn'].'\');"'?> <?if ($args['disabled']) echo "disabled"?> class="button" type="submit" name="<?php echo $args['action']?>"
                       value="<?php echo esc_html($args['label']) ?>">
            <?php }  ?>

            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }

        public
        function upload($args)
        {
            if (!$this->show_field($args)) return;

            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>

            <input type="file" type="submit" name="cmplz-upload-file"
                   value="<?php echo esc_html($args['label']) ?>">
            <input <?if ($args['disabled']) echo "disabled"?> class="button" type="submit" name="<?php echo $args['action']?>"
                   value="<?php _e('Start', 'complianz') ?>">
            <?php do_action('complianz_after_field', $args); ?>
            <?php
        }


        public
        function save_button()
        {
            wp_nonce_field('complianz_save', 'complianz_nonce');
            ?>
            <th></th>
            <td>
                <input class="button button-primary" type="submit" name="cmplz-save"
                       value="<?php _e("Save", 'complianz') ?>">

            </td>
            <?php
        }


        public
        function multiple($args)
        {
            $values = $this->get_value($args['fieldname']);
            if (!$this->show_field($args)) return;
            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label><?php echo esc_html($args['label']) ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <button class="button" type="submit" name="cmplz_add_multiple"
                    value="<?php echo esc_html($args['fieldname']) ?>"><?php _e("Add new", 'complianz') ?></button>
            <br><br>
            <?php
            if ($values) {
                foreach ($values as $key => $value) {
                    ?>

                    <div>
                        <div>
                            <label><?php _e('Description', 'complianz') ?></label>
                        </div>
                        <div>
                        <textarea class="cmplz_multiple"
                                  name="cmplz_multiple[<?php echo esc_html($args['fieldname']) ?>][<?php echo $key ?>][description]"><?php if (isset($value['description'])) echo esc_html($value['description']) ?></textarea>
                        </div>

                    </div>
                    <button class="button cmplz-remove" type="submit"
                            name="cmplz_remove_multiple[<?php echo esc_html($args['fieldname']) ?>]"
                            value="<?php echo $key ?>"><?php _e("Remove", 'complianz') ?></button>
                    <?php
                }
            }
            ?>
            <?php do_action('complianz_after_field', $args); ?>
            <?php

        }


        public
        function cookies($args)
        {
            $values = $this->get_value($args['fieldname']);
            //move "complianz" cookie to the top.
            if (!empty($values)) {
                foreach ($values as $key => $cookie) {
                    if ($cookie['key'] === 'complianz') {
                        unset($values[$key]);
                        $new_arr = array();
                        $new_arr[$key] = $cookie;
                        $values = $new_arr + $values;
                    }
                }
            }

            if (!$this->show_field($args)) return;

            $args['first'] =true;
            ?>

            <?php do_action('complianz_before_label', $args); ?>
            <label><?php _e("Cookies", 'complianz') ?></label>
            <?php do_action('complianz_after_label', $args); ?>

            <?php
            if ($values) {
                foreach ($values as $key => $value) {
                    $value_key = (isset($value['key'])) ? $value['key'] : false;
                    $value['label'] = empty($value['label']) ? COMPLIANZ()->cookie->get_default_value('label', $value_key) : $value['label'];
                    $value['used_names'] = empty($value['used_names']) ? COMPLIANZ()->cookie->get_default_value('used_names', $value_key) : $value['used_names'];
                    $value['purpose'] = empty($value['purpose']) ? COMPLIANZ()->cookie->get_default_value('purpose', $value_key) : $value['purpose'];
                    $value['privacy_policy_url'] = empty($value['privacy_policy_url']) ? COMPLIANZ()->cookie->get_default_value('privacy_policy_url', $value_key) : $value['privacy_policy_url'];
                    $value['storage_duration'] = empty($value['storage_duration']) ? COMPLIANZ()->cookie->get_default_value('storage_duration', $value_key) : $value['storage_duration'];
                    $value['description']= empty($value['description']) ? COMPLIANZ()->cookie->get_default_value('description', $value_key) : $value['description'];
                    $value['key'] = empty($value['key']) ? COMPLIANZ()->cookie->get_default_value('key', $value_key) : $value['key'];

                    /*
                     * Because checkboxes can be saved with an empty value, we should not override these when empty
                     *
                     * */
                    $saved_by_user = (isset($value['saved_by_user']) && $value['saved_by_user']) ? true : false;
                    $value['functional'] = !$saved_by_user && empty($value['functional']) ? COMPLIANZ()->cookie->get_default_value('functional', $value_key) : $value['functional'];
                    $value['show'] = !$saved_by_user && empty($value['show']) ? COMPLIANZ()->cookie->get_default_value('show', $value_key) : $value['show'];

                    //$value = wp_parse_args($value, $default_index);
                    //first, we try if there's a fieldname.
                    if (!empty($value['label'])) {
                        $cookiename = $value['label'];
                    } elseif (!empty($value['key'])) {
                        $cookiename = $value['key'];
                    } else {
                        $cookiename = __("Not recognized", 'complianz');
                    }

                    $functional_checked = $value['functional'] ? 'checked' : '';
                    $show_checked = $value['show'] ? 'checked' : '';


                    //cmplz_panel($s_plugin_name.$add_btn, $html);
                    $html = '

                    <div class="cmplz-cookie-field multiple-field">
                        <div>
                            <div><label>'. __('Name', 'complianz').'</label></div>
                            <input type="text"
                                   name="cmplz_multiple['.esc_html($args['fieldname']) .']['. $key .'][label]"
                                   value="'. esc_html($value['label']) .'">
                            <input type="hidden"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. $key .'][key]"
                                   value="'. esc_html($value['key']) .'">
                        </div>
                        <div>

                        </div>
                        <div>
                            <label>
                                <input type="hidden"
                                       name="cmplz_multiple['. esc_html($args['fieldname']) .']['. $key .'][functional]"
                                       value="">
                                <input type="checkbox"
                                       name="cmplz_multiple['. esc_html($args['fieldname']) .']['. $key .'][functional]"
                                    '.$functional_checked .'>
                                '. __('This is a functional cookie', 'complianz') .'</label>
                        </div>
                        <div>
                            <label>
                                <input type="hidden"
                                       name="cmplz_multiple['. esc_html($args['fieldname']) .']['. $key .'][show]"
                                       value="">
                                <input type="checkbox"
                                       name="cmplz_multiple['. esc_html($args['fieldname']) .']['. $key .'][show]"
                                    '. $show_checked .'>
                                '. __('Add this cookie to the cookie policy', 'complianz') .'</label>
                        </div>
                        <br>
                        <div>
                            <label>'. __('Used names', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][used_names]"
                                   value="'. esc_html($value['used_names']) .'">
                        </div>
                        <div>
                            <label>'. __('Privacy statement URL', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][privacy_policy_url]"
                                   value="'. esc_html($value['privacy_policy_url']) .'">
                        </div>
                        <div>
                            <label>'. __('Purpose', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][purpose]"
                                   value="'. esc_html($value['purpose']) .'">
                        </div>
                        <div>
                            <label>'. __('Retention period', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][storage_duration]"
                                   value="'. esc_html($value['storage_duration']) .'">
                        </div>
                        <div>
                            <label>'. __('Description', 'complianz') .'</label>
                        </div>
                        <div>
                        <textarea class="cmplz_multiple"
                                  name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][description]">'. esc_html($value['description']) .'</textarea>
                        </div>

                        <input class="button" type="submit" name="cmplz-save" value="'.__('Save','complianz').'">
                        <button class="button cmplz-remove" type="submit"
                            name="cmplz_remove_multiple['. esc_html($args['fieldname']) .']"
                            value="'. esc_html($key) .'">'. __("Remove", 'complianz') .'</button>
                    </div>';

                    $icons = '';

                    $icons .= ($value['functional']) ? '<i class="fa fa-code"></i>' : '<i class="fa fa-fw"></i>';
                    $icons .= ($value['show']) ? '<i class="fa fa-file"></i>' : '<i class="fa fa-fw"></i>';
                    $icons = '<span style="float:right">'.$icons.'</span>';

                    cmplz_panel(sprintf(__('Cookie "%s"', 'complianz'), $cookiename), $html, $icons,true);

                }
            }
            ?>
            <button class="button" type="submit" class="cmplz-add-new-cookie" name="cmplz_add_multiple"
                    value="<?php echo esc_html($args['fieldname']) ?>"><?php _e("Add new cookie", 'complianz') ?></button>

            <?php do_action('complianz_after_field', $args); ?>
            <?php

        }

        public
        function processors($args)
        {
            $processing_agreements = COMPLIANZ()->processing->processing_agreements();

            //as an exception to this specific field, we use the same data for both us and eu
            $fieldname = str_replace("_us", "", $args['fieldname']);
            $values = $this->get_value($fieldname);
            $region = $args['region'];

            if (!is_array($values)) $values = array();
            if (!$this->show_field($args)) return;
            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label><?php echo $args["label"]." ".__('list', 'complianz') ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <?php
            if ($values) {
                foreach ($values as $key => $value) {
                    $default_index = array(
                        'name' => '',
                        'country' => '',
                        'purpose' => '',
                        'data' => '',
                        'processing_agreement'=> 0,
                    );

                    $value = wp_parse_args($value, $default_index);
                    $create_processing_agreement_link = '<a href="'.admin_url("admin.php?page=cmplz-processing-$region").'">';

                    $processing_agreement_outside_c = floatval(($value['processing_agreement'])==-1) ? 'selected' : '';
                    $html='<div class="multiple-field">
                        <div>
                            <label>'. sprintf(__("Name of the %s with whom you share the data", 'complianz'),$args['label']) .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($fieldname) .']['. esc_html($key) .'][name]"
                                   value="'. esc_html($value['name']) .'">
                        </div>
                        <div>
                            <label>'.sprintf(__('Select the processing agreement you made with this %s, or %screate one%s', 'complianz'), $args['label'],$create_processing_agreement_link,'</a>') .'</label>
                        </div>
                        <div>
                            <label>
                                <select name="cmplz_multiple['. esc_html($fieldname) .']['. esc_html($key) .'][processing_agreement]">
                                    <option value="0">'.__('No agreement selected','complianz').'</option>
                                    <option value="-1" '.$processing_agreement_outside_c.'>'.__('A processing agreement outside Complianz Privacy Suite','complianz').'</option>';
                                    foreach($processing_agreements as $id => $title){
                                        $selected = (intval($value['processing_agreement'])==$id) ? 'selected' : '';
                                        $html .= '<option value="'.$id.'" '.$selected.'>'.$title.'</option>';
                                    }
                                    $html .= '</select>
                                <br><br>
                        </div>
                        <div>
                            <label>'.sprintf(__('%s country', 'complianz'),$args['label']) .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($fieldname) .']['. esc_html($key) .'][country]"
                                   value="'. esc_html($value['country']) .'">
                        </div>

                        <div>
                            <label>'.__('Purpose', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($fieldname) .']['. esc_html($key) .'][purpose]"
                                   value="'. esc_html($value['purpose']) .'">
                        </div>';
                        if ($region==='eu') {
                        $html .= '
                        <div>
                            <label>'.__('What type of data is shared', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($fieldname) .']['. esc_html($key) .'][data]"
                                   value="'. esc_html($value['data']) .'">
                        </div>';

                        }
                    $html.='<input class="button" type="submit" name="cmplz-save" value="'.__('Save','complianz').'">
                            <button class="button cmplz-remove" type="submit"
                            name="cmplz_remove_multiple['. esc_html($fieldname) .']"
                            value="'. esc_html($key) .'">'.__("Remove", 'complianz') .'</button>';

                    $html .='</div>';

                    $title = esc_html($value['name']);
                    if ($title=='') $title = __('New entry','complianz');
                    cmplz_panel($title, $html, '', true);
                    ?>

                    <?php
                }
            }
            ?>
            <button class="button" type="submit" class="cmplz-add-new-processor" name="cmplz_add_multiple"
                    value="<?php echo esc_html($fieldname) ?>"><?php printf(__("Add new %s", 'complianz'),$args['label']) ?></button>
            <?php do_action('complianz_after_field', $args); ?>
            <?php

        }

        public
        function thirdparties($args)
        {
            $values = $this->get_value($args['fieldname']);
            if (!is_array($values)) $values = array();
            if (!$this->show_field($args)) return;
            ?>
            <?php do_action('complianz_before_label', $args); ?>
            <label><?php echo $args["label"] ?></label>
            <?php do_action('complianz_after_label', $args); ?>
            <?php
            if ($values) {
                foreach ($values as $key => $value) {
                    $default_index = array(
                        'name' => '',
                        'country' => '',
                        'purpose' => '',
                        'data' => '',
                    );

                    $value = wp_parse_args($value, $default_index);

                    $html = '
                    <div class="multiple-field">
                        <div>
                            <label>'.__('Name of the third party with whom you share the data', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][name]"
                                   value="'. esc_html($value['name']) .'">
                        </div>

                        <div>
                            <label>'.__('Third party country', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][country]"
                                   value="'. esc_html($value['country']) .'">
                        </div>


                        <div>
                            <label>'.__('Purpose', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][purpose]"
                                   value="'. esc_html($value['purpose']) .'">
                        </div>
                        <div>
                            <label>'.__('What type of data is shared', 'complianz') .'</label>
                        </div>
                        <div>
                            <input type="text"
                                   name="cmplz_multiple['. esc_html($args['fieldname']) .']['. esc_html($key) .'][data]"
                                   value="'. esc_html($value['data']) .'">
                        </div>


                    </div>
                    <input class="button" type="submit" name="cmplz-save" value="'.__('Save','complianz').'">
                    <button class="button cmplz-remove" type="submit"
                            name="cmplz_remove_multiple['. esc_html($args['fieldname']) .']"
                            value="'. esc_html($key) .'">'.__("Remove", 'complianz') .'</button>';

                    $title = esc_html($value['name']);
                    if ($title=='') $title = sprintf(__('New entry','complianz'));
                    cmplz_panel($title, $html, '', true);
                }
            }
            ?>
            <button class="button" type="submit" class="cmplz-add-new-thirdparty" name="cmplz_add_multiple"
                    value="<?php echo esc_html($args['fieldname']) ?>"><?php _e("Add new thirdparty", 'complianz') ?></button>
            <?php do_action('complianz_after_field', $args); ?>
            <?php

        }


        public
        function get_value($fieldname, $default = '')
        {

            $fields = COMPLIANZ()->config->fields();
            $page = $fields[$fieldname]['page'];
            $options = get_option('complianz_options_' . $page);

            //allow for cookie warning variations
            if (isset($fields[$fieldname]['has_variations']) && $fields[$fieldname]['has_variations']) $fieldname .= $this->variation_id;

            //if no value isset, pass a default
            $value = isset($options[$fieldname]) ? $options[$fieldname] : apply_filters('cmplz_default_value', $default, $fieldname);
            return $value;
        }

        public
        function sanitize_fieldname($fieldname)
        {
            $fields = COMPLIANZ()->config->fields();
            if (array_key_exists($fieldname, $fields)) return $fieldname;

            return false;
        }

        public
        function get_help_tip($args)
        {
            if (!isset($args['help'])) return;
            ?>
            <span class="cmplz-tooltip-right tooltip-right" data-cmplz-tooltip="<?php echo $args['help'] ?>">
              <span class="dashicons dashicons-editor-help"></span>
            </span>
            <?php
        }

        public
        function get_comment($args)
        {
            if (!isset($args['comment'])) return;
            ?>
            <div class="cmplz-comment"><?php echo $args['comment'] ?></div>
            <?php
        }


        /*
         * Check if all required fields are answered
         *
         *
         *
         * */

        public
        function step_complete($step)
        {

        }


        /*
         * Check if all required fields in a section are answered
         *
         *
         * */

        public
        function section_complete($section)
        {

        }


        public
        function has_errors()
        {
            if (count($this->form_errors) > 0) {
                return true;
            }


            return false;
        }


    }
} //class closure
