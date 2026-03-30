<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_Status_Register
{
    public function __construct()
    {
        add_action('init', array($this, 'register_custom_order_statuses'));
    }

    public function register_custom_order_statuses()
    {
        $args = array(
            'post_type' => 'haw_status',
            'post_status' => 'publish',
            'numberposts' => -1
        );
        $statuses = get_posts($args);

        foreach ($statuses as $status_post) {
            $raw_slug = sanitize_title($status_post->post_name);
            $wc_slug = 'wc-' . $raw_slug;
            $label = $status_post->post_title;

            register_post_status($wc_slug, array(
                'label' => $label,
                'public' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'exclude_from_search' => false,
                'label_count' => _n_noop(
                    $label . ' <span class="count">(%s)</span>',
                    $label . ' <span class="count">(%s)</span>',
                    'haw'
                ),
            ));
        }
    }
}
