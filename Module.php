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
            $primary = $resource->primaryMedia();
            if ($primary) {
                $mediaType = $primary->mediaType();
                $main = strtok((string) $mediaType, '/');
                $map = ['audio' => 'audio', 'video' => 'video', 'image' => 'image'];
                $ogType = $map[$main] ?? 'article';
            } else {
                $ogType = 'article';
            }
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
    }

    public function handleSiteResources(Event $event): void
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true)) {
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

        $mediaMode = $siteSettings->get('metatags_media_mode', 'thumbnail');

        $plugins = $view->getHelperPluginManager();
        if (!$plugins->has('pageMetadata')) {
            if ($mediaMode === 'none') {
                return;
            }
            if ($mediaMode === 'thumbnail'
                && ($value = $this->mainImage($page))
                && ($image = $this->imageData($value, $siteSettings->get('metatags_media_derivative', 'large')))
            ) {
                $this->appendImage($view, $image);
                return;
            }
            $this->appendThumbnailDefault($view);
            return;
        }

        $pageMetadata = $plugins->get('pageMetadata');
        if ($summary = $this->shortText((string) $pageMetadata('summary', $page))) {
            $headMeta->appendName('description', $summary);
            $headMeta->appendProperty('og:description', $summary);
            $headMeta->appendName('twitter:description', $summary);
        }

        if ($mediaMode === 'thumbnail'
            && ($thumbnail = $pageMetadata('main_image', $page))
            && ($image = $this->imageData($thumbnail, $siteSettings->get('metatags_media_derivative', 'large')))
        ) {
            $this->appendImage($view, $image);
        } elseif ($mediaMode !== 'none') {
            $this->appendThumbnailDefault($view);
        }

        if ($value = $pageMetadata('tags')) {
            if (is_array($value)) {
                $value = implode(', ', array_unique(array_filter(array_map('trim', $value), 'strlen')));
            }
            $headMeta->appendName('keywords', $value);
        }

        if ($value = $pageMetadata('credits')) {
            $headMeta->appendName('author', $value);
        }
    }

    public function handleSitePages(Event $event): void
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\Settings\Site');
        if (!$siteSettings->get('metatags_enabled', true)) {
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
     * Keep only the first Open Graph and Twitter tags of each name.
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
            if (preg_match('~^(?:og|twitter):~', $key)) {
                if (isset($keys[$key])) {
                    continue;
                }
                $keys[$key] = true;
            }
            $items[] = $item;
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
        if ($value = $this->ogLocale((string) ($siteSettings->get('locale') ?: $view->setting('locale')))) {
            $headMeta->appendProperty('og:locale', $value);
        }
        $query = $withQuery ? $this->currentQuery() : [];
        if ($url = $this->currentUrl($view, $query)) {
            $headMeta->appendProperty('og:url', $url);
        }

        $headMeta->appendName('twitter:card', in_array($derivative, ['original', 'large']) ? 'summary_large_image' : 'summary');
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
        $texts = array_map(
            fn ($v) => (string) ($v->valueResource() ? $v->valueResource()->displayTitle() : ($v->value() ?? $v->uri())),
            $resource->value($term, ['all' => true])
        );
        return implode(', ', array_unique(array_filter(array_map('trim', $texts), 'strlen')));
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
