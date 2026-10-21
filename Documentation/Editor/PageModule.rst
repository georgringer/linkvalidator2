.. include:: /Includes.rst.txt

.. _editor-page-module:

================================
Page module and rich text editor
================================

Page module
===========

If a page has broken links, the page module shows a callout above the content:
:guilabel:`3 broken links on this page`. :guilabel:`Show them in the report`
opens the report for the page.

The callout counts the links of the page itself, not of its subpages, and only
the ones you may fix. It is not shown in a workspace.

Integrators can :ref:`switch it off <configuration>`.

Rich text editor
================

When a record is opened, broken links in the rich text are marked in the text.
The marker uses the same checks as the report. For external URLs it uses
stored results only, opening a record never requests a URL.
