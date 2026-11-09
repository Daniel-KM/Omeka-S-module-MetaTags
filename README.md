Meta Tags (module for Omeka S)
==============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Meta Tags] is a module for [Omeka S] that adds [Open Graph] and [Twitter cards]
tags inside the header of all pages, item sets and items, so the title, the
description and the image or media are displayed automatically in the social
network threads.


Installation
------------

See general end user documentation for [installing a module].

The module [Common] must be installed first.

You may use the release zip to install it or clone the source via git.

* From the zip

Download the last release [MetaTags.zip] from the list of releases (the master
does not contain the dependency), and uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `MetaTags`.

```sh
cd modules
git clone https://gitlab.com/Daniel-KM/Omeka-S-module-MetaTags.git MetaTags
```

Then install it like any other Omeka module and follow the config instructions.

* For test

The module includes a test suite. Run it from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/MetaTags/phpunit.xml
```


Usage
-----

Simply enable or disable the output in the settings of each site (enabled by
default) and set your own params.

To display all site page metadata, the module uses the data set via the block
"page metadata" of the module [Block Plus]. If you want to include them in meta
tags, you should install it. Specific metadata are: cover image, page tags,
credits, description. Without them, the image of a page is the first image of
its blocks (asset, attachments), else the default image, and the description of
a page is the summary of the site.

The description is converted into plain text and shortened to 200 characters,
the recommended length for the cards of the social networks.

The simple Open Graph type of a resource is `video.other` when its main media is
a video, else `article`, because `image` and `audio` are not standard types and
`music.song` is reserved to music.

The option "Resource class" of the Open Graph type uses the term of the class,
for example `dctype:Image`. It is more precise, but it is not a standard type of
Open Graph, so some networks may ignore it.

The author and the rights of the resources are not output by default. They can
be set in the site settings with the values of a property, for example
`dcterms:creator` for the author and `dcterms:rights` for the rights.

For the files stored locally, the media type, the width and the height of the
image are added, with the alternative text when it is set. The locale of the
site is converted into the format of Open Graph, for example `fr` to `fr_FR`.
The url of the lists of resources keeps the query of the search.

For the search engines, a link `canonical` with the same url is added, and the
description of the site is used for the lists. The searches and the sorted lists
duplicate the main list, so they are marked `noindex, follow`: the engines do
not index them, but follow their links. The parameters are set in the site
settings, by default `fulltext_search`, `sort_by`, `sort_order` and `per_page`.
So the pagination and the lists filtered by a property, a class or an item set,
often linked from the menu, are still indexed. Leave the setting empty to index
all lists.

The date of the documents is output as `article:published_time` when its
property is set in the site settings, for example `dcterms:date` or
`dcterms:issued`. Only the dates formatted as year, month, day or date time (ISO
8601) are used, so a value like "16th century" is skipped. The dates of creation
and modification of the records are not used, because they are rarely the date
of the document. For the site pages, the dates of creation and modification of
the page are used, because they are the dates of its publication.

For the digital libraries and the repositories of articles, an option adds the
tags for the citation to the pages of items:
- the tags of [Highwire Press], used by [Google Scholar] and by the managers of
  references like Zotero: `citation_title`, `citation_author` (one tag by
  author, from the property of the author, else `dcterms:creator`),
  `citation_publication_date` (from the property of the date, else
  `dcterms:issued` or `dcterms:date`, formatted as ISO 8601),
  `citation_publisher`, `citation_journal_title` (`dcterms:isPartOf`),
  `citation_language`, the identifiers and pages of the vocabulary Bibo
  (`citation_doi`, `citation_isbn`, `citation_issn`, `citation_volume`,
  `citation_issue`, `citation_firstpage`, `citation_lastpage`), and
  `citation_pdf_url` for the first pdf of the item;
- the public values of Dublin Core, as `DCTERMS.title`, `DCTERMS.creator`, etc.

The tags of citation and of Dublin Core are not duplicated when another module
appends the same ones, for example [Resource Meta], that maps the properties to
any meta tags by resource template: the tag of the other module is kept,
whatever the case of the name (`dcterms.title` or `DCTERMS.title`).

Another option adds the structured data of [schema.org] as json-ld, used by the
search engines for rich results. The values are the same than the other tags:
name, description, url, image, authors, date of publication, language,
publisher, keywords (`dcterms:subject`), license (an uri of the property of the
rights) and collections. The type is set from the resource class, for example
`Book`, `ScholarlyArticle` or `ImageObject` for the classes of Bibo and Dublin
Core Types, or directly for a class of schema.org, else `CreativeWork`. The
media have the type of their file and the site pages are `WebPage`. The mapping
is fixed. The data are skipped when another module appends data of schema.org.

When the sites are grouped by language with the module [Internationalisation],
the links `alternate` with the attribute `hreflang` and the tags
`og:locale:alternate` point to the same page in the other languages. A site
without translation of the page is skipped.

The module [Sharing] appends its own Open Graph tags on the pages of items, of
media and of site pages. When the meta tags are enabled for the site, the
duplicate tags are removed and the ones of this module are kept.


Notes
-----

The Open Graph tags are used by most of the networks (Facebook, LinkedIn,
Bluesky, Mastodon, etc.). The Twitter tags are appended too for the title, the
description and the image, with the attribute `name`, according to the
[recommendations] of Twitter (X). The Twitter accounts are output only when they
are set in the site settings. The card is a large one (`summary_large_image`)
only when the page has an image with the derivative "large" or "original", else
a small one (`summary`).


TODO
----

- [x] Clarify the open graph type: use og type or the resource class?
- [x] Clarify who is the author of the document: the owner of the resource or the creator? Add a setting. The same for the rights.
- [x] Add data about media type and size of the media.
- [x] Avoid mixing with module Sharing.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Copyright
---------

* Copyright Daniel Berthereau, 2020-2026 (see [Daniel-KM] on GitLab)

This module was built for the digital library of the cooperative heritage [Fondation Maison de Salins].


[Meta Tags]: https://gitlab.com/Daniel-KM/Omeka-S-module-MetaTags
[Omeka S]: https://omeka.org/s
[Open Graph]: https://ogp.me
[Twitter cards]: https://developer.twitter.com/en/docs/twitter-for-websites/cards
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Block Plus]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlockPlus
[Sharing]: https://github.com/omeka-s-modules/Sharing
[Internationalisation]: https://gitlab.com/Daniel-KM/Omeka-S-module-Internationalisation
[Highwire Press]: https://scholar.google.com/intl/en/scholar/inclusion.html#indexing
[Google Scholar]: https://scholar.google.com
[Resource Meta]: https://omeka.org/s/modules/ResourceMeta
[schema.org]: https://schema.org
[MetaTags.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-MetaTags/-/releases
[recommendations]: https://developer.twitter.com/en/docs/twitter-for-websites/cards/guides/getting-started#opengraph
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-MetaTags/-/work_items
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Fondation Maison de Salins]: https://collections.maison-salins.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
