<?php

namespace MetaTags;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractRepresentation;
use Omeka\Api\Representation\AssetRepresentation;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.before',
            [$this, 'handleSiteResource']
        );
        // This page does not exist.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.show.before',
            [$this, 'handleSiteResource']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.show.before',
            [$this, 'handleSiteResource']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.browse.before',
            [$this, 'handleSiteResources']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.browse.before',
            [$this, 'handleSiteResources']
        );
        // This page does not exist.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.browse.before',
            [$this, 'handleSiteResources']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Site\Page',
            'view.show.before',
            [$this, 'handleSitePage']
        );
        // This trigger works, but the event is not set in the default theme.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Page',
            'view.browse.before',
            [$this, 'handleSitePages']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );
    }

    public function handleSiteResource(Event $event)
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true)) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $headMeta = $view->headMeta();

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $view->resource;

        $ogTypeMode = $siteSettings->get('metatags_og_type', 'og_type');
        if ($ogTypeMode === 'rdf_class') {
            $ogType = $resource->resourceClass();
        }
        if (empty($ogType)) {
            $primary = $resource->primaryMedia();
            if ($primary) {
                $mediaType = $primary->mediaType();
                $main = strtok($mediaType, '/');
                $map = ['audio' => 'audio', 'video' => 'video', 'image' => 'image'];
                $ogType = isset($map[$main]) ? $map[$main] : 'article';
            } else {
                $ogType = 'article';
            }
        }

        $this->commonMeta($view, $ogType);

        $derivative = $siteSettings->get('metatags_media_derivative', 'large');

        $headMeta->appendProperty('og:title', $resource->displayTitle());
        if ($summary = $resource->displayDescription()) {
            $headMeta->appendProperty('og:description', $summary);
        }
        if ($derivative !== 'none'
            && $value = $this->thumbnailUrl($resource, $derivative)
        ) {
            $headMeta->appendProperty('og:image', $value);
            // TODO Add size and media type of the image.
        }

        if ($summary) {
            $headMeta->appendName('description', $summary);
        }
        /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
        $values = $resource->value('dcterms:subject', ['all' => true]);
        if ($values) {
            $values = implode(', ', array_unique(array_filter(array_map(function ($v) {
                return $v->valueResource() ? $v->valueResource()->displayTitle() : $v->value();
            }, $values), 'strlen')));
            if ($values) {
                $headMeta->appendName('keywords', $values);
            }
        }
        /* // TODO Who is the creator? Owner of resource or author of document?
        if ($value = $resource->value('dcterms:creator')) {
            $headMeta->appendName('author', (string) $value);
        }
        if ($value = $resource->value('dcterms:rights')) {
            $headMeta->appendName('copyright', (string) $value);
        }
        */
    }

    public function handleSiteResources(Event $event)
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true)) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $headMeta = $view->headMeta();

        $this->commonMeta($view, 'website');

        $derivative = $siteSettings->get('metatags_media_derivative', 'large');

        $site = $view->site;
        if ($site && $value = $site->title()) {
            $value .= ' · ' . $view->setting('installation_title', 'Omeka S');
            $headMeta->appendProperty('og:title', $value);
        }
        if ($site && $value = $site->summary()) {
            $headMeta->appendProperty('og:description', $value);
        }
        if ($derivative !== 'none') {
            $resources = $view->resources;
            $resource = reset($resources);
            if ($resource && $value = $this->thumbnailUrl($resource, $derivative)) {
                $headMeta->appendProperty('og:image', $value);
                // TODO Add size and media type of the image.
            }
        }
    }

    public function handleSitePage(Event $event)
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true)) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $headMeta = $view->headMeta();

        $this->commonMeta($view, 'article');

        /** @var \Omeka\Api\Representation\SitePageRepresentation $page */
        $page = $view->page;
        $headMeta->appendProperty('og:title', $page->title());

        $plugins = $view->getHelperPluginManager();
        if (!$plugins->has('pageMetadata')) {
            return;
        }

        $pageMetadata = $plugins->get('pageMetadata');
        if ($value = $pageMetadata('summary', $page)) {
            $headMeta->appendProperty('og:description', $value);
        }
        if ($summary = $pageMetadata('summary', $page)) {
            $headMeta->appendProperty('og:description', $summary);
        }
        $derivative = $siteSettings->get('metatags_media_derivative', 'large');
        if ($derivative !== 'none'
            && $value = $pageMetadata('main_image', $page)
        ) {
            $headMeta->appendProperty('og:image', $this->thumbnailUrl($value, $derivative));
            // TODO Add size and media type of the image.
        }

        // Note: the head title (site title) is already added by Omeka.
        if ($summary) {
            $headMeta->appendName('description', $value);
        }
        if ($value = $pageMetadata('tags')) {
            $headMeta->appendName('keywords', $value);
        }
        if ($value = $pageMetadata('credits')) {
            $headMeta->appendName('author', $value);
        }
    }

    public function handleSitePages(Event $event)
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true)) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $headMeta = $view->headMeta();

        $this->commonMeta($view, 'website');

        $site = $view->site;
        if ($site && $value = $site->title()) {
            $value .= ' · ' . $view->setting('installation_title', 'Omeka S');
            $headMeta->appendProperty('og:title', $value);
        }
        if ($site && $value = $site->summary()) {
            $headMeta->appendProperty('og:description', $value);
        }
    }

    /**
     * @param PhpRenderer $view
     * @param string|\Omeka\Api\Representation\ResourceClassRepresentation $ogType
     */
    protected function commonMeta(PhpRenderer $view, $ogType = 'website')
    {
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $headMeta = $view->headMeta();

        // The prefix cannot be added to <head>.
        $view->htmlElement('html')->appendAttribute('prefix', 'og: https://ogp.me/ns#');

        $derivative = $siteSettings->get('metatags_media_derivative', 'large');
        if (is_object($ogType)) {
            $vocabulary = $ogType->vocabulary();
            $view->htmlElement('html')
                ->appendAttribute('prefix', $vocabulary->prefix() . ': ' . $vocabulary->namespaceUri());
            $headMeta->appendProperty('og:type', $ogType->term());
        } else {
            $headMeta->appendProperty('og:type', $ogType);
        }

        $site = $view->site;
        if ($site) {
            $headMeta->appendProperty('og:site_name', $site->title());
        }
        if ($value = $siteSettings->get('locale')) {
            $headMeta->appendProperty('og:locale', $value);
        }
        $headMeta->appendProperty('og:url', $view->serverUrl(true));

        $headMeta->appendName('twitter:card', in_array($derivative, ['original', 'large']) ? 'summary' : 'summary_large_image');
        if ($value = $siteSettings->get('metatags_twitter_domain', true)) {
            $headMeta->appendName('twitter:domain', $value);
        }
        if ($value = $siteSettings->get('metatags_twitter_site', true)) {
            $headMeta->appendName('twitter:site', $value);
        }
        if ($value = $siteSettings->get('metatags_twitter_creator', true)) {
            $headMeta->appendName('twitter:creator', $value);
        }
    }

    protected function thumbnailUrl(AbstractRepresentation $representation, $type = 'large')
    {
        if ($representation instanceof AssetRepresentation) {
            return $representation->assetUrl();
        }

        $thumbnail = $representation->thumbnail();
        if ($thumbnail) {
            return $thumbnail->assetUrl();
        }

        $primaryMedia = $representation->primaryMedia();
        if (!$primaryMedia) {
            return null;
        }
        // The fallback uri is not absolute. Anyway, don't return it.
        $url = $primaryMedia->thumbnailUrl($type);
        return strpos($url, 'http') === 0
            ? $url
            : null;
    }
}
