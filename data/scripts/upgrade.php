<?php declare(strict_types=1);

namespace MetaTags;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.96')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.96'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

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

if (version_compare($oldVersion, '3.4.7', '<')) {
    // The tag "twitter:domain" is no longer read by X.
    $connection->executeStatement(
        'DELETE FROM `site_setting` WHERE `id` = :id',
        ['id' => 'metatags_twitter_domain']
    );

    $message = new PsrMessage(
        'The meta tags were fixed: the Twitter accounts are output only when they are set, the card is large for large images and the description is shortened to 200 characters.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'The settings of the module are displayed again in the site settings. New settings allow to set the author and the rights of the resources. The size, the type and the alternative text of the image are added, the locale uses the format of Open Graph and the duplicate tags of the module Sharing are removed. A canonical link is added, and the searches are not indexed by the search engines. The date of the documents can be set, the links to the other languages are added and options add the tags for citation and the structured data of schema.org. The setting "Twitter domain" was removed.' // @translate
    );
    $messenger->addSuccess($message);
}
