@extends('statamic::layout')
@section('title', __('Brands'))

@section('content')
    <header class="mb-6">
        <h1>{{ __('Brands') }}</h1>
        <p class="text-gray text-sm mt-1">{{ __('Choose the brand you are working in. All contacts, lists, consent and sends are isolated per brand.') }}</p>
    </header>

    <div class="card p-0">
        <table class="data-table">
            <tbody>
                @foreach ($brands as $brand)
                    <tr>
                        <td>
                            <span class="font-medium">{{ $brand->name }}</span>
                            <span class="text-gray text-2xs ml-2">{{ $brand->handle }}</span>
                            @if ($brand->is_default)
                                <span class="badge-pill-sm bg-blue-100 text-blue-700 ml-2">{{ __('Default') }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ($brand->id === $currentId)
                                <span class="badge-pill-sm bg-green-100 text-green-700">{{ __('Active') }}</span>
                            @else
                                <form method="POST" action="{{ cp_route('brand-context.switcher.switch') }}">
                                    @csrf
                                    <input type="hidden" name="brand_id" value="{{ $brand->id }}">
                                    <button type="submit" class="btn-sm">{{ __('Switch') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
