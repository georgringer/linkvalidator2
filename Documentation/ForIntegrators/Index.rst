.. include:: /Includes.rst.txt

.. _for-integrators:

===============
For integrators
===============

This chapter is about setting the extension up and running it: who may see
what, how links are checked in the background, and how to keep results
accurate.

How the extension works, in short:

*   Every record that can hold links is found through the TCA: fields of
    type :php:`link`, rich text fields and fields with the soft reference
    parsers `typolink`, `typolink_tag` or `url`. Nothing has to be
    configured per field, :ref:`excluded fields <configuration>` are the
    exception.
*   A link target is checked once and the result is reused for every record
    that links to it. External URLs are checked again after the cache
    lifetime.
*   Only broken links and notices are stored. A link that is fine leaves no
    row.
*   Only the live workspace is checked.

.. toctree::
    :maxdepth: 1
    :titlesonly:

    Permissions
    Configuration
    BackgroundChecking
    Exclusions
    KeepingResultsCurrent
    DashboardWidget
