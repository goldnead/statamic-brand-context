<?php

namespace Goldnead\BrandContext\Http\Controllers\Cp;

use Goldnead\BrandContext\BrandManager;
use Goldnead\BrandContext\Http\Requests\UpdateBrandSettingsRequest;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Facades\Addon;

/**
 * The one settings screen for the whole suite.
 *
 * Every registered addon becomes a section on this page, drawn from its own
 * `settingsGroups()`. An agency that buys the suite gets one place to look
 * instead of sixteen, which is the actual product argument for this layer; the
 * three-thousand-odd lines it removes are a side effect.
 *
 * **The screen always works on the current brand**, the one in the brand
 * switcher. The brand is never read from the request. An operator of brand A
 * cannot write brand B's settings by editing the payload, because the payload
 * has no say in it — the same argument, and the same guarantee, as
 * {@see BrandUserController}.
 *
 * **Sections the operator may not manage are not rendered.** Statamic core
 * hides what you cannot reach rather than showing it greyed out, and a
 * read-only section here would invite a question ("why can I see this?") that
 * nobody on the site can answer.
 */
class BrandSettingsController extends BaseController
{
    public function __construct(
        protected SettingsRegistry $registry,
        protected SettingsManager $settings,
        protected BrandManager $brands,
    ) {}

    public function index(Request $request): Response
    {
        $sections = [];

        foreach (array_keys($this->registry->all()) as $namespace) {
            if (! $this->canManage($request, $namespace)) {
                continue;
            }

            $sections[] = [
                'namespace' => $namespace,
                'title' => $this->title($namespace),
                'config_path' => $this->registry->configPath($namespace),
                'groups' => $this->withNormalisedOptions($this->registry->groups($namespace)),
                'values' => $this->settings->for($namespace)->values(),
            ];
        }

        // An install that has not run migrations has neither table.
        // `BrandManager::default()` throws there — by design, it is telling
        // the truth — but a settings screen answering 500 for it is the wrong
        // way to pass that on, and it is the likeliest first impression on a
        // standalone install of one addon. Reading works without any of it
        // (`overrides()` falls back to the packaged values), so the page
        // renders, shows what the config files say, and names the command.
        $brand = $this->currentBrand();

        // Same for the table on its own, which is what an *upgrade* without
        // migrations looks like: `brands` is there, `brand_settings` is not.
        // Offering Save over a table that does not exist hands the operator an
        // SQL error for doing what the screen invited.
        $writable = $brand !== null && $this->tableExists();

        return Inertia::render('brand-context::Settings', [
            'writable' => $writable,
            'brand' => $brand === null ? null : [
                'id' => $brand->id,
                'name' => $brand->name,
                'handle' => $brand->handle,
            ],
            // Never "multi-brand" without a brand to name: the switcher notice
            // would point at a brand the page could not show.
            'multiBrand' => $brand !== null && $this->brands->multiBrandEnabled(),
            'sections' => $sections,
            'updateUrl' => cp_route('brand-context.settings.update'),
            // Where the empty state points. Settings appear on this page when
            // an addon that offers them is installed, and the addon list is
            // where an operator sees what is — an empty state with nowhere to
            // go reads as a broken page rather than an empty one.
            'addonsUrl' => cp_route('addons.index'),
        ]);
    }

    /**
     * Write one namespace's settings.
     *
     * Redirects back rather than answering with JSON, so the Inertia visit
     * re-renders {@see index()} and the form is refilled from the values as
     * they now stand — which is not always what was typed. A blank nullable
     * field is stored as null and comes back null, an integer arrives from a
     * text input as a string and comes back an integer, and a value equal to
     * the packaged default is dropped and comes back as whatever the config
     * file says.
     *
     * Going through Inertia rather than axios is what gives this screen the
     * progress bar, the flash toast, the dirty-state guard and correct
     * back-button behaviour. An axios call plus a hand-rolled toast bypasses
     * all four, which is the defect the studio linter names as
     * `ui.inertia-navigation`.
     */
    public function update(UpdateBrandSettingsRequest $request): RedirectResponse
    {
        $namespace = $request->namespace();

        // Handed over as it arrived. Putting each value into the type its
        // field declares is {@see NamespaceSettings::save()}'s job, not this
        // controller's: it used to live here, which left every other write
        // path — a console command, a migration, the facade — free to store a
        // string where an integer belongs.
        $this->settings
            ->for($namespace)
            ->save($request->validated()['settings']);

        return back()->with('success', __('brand-context::messages.settings_saved', [
            'addon' => $this->title($namespace),
        ]));
    }

    /**
    /**
     * The current brand, or null on an install whose migrations never ran.
     *
     * `BrandManager::default()` throws there, and rightly so — it is the only
     * honest answer to "which brand is this". This screen just must not pass
     * it on as a 500: reading needs no brand at all, so the page still shows
     * what the config files say and tells the operator what to run.
     */
    protected function currentBrand(): ?Brand
    {
        try {
            return $this->brands->current();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the overrides table is there to write to.
     *
     * A failure to ask is treated as "no": a database this cannot reach is not
     * a database this should offer to write.
     */
    protected function tableExists(): bool
    {
        try {
            return Schema::hasTable('brand_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Hand the screen one shape of select options, whichever the addon wrote.
     *
     * Done here rather than in the page, so the browser never has to guess
     * which spelling it received. {@see SettingsRegistry::normaliseOptions()}
     * for the two that are accepted and why.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function withNormalisedOptions(array $groups): array
    {
        foreach ($groups as $g => $group) {
            foreach ($group['fields'] ?? [] as $f => $field) {
                if (($field['type'] ?? null) !== 'select') {
                    continue;
                }

                $groups[$g]['fields'][$f]['options'] =
                    SettingsRegistry::normaliseOptions($field['options'] ?? []);
            }
        }

        return $groups;
    }

    /**
     * The heading for one section: the addon's own name, as Statamic knows it.
     *
     * Asked of the addon registry rather than kept as a list here — a second
     * name for an addon is a name that goes stale the first time one is
     * renamed. Deriving it from the namespace instead is what the first
     * version did, and it put "Leadhub" above a section every other screen in
     * the Control Panel calls "LeadHub".
     *
     * Matched on the slug first and on the package name second, because the
     * two do not always agree: `goldnead/statamic-leadhub` has the slug
     * `leadhub`, but `goldnead/statamic-automations` has `statamic-automations`.
     * A namespace that matches neither falls back to itself made readable,
     * which is still better than an exception on a settings screen.
     */
    protected function title(string $namespace): string
    {
        try {
            $addon = Addon::all()->first(
                fn ($a) => $a->slug() === $namespace
                    || str_ends_with($a->package(), '/statamic-'.$namespace)
            );

            if ($addon !== null && $addon->name() !== null && $addon->name() !== '') {
                return $addon->name();
            }
        } catch (\Throwable) {
            // No addon registry available (a plain Laravel context, or a boot
            // that has not reached Statamic). Falling through is correct: a
            // heading is not worth a broken page.
        }

        return ucwords(str_replace(['-', '_'], ' ', $namespace));
    }

    protected function canManage(Request $request, string $namespace): bool
    {
        $user = $request->user();
        $permission = $this->registry->permission($namespace);

        return $user !== null
            && $permission !== null
            && method_exists($user, 'can')
            && $user->can($permission);
    }
}
