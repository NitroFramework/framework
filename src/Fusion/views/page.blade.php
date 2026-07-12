{{-- Zero-config page shell for `Route::fusion('/path', 'Component')` when no host
     view is given. It renders the client component into the configured layout
     (config('fusion.layout')), so a Fusion page picks up the app's chrome the
     same way a routed Livewire component does. Pass your own view to
     Route::fusion() when you want full control over the page. --}}
@extends($__layout)

@section($__section)
    @fusion($__component)
@endsection

@push('scripts')
    @fusionScripts
@endpush
