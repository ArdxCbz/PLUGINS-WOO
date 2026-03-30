<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_Status_Admin_Actions
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_status_styles'), 20);
        add_filter('woocommerce_admin_order_actions', array($this, 'add_custom_status_button'), 20, 2);
    }

    public function enqueue_status_styles()
    {
        wp_enqueue_style('woocommerce_admin_styles');

        $statuses = get_posts(array(
            'post_type' => 'haw_status',
            'post_status' => 'publish',
            'numberposts' => -1,
        ));

        $css = '';
        foreach ($statuses as $st) {
            $slug = sanitize_title($st->post_name);
            $bg_color = get_post_meta($st->ID, '_hpos_ardxoz_woo_status_color', true);
            if (!$bg_color) {
                $bg_color = '#cccccc';
            }
            $text_color = '#ffffff';

            $css .= "mark.order-status.status-{$slug} {
                    background-color: {$bg_color} !important;
                    color: {$text_color} !important;
                    padding: 2px 6px;
                    border-radius: 4px;
                 }\n";

            $css .= ".wc-action-button-{$slug} {
                    background-color: transparent !important;
                    border:           1px solid {$bg_color} !important;
                    border-radius:    4px !important;
                    position:         relative !important;
                    width:            2em !important;
                    height:           2em !important;
                    overflow:         hidden !important;
                    cursor:           pointer !important;
                 }\n";

            $css .= ".wc-action-button-{$slug}::before {
                     content:          \"\";
                     display:          block;
                     width:            1.2em;
                     height:           1.2em;
                     background-color: {$bg_color} !important;
                     border-radius:    50%;
                     position:         absolute;
                     top:              calc(50% - 0.6em);
                     left:             calc(50% - 0.6em);
                 }\n";

            $css .= ".wc-action-button-{$slug}::after {
                     content:            \"\\e03c\";
                     font-family:        WooCommerce;
                     speak:              none;
                     font-weight:        400;
                     font-variant:       normal;
                     text-transform:     none;
                     line-height:        1;
                     position:           absolute;
                     top:                0;
                     left:               0;
                     width:              100%;
                     height:              100%;
                     text-align:         center;
                     color:              {$text_color} !important;
                     pointer-events:     none;
                 }\n";
        }

        wp_add_inline_style('woocommerce_admin_styles', $css);

        $grid_css = "
            .widefat td.column-wc_actions .wc-actions,
            .widefat td.column-order_actions .wc-actions {
                display:               grid !important;
                grid-template-columns: repeat(4, 2em) !important;
                grid-auto-rows:        2em !important;
                grid-gap:              4px !important;
                justify-items:         center !important;
                align-items:           center !important;
            }
            .widefat td.column-wc_actions .wc-actions a.button,
            .widefat td.column-order_actions .wc-actions a.print-preview-button {
                display:        block    !important;
                width:          2em      !important;
                height:         2em      !important;
                margin:         0        !important;
                padding:        0        !important;
                position:       relative !important;
                overflow:       hidden   !important;
            }
        ";
        wp_add_inline_style('woocommerce_admin_styles', $grid_css);
    }

    public function add_custom_status_button($actions, $order)
    {
        $status_posts = get_posts(array(
            'posts_per_page' => -1,
            'post_type' => 'haw_status',
            'post_status' => 'publish',
        ));

        foreach ($status_posts as $status_post) {
            $raw_slug = sanitize_title($status_post->post_name);
            $label = $status_post->post_title;

            if ($order->has_status($raw_slug)) {
                continue;
            }

            $url = wp_nonce_url(
                admin_url(
                    'admin-ajax.php?action=woocommerce_mark_order_status' .
                    '&status=' . $raw_slug .
                    '&order_id=' . $order->get_id()
                ),
                'woocommerce-mark-order-status'
            );

            $actions[$raw_slug] = array(
                'url' => $url,
                'name' => $label,
                'action' => $raw_slug,
            );
        }

        return $actions;
    }
}
