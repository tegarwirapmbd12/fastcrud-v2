<?php

declare(strict_types=1);

return [
    'generate_migration' => true,
    'generate_model' => true,
    'generate_repository' => false,
    'generate_controller' => true,
    'generate_routes' => true,
    'generate_blade' => true,
    'generate_sidenav' => true,
    'sidenav_default_icon' => 'book-open-text',
    'soft_deletes' => false,
    'api_controller' => false,
    // Rules containing "nullable" will also generate nullable migration columns.
    // Example field syntax for one nullable column: title:string:nullable.
    'validation_rules' => [
        'default' => 'required',
        'string' => 'required|string|max:255',
        'text' => 'required',
        'longText' => 'required',
        'integer' => 'required|integer',
        'boolean' => 'boolean',
        'date' => 'required|date',
        // 'dateTime' => 'required|date',
        // 'timestamp' => 'required|date',
        // 'email' => 'required|email',
    ],
];
