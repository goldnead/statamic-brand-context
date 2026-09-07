<?php

return [
    // Brand switcher (Control Panel header)
    'switcher_aria_label' => 'Switch brand',
    'switcher_label' => 'Brand',

    // The brand field on the user form
    'user_brands_display' => 'Brands',
    'user_brands_instructions' => 'Which brands does this person appear in as an assignee? Empty = all of them.',

    // Suite settings screen
    'nav_settings' => 'Addon Settings',
    'settings_saved' => ':addon settings saved.',
    'settings_brand_notice' => 'These settings apply to :brand. Switch brand in the header to edit another one.',
    'settings_follows_config' => 'Anything not changed here follows config/:path.php.',
    'settings_not_writable' => 'The brand_settings table is missing, so nothing here can be saved. The values below are the ones from the config files. Run php artisan migrate to change them.',
    'settings_failed_heading' => 'These addons could not register their settings and are missing from this page. The full error is in the log.',
    'settings_empty_heading' => 'None of the installed addons registers any settings.',
    'settings_empty_item_heading' => 'View add-ons',
    'settings_empty_description' => 'Addons that offer settings appear here once installed.',
];
