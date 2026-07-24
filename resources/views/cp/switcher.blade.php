{{--
  Standalone brand switcher page. Statamic 6's CP is Inertia/Vue-based, so a
  blade @extends('statamic::layout') renders blank — this is a self-contained,
  lightweight page instead. Reachable at /cp/brands (auth-guarded).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Brands') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin:0; background:#f3f4f6; color:#111827; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; }
        .wrap { max-width:560px; margin:48px auto; padding:0 20px; }
        h1 { font-size:22px; margin:0 0 6px; }
        p.sub { color:#6b7280; font-size:14px; margin:0 0 24px; line-height:1.5; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
        .row { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #f0f1f3; }
        .row:last-child { border-bottom:0; }
        .name { font-weight:600; }
        .handle { color:#9ca3af; font-size:12px; margin-left:8px; }
        .pill { font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; }
        .pill-default { background:#eef2ff; color:#4338ca; }
        .pill-active { background:#dcfce7; color:#15803d; }
        button { background:#111827; color:#fff; border:0; border-radius:8px; padding:8px 16px; font-weight:600; font-size:13px; cursor:pointer; }
        button:hover { background:#374151; }
        a.back { display:inline-block; margin-top:20px; color:#4338ca; font-size:14px; text-decoration:none; }
        @media (prefers-color-scheme: dark) {
            body { background:#0b0f19; color:#e5e7eb; }
            .card { background:#111827; border-color:#1f2937; }
            .row { border-color:#1f2937; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>{{ __('Brands') }}</h1>
        <p class="sub">{{ __('Choose the brand you are working in. Contacts, lists, consent and sends are isolated per brand.') }}</p>

        <div class="card">
            @foreach ($brands as $brand)
                <div class="row">
                    <div>
                        <span class="name">{{ $brand->name }}</span>
                        <span class="handle">{{ $brand->handle }}</span>
                        @if ($brand->is_default)
                            <span class="pill pill-default">{{ __('Default') }}</span>
                        @endif
                    </div>
                    <div>
                        @if ($brand->id === $currentId)
                            <span class="pill pill-active">{{ __('Active') }}</span>
                        @else
                            <form method="POST" action="{{ cp_route('brand-context.switcher.switch') }}">
                                @csrf
                                <input type="hidden" name="brand_id" value="{{ $brand->id }}">
                                <button type="submit">{{ __('Switch') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <a class="back" href="{{ cp_route('index') }}">&larr; {{ __('Back to Control Panel') }}</a>
    </div>
</body>
</html>
