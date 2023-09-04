<?php declare(strict_types=1);

namespace MetaTags;

use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

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
