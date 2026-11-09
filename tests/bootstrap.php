<?php declare(strict_types=1);

/**
 * Bootstrap file for module tests.
 *
 * Use Common module Bootstrap helper for test setup.
 */

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    [
        'Common',
        'MetaTags',
        // Optional: check that its tags are not duplicated.
        '?Sharing',
        // Optional: the image of the pages is found via its helper pageMetadata.
        '?BlockPlus',
    ],
    'MetaTagsTest',
    __DIR__ . '/MetaTagsTest'
);
