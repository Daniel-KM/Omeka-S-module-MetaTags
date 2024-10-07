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


Usage
-----

Simply enable or disable the output in the settings of each site (enabled by
default) and set your own params.

To display all site page metadata, the module uses the data set via the block
"page metadata" of the module [Block Plus]. If you want to include them in meta
tags, you should install it. Specific metadata are: cover image, page tags,
credits, description.


Notes
-----

The meta tags follows the [recommendations] of Twitter, so the Twitter tags are
not duplicated with the OpenGraph ones.


TODO
----

- [x] Clarify the open graph type: use og type or the resource class?
- [ ] Clarify who is the author of the document: the owner of the resource or the creator? Add a setting. The same for the rights.
- [ ] Add data about media type and size of the media.
- [ ] Avoid mixing with module Sharing.


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

* The library [jQuery-Autocomplete] is published under the license [MIT].


Copyright
---------

* Copyright Daniel Berthereau, 2020-2024 (see [Daniel-KM] on GitLab)

This module was built for the digital library of the cooperative heritage [Fondation Maison de Salins].


[Meta Tags]: https://gitlab.com/Daniel-KM/Omeka-S-module-MetaTags
[Omeka S]: https://omeka.org/s
[Open Graph]: https://ogp.me
[Twitter cards]: https://developer.twitter.com/en/docs/twitter-for-websites/cards
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Block Plus]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlockPlus
[MetaTags.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-MetaTags/-/releases
[recommendations]: https://developer.twitter.com/en/docs/twitter-for-websites/cards/guides/getting-started#opengraph
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-MetaTags/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: http://opensource.org/licenses/MIT
[Fondation Maison de Salins]: https://collections.maison-salins.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
