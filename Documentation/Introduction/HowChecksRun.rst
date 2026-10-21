.. include:: /Includes.rst.txt

.. _how-checks-run:

===================
How checks are run
===================

All ways to check links end in the same step: one record is checked. They
differ in which records they pick and how many can be checked.

.. list-table::
    :header-rows: 1
    :widths: 20 40 40

    *   -   Way
        -   Use it for
        -   Details
    *   -   Backend module
        -   Small areas, a quick look after editing
        -   :ref:`Editors <editor-module>`. The browser checks page by page,
            so large trees do not time out.
    *   -   Message queue
        -   Whole sites, regular runs
        -   :ref:`Integrators <background-queue>`. One command fills the
            queue, one or more workers check it.
    *   -   CLI command
        -   Scripts, debugging, one record
        -   :ref:`linkvalidator2:check <background-command>`. Checks right
            away in one process.
    *   -   Saving a record
        -   Keeping results current
        -   :ref:`Automatic <keeping-results-current>`. Internal links are
            checked at once, external URLs from stored results.

.. figure:: /Images/Overview.svg
    :alt: Four ways to start a check call the same method, which writes
          the tables target, broken_link and record_status.
    :class: with-border

    Every way ends in :php:`RecordCheckService::checkRecord()`. Saving a
    record uses stored results for external URLs only.

What happens to a record
========================

#.  The links are found in the fields the TCA shows for the record type:
    link fields, rich text, plain URLs and shortcut pages.
#.  Each link is resolved with the core `LinkService`, for example to a page,
    a record, a file or an external URL.
#.  Links that match an :ref:`exclusion <exclusions>` are skipped.
#.  The target is checked. Results of external URLs are reused for
    :ref:`some time <confval-linkvalidator2-externalcachelifetime>`.
#.  The stored results of the record are replaced. A fixed link disappears
    without further steps.

A target is stored once, however many records link to it. Only problems are
stored. See :ref:`what counts as broken <configuration>` for the statuses.
