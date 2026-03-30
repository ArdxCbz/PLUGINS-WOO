<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_Status_List
{
    public function __construct()
    {
        add_filter('wc_order_statuses', array($this, 'add_to_order_statuses_list'), 10, 1);
    }

    public function add_to_order_statuses_list($order_statuses)
    {
        $args = array(
            'post_type' => 'haw_status',
            'post_status' => 'publish',
            'numberposts' => -1
        );
        $statuses = get_posts($args);

        $new_statuses = array();
        foreach ($order_statuses as $key => $status_label) {
            $new_statuses[$key] = $status_label;
            if ('wc-processing' === $key) {
                foreach ($statuses as $status_post) {
                    $raw_slug = sanitize_title($status_post->post_name);
                    $wc_slug = 'wc-' . $raw_slug;
                    $label = $status_post->post_title;
                    $new_statuses[$wc_slug] = $label;
                }
            }
        }
        return $new_statuses;
    }
}
