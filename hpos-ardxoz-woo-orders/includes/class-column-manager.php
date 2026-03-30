<?php
namespace HPOS\Ardxoz\Woo\Orders;
defined('ABSPATH') || exit;

class Column_Manager
{
    public static function init()
    {
        Order_Column::register();
        Info_Column::register();
        Status_Location_Column::register();
        Products_Column::register();
        Customer_Column::register();
        Payment_Column::register();
    }
}
