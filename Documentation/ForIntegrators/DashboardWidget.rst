.. include:: /Includes.rst.txt

.. _dashboard-widget:

================
Dashboard widget
================

With EXT:dashboard installed, the widget :guilabel:`Broken links` can be added
to a dashboard. It lists the pages below a page with the most broken links,
with their number, and a button that opens the report for the page.

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

.. confval:: Pages shown
    :name: linkvalidator2-widget-limit
    :type: integer
    :Default: 10

    Number of pages in the list, the ones with the most broken links first.
    The widget tells how many more pages have broken links.

Permissions
===========

Users need access to the module :guilabel:`Check Links` and to the selected
page. The widget counts what the user may fix, like the report does:
:ref:`permissions <permissions>`. Notices and results that need no fixing are
not counted as broken links.
