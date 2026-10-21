.. include:: /Includes.rst.txt

.. _dashboard-widget:

================
Dashboard widget
================

With EXT:dashboard installed, the widget :guilabel:`Broken links` can be added
to a dashboard. It lists the broken links below a page by type of link
(external URLs, pages, files and so on) with their number. Every type links to
the report, filtered by that type, and a button opens the report for the page.
Both are only there for users who have access to the module.

The widget is optional. It is registered in `Configuration/Services.php` only
if the dashboard is there, the extension works without it.

Settings
========

Every instance of the widget has its own settings. They are changed with the
cog icon of the widget, like the ones of the RSS widget.

.. confval:: Title
    :name: linkvalidator2-widget-label
    :type: string
    :Default: (empty)

    Replaces the title of the widget.

.. confval:: Page
    :name: linkvalidator2-widget-page
    :type: page
    :Default: (none)

    The page and its subpages are counted. Without a page the widget asks for
    one.

.. confval:: Levels
    :name: linkvalidator2-widget-depth
    :type: integer
    :Default: 1 level

    Levels of subpages below the page: only the page, one to four levels or
    all levels.

Permissions
===========

The widget counts what the user may fix, like the report does:
:ref:`permissions <permissions>`. Users need access to the selected page.
Users without access to the module :guilabel:`Check Links` see the numbers,
but no links and no button. Notices and results that need no fixing are not
counted as broken links.
