<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * A stand-in addon that offers settings, so the layer can be tested without
 * pulling a real sibling package into this one's suite.
 *
 * It carries one field of every supported type, because the type is what the
 * validation, the coercion and the "equals the packaged default" comparison
 * each branch on — and a boolean false, an integer zero and an empty list are
 * the three values most likely to be mistaken for "unset".
 */
class FakeAddonSettings implements ProvidesSettings
{
    public static function settingsNamespace(): string
    {
        return 'widgets';
    }

    public static function settingsConfigPath(): string
    {
        return 'widgets';
    }

    public static function settingsPermission(): string
    {
        return 'manage widget settings';
    }

    public static function settingsGroups(): array
    {
        return [
            [
                'title' => 'General',
                'description' => 'Everything about widgets.',
                'fields' => [
                    [
                        'key' => 'label',
                        'type' => 'string',
                        'label' => 'Label',
                        'nullable' => false,
                    ],
                    [
                        'key' => 'retention.days',
                        'type' => 'integer',
                        'label' => 'Retention',
                        'nullable' => true,
                        'min' => 1,
                    ],
                    [
                        'key' => 'enabled',
                        'type' => 'boolean',
                        'label' => 'Enabled',
                        'nullable' => false,
                    ],
                    [
                        // Mehrzeiliger Fliesstext. Der Fall dahinter ist die
                        // Widerrufsbelehrung in `offers`: ein Rechtstext, der
                        // dem Betreiber gehoert und keine 255 Zeichen traegt.
                        'key' => 'withdrawal_text',
                        'type' => 'text',
                        'label' => 'Withdrawal terms',
                        'nullable' => true,
                    ],
                    [
                        'key' => 'redact_keys',
                        'type' => 'list',
                        'label' => 'Redacted keys',
                        'nullable' => false,
                    ],
                    [
                        'key' => 'retry_on_status',
                        'type' => 'list',
                        'items' => 'integer',
                        'label' => 'Retry on',
                        'nullable' => false,
                    ],
                    [
                        'key' => 'strategy',
                        'type' => 'select',
                        'label' => 'Strategy',
                        'nullable' => false,
                        'options' => [
                            'exponential' => 'Exponential',
                            'fixed' => 'Fixed',
                        ],
                    ],
                ],
            ],
        ];
    }
}
