<?php

namespace Goldnead\BrandContext\Contracts;

/**
 * What an addon writes to get a settings screen. Everything else — the table,
 * the form, the validation, the routes, the permission, the brand dimension —
 * comes from this package.
 *
 * Before this contract existed, `automations`, `leadhub` and `webhook-manager`
 * had each built the same five parts independently: a definition class, a
 * request, a controller, a key/value model and a Vue page, about 3,260 lines
 * for three copies of one mechanism. The only part of that which legitimately
 * belonged to the addon was the field list. This interface is that part, and
 * nothing else.
 *
 * **One definition, three readers.** {@see settingsGroups()} feeds the screen,
 * the validation and the config override alike. A field cannot appear on the
 * screen without a rule behind it, and a rule cannot exist for a field nobody
 * can see. The alternative — a hand-kept list of labels in JavaScript — is a
 * second description of the config file that can quietly disagree with it,
 * which is what the read-only screens in this family used to be.
 *
 * **What does not belong in here.** The lesson is inherited from
 * `automations`, where it was paid for:
 *
 * - **Secrets.** API keys, SMTP credentials and tokens stay in `.env` and the
 *   secret store. Offered here they would land in the database, and from there
 *   in every backup and every export.
 * - **Anything not switchable under a running install.** Storage drivers,
 *   table names, migration paths. A control that needs the data moved first is
 *   a control that breaks the site.
 * - **Detected state.** Whether a sibling addon is installed is Composer's
 *   answer, not an operator's. Show it, do not offer it.
 */
interface ProvidesSettings
{
    /**
     * The namespace this addon's settings live under, e.g. `automations`.
     *
     * Stable forever: it is stored in `brand_settings.namespace` on every row,
     * so renaming it orphans every override the site has made. It is also the
     * segment the permission and the screen's section heading are derived
     * from.
     */
    public static function settingsNamespace(): string;

    /**
     * The config root that unset values keep following, e.g. `automations`
     * for `config('automations.runs.prune_after_days')`.
     *
     * Usually the same string as the namespace, but not necessarily — the
     * namespace names the addon, this names a config file — so it is asked for
     * separately rather than assumed.
     */
    public static function settingsConfigPath(): string;

    /**
     * The permission that gates this addon's section, e.g.
     * `manage automation settings`.
     *
     * Asked for rather than derived from the namespace. The three addons that
     * had their own settings screen before this layer existed already ship
     * permissions — `manage automation settings`, singular, not
     * `manage automations settings` — and those are assigned to real user
     * groups on live installations. A derived name would silently stop
     * matching them, and the operator would lose the screen without anything
     * saying why.
     *
     * The addon registers the permission itself, as it always did. This
     * package only asks which one to check.
     */
    public static function settingsPermission(): string;

    /**
     * The editable settings, grouped in the order the screen shows them.
     *
     * Shape, unchanged from the three implementations this generalises:
     *
     * ```php
     * [
     *     [
     *         'title' => __('Runs'),
     *         'description' => __('How much of each run is kept.'),
     *         'fields' => [
     *             [
     *                 'key' => 'runs.prune_after_days', // path under the config root
     *                 'type' => 'integer',              // string|integer|boolean|list|select
     *                 'label' => __('Retention'),
     *                 'description' => __('Days a finished run is kept.'),
     *                 'nullable' => true,               // empty is a real value
     *                 'min' => 1,                       // integer only, optional
     *             ],
     *             [
     *                 'key' => 'retry.retry_on_status',
     *                 'type' => 'list',
     *                 'items' => 'integer',             // list only; defaults to string
     *                 'label' => __('Retry on'),
     *             ],
     *             [
     *                 'key' => 'retry.strategy',
     *                 'type' => 'select',
     *                 'options' => [                    // select only, required
     *                     'exponential' => __('Exponential'),
     *                     'fixed' => __('Fixed'),
     *                 ],
     *                 'label' => __('Strategy'),
     *             ],
     *         ],
     *     ],
     * ]
     * ```
     *
     * `nullable` is not decoration. An unset retention means "same as the
     * packaged default", which is a different state from zero days, and the
     * validation, the form and the store each have to keep them apart.
     *
     * `items` is not decoration either. A list whose packaged default holds
     * integers comes back from a textarea as strings, and a stored `["500"]`
     * never equals a packaged `[500]` — the row can then never be deleted, the
     * field is pinned for good, and a reader comparing strictly quietly stops
     * matching. Declare `items => 'integer'` and the layer keeps the type.
     *
     * `select` exists so a field with a fixed set of answers is a fixed set of
     * answers. Offered as a string it renders as a free-text box, and the
     * first person to type `sha257` into a hash algorithm finds out at
     * delivery time instead of at the form.
     *
     * @return array<int, array{title: string, description?: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array;
}
