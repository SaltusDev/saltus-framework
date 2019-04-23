<?php
return [
    'type' => 'tax',
    'name' => 'genre',
    'links' => [
        'post', 'book',
    ],
    'labels' => [
        'has_one' => 'Genre',
        'has_many' => 'Genres',
        'text_domain' => 'sage',
    ],
];