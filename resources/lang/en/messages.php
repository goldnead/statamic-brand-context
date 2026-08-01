<?php

return [
    'nav_brand_members' => 'Brand Members',
    'permission_group' => 'Brands',
    'manage_brand_members' => 'Manage brand members',
    'unknown_user' => 'This user no longer exists.',

    // Brand switcher (Control Panel header)
    'switcher_aria_label' => 'Switch brand',
    'switcher_label' => 'Brand',

    // Brand Members screen
    'users_heading' => 'Users',
    'scope_note' => 'Assignments here apply to :brand — the brand currently selected in the switcher.',
    'transition_note' => 'A user with no assignment to any brand counts as a member of every brand. That is why existing users keep appearing everywhere until you assign them for the first time. The first assignment is what narrows a user down: from then on they belong only to the brands listed for them.',
    'state_member' => 'Member',
    'state_unassigned' => 'Unassigned — counts everywhere',
    'state_elsewhere' => 'Other brands only',
    'action_assign' => 'Assign',
    'action_remove' => 'Remove',
    'empty_heading' => 'No Control Panel users',
    'empty_description' => 'Brand membership assigns existing Control Panel users to a brand. Create a user first and it will appear here.',
    'remove_confirm_title' => 'Remove from :brand?',
    'remove_confirm_body' => ':user loses access to everything scoped to :brand. Their account and their memberships in other brands are untouched.',
    'remove_confirm_button' => 'Remove from brand',
];
