<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Goldnead\BrandContext\Contracts\DescribesSettingsScreen;
use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * An addon that says where its tab sits and which icon its sidebar entry
 * carries.
 *
 * The counterpart to {@see FakeAddonSettings}, which says neither — and that
 * pairing is the test: the two have to be able to stand on the same screen,
 * because on a real installation twenty-two addons say nothing and at most a
 * couple ever will.
 *
 * Its namespace sorts *after* `widgets` alphabetically on purpose, so a test
 * that finds it first has found the order and not the alphabet.
 */
class OrderedAddonSettings implements DescribesSettingsScreen, ProvidesSettings
{
    public static function settingsNamespace(): string
    {
        return 'zeppelin';
    }

    public static function settingsConfigPath(): string
    {
        return 'zeppelin';
    }

    public static function settingsPermission(): string
    {
        return 'manage zeppelin settings';
    }

    public static function settingsOrder(): int
    {
        return 10;
    }

    public static function settingsIcon(): string
    {
        return 'lightning-bolt';
    }

    public static function settingsGroups(): array
    {
        return [
            [
                'title' => 'General',
                'fields' => [
                    [
                        'key' => 'label',
                        'type' => 'string',
                        'label' => 'Label',
                        'nullable' => false,
                    ],
                ],
            ],
        ];
    }
}
