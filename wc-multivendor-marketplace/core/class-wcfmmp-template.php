<?php

if (!defined('ABSPATH'))
    exit;








class WCFMmp_Template {

    public $template_url;

    public function __construct() {
        $this->template_url = 'wcfm/';
    }

    









    public function get_template($template_name, $args = array(), $template_path = '', $default_path = '') {

        if ($args && is_array($args))
            extract($args);

        $located = $this->locate_template($template_name, $template_path, $default_path);

        include ($located);
    }

    














    public function locate_template($template_name, $template_path = '', $default_path = '') {
        global $woocommerce, $WCFMmp;
        $default_path = apply_filters('wcfm_template_path', $default_path);
        if (!$template_path) {
            $template_path = $this->template_url;
        }
        if (!$default_path) {
            $default_path = $WCFMmp->plugin_path . 'views/';
        }
         
        $template = locate_template(array(trailingslashit($template_path) . $template_name, $template_name));
         
        $template = apply_filters('wcfm_locate_template', $template, $template_name, $template_path, $default_path);
         
        if (!$template) {
            $template = $default_path . $template_name;
        }

        return apply_filters( 'wcfmmp_locate_template', $template, $template_path, $default_path );
    }

    







    public function get_template_part($slug, $name = '') {
        global $WCFMmp;
        $template = '';

         
        if ($name)
            $template = $this->locate_template(array("{$slug}-{$name}.php", "{$this->template_url}{$slug}-{$name}.php"));

         
        if (!$template && $name && file_exists($WCFMmp->plugin_path . "views/{$slug}-{$name}.php"))
            $template = $WCFMmp->plugin_path . "views/{$slug}-{$name}.php";

         
        if (!$template)
            $template = $this->locate_template(array("{$slug}.php", "{$this->template_url}{$slug}.php"));

        echo $template;

        if ($template)
            load_template($template, false);
    }

}