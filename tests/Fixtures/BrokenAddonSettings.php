<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\BrandContext\Settings\SettingsManager;
use RuntimeException;

/**
 * Ein Addon, das sich sauber anmeldet und erst beim Aufzaehlen seiner Felder
 * auseinanderfaellt.
 *
 * Das ist der Fall, den eine Absicherung allein in `register()` nicht faengt:
 * `settingsGroups()` wird aus {@see SettingsManager::applyNamespace()}
 * gerufen, also aus `app->booted()`. Ein fehlender Import oder ein Aufruf auf
 * einer Klasse, die es nicht gibt, wirft dort und nimmt die Installation
 * genauso mit wie eine kaputte Anmeldung.
 */
class BrokenAddonSettings implements ProvidesSettings
{
    public static function settingsNamespace(): string
    {
        return 'kaputt';
    }

    public static function settingsConfigPath(): string
    {
        return 'kaputt';
    }

    public static function settingsPermission(): string
    {
        return 'manage kaputt settings';
    }

    public static function settingsGroups(): array
    {
        throw new RuntimeException('Class "Goldnead\Kaputt\Fields" not found');
    }
}
