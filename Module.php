<?php declare(strict_types=1);

namespace MetaTags;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\AbstractRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\AssetRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Module\AbstractModule;

/**
 * MetaTags.
 *
 * @copyright Daniel Berthereau 2020-2024
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    /**
     * Ids of the optional tags appended by this module.
     *
     * @var array
     */
    protected $ownTags = [];

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.62')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.62'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
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

        // Remove the duplicate tags appended later by other modules, like
        // Sharing.
        foreach ([
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\Media',
            'Omeka\Controller\Site\Page',
        ] as $controller) {
            $sharedEventManager->attach(
                $controller,
                'view.show.after',
                [$this, 'handleDuplicateTags'],
                -100
            );
        }

        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );
    }

    public function handleSiteResource(Event $event): void
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
            // Only "video.other" is a standard type of Open Graph for a media:
            // "image" and "audio" are not types, and "music.song" is music.
            $primary = $resource->primaryMedia();
            $ogType = $primary && strtok((string) $primary->mediaType(), '/') === 'video'
                ? 'video.other'
                : 'article';
        }

        $this->commonMeta($view, $ogType);

        if ($title = $resource->displayTitle()) {
            $headMeta->appendProperty('og:title', $title);
            $headMeta->appendName('twitter:title', $title);
        }

        if ($summary = $this->shortText((string) $resource->displayDescription())) {
            // Note: the head title (site title) is already added by Omeka.
            $headMeta->appendName('description', $summary);
            $headMeta->appendProperty('og:description', $summary);
            $headMeta->appendName('twitter:description', $summary);
        }

        // The dates of the record are rarely the date of the document.
        $dateTerm = $siteSettings->get('metatags_date', '');
        if ($dateTerm && $date = $this->isoDate($resource, $dateTerm)) {
            $headMeta->appendProperty('article:published_time', $date);
        }

        $this->appendThumbnail($resource, $view);

        if ($value = $this->valueTexts($resource, 'dcterms:subject')) {
            $headMeta->appendName('keywords', $value);
        }

        $author = $siteSettings->get('metatags_author', '');
        if ($author && $value = $this->valueTexts($resource, $author)) {
            $headMeta->appendName('author', $value);
        }

        $rights = $siteSettings->get('metatags_rights', '');
        if ($rights && $value = $this->valueTexts($resource, $rights)) {
            $headMeta->appendName('copyright', $value);
        }

        if ($resource instanceof ItemRepresentation && $siteSettings->get('metatags_citation', false)) {
            $this->appendCitation($view, $resource, $author ?: 'dcterms:creator', $dateTerm);
        }

        if ($this->isJsonLdEnabled()) {
            $this->appendJsonLd($view, $this->resourceJsonLd($view, $resource, $summary, $author ?: 'dcterms:creator', $dateTerm, $rights));
        }
    }

    public function handleSiteResources(Event $event): void
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true) || !$this->isBrowseAction()) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $headMeta = $view->headMeta();

        $this->commonMeta($view, 'website', true);
        $site = $view->site;

        if ($site && $value = $site->title()) {
            $value .= ' · ' . $view->setting('installation_title', 'Omeka S');
            $headMeta->appendProperty('og:title', $value);
            $headMeta->appendName('twitter:title', $value);
        }

        if ($site && $value = $this->shortText((string) $site->summary())) {
            $headMeta->appendName('description', $value);
            $headMeta->appendProperty('og:description', $value);
            $headMeta->appendName('twitter:description', $value);
        }

        $resources = $view->resources;
        $resource = reset($resources);
        if ($resource) {
            $this->appendThumbnail($resource, $view);
        }
    }

    public function handleSitePage(Event $event): void
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

        $title = $page->title();
        // Note: the head title (site title) is already added by Omeka.
        $headMeta->appendProperty('og:title', $title);
        $headMeta->appendName('twitter:title', $title);

        // The dates of a page are the dates of its publication.
        $headMeta->appendProperty('article:published_time', $page->created()->format(DATE_ATOM));
        if ($modified = $page->modified()) {
            $headMeta->appendProperty('article:modified_time', $modified->format(DATE_ATOM));
        }

        $plugins = $view->getHelperPluginManager();
        $pageMetadata = $plugins->has('pageMetadata') ? $plugins->get('pageMetadata') : null;

        // The summary of the page, else the summary of the site.
        $site = $view->site;
        $summary = $this->shortText((string) ($pageMetadata ? $pageMetadata('summary', $page) : ''))
            ?: $this->shortText((string) ($site ? $site->summary() : ''));
        if ($summary) {
            $headMeta->appendName('description', $summary);
            $headMeta->appendProperty('og:description', $summary);
            $headMeta->appendName('twitter:description', $summary);
        }

        // The main image is the one of the block of metadata, else the first
        // image of the blocks.
        $mediaMode = $siteSettings->get('metatags_media_mode', 'thumbnail');
        $image = null;
        if ($mediaMode === 'thumbnail') {
            $value = $pageMetadata
                ? ($pageMetadata('main_image', $page) ?: $pageMetadata('first_image', $page))
                : $this->mainImage($page);
            $image = $value ? $this->imageData($value, $siteSettings->get('metatags_media_derivative', 'large')) : null;
        }
        if ($image) {
            $this->appendImage($view, $image);
        } elseif ($mediaMode !== 'none') {
            $this->appendThumbnailDefault($view);
        }

        if ($pageMetadata && $value = $pageMetadata('tags')) {
            if (is_array($value)) {
                $value = implode(', ', array_unique(array_filter(array_map('trim', $value), 'strlen')));
            }
            $headMeta->appendName('keywords', $value);
        }

        if ($pageMetadata && $value = $pageMetadata('credits')) {
            $headMeta->appendName('author', $value);
        }

        if ($this->isJsonLdEnabled()) {
            $data = [
                '@type' => 'WebPage',
                'name' => $title,
                'description' => $summary,
                'url' => $this->tagValue($view, 'og:url'),
                'image' => $this->tagValue($view, 'og:image'),
                'datePublished' => $page->created()->format(DATE_ATOM),
                'dateModified' => $page->modified() ? $page->modified()->format(DATE_ATOM) : null,
                'isPartOf' => $site ? ['@type' => 'WebSite', 'name' => $site->title(), 'url' => $this->absoluteUrl($view, $site->siteUrl())] : null,
            ];
            $this->appendJsonLd($view, $data);
        }
    }

    public function handleSitePages(Event $event): void
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true) || !$this->isBrowseAction()) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $headMeta = $view->headMeta();

        $this->commonMeta($view, 'website', true);

        $site = $view->site;
        if ($site && $value = $site->title()) {
            $value .= ' · ' . $view->setting('installation_title', 'Omeka S');
            $headMeta->appendProperty('og:title', $value);
            $headMeta->appendName('twitter:title', $value);
        }

        if ($site && $value = $this->shortText((string) $site->summary())) {
            // Note: the head title (site title) is already added by Omeka.
            $headMeta->appendName('description', $value);
            $headMeta->appendProperty('og:description', $value);
            $headMeta->appendName('twitter:description', $value);
        }

        $mediaMode = $siteSettings->get('metatags_media_mode', 'thumbnail');
        if ($mediaMode !== 'none') {
            $this->appendThumbnailDefault($view);
        }
    }

    /**
     * Check if the current page is a list.
     *
     * The event "view.browse.before" may be triggered by a block inside another
     * page, for example the results of a search form, with the controller of
     * the route, so the tags of the page should not be replaced.
     */
    protected function isBrowseAction(): bool
    {
        $routeMatch = $this->getServiceLocator()->get('Omeka\Status')->getRouteMatch();
        return $routeMatch && $routeMatch->getParam('action') === 'browse';
    }

    /**
     * Keep only the first Open Graph and Twitter tags of each name.
     *
     * The alternate locales are multiple, so they are kept. The optional tags of
     * this module (citation, Dublin Core, schema.org) are removed when another
     * module appends them too.
     */
    public function handleDuplicateTags(Event $event): void
    {
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true)) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $container = $view->headMeta()->getContainer();
        $keys = [];
        $items = [];
        foreach ($container->getArrayCopy() as $item) {
            $key = (string) ($item->{$item->type} ?? '');
            if ($key !== 'og:locale:alternate' && preg_match('~^(?:og|twitter):~', $key)) {
                if (isset($keys[$key])) {
                    continue;
                }
                $keys[$key] = true;
            }
            $items[] = $item;
        }

        // The optional tags of this module are removed when another module
        // appends the same ones, for example ResourceMeta, case insensitively.
        if ($this->ownTags) {
            $others = [];
            foreach ($items as $item) {
                if (!isset($this->ownTags[spl_object_id($item)])) {
                    $others[mb_strtolower((string) ($item->{$item->type} ?? ''))] = true;
                }
            }
            $items = array_values(array_filter($items, fn ($item) => !isset($this->ownTags[spl_object_id($item)])
                || !isset($others[mb_strtolower((string) ($item->{$item->type} ?? ''))])));

            // The same for the data of schema.org.
            $scripts = $view->headScript()->getContainer();
            $hasOtherSchema = false;
            foreach ($scripts->getArrayCopy() as $script) {
                if (!isset($this->ownTags[spl_object_id($script)])
                    && ($script->type ?? null) === 'application/ld+json'
                    && strpos((string) ($script->source ?? ''), 'schema.org') !== false
                ) {
                    $hasOtherSchema = true;
                    break;
                }
            }
            if ($hasOtherSchema) {
                $scripts->exchangeArray(array_values(array_filter(
                    $scripts->getArrayCopy(),
                    fn ($script) => !isset($this->ownTags[spl_object_id($script)])
                )));
            }
        }

        $container->exchangeArray($items);
    }

    /**
     * @param PhpRenderer $view
     * @param string|\Omeka\Api\Representation\ResourceClassRepresentation $ogType
     * @param bool $withQuery Keep the query of the url, for the lists.
     */
    protected function commonMeta(PhpRenderer $view, $ogType = 'website', bool $withQuery = false): void
    {
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $headMeta = $view->headMeta();

        // The prefix cannot be added to <head>. Anyway, many sites use html,
        // and some use the xml standard way (xmlns:og).
        $view->htmlElement('html')->appendAttribute('prefix', 'og: https://ogp.me/ns#');

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
        if ($value = $this->ogLocale((string) ($siteSettings->get('locale') ?: $view->setting('locale')))) {
            $headMeta->appendProperty('og:locale', $value);
        }
        $query = $withQuery ? $this->currentQuery() : [];
        if ($url = $this->currentUrl($view, $query)) {
            $headMeta->appendProperty('og:url', $url);
            $view->headLink(['rel' => 'canonical', 'href' => $url]);
            $this->appendAlternateLanguages($view, $url, $query);
        }
        // The searches and the sorted lists duplicate the main list.
        $noindex = $siteSettings->get('metatags_noindex_params', ['fulltext_search', 'sort_by', 'sort_order', 'per_page']);
        if ($noindex && array_intersect_key($query, array_flip((array) $noindex))) {
            $headMeta->appendName('robots', 'noindex, follow');
        }

        // The card is a large one only when a large image is appended.
        $headMeta->appendName('twitter:card', 'summary');
        if ($value = $siteSettings->get('metatags_twitter_site', '')) {
            $headMeta->appendName('twitter:site', $value);
        }
        if ($value = $siteSettings->get('metatags_twitter_creator', '')) {
            $headMeta->appendName('twitter:creator', $value);
        }
    }

    protected function appendThumbnail(AbstractRepresentation $representation, PhpRenderer $view): void
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        $mediaMode = $siteSettings->get('metatags_media_mode', 'thumbnail');
        if ($mediaMode === 'none') {
            return;
        }

        if ($mediaMode === 'thumbnail'
            || ($mediaMode === 'thumbnail_static' && !($representation instanceof \Omeka\Api\Representation\SitePageRepresentation))
        ) {
            $image = $this->imageData($representation, $siteSettings->get('metatags_media_derivative', 'large'));
            if ($image) {
                $this->appendImage($view, $image);
                return;
            }
        }

        $this->appendThumbnailDefault($view);
    }

    protected function appendThumbnailDefault(PhpRenderer $view): void
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        $assetId = (int) $siteSettings->get('metatags_media_asset');
        if (!$assetId) {
            return;
        }
        try {
            /** @var \Omeka\Api\Representation\AssetRepresentation $asset */
            $asset = $services->get('Omeka\ApiManager')->read('assets', $assetId)->getContent();
        } catch (NotFoundException $e) {
            // The asset may have been deleted.
            return;
        }
        $image = $this->imageData($asset);
        if ($image) {
            $this->appendImage($view, $image);
        }
    }

    /**
     * @param array $image Keys "url", "type", "width", "height" and "alt".
     */
    protected function appendImage(PhpRenderer $view, array $image): void
    {
        $headMeta = $view->headMeta();
        $derivative = $this->getServiceLocator()->get('Omeka\Settings\Site')->get('metatags_media_derivative', 'large');
        if (in_array($derivative, ['original', 'large'])) {
            $headMeta->setName('twitter:card', 'summary_large_image');
        }
        $headMeta->appendProperty('og:image', $image['url']);
        if (!empty($image['type'])) {
            $headMeta->appendProperty('og:image:type', $image['type']);
        }
        if (!empty($image['width']) && !empty($image['height'])) {
            $headMeta->appendProperty('og:image:width', (string) $image['width']);
            $headMeta->appendProperty('og:image:height', (string) $image['height']);
        }
        if (!empty($image['alt'])) {
            $headMeta->appendProperty('og:image:alt', $image['alt']);
        }
        $headMeta->appendName('twitter:image', $image['url']);
        if (!empty($image['alt'])) {
            $headMeta->appendName('twitter:image:alt', $image['alt']);
        }
    }

    /**
     * Get the url, the media type, the size and the alternative text of image.
     *
     * The fallback thumbnails are skipped. The original is used only when it is
     * an image supported by the social networks, else the large thumbnail.
     *
     * @return array|null Keys "url", "type", "width", "height" and "alt".
     */
    protected function imageData(AbstractRepresentation $representation, string $derivative = 'large'): ?array
    {
        $asset = $representation instanceof AssetRepresentation
            ? $representation
            : $representation->thumbnail();
        if ($asset) {
            return $this->completeImageData([
                'url' => $asset->assetUrl(),
                'type' => $asset->mediaType(),
                'alt' => method_exists($asset, 'altText') ? (string) $asset->altText() : '',
            ], 'asset/' . $asset->filename());
        }

        $media = $representation->primaryMedia();
        if (!$media) {
            return null;
        }

        $alt = method_exists($media, 'altTextResolved') ? (string) $media->altTextResolved() : '';
        if ($derivative === 'original'
            && $media->hasOriginal()
            && in_array($media->mediaType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
        ) {
            return $this->completeImageData([
                'url' => $media->originalUrl(),
                'type' => $media->mediaType(),
                'alt' => $alt,
            ], 'original/' . $media->filename());
        }

        if (!$media->hasThumbnails()) {
            return null;
        }
        $derivative = $derivative === 'original' ? 'large' : $derivative;
        return $this->completeImageData([
            'url' => $media->thumbnailUrl($derivative),
            'type' => 'image/jpeg',
            'alt' => $alt,
        ], $derivative . '/' . $media->storageId() . '.jpg');
    }

    /**
     * Add the size of the image when the file is stored locally.
     */
    protected function completeImageData(array $image, string $storagePath): ?array
    {
        // A relative url cannot be used by the social networks.
        if (substr((string) $image['url'], 0, 4) !== 'http') {
            return null;
        }
        $store = $this->getServiceLocator()->get('Omeka\File\Store');
        if ($store instanceof \Omeka\File\Store\Local) {
            $path = $store->getLocalPath($storagePath);
            $size = is_file($path) && is_readable($path) ? @getimagesize($path) : false;
            if ($size) {
                $image['width'] = $size[0];
                $image['height'] = $size[1];
                $image['type'] = $size['mime'] ?: $image['type'];
            }
        }
        return $image;
    }

    /**
     * Get the list of the distinct values of a property as a string.
     */
    protected function valueTexts(AbstractResourceEntityRepresentation $resource, string $term): string
    {
        return implode(', ', $this->valueList($resource, $term));
    }

    /**
     * Get the list of the distinct values of a property.
     */
    protected function valueList(AbstractResourceEntityRepresentation $resource, string $term): array
    {
        $texts = array_map(
            fn ($v) => (string) ($v->valueResource() ? $v->valueResource()->displayTitle() : ($v->value() ?? $v->uri())),
            $resource->value($term, ['all' => true])
        );
        return array_values(array_unique(array_filter(array_map('trim', $texts), 'strlen')));
    }

    /**
     * Check if the data of schema.org should be appended.
     */
    protected function isJsonLdEnabled(): bool
    {
        return (bool) $this->getServiceLocator()->get('Omeka\Settings\Site')->get('metatags_jsonld', false);
    }

    /**
     * Get the data of schema.org of a resource.
     *
     * The values are the ones of the other tags, so the mapping is the same.
     */
    protected function resourceJsonLd(PhpRenderer $view, AbstractResourceEntityRepresentation $resource, string $summary, string $authorTerm, string $dateTerm, string $rightsTerm): array
    {
        $license = null;
        if ($rightsTerm) {
            foreach ($resource->value($rightsTerm, ['all' => true]) as $value) {
                $uri = $value->uri() ?: (string) $value->value();
                if (preg_match('~^https?://~', $uri)) {
                    $license = $uri;
                    break;
                }
            }
        }

        $isPartOf = [];
        if ($resource instanceof ItemRepresentation) {
            $site = $view->site;
            foreach ($resource->itemSets() as $itemSet) {
                $isPartOf[] = [
                    '@type' => 'Collection',
                    'name' => (string) $itemSet->displayTitle(),
                    'url' => $site ? $this->absoluteUrl($view, $itemSet->siteUrl($site->slug())) : null,
                ];
            }
        }

        $publishers = array_map(fn ($v) => ['@type' => 'Organization', 'name' => $v], $this->valueList($resource, 'dcterms:publisher'));

        return [
            '@type' => $this->schemaType($resource),
            'name' => (string) $resource->displayTitle(),
            'description' => $summary,
            'url' => $this->tagValue($view, 'og:url'),
            'image' => $this->tagValue($view, 'og:image'),
            // The authors may be persons or organizations, so they are names.
            'author' => $this->valueList($resource, $authorTerm) ?: null,
            'datePublished' => $dateTerm ? $this->isoDate($resource, $dateTerm) : null,
            'inLanguage' => $this->valueList($resource, 'dcterms:language')[0] ?? null,
            'publisher' => $publishers ?: null,
            'keywords' => $this->valueTexts($resource, 'dcterms:subject') ?: null,
            'license' => $license,
            'isPartOf' => $isPartOf ?: null,
        ];
    }

    /**
     * Get the type of schema.org of a resource from its class or media type.
     */
    protected function schemaType(AbstractResourceEntityRepresentation $resource): string
    {
        if ($resource instanceof \Omeka\Api\Representation\ItemSetRepresentation) {
            return 'Collection';
        }
        if ($resource instanceof \Omeka\Api\Representation\MediaRepresentation) {
            $types = ['image' => 'ImageObject', 'video' => 'VideoObject', 'audio' => 'AudioObject'];
            return $types[strtok((string) $resource->mediaType(), '/')] ?? 'MediaObject';
        }
        $class = $resource->resourceClass();
        if (!$class) {
            return 'CreativeWork';
        }
        $term = $class->term();
        if (strpos($term, 'schema:') === 0) {
            return substr($term, 7);
        }
        $types = [
            'bibo:AcademicArticle' => 'ScholarlyArticle',
            'bibo:Article' => 'Article',
            'bibo:AudioDocument' => 'AudioObject',
            'bibo:AudioVisualDocument' => 'VideoObject',
            'bibo:Book' => 'Book',
            'bibo:Chapter' => 'Chapter',
            'bibo:Image' => 'ImageObject',
            'bibo:Letter' => 'Message',
            'bibo:Manuscript' => 'Manuscript',
            'bibo:Map' => 'Map',
            'bibo:Periodical' => 'Periodical',
            'bibo:Report' => 'Report',
            'bibo:Thesis' => 'Thesis',
            'bibo:Webpage' => 'WebPage',
            'dctype:Collection' => 'Collection',
            'dctype:Dataset' => 'Dataset',
            'dctype:Image' => 'ImageObject',
            'dctype:MovingImage' => 'VideoObject',
            'dctype:Software' => 'SoftwareApplication',
            'dctype:Sound' => 'AudioObject',
            'dctype:StillImage' => 'ImageObject',
            'dctype:Text' => 'CreativeWork',
        ];
        return $types[$term] ?? 'CreativeWork';
    }

    /**
     * Append the data of schema.org as json-ld, without the empty values.
     */
    protected function appendJsonLd(PhpRenderer $view, array $data): void
    {
        $clean = function (array $data) use (&$clean): array {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $value = $clean($value);
                }
                if ($value === null || $value === '' || $value === []) {
                    unset($data[$key]);
                } else {
                    $data[$key] = $value;
                }
            }
            return $data;
        };
        $data = ['@context' => 'https://schema.org'] + $clean($data);
        // The flags avoid to close the script with a value.
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $headScript = $view->headScript();
        $headScript->appendScript($json, 'application/ld+json', ['noescape' => true]);
        $items = $headScript->getContainer()->getArrayCopy();
        $this->ownTags[spl_object_id(end($items))] = true;
    }

    /**
     * Append an optional tag, that is removed if another module appends it.
     *
     * @see self::handleDuplicateTags()
     */
    protected function appendOwnName(\Laminas\View\Helper\HeadMeta $headMeta, string $name, string $content): void
    {
        $headMeta->appendName($name, $content);
        $items = $headMeta->getContainer()->getArrayCopy();
        $this->ownTags[spl_object_id(end($items))] = true;
    }

    /**
     * Get the content of the first meta tag with a name or a property.
     */
    protected function tagValue(PhpRenderer $view, string $key): ?string
    {
        foreach ($view->headMeta()->getContainer() as $item) {
            if (($item->{$item->type} ?? null) === $key) {
                return (string) $item->content;
            }
        }
        return null;
    }

    protected function absoluteUrl(PhpRenderer $view, string $url): string
    {
        return substr($url, 0, 4) === 'http' ? $url : $view->serverUrl() . $url;
    }

    /**
     * Append the tags for the citation of a document.
     *
     * The tags of Highwire Press are used by Google Scholar and by the managers
     * of references (Zotero, Mendeley). The tags of Dublin Core are used by
     * other harvesters. Each value is a distinct tag.
     *
     * @see https://scholar.google.com/intl/en/scholar/inclusion.html#indexing
     * @see https://www.dublincore.org/specifications/dublin-core/dc-html/
     */
    protected function appendCitation(PhpRenderer $view, ItemRepresentation $item, string $authorTerm, string $dateTerm): void
    {
        $headMeta = $view->headMeta();

        $this->appendOwnName($headMeta, 'citation_title', (string) $item->displayTitle());
        foreach ($this->valueList($item, $authorTerm) as $value) {
            $this->appendOwnName($headMeta, 'citation_author', $value);
        }
        // Google Scholar requires the format "2009/05/30" or "2009".
        $date = $this->isoDate($item, $dateTerm ?: 'dcterms:issued') ?? $this->isoDate($item, 'dcterms:date');
        if ($date) {
            $this->appendOwnName($headMeta, 'citation_publication_date', strtr(strtok(ltrim($date, '-'), 'T'), '-', '/'));
        }
        $map = [
            'dcterms:publisher' => 'citation_publisher',
            'dcterms:isPartOf' => 'citation_journal_title',
            'dcterms:language' => 'citation_language',
            'bibo:doi' => 'citation_doi',
            'bibo:isbn' => 'citation_isbn',
            'bibo:issn' => 'citation_issn',
            'bibo:volume' => 'citation_volume',
            'bibo:issue' => 'citation_issue',
            'bibo:pageStart' => 'citation_firstpage',
            'bibo:pageEnd' => 'citation_lastpage',
        ];
        foreach ($map as $term => $name) {
            foreach ($this->valueList($item, $term) as $value) {
                $this->appendOwnName($headMeta, $name, $value);
            }
        }
        foreach ($item->media() as $media) {
            if ($media->mediaType() === 'application/pdf' && $media->hasOriginal()) {
                $this->appendOwnName($headMeta, 'citation_pdf_url', $media->originalUrl());
                break;
            }
        }

        // The values of Dublin Core are output with the prefix "DCTERMS".
        $view->headLink(['rel' => 'schema.DCTERMS', 'href' => 'http://purl.org/dc/terms/']);
        foreach ($item->values() as $term => $propertyData) {
            if (strpos($term, 'dcterms:') !== 0) {
                continue;
            }
            $name = 'DCTERMS.' . substr($term, 8);
            foreach ($this->valueList($item, $term) as $value) {
                $this->appendOwnName($headMeta, $name, $value);
            }
        }
    }

    /**
     * Convert a locale into the format of Open Graph "language_TERRITORY".
     *
     * The territory is required, so the main one is added when missing.
     */
    protected function ogLocale(string $locale): ?string
    {
        if (!preg_match('~^([a-z]{2,3})(?:[_-][a-z]{4})?(?:[_-]([a-z]{2}))?(?:$|[_.@-])~i', $locale, $matches)) {
            return null;
        }
        $language = strtolower($matches[1]);
        if (!empty($matches[2])) {
            return $language . '_' . strtoupper($matches[2]);
        }
        $territories = [
            'ar' => 'AR',
            'br' => 'FR',
            'ca' => 'ES',
            'co' => 'FR',
            'cs' => 'CZ',
            'cy' => 'GB',
            'da' => 'DK',
            'el' => 'GR',
            'en' => 'US',
            'et' => 'EE',
            'eu' => 'ES',
            'fa' => 'IR',
            'ga' => 'IE',
            'gl' => 'ES',
            'he' => 'IL',
            'hi' => 'IN',
            'hy' => 'AM',
            'ja' => 'JP',
            'ka' => 'GE',
            'ko' => 'KR',
            'ms' => 'MY',
            'nb' => 'NO',
            'nn' => 'NO',
            'oc' => 'FR',
            'sl' => 'SI',
            'sq' => 'AL',
            'sr' => 'RS',
            'sv' => 'SE',
            'uk' => 'UA',
            'vi' => 'VN',
            'zh' => 'CN',
        ];
        return $language . '_' . ($territories[$language] ?? strtoupper($language));
    }

    /**
     * Get the query of the current page, without the empty values.
     *
     * The query of the uri is used, because the controllers of the lists add
     * the default sort and page to the parameters.
     */
    protected function currentQuery(): array
    {
        $request = $this->getServiceLocator()->get('Request');
        if (!$request instanceof \Laminas\Http\Request) {
            return [];
        }
        parse_str((string) $request->getUri()->getQuery(), $query);
        if (($query['page'] ?? null) === '1') {
            unset($query['page']);
        }
        return array_filter($query, fn ($v) => $v !== '' && $v !== []);
    }

    /**
     * Get the canonical url of the current page, with the query for the lists.
     *
     * The request uri is not used, since it is not available outside of http.
     */
    protected function currentUrl(PhpRenderer $view, array $query = []): ?string
    {
        try {
            $url = $view->url(null, [], $query ? ['query' => $query] : [], true);
        } catch (\Throwable $e) {
            return null;
        }
        return $url && substr($url, 0, 4) !== 'http'
            ? $view->serverUrl() . $url
            : $url;
    }

    /**
     * Append the links to the current page in the other languages.
     *
     * The groups of sites by language are managed by the module
     * Internationalisation. The page of a site without translation is skipped,
     * because the module links it to the current page.
     */
    protected function appendAlternateLanguages(PhpRenderer $view, string $url, array $query): void
    {
        $plugins = $view->getHelperPluginManager();
        if (!$plugins->has('languageList')) {
            return;
        }

        $site = $view->site;
        $currentSlug = $site ? $site->slug() : null;
        $currentPath = strtok($url, '?');
        $serverUrl = $view->serverUrl();

        $links = [];
        foreach ($plugins->get('languageList')('current_page') as $entry) {
            if (empty($entry['url']) || empty($entry['locale'])) {
                continue;
            }
            if ($entry['site'] === $currentSlug) {
                $links[(string) $entry['locale']] = $url;
                continue;
            }
            $path = strtok((string) $entry['url'], '?');
            if (substr($path, 0, 4) !== 'http') {
                $path = $serverUrl . $path;
            }
            if ($path === $currentPath) {
                continue;
            }
            // Same encoding than the router.
            // @see \Laminas\Uri\Uri::setQuery()
            $links[(string) $entry['locale']] = $query
                ? $path . '?' . strtr(http_build_query($query, '', '&'), ['+' => '%20'])
                : $path;
        }
        if (count($links) < 2) {
            return;
        }

        $headMeta = $view->headMeta();
        $currentLocale = $this->ogLocale((string) ($view->siteSetting('locale') ?: $view->setting('locale')));
        foreach ($links as $locale => $href) {
            $view->headLink(['rel' => 'alternate', 'hreflang' => strtr($locale, '_', '-'), 'href' => $href]);
            $ogLocale = $this->ogLocale($locale);
            if ($ogLocale && $ogLocale !== $currentLocale) {
                $headMeta->appendProperty('og:locale:alternate', $ogLocale);
            }
        }
    }

    /**
     * Get the first value of a property that is a date in the format ISO 8601.
     *
     * A date that is not a year, a month, a day or a date time, for example
     * "16th century" or "ca. 1890", is skipped, so no wrong date is output.
     */
    protected function isoDate(AbstractResourceEntityRepresentation $resource, string $term): ?string
    {
        foreach ($resource->value($term, ['all' => true]) as $value) {
            $date = trim((string) $value->value());
            if (preg_match('~^-?\d{4}(?:-\d{2}(?:-\d{2}(?:T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?)?)?$~', $date)) {
                return $date;
            }
        }
        return null;
    }

    /**
     * Get a plain text shortened to the recommended length for descriptions.
     */
    protected function shortText(string $text, int $length = 200): string
    {
        $text = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $short = mb_substr($text, 0, $length - 1);
        // Cut at the last space, if any, to avoid a truncated word.
        $space = mb_strrpos($short, ' ');
        return rtrim($space > $length / 2 ? mb_substr($short, 0, $space) : $short, ' ,;:.') . '…';
    }

    /**
     * @see \BlockPlus\View\Helper\PageMetadata
     *
     * @param SitePageRepresentation $page
     * @return \Omeka\Api\Representation\AssetRepresentation|\Omeka\Api\Representation\MediaRepresentation|null
     */
    protected function mainImage(SitePageRepresentation $page)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($page->blocks() as $block) {
            $layout = $block->layout();
            if ($layout === 'pageMetadata') {
                $asset = $block->dataValue('cover');
                if ($asset) {
                    try {
                        return $api->read('assets', ['id' => $asset])->getContent();
                    } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    }
                }
            } elseif ($layout === 'asset') {
                // The data of the core block are the list of attachments.
                foreach ($block->data() as $attachment) {
                    if (is_array($attachment) && !empty($attachment['id'])) {
                        try {
                            return $api->read('assets', ['id' => $attachment['id']])->getContent();
                        } catch (\Omeka\Api\Exception\NotFoundException $e) {
                        }
                    }
                }
                continue;
            } elseif ($layout === 'assets') {
                foreach ($block->dataValue('assets', []) as $asset) {
                    try {
                        return $api->read('assets', ['id' => $asset['asset']])->getContent();
                    } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    }
                }
                continue;
            }
            foreach ($block->attachments() as $attachment) {
                $media = $attachment->media();
                if ($media && ($media->hasThumbnails() || $media->thumbnail())) {
                    return $media;
                }
                $item = $attachment->item();
                if ($item) {
                    if ($thumbnail = $item->thumbnail()) {
                        return $thumbnail;
                    }
                    $media = $item->primaryMedia();
                    if ($media && ($media->hasThumbnails() || $media->thumbnail())) {
                        return $media;
                    }
                }
            }
        }
        return null;
    }
}
