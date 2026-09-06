<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * A second stand-in addon, used only to register a namespace *after* the
 * manager has already applied once.
 *
 * Separate from {@see FakeAddonSettings} on purpose: the registry refuses two
 * providers for one namespace, and the point of this fixture is to arrive
 * late, not to arrive twice.
 */
class LateAddonSettings implements ProvidesSettings
{
    public static function settingsNamespace(): string
    {
        return 'latecomer';
    }

    public static function settingsConfigPath(): string
    {
        return 'latecomer';
    }

    public static function settingsPermission(): string
    {
        return 'manage latecomer settings';
    }

    public static function settingsGroups(): array
    {
        return [
            [
                'title' => 'General',
                'fields' => [
                    [
                        'key' => 'flag',
                        'type' => 'boolean',
                        'label' => 'Flag',
                        'nullable' => false,
                    ],
                ],
            ],
        ];
    }
}
