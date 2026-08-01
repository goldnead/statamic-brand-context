<?php

return [
    'nav_brand_members' => 'Markenzugehörigkeit',
    'permission_group' => 'Marken',
    'manage_brand_members' => 'Markenzugehörigkeit verwalten',
    'unknown_user' => 'Diesen Nutzer gibt es nicht mehr.',

    // Marken-Umschalter (Control-Panel-Kopfzeile)
    'switcher_aria_label' => 'Marke wechseln',
    'switcher_label' => 'Marke',

    // Brand-Members-Screen
    'users_heading' => 'Nutzer',
    'scope_note' => 'Zuordnungen hier gelten für :brand, also die im Umschalter aktuell gewählte Marke.',
    'transition_note' => 'Ein Nutzer ohne Zuordnung zu irgendeiner Marke gilt als Mitglied jeder Marke. Deshalb tauchen bestehende Nutzer überall auf, bis sie zum ersten Mal zugeordnet werden. Erst die erste Zuordnung grenzt einen Nutzer ein: ab dann gehört er nur noch zu den für ihn gelisteten Marken.',
    'state_member' => 'Mitglied',
    'state_unassigned' => 'Nicht zugeordnet — zählt überall',
    'state_elsewhere' => 'Nur andere Marken',
    'action_assign' => 'Zuordnen',
    'action_remove' => 'Entfernen',
    'empty_heading' => 'Keine Control-Panel-Nutzer',
    'empty_description' => 'Markenzugehörigkeit ordnet bestehende Control-Panel-Nutzer einer Marke zu. Lege zuerst einen Nutzer an, dann erscheint er hier.',
    'remove_confirm_title' => 'Aus :brand entfernen?',
    'remove_confirm_body' => ':user verliert den Zugriff auf alles, was zu :brand gehört. Das Konto und Zuordnungen zu anderen Marken bleiben unberührt.',
    'remove_confirm_button' => 'Aus Marke entfernen',
];
