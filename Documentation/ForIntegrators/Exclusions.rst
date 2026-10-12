.. include:: /Includes.rst.txt

.. _exclusions:

==========
Exclusions
==========

Exclusions keep links out of the checks, for example a login page that is
restricted on purpose, a site that blocks every bot, or an archive nobody
maintains.

An exclusion is a record on a page. It applies to the records on that page
and on all pages below it. On the root level it applies to all pages, only
administrators can create it there.

.. list-table::
    :header-rows: 1
    :widths: 20 80

    *   -   Type
        -   Excludes
    *   -   URL or domain
        -   Everything that starts with the value. :php:`example.org`
            excludes the domain and its subdomains, :php:`example.org/blog`
            everything below that path. The scheme and an anchor do not
            matter, so editors can paste a URL.
    *   -   Page
        -   Links to one page.
    *   -   All links
        -   Turns checking off for the pages and subpages, for example for an
            archive.

An exclusion can have a start and a stop time.

Editors
=======

Editors create exclusions with :guilabel:`Ignore` on a result: the URL, its
domain or the target page, for the page of the record and its subpages. The
view :guilabel:`Exclusions` in the module lists them with their levels,
shows the ones inherited from the pages above and lets an editor edit, hide
or delete them.

An editor needs

*   :php:`tables_modify` for :php:`tx_linkvalidator2_exclusion`
*   and edit permission on the content of the page the exclusion is stored on.

Without both the module offers nothing to change exclusions: no
:guilabel:`Ignore` in the results, no :guilabel:`Exclusions` button and no
link :guilabel:`Show exclusions` in the notice about links that are not
checked. The number in that notice is shown to everybody. The list of
exclusions shows the ones inherited from the pages above only for pages the
user may read and with :php:`tables_select` for the table.

What happens to the results
===========================

*   A new exclusion removes the results it matches at once, without checking
    anything: excluded links are not stored. This is fast, also with
    thousands of results. The counter "links are not checked because of
    exclusions" counts links that were fine only at the next check of their
    record.
*   Hiding or deleting an exclusion, or moving it to another page, makes the
    links it kept out checkable again. The records below its page are queued
    with priority, see :ref:`background-priority`.

Custom types
============

A new type is a class that implements
:php:`GeorgRinger\Linkvalidator2\Exclusion\ExclusionMatcherInterface`.
It is tagged automatically. The field :php:`type` of the table
:php:`tx_linkvalidator2_exclusion` needs the new item and a TCA type with the
fields of the exclusion. The scope (page and subpages) is handled for every
type.

A matcher can additionally implement
:php:`PrefilterableExclusionMatcherInterface` and name a text that the link
string of every link it matches contains. The database narrows the stored
results down with it before the matcher decides. Without it, all results of
the page tree are looked at.
