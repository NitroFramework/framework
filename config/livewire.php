<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Class Namespace
    |---------------------------------------------------------------------------
    |
    | The root namespace class-based Livewire components are resolved from by
    | convention (e.g. 'user-profile' => App\Livewire\UserProfile).
    |
    */

    'class_namespace' => 'App\\Livewire',

    /*
    |---------------------------------------------------------------------------
    | View Path
    |---------------------------------------------------------------------------
    |
    | Where single-file components live (a <?php new class extends Component ?>
    | block followed by its Blade view in one .blade.php file), and the default
    | location for a class-based component's conventional view.
    |
    */

    'view_path' => base_path('resources/views/livewire'),

    /*
    |---------------------------------------------------------------------------
    | Page Layout
    |---------------------------------------------------------------------------
    |
    | The layout a routed full-page component renders into when it does not
    | declare its own #[Layout(...)]. The component's HTML fills $section.
    |
    */

    'layout' => 'layouts.app',
    'layout_section' => 'content',

    /*
    |---------------------------------------------------------------------------
    | Endpoints
    |---------------------------------------------------------------------------
    |
    | The routes the client posts to for update commits and file uploads.
    |
    */

    'update_uri' => '/livewire/update',
    'upload_uri' => '/livewire/upload',

    /*
    |---------------------------------------------------------------------------
    | Temporary File Uploads
    |---------------------------------------------------------------------------
    |
    | wire:model file uploads are stored in a temporary directory (relative to
    | storage/app) until an action moves them into permanent storage. Rules are
    | applied when the component validates the uploaded property.
    |
    */

    'temporary_file_upload' => [
        'directory' => 'livewire-tmp',
        'rules' => ['file', 'max:12288'], // 12 MB
        'preview_mimes' => ['png', 'gif', 'bmp', 'svg', 'jpg', 'jpeg', 'webp', 'mp4', 'mov', 'mp3', 'wav'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Lazy Loading Placeholder
    |---------------------------------------------------------------------------
    |
    | The default placeholder view rendered for #[Lazy] components that do not
    | define their own placeholder() method. Null uses a built-in skeleton.
    |
    */

    'lazy_placeholder' => null,

    /*
    |---------------------------------------------------------------------------
    | Pagination Theme
    |---------------------------------------------------------------------------
    |
    | The theme used by the WithPagination trait's pagination view.
    |
    */

    'pagination_theme' => 'tailwind',

];
