<?php

function register_custom_statuses($order_statuses){
    $order_statuses['wc-processing-p'] = array(
        'label'                     => _x( 'Processing Payment', 'Order status', 'woocommerce' ),
        'public'                    => false,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Processing Payment<span class="count">(%s)</span>', 'Processing Payment<span class="count">(%s)</span>', 'woocommerce' ),
    );
    return $order_statuses;
}

add_filter('woocommerce_register_shop_order_post_statuses', 'register_custom_statuses',10,1);
function add_custom_statuses( $order_statuses ) {

    $order_statuses['wc-processing-p'] = _x( 'Processing Payment', 'Order status', 'woocommerce' );
    return $order_statuses;
}
add_filter( 'wc_order_statuses', 'add_custom_statuses',10,1);