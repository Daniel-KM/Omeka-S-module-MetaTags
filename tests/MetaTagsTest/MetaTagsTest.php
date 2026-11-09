<?php declare(strict_types=1);

namespace MetaTagsTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Meta tags appended to the head of the public pages.
 */
class MetaTagsTest extends AbstractHttpControllerTestCase
{
    protected $site;

    protected array $itemIds = [];

    protected array $assetIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->login();
        // A site created after the install: the settings of the module are not
        // set, so the default values are used.
        $this->site = $this->api()->create('sites', [
            'o:slug' => 'metatags-test',
            'o:title' => 'Meta tags test',
            'o:theme' => 'default',
            'o:is_public' => true,
        ])->getContent();
        // The request uri is not available outside of http, but it is required
        // by the module Sharing.
        if ($this->isSharingActive()) {
            $_SERVER['REQUEST_URI'] = '/';
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
        $_SERVER['HTTP_HOST'] = 'example.org';
    }

    protected function isSharingActive(): bool
    {
        $module = $this->getApplicationServiceLocator()->get('Omeka\ModuleManager')->getModule('Sharing');
        return $module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }

    public function tearDown(): void
    {
        $this->login();
        foreach ($this->itemIds as $id) {
            $this->api()->delete('items', $id);
        }
        foreach ($this->assetIds as $id) {
            $this->api()->delete('assets', $id);
        }
        $this->api()->delete('sites', $this->site->id());
        parent::tearDown();
    }

    protected function login(): void
    {
        $auth = $this->getApplicationServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    protected function api(): \Omeka\Api\Manager
    {
        return $this->getApplicationServiceLocator()->get('Omeka\ApiManager');
    }

    protected function siteSettings(): \Omeka\Settings\SiteSettings
    {
        $siteSettings = $this->getApplicationServiceLocator()->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($this->site->id());
        return $siteSettings;
    }

    protected function createItem(string $description = 'Description', array $data = []): \Omeka\Api\Representation\ItemRepresentation
    {
        $item = $this->api()->create('items', $data + [
            'o:site' => [['o:id' => $this->site->id()]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => 'Audit item']],
            'dcterms:description' => [['type' => 'literal', 'property_id' => 4, '@value' => $description]],
            'dcterms:subject' => [
                ['type' => 'literal', 'property_id' => 3, '@value' => 'History'],
                ['type' => 'literal', 'property_id' => 3, '@value' => 'Art'],
            ],
        ])->getContent();
        $this->itemIds[] = $item->id();
        return $item;
    }

    /**
     * Get the meta tags of the response, by name or property.
     */
    protected function metaTags(): array
    {
        $html = $this->getResponse()->getContent();
        preg_match_all('~<meta\s+(name|property)="([^"]+)"\s+content="([^"]*)"~', $html, $matches, PREG_SET_ORDER);
        $tags = [];
        foreach ($matches as $match) {
            $key = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
            $tags[$key][] = ['attribute' => $match[1], 'content' => html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5)];
        }
        return $tags;
    }

    protected function canonical(): ?string
    {
        $html = $this->getResponse()->getContent();
        return preg_match('~<link\s+href="([^"]+)"\s+rel="canonical"~', $html, $match)
            ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }

    public function testItemPage(): void
    {
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertResponseStatusCode(200);
        $tags = $this->metaTags();

        $this->assertSame('article', $tags['og:type'][0]['content']);
        $this->assertSame('Meta tags test', $tags['og:site_name'][0]['content']);
        $this->assertSame('Audit item', $tags['og:title'][0]['content']);
        $this->assertSame('Description', $tags['og:description'][0]['content']);
        $this->assertSame('Description', $tags['description'][0]['content']);
        $this->assertSame('History, Art', $tags['keywords'][0]['content']);

        // The url is built without the request uri.
        $this->assertSame('http://example.org/s/metatags-test/item/' . $item->id(), $tags['og:url'][0]['content']);
        $this->assertSame($tags['og:url'][0]['content'], $this->canonical());
        $this->assertArrayNotHasKey('robots', $tags);

        // The dates of the record are not the date of the document.
        $this->assertArrayNotHasKey('article:published_time', $tags);
        $this->assertArrayNotHasKey('article:modified_time', $tags);

        // The twitter tags use the attribute "name".
        $this->assertSame('name', $tags['twitter:title'][0]['attribute']);
        $this->assertSame('name', $tags['twitter:description'][0]['attribute']);
        // The item has no image, so the card is a small one.
        $this->assertSame(['summary'], array_column($tags['twitter:card'], 'content'));

        // The twitter accounts are not set, so they are not output.
        $this->assertArrayNotHasKey('twitter:domain', $tags);
        $this->assertArrayNotHasKey('twitter:site', $tags);
        $this->assertArrayNotHasKey('twitter:creator', $tags);
    }

    public function testTwitterAccounts(): void
    {
        $this->siteSettings()->set('metatags_twitter_site', '@my_site');
        $this->siteSettings()->set('metatags_media_derivative', 'medium');
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $tags = $this->metaTags();
        $this->assertSame('@my_site', $tags['twitter:site'][0]['content']);
        $this->assertSame('summary', $tags['twitter:card'][0]['content']);
    }

    public function testLongDescriptionIsShortened(): void
    {
        $description = '<p>' . str_repeat('A long description of the item. ', 20) . '</p>';
        $item = $this->createItem($description);
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $content = $this->metaTags()['og:description'][0]['content'];
        $this->assertLessThanOrEqual(200, mb_strlen($content));
        $this->assertStringEndsWith('…', $content);
        $this->assertStringStartsWith('A long description of the item. A long', $content);
        $this->assertStringNotContainsString('<p>', $content);
    }

    public function testDeletedDefaultAssetIsIgnored(): void
    {
        $this->siteSettings()->set('metatags_media_mode', 'static');
        $this->siteSettings()->set('metatags_media_asset', 999999);
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertResponseStatusCode(200);
        $tags = $this->metaTags();
        $this->assertArrayHasKey('og:title', $tags);
        $this->assertArrayNotHasKey('og:image', $tags);
    }

    public function testDisabled(): void
    {
        $this->siteSettings()->set('metatags_enabled', false);
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertResponseStatusCode(200);
        $tags = $this->metaTags();
        // The module Sharing may append its own Open Graph tags.
        $this->assertArrayNotHasKey('twitter:title', $tags);
        $this->assertArrayNotHasKey('keywords', $tags);
        if (!$this->isSharingActive()) {
            $this->assertArrayNotHasKey('og:title', $tags);
        }
    }

    public function testSitePage(): void
    {
        $page = $this->api()->create('site_pages', [
            'o:site' => ['o:id' => $this->site->id()],
            'o:slug' => 'about',
            'o:title' => 'About',
        ])->getContent();
        $this->dispatch('/s/metatags-test/page/' . $page->slug());
        $this->assertResponseStatusCode(200);
        $tags = $this->metaTags();
        $this->assertSame('article', $tags['og:type'][0]['content']);
        $this->assertSame('About', $tags['og:title'][0]['content']);
        $this->assertSame('http://example.org/s/metatags-test/page/about', $tags['og:url'][0]['content']);
        // The dates of a page are the dates of its publication.
        $this->assertSame($page->created()->format(DATE_ATOM), $tags['article:published_time'][0]['content']);
        // Without summary, there is no description.
        $this->assertArrayNotHasKey('description', $tags);
    }

    public function testListInsideAPageDoesNotReplaceTheTags(): void
    {
        $page = $this->api()->create('site_pages', [
            'o:site' => ['o:id' => $this->site->id()],
            'o:slug' => 'with-results',
            'o:title' => 'With results',
        ])->getContent();
        $this->dispatch('/s/metatags-test/page/' . $page->slug());

        // A block like the results of a search form triggers the event of the
        // lists with the controller of the page.
        $view = $this->getApplicationServiceLocator()->get('ViewRenderer');
        $count = fn () => count($view->headMeta()->getContainer()) + count($view->headLink()->getContainer());
        $before = $count();
        $module = $this->getApplication()->getServiceManager()->get('ModuleManager')->getModule('MetaTags');
        $module->handleSitePages(new \Laminas\EventManager\Event('view.browse.before', $view));
        $module->handleSiteResources(new \Laminas\EventManager\Event('view.browse.before', $view));
        $this->assertSame($before, $count());
    }

    public function testDescriptionOfPageIsTheSummaryOfTheSite(): void
    {
        $this->api()->update('sites', $this->site->id(), ['o:summary' => '<p>Summary of the site</p>'], [], ['isPartial' => true]);
        $page = $this->api()->create('site_pages', [
            'o:site' => ['o:id' => $this->site->id()],
            'o:slug' => 'without-summary',
            'o:title' => 'Without summary',
        ])->getContent();
        $this->dispatch('/s/metatags-test/page/' . $page->slug());
        $tags = $this->metaTags();
        $this->assertSame('Summary of the site', $tags['description'][0]['content']);
        $this->assertSame('Summary of the site', $tags['og:description'][0]['content']);
        $this->assertSame('Summary of the site', $tags['twitter:description'][0]['content']);
    }

    /**
     * Create an asset with a local png file of 3×2 pixels.
     */
    protected function createAsset(): int
    {
        $services = $this->getApplicationServiceLocator();
        $storageId = 'metatags-test-' . bin2hex(random_bytes(4));
        $image = imagecreatetruecolor(3, 2);
        imagepng($image, $services->get('Omeka\File\Store')->getLocalPath('asset/' . $storageId . '.png'));
        imagedestroy($image);

        $entityManager = $services->get('Omeka\EntityManager');
        $asset = new \Omeka\Entity\Asset();
        $asset->setName('Default image');
        $asset->setStorageId($storageId);
        $asset->setExtension('png');
        $asset->setMediaType('image/png');
        if (method_exists($asset, 'setAltText')) {
            $asset->setAltText('Logo of the site');
        }
        $entityManager->persist($asset);
        $entityManager->flush();
        $this->assetIds[] = $asset->getId();
        return $asset->getId();
    }

    public function testLocale(): void
    {
        $item = $this->createItem();
        $this->siteSettings()->set('locale', 'fr');
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertSame('fr_FR', $this->metaTags()['og:locale'][0]['content']);

        $this->siteSettings()->set('locale', 'en');
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertSame('en_US', $this->metaTags()['og:locale'][0]['content']);

        $this->siteSettings()->set('locale', 'pt_BR');
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertSame('pt_BR', $this->metaTags()['og:locale'][0]['content']);
    }

    public function testDateOfTheDocument(): void
    {
        $this->siteSettings()->set('metatags_date', 'dcterms:date');
        $item = $this->createItem('Description', [
            'dcterms:date' => [
                // A date that is not formatted is skipped.
                ['type' => 'literal', 'property_id' => 7, '@value' => '16th century'],
                ['type' => 'literal', 'property_id' => 7, '@value' => '1543-05-24'],
            ],
        ]);
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertSame('1543-05-24', $this->metaTags()['article:published_time'][0]['content']);

        $item = $this->createItem('Description', [
            'dcterms:date' => [['type' => 'literal', 'property_id' => 7, '@value' => 'ca. 1890']],
        ]);
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertArrayNotHasKey('article:published_time', $this->metaTags());
    }

    protected function propertyId(string $term): int
    {
        return $this->api()->search('properties', ['term' => $term, 'limit' => 1])->getContent()[0]->id();
    }

    public function testImageOfPageIsTheFirstImageOfTheBlocks(): void
    {
        $assetId = $this->createAsset();
        $page = $this->api()->create('site_pages', [
            'o:site' => ['o:id' => $this->site->id()],
            'o:slug' => 'with-asset',
            'o:title' => 'With asset',
            'o:block' => [
                ['o:layout' => 'html', 'o:data' => ['html' => '<p>Text</p>']],
                ['o:layout' => 'asset', 'o:data' => [['id' => $assetId, 'page' => null, 'alt_link_title' => '', 'caption' => '']]],
            ],
        ])->getContent();
        $this->dispatch('/s/metatags-test/page/' . $page->slug());
        $this->assertResponseStatusCode(200);
        $tags = $this->metaTags();
        $asset = $this->api()->read('assets', $assetId)->getContent();
        $this->assertSame($asset->assetUrl(), $tags['og:image'][0]['content'] ?? null);
    }

    public function testOpenGraphTypeOfVideo(): void
    {
        $item = $this->createItem('Description', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Media</p>']],
        ]);
        // The type is not checked by the ingester html.
        $this->getApplicationServiceLocator()->get('Omeka\Connection')->executeStatement(
            'UPDATE `media` SET `media_type` = "video/mp4" WHERE `item_id` = ?',
            [$item->id()]
        );
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertSame('video.other', $this->metaTags()['og:type'][0]['content']);
    }

    public function testCitation(): void
    {
        $this->siteSettings()->set('metatags_citation', true);
        $value = fn (string $term, string $text) => ['type' => 'literal', 'property_id' => $this->propertyId($term), '@value' => $text];
        $item = $this->createItem('Description', [
            'dcterms:creator' => [$value('dcterms:creator', 'Victor Hugo'), $value('dcterms:creator', 'Juliette Drouet')],
            'dcterms:issued' => [$value('dcterms:issued', '1862-04-03')],
            'dcterms:publisher' => [$value('dcterms:publisher', 'Lacroix')],
            'dcterms:isPartOf' => [$value('dcterms:isPartOf', 'Revue de test')],
        ]);
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $tags = $this->metaTags();
        $contents = fn (string $name) => array_column($tags[$name] ?? [], 'content');

        $this->assertSame(['Audit item'], $contents('citation_title'));
        // Each author is a distinct tag.
        $this->assertSame(['Victor Hugo', 'Juliette Drouet'], $contents('citation_author'));
        // Google Scholar requires the date with slashes.
        $this->assertSame(['1862/04/03'], $contents('citation_publication_date'));
        $this->assertSame(['Lacroix'], $contents('citation_publisher'));
        $this->assertSame(['Revue de test'], $contents('citation_journal_title'));
        $this->assertArrayNotHasKey('citation_pdf_url', $tags);

        // The values of Dublin Core.
        $this->assertSame(['Audit item'], $contents('DCTERMS.title'));
        $this->assertSame(['Victor Hugo', 'Juliette Drouet'], $contents('DCTERMS.creator'));
        $this->assertSame(['History', 'Art'], $contents('DCTERMS.subject'));
        $this->assertStringContainsString('rel="schema.DCTERMS"', $this->getResponse()->getContent());
    }

    /**
     * Get the data of schema.org of the response.
     */
    protected function jsonLd(): array
    {
        preg_match_all('~<script\s+type="([^"]*)"[^>]*>(.*?)</script>~s', $this->getResponse()->getContent(), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5) === 'application/ld+json') {
                $data = json_decode(trim($match[2]), true);
                if (($data['@context'] ?? null) === 'https://schema.org') {
                    return $data;
                }
            }
        }
        return [];
    }

    public function testJsonLdOfItem(): void
    {
        $this->siteSettings()->set('metatags_jsonld', true);
        $this->siteSettings()->set('metatags_author', 'dcterms:creator');
        $this->siteSettings()->set('metatags_date', 'dcterms:issued');
        $class = $this->api()->search('resource_classes', ['term' => 'bibo:Book', 'limit' => 1])->getContent()[0];
        $itemSet = $this->api()->create('item_sets', [
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => 'Collection test']],
        ])->getContent();
        $value = fn (string $term, string $text) => ['type' => 'literal', 'property_id' => $this->propertyId($term), '@value' => $text];
        $item = $this->createItem('Description', [
            'o:resource_class' => ['o:id' => $class->id()],
            'o:item_set' => [['o:id' => $itemSet->id()]],
            'dcterms:title' => [$value('dcterms:title', 'Les Misérables </script><b>x</b>')],
            'dcterms:creator' => [$value('dcterms:creator', 'Victor Hugo')],
            'dcterms:issued' => [$value('dcterms:issued', '1862')],
            'dcterms:publisher' => [$value('dcterms:publisher', 'Lacroix')],
            'dcterms:language' => [$value('dcterms:language', 'fr')],
        ]);
        try {
            $this->dispatch('/s/metatags-test/item/' . $item->id());
            $html = $this->getResponse()->getContent();
            // A value cannot close the script.
            $this->assertStringNotContainsString('</script><b>', $html);
            $data = $this->jsonLd();
            $this->assertSame('Book', $data['@type']);
            $this->assertSame('Les Misérables </script><b>x</b>', $data['name']);
            $this->assertSame('Description', $data['description']);
            $this->assertSame('http://example.org/s/metatags-test/item/' . $item->id(), $data['url']);
            $this->assertSame(['Victor Hugo'], $data['author']);
            $this->assertSame('1862', $data['datePublished']);
            $this->assertSame('fr', $data['inLanguage']);
            $this->assertSame([['@type' => 'Organization', 'name' => 'Lacroix']], $data['publisher']);
            $this->assertSame('History, Art', $data['keywords']);
            $this->assertSame('Collection', $data['isPartOf'][0]['@type']);
            $this->assertSame('Collection test', $data['isPartOf'][0]['name']);
            // The empty values are skipped.
            $this->assertArrayNotHasKey('image', $data);
            $this->assertArrayNotHasKey('license', $data);
        } finally {
            $this->api()->delete('item_sets', $itemSet->id());
        }
    }

    public function testJsonLdOfPage(): void
    {
        $this->siteSettings()->set('metatags_jsonld', true);
        $page = $this->api()->create('site_pages', [
            'o:site' => ['o:id' => $this->site->id()],
            'o:slug' => 'json-ld',
            'o:title' => 'Json-ld',
        ])->getContent();
        $this->dispatch('/s/metatags-test/page/' . $page->slug());
        $data = $this->jsonLd();
        $this->assertSame('WebPage', $data['@type']);
        $this->assertSame('Json-ld', $data['name']);
        $this->assertSame('http://example.org/s/metatags-test/page/json-ld', $data['url']);
        $this->assertSame($page->created()->format(DATE_ATOM), $data['datePublished']);
        $this->assertSame(['@type' => 'WebSite', 'name' => 'Meta tags test', 'url' => 'http://example.org/s/metatags-test'], $data['isPartOf']);
    }

    public function testNoJsonLdByDefault(): void
    {
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertSame([], $this->jsonLd());
    }

    public function testOwnTagsAreNotDuplicatedWithAnotherModule(): void
    {
        $this->siteSettings();
        $item = $this->createItem('Description', [
            'dcterms:creator' => [['type' => 'literal', 'property_id' => $this->propertyId('dcterms:creator'), '@value' => 'Victor Hugo']],
        ]);
        $view = $this->getApplicationServiceLocator()->get('ViewRenderer');
        $view->site = $this->site;
        $module = $this->getApplication()->getServiceManager()->get('ModuleManager')->getModule('MetaTags');
        (function () use ($view, $item): void {
            $this->appendCitation($view, $item, 'dcterms:creator', '');
            $this->appendJsonLd($view, ['@type' => 'Book', 'name' => 'Own']);
        })->call($module);

        // Tags appended by another module, like ResourceMeta.
        $view->headMeta()->appendName('dcterms.title', 'Other title');
        $view->headMeta()->appendName('citation_title', 'Other citation');
        $view->headScript()->appendScript('{"@context":"https://schema.org","@type":"Thing"}', 'application/ld+json', ['noescape' => true]);

        $module->handleDuplicateTags(new \Laminas\EventManager\Event('view.show.after', $view));

        $tags = [];
        foreach ($view->headMeta()->getContainer() as $tag) {
            $tags[$tag->{$tag->type}][] = $tag->content;
        }
        // The tags of the other module are kept, whatever the case.
        $this->assertSame(['Other title'], $tags['dcterms.title']);
        $this->assertArrayNotHasKey('DCTERMS.title', $tags);
        $this->assertSame(['Other citation'], $tags['citation_title']);
        // The tags that the other module does not append are kept.
        $this->assertSame(['Victor Hugo'], $tags['citation_author']);
        $this->assertSame(['Victor Hugo'], $tags['DCTERMS.creator']);
        // Only the data of schema.org of the other module are kept.
        $sources = array_map(fn ($script) => $script->source, $view->headScript()->getContainer()->getArrayCopy());
        $this->assertSame(['{"@context":"https://schema.org","@type":"Thing"}'], array_values(array_filter($sources)));
    }

    /**
     * Get the callables of a nested list of listeners.
     */
    protected function callables(array $listeners): array
    {
        $callables = [];
        foreach ($listeners as $listener) {
            if (is_callable($listener)) {
                $callables[] = $listener;
            } elseif (is_array($listener)) {
                $callables = array_merge($callables, $this->callables($listener));
            }
        }
        return $callables;
    }

    public function testDuplicateTagsAreRemovedWhenListenersAreDetached(): void
    {
        $shared = $this->getApplicationServiceLocator()->get('SharedEventManager');
        $identifier = 'Omeka\Controller\Site\Item';

        // Like the module BlocksDisposition, detach the listeners of modules.
        foreach ($this->callables($shared->getListeners([$identifier], 'view.show.after')) as $callable) {
            if (is_array($callable)) {
                $shared->detach($callable, $identifier, 'view.show.after');
            }
        }

        $kept = null;
        foreach ($this->callables($shared->getListeners([$identifier], 'view.show.after')) as $callable) {
            if ($callable instanceof \Closure && (new \ReflectionFunction($callable))->getClosureThis() instanceof \MetaTags\Module) {
                $kept = $callable;
            }
        }
        $this->assertNotNull($kept);

        $this->siteSettings();
        $view = $this->getApplicationServiceLocator()->get('ViewRenderer');
        $view->headMeta()->appendProperty('og:title', 'First');
        $view->headMeta()->appendProperty('og:title', 'Second');
        $kept(new \Laminas\EventManager\Event('view.show.after', $view));
        $titles = [];
        foreach ($view->headMeta()->getContainer() as $item) {
            if (($item->property ?? null) === 'og:title') {
                $titles[] = $item->content;
            }
        }
        $this->assertSame(['First'], $titles);
    }

    public function testNoCitationByDefault(): void
    {
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $tags = $this->metaTags();
        $this->assertArrayNotHasKey('citation_title', $tags);
        $this->assertArrayNotHasKey('DCTERMS.title', $tags);
    }

    public function testAuthorAndRights(): void
    {
        $this->siteSettings()->set('metatags_author', 'dcterms:creator');
        $this->siteSettings()->set('metatags_rights', 'dcterms:rights');
        $item = $this->createItem('Description', [
            'dcterms:creator' => [
                ['type' => 'literal', 'property_id' => 2, '@value' => 'Victor Hugo'],
                ['type' => 'literal', 'property_id' => 2, '@value' => 'Juliette Drouet'],
            ],
            'dcterms:rights' => [['type' => 'literal', 'property_id' => 15, '@value' => 'Public domain']],
        ]);
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $tags = $this->metaTags();
        $this->assertSame('Victor Hugo, Juliette Drouet', $tags['author'][0]['content']);
        $this->assertSame('Public domain', $tags['copyright'][0]['content']);
    }

    public function testNoAuthorByDefault(): void
    {
        $item = $this->createItem('Description', [
            'dcterms:creator' => [['type' => 'literal', 'property_id' => 2, '@value' => 'Victor Hugo']],
        ]);
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $tags = $this->metaTags();
        $this->assertArrayNotHasKey('author', $tags);
        $this->assertArrayNotHasKey('copyright', $tags);
    }

    public function testImageData(): void
    {
        $assetId = $this->createAsset();
        $this->siteSettings()->set('metatags_media_mode', 'static');
        $this->siteSettings()->set('metatags_media_asset', $assetId);
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $tags = $this->metaTags();
        $this->assertStringEndsWith('.png', $tags['og:image'][0]['content']);
        $this->assertSame($tags['og:image'][0]['content'], $tags['twitter:image'][0]['content']);
        $this->assertSame('image/png', $tags['og:image:type'][0]['content']);
        $this->assertSame('3', $tags['og:image:width'][0]['content']);
        $this->assertSame('2', $tags['og:image:height'][0]['content']);
        // The default derivative is "large", so the card is a large one.
        $this->assertSame(['summary_large_image'], array_column($tags['twitter:card'], 'content'));
        if (method_exists(\Omeka\Entity\Asset::class, 'setAltText')) {
            $this->assertSame('Logo of the site', $tags['og:image:alt'][0]['content']);
            $this->assertSame('Logo of the site', $tags['twitter:image:alt'][0]['content']);
        }
    }

    public function testDuplicateTagsAreRemoved(): void
    {
        $services = $this->getApplicationServiceLocator();
        $this->siteSettings();
        $view = $services->get('ViewRenderer');
        $headMeta = $view->headMeta();
        $headMeta->appendProperty('og:type', 'article');
        $headMeta->appendProperty('og:title', 'Audit item');
        $headMeta->appendName('twitter:title', 'Audit item');
        // Tags appended later by the module Sharing.
        $headMeta->appendProperty('og:type', 'website');
        $headMeta->appendProperty('og:title', 'Other title');
        $headMeta->appendProperty('og:audio', 'https://example.org/audio.mp3');
        $headMeta->appendName('description', 'First');
        $headMeta->appendName('description', 'Second');

        $module = $this->getApplication()->getServiceManager()->get('ModuleManager')->getModule('MetaTags');
        $module->handleDuplicateTags(new \Laminas\EventManager\Event('view.show.after', $view));

        $tags = [];
        foreach ($headMeta->getContainer() as $item) {
            $tags[$item->{$item->type}][] = $item->content;
        }
        $this->assertSame([
            'og:type' => ['article'],
            'og:title' => ['Audit item'],
            'twitter:title' => ['Audit item'],
            'og:audio' => ['https://example.org/audio.mp3'],
            // Only the Open Graph and Twitter tags are checked.
            'description' => ['First', 'Second'],
        ], $tags);
    }

    public function testSharingTagsAreNotDuplicated(): void
    {
        if (!$this->isSharingActive()) {
            $this->markTestSkipped('Requires module Sharing.');
        }
        $item = $this->createItem();
        $this->dispatch('/s/metatags-test/item/' . $item->id());
        $this->assertResponseStatusCode(200);
        foreach ($this->metaTags() as $key => $values) {
            if (preg_match('~^(?:og|twitter):~', $key)) {
                $this->assertCount(1, $values, $key);
            }
        }
        $this->assertSame('article', $this->metaTags()['og:type'][0]['content']);
    }

    public function testSiteSettingsForm(): void
    {
        $this->dispatch('/admin/site/s/metatags-test/edit');
        $this->assertResponseStatusCode(200);
        $html = $this->getResponse()->getContent();
        $this->assertStringContainsString('name="metatags_author"', $html);
        $this->assertStringContainsString('name="metatags_rights"', $html);
        $this->assertStringContainsString('name="metatags_date"', $html);
        $this->assertStringContainsString('name="metatags_citation"', $html);
        $this->assertStringContainsString('name="metatags_jsonld"', $html);
        $this->assertStringContainsString('value="fulltext_search&#x20;sort_by&#x20;sort_order&#x20;per_page"', $html);
        $this->assertStringNotContainsString('metatags_twitter_domain', $html);
    }

    public function testItemBrowse(): void
    {
        // A local version of the view of the core may use the module ContactUs.
        $view = OMEKA_PATH . '/application/view/omeka/site/item/browse.phtml';
        if (strpos((string) file_get_contents($view), 'contactUsSelector') !== false
            && !$this->getApplicationServiceLocator()->get('ViewHelperManager')->has('contactUsSelector')
        ) {
            $this->markTestSkipped('The local view of the core requires the module ContactUs.');
        }
        $this->api()->update('sites', $this->site->id(), ['o:summary' => 'Summary of the site'], [], ['isPartial' => true]);
        $this->createItem();
        $this->dispatch('/s/metatags-test/item');
        $this->assertResponseStatusCode(200);
        $tags = $this->metaTags();
        $this->assertSame('Summary of the site', $tags['description'][0]['content']);
        $this->assertSame('http://example.org/s/metatags-test/item', $tags['og:url'][0]['content']);
        $this->assertArrayNotHasKey('robots', $tags);

        // The pagination is indexed, not the searches.
        $this->dispatch('/s/metatags-test/item?page=2');
        $tags = $this->metaTags();
        $this->assertArrayNotHasKey('robots', $tags);
        $this->assertSame('http://example.org/s/metatags-test/item?page=2', $tags['og:url'][0]['content']);

        $this->dispatch('/s/metatags-test/item?fulltext_search=art&sort_by=&page=1');
        $tags = $this->metaTags();
        $this->assertSame('noindex, follow', $tags['robots'][0]['content']);
        $this->assertSame('http://example.org/s/metatags-test/item?fulltext_search=art', $tags['og:url'][0]['content']);
        $this->assertSame($tags['og:url'][0]['content'], $this->canonical());

        // The filtered lists are indexed by default.
        $this->dispatch('/s/metatags-test/item?resource_class_id=1');
        $this->assertArrayNotHasKey('robots', $this->metaTags());

        $this->siteSettings()->set('metatags_noindex_params', []);
        $this->dispatch('/s/metatags-test/item?fulltext_search=art');
        $this->assertArrayNotHasKey('robots', $this->metaTags());
    }

    /**
     * Get the links and the alternate locales appended for the other languages.
     *
     * The helper of the module Internationalisation is simulated.
     */
    protected function alternateLanguages(string $url, array $query = []): array
    {
        $services = $this->getApplicationServiceLocator();
        $this->siteSettings()->set('locale', 'fr');
        $view = $services->get('ViewRenderer');
        $view->site = $this->site;
        $plugins = $view->getHelperPluginManager();
        $plugins->setAllowOverride(true);
        $plugins->setService('languageList', new class extends \Laminas\View\Helper\AbstractHelper {
            public function __invoke(?string $type = null): array
            {
                return [
                    ['site' => 'metatags-test', 'locale' => 'fr', 'url' => '/s/metatags-test/page/about'],
                    ['site' => 'english', 'locale' => 'en_US', 'url' => '/s/english/page/about-us?x=1'],
                    // A site without translation links to the current page.
                    ['site' => 'breton', 'locale' => 'br', 'url' => '/s/metatags-test/page/about'],
                ];
            }
        });

        $module = $this->getApplication()->getServiceManager()->get('ModuleManager')->getModule('MetaTags');
        (fn () => $this->appendAlternateLanguages($view, $url, $query))->call($module);

        $links = [];
        foreach ($view->headLink()->getContainer() as $item) {
            if (($item->rel ?? null) === 'alternate' && isset($item->hreflang)) {
                $links[$item->hreflang] = $item->href;
            }
        }
        $locales = [];
        foreach ($view->headMeta()->getContainer() as $item) {
            if (($item->property ?? null) === 'og:locale:alternate') {
                $locales[] = $item->content;
            }
        }
        return [$links, $locales];
    }

    public function testAlternateLanguages(): void
    {
        [$links, $locales] = $this->alternateLanguages('http://example.org/s/metatags-test/page/about');
        $this->assertSame([
            'fr' => 'http://example.org/s/metatags-test/page/about',
            'en-US' => 'http://example.org/s/english/page/about-us',
        ], $links);
        $this->assertSame(['en_US'], $locales);
    }

    public function testAlternateLanguagesKeepTheQueryOfLists(): void
    {
        [$links] = $this->alternateLanguages('http://example.org/s/metatags-test/item?fulltext_search=a%20b', ['fulltext_search' => 'a b']);
        $this->assertSame('http://example.org/s/metatags-test/item?fulltext_search=a%20b', $links['fr']);
        $this->assertSame('http://example.org/s/english/page/about-us?fulltext_search=a%20b', $links['en-US']);
    }

    public function testAlternateLocalesAreNotDeduplicated(): void
    {
        $this->siteSettings();
        $view = $this->getApplicationServiceLocator()->get('ViewRenderer');
        $headMeta = $view->headMeta();
        $headMeta->appendProperty('og:locale:alternate', 'en_US');
        $headMeta->appendProperty('og:locale:alternate', 'br_FR');
        $module = $this->getApplication()->getServiceManager()->get('ModuleManager')->getModule('MetaTags');
        $module->handleDuplicateTags(new \Laminas\EventManager\Event('view.show.after', $view));
        $this->assertCount(2, $headMeta->getContainer());
    }
}
