<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-Brand Mode
    |--------------------------------------------------------------------------
    |
    | When false (the default), the package behaves as a single-brand system:
    | every record belongs to the default brand, the global scope is a no-op,
    | and no brand switcher is shown in the Control Panel. Most Statamic
    | instances use it this way and never notice the machinery.
    |
    | When true, hard brand isolation is enforced: the global scope filters
    | every branded model by the current brand, new records are stamped with
    | the current brand, and the CP brand switcher becomes available.
    |
    | This can be gated further behind a license via the `license_check`
    | callback below, so multi-brand can be sold as a premium feature.
    |
    */

    'multi_brand' => env('BRAND_CONTEXT_MULTI_BRAND', false),

    /*
    |--------------------------------------------------------------------------
    | License / Premium Gate
    |--------------------------------------------------------------------------
    |
    | Optional callable (or invokable class name) returning a bool. When set,
    | multi-brand is only active if BOTH `multi_brand` is true AND this returns
    | true. Leave null to gate on the config flag alone.
    |
    */

    'license_check' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Brand
    |--------------------------------------------------------------------------
    |
    | The handle and display name of the brand that always exists. All existing
    | data is backfilled onto it, and in single-brand mode everything lives on
    | it. Keep the handle stable once installed.
    |
    */

    'default_handle' => env('BRAND_CONTEXT_DEFAULT_HANDLE', 'default'),
    'default_name' => env('BRAND_CONTEXT_DEFAULT_NAME', 'Default'),

    /*
    |--------------------------------------------------------------------------
    | Fail Mode (multi-brand only)
    |--------------------------------------------------------------------------
    |
    | Governs the global scope when multi-brand is ON but no current brand is
    | resolved (e.g. an unauthenticated or misconfigured request path).
    |
    |  - 'closed' (default, recommended): return no rows. Nothing leaks across
    |    brands. Legitimate all-brand access must be explicit via
    |    BrandContext::withoutBrandScope().
    |  - 'open': skip the scope. Only for trusted single-tenant-per-process
    |    setups; never use where untrusted requests can reach branded models.
    |
    */

    'fail_mode' => env('BRAND_CONTEXT_FAIL_MODE', 'closed'),

    /*
    |--------------------------------------------------------------------------
    | Which brand a website request belongs to
    |--------------------------------------------------------------------------
    |
    | The Control Panel reads the active brand from the session. A visitor has
    | no session to read, so a multi-brand website has to say which brand a
    | request belongs to — otherwise the fail-closed scope hides everything and
    | the site serves empty pages without a single error.
    |
    | Map either the Statamic site or the host. One site per brand is the
    | tidiest arrangement and gives each brand its own URLs; `hosts` is for
    | several domains on one site.
    |
    |   'sites' => ['nordlicht' => 'nordlicht', 'halbmond' => 'halbmond'],
    |   'hosts' => ['halbmond.de' => 'halbmond', 'chorwerkstatt.de' => 'chorwerkstatt'],
    |
    | A request that matches nothing falls back to the default brand and says so
    | in the log, once per request.
    |
    */

    'sites' => [],

    'hosts' => [],

    /*
    | Several brands on one domain, told apart by the first path segment. This
    | is what an agency reaches for before it buys the second domain:
    |
    |   'paths' => ['chorwerkstatt' => 'chorwerkstatt', 'halbmond' => 'halbmond'],
    |
    | Only the first segment is read. A brand owns a prefix, not a scattering of
    | URLs.
    */

    'paths' => [],

    /*
    | `?brand=<handle>` on a public URL. Off by default: on a scoped site it is
    | a way to read another brand's data by guessing a handle. Useful for a
    | demo or a preview, and a deliberate choice either way.
    */

    'allow_query_override' => env('BRAND_CONTEXT_ALLOW_QUERY_OVERRIDE', false),

];
