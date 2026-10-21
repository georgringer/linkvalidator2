.. include:: /Includes.rst.txt

.. _editor-module:

=============
Report module
=============

Open :guilabel:`Link Management > Check Links (new)` and select a page in the
page tree. The report lists the broken links of that page and, depending on
the selected levels, its subpages.

Checking
========

The report shows what the last check found. To check now, choose the levels
in the document header (:guilabel:`This page` up to :guilabel:`Infinite`)
and press :guilabel:`Check links`.

A progress bar shows the page that is checked. :guilabel:`Cancel` stops after
the current page, results of pages already checked stay. When the check is
done, a message tells how many links are broken and how many are new.

External URLs that were checked recently are not requested again. To request
them anyway, enable :guilabel:`Options > Request external URLs again` before
checking.

.. note::

    Every record on the pages is checked, but the report lists only what you
    may fix. The check also updates the results other editors see.

Reading the report
==================

.. list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Column
        -   Content
    *   -   Record
        -   The record and field that contains the link, with a link to edit
            it.
    *   -   Link
        -   The link text and the target. The target opens in a new tab.
    *   -   Status
        -   :guilabel:`Broken`, :guilabel:`Cannot be checked` or
            :guilabel:`Notice`.
    *   -   Problem
        -   What is wrong, for example :guilabel:`Page not found (HTTP 404)`.
    *   -   First detected
        -   Since when the link is reported.

Only :guilabel:`Broken` means the link is certainly wrong.
:guilabel:`Cannot be checked` means the server did not answer reliably
(timeout, access denied, error 5xx), the link may work.
:guilabel:`Notice` marks a target that is reachable but restricted, for
example only for logged-in users or not published yet.

Use the filter above the list to narrow it down by status, link type, table,
language or text. Columns can be sorted. :guilabel:`Reset` clears the filter.

An empty list says why it is empty: not checked yet, no broken links with the
time of the last check, or nothing matches the filter.

Actions
=======

:guilabel:`Check this link again`
    Checks the target now, also an external URL. All records that link to it
    show the new result. If the link works now, the result disappears.

:guilabel:`Show details of the check`
    Shows the target, HTTP status, error code and message. Useful when a
    link is marked :guilabel:`Cannot be checked`.

:guilabel:`Ignore`
    Stops reporting a URL, its domain or the target page for this page and
    its subpages. See below.

Ignoring links
--------------

:guilabel:`Ignore` creates an exclusion on the page of the record. It applies
to that page and its subpages. Excluded links are neither checked nor shown.
The report tells how many links are skipped this way.

:guilabel:`Show exclusions` in the document header lists the exclusions of
the page with the ones inherited from pages above. There you can edit, hide
or delete them. A link whose exclusion was removed is checked again by the
next background run.
