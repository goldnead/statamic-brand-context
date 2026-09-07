<?php

return [
    // Marken-Umschalter (Control-Panel-Kopfzeile)
    'switcher_aria_label' => 'Marke wechseln',
    'switcher_label' => 'Marke',

    // Markenfeld im Nutzer-Formular
    'user_brands_display' => 'Marken',
    'user_brands_instructions' => 'In welchen Marken taucht diese Person als Zuständige:r auf? Leer = in allen.',

    // Einstellungs-Bildschirm der Suite
    'nav_settings' => 'Addon-Einstellungen',
    'settings_saved' => 'Einstellungen für :addon gespeichert.',
    'settings_brand_notice' => 'Diese Einstellungen gelten für :brand. Für eine andere Marke oben im Kopf die Marke wechseln.',
    'settings_follows_config' => 'Was hier nicht geändert wurde, folgt weiter config/:path.php.',
    'settings_not_writable' => 'Die Tabelle brand_settings fehlt, deshalb lässt sich hier nichts speichern. Unten stehen die Werte aus den Config-Dateien. Zum Ändern einmal php artisan migrate laufen lassen.',
    'settings_failed_heading' => 'Diese Addons konnten ihre Einstellungen nicht anmelden und fehlen deshalb auf dieser Seite. Der vollständige Fehler steht im Log.',
    'settings_empty_heading' => 'Keines der installierten Addons meldet Einstellungen an.',
    'settings_empty_item_heading' => 'Add-ons ansehen',
    'settings_empty_description' => 'Addons mit Einstellungen erscheinen hier, sobald sie installiert sind.',
];
