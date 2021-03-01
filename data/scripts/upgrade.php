<?php declare(strict_types=1);

namespace MetaTags;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 */

// $settings = $services->get('Omeka\Settings');
// $config = require dirname(__DIR__, 2) . '/config/module.config.php';
// $connection = $services->get('Omeka\Connection');
// $entityManager = $services->get('Omeka\EntityManager');
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
// $space = strtolower(__NAMESPACE__);

if (version_compare($oldVersion, '3.3.0.4', '<')) {
    $siteSettings = $services->get('Omeka\Settings\Site');
    $ids = $api->search('sites', [], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
    foreach ($ids as $id) {
        $siteSettings->setTargetId($id);
        $mode = $siteSettings->get('metatags_media_derivative', 'thumbnail') ?: 'thumbnail';
        if ($mode === 'none') {
            $siteSettings->set('metatags_media_mode', 'none');
            $siteSettings->set('metatags_media_derivative', 'large');
        } else {
            $siteSettings->set('metatags_media_mode', 'thumbnail');
        }
        $siteSettings->delete('metatags_enable_media');
    }
}
