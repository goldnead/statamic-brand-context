<?php

return [
    // Brand switcher (Control Panel header)
    'switcher_aria_label' => 'Switch brand',
    'switcher_label' => 'Brand',

    // The brand field on the user form
    'user_brands_display' => 'Brands',
    'user_brands_instructions' => 'Which brands does this person appear in as an assignee? Empty = all of them.',

    // Suite settings screen
    'nav_settings' => 'Addon Suite settings',
    'nav_suite' => 'Addon Suite',
    'nav_settings_child' => 'Settings',
    'settings_saved' => ':addon settings saved.',
    'settings_brand_notice' => 'These settings apply to :brand. Switch brand in the header to edit another one.',
    'settings_follows_config' => 'Anything not changed here follows config/:path.php.',
    'settings_not_writable' => 'The brand_settings table is missing, so nothing here can be saved. The values below are the ones from the config files. Run php artisan migrate to change them.',
    'settings_failed_heading' => 'These addons could not register their settings and are missing from this page. The full error is in the log.',
    'settings_empty_heading' => 'None of the installed addons registers any settings.',
    'settings_empty_item_heading' => 'View add-ons',
    'settings_empty_description' => 'Addons that offer settings appear here once installed.',

    // Period of the cards on the funnels map
    'map_period_days' => 'last :days days',

    // Dry run on the funnels map
    'sim_again' => ':name is already running on this path',
    'sim_denied' => 'You may not see this dry run',
    'sim_unknown' => 'Effect unknown',
    'sim_mail_fork' => 'After this mail',
    'sim_mail_same' => 'opened · clicked · nothing: the sequence goes on',
    'sim_mail_bought' => 'bought: :name',
    'sim_rejoin' => 'the sequence goes on as above',
    'sim_more' => '{1} 1 more way|[2,*] :count more ways',
    'sim_buys' => 'buys: :name',
    'sim_buys_unknown' => 'buys a product the map does not show',
    'sim_clicks' => 'clicks: :link',
    'sim_unsubscribes' => 'unsubscribes',
    'sim_unsubscribed_mail' => 'Unsubscribed: this marketing mail does not go out',
    'sim_why_enter' => ':type “:name”: :how',
    'sim_how_requested' => 'asked for',
    'sim_how_purchased' => 'bought',
    'sim_how_started' => 'starts on its own',
    'sim_how_edge' => 'reached over the connection “:edge”',
    'sim_how_reached' => 'reached',
];
