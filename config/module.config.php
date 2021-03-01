<?php declare(strict_types=1);

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
            'metatags_og_type' => 'og_type',
            'metatags_twitter_domain' => '',
            'metatags_twitter_site' => '',
            'metatags_twitter_creator' => '',
            'metatags_media_mode' => 'thumbnail',
            'metatags_media_asset' => '',
            'metatags_media_derivative' => 'large',
        ],
    ],
];
