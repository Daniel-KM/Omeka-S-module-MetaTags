<?php

namespace MetaTags;

return [
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'metatags' => [
        'site_settings' => [
            'metatags_enabled' => true,
            'metatags_twitter_domain' => '',
            'metatags_twitter_site' => '',
            'metatags_twitter_creator' => '',
            'metatags_media_derivative' => 'large',
        ],
    ],
];
