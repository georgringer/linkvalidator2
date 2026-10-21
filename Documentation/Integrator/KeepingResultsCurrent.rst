.. include:: /Includes.rst.txt

.. _keeping-results-current:

=======================
Keeping results current
=======================

Besides the regular run of the :ref:`queue <background-queue>`, results follow
what happens in the backend.

Saving records
==============

Saving a record in the backend checks it at once: internal links are checked
immediately, external URLs from the cache. A record with external URLs that
have no result yet is queued with priority, see
:ref:`background-priority`, and checked completely by a worker.

*   Deleting a record removes its results.
*   Copying, moving, restoring, localizing and publishing count as saving.
*   Only the live workspace is checked.

Changed targets
===============

If a target changes, for example a page is hidden or deleted, the records
that link to it are found through the reference index and queued with
priority, see :ref:`background-priority`. :guilabel:`Check links` reports
them as well.

.. important::

    Keep the reference index up to date after imports and other changes around
    the DataHandler, for example with
    :bash:`vendor/bin/typo3 referenceindex:update`. A record the index does
    not know is not found.

The reference index does not know external URLs. An external URL that turns
broken is therefore only noticed by a regular run, when the cache lifetime of
its result has passed.

Checking a single link again
============================

Every result in the report has a button :guilabel:`Check this link again`
next to :guilabel:`Ignore`. It checks the record of the result at once, for an
external URL with a new request even if the stored result would still be
valid. The result of the target is stored once, so every stored result for
the same target takes it, without checking the other records. The editor
sees the outcome as a notification. The button is only there if
:ref:`allowManualCheck <confval-linkvalidator2-allowmanualcheck>` is on.

Checking again from the module
==============================

A check started with :guilabel:`Check links` reuses the stored result of an
external URL as long as it is younger than the cache lifetime. The checkbox
:guilabel:`Request external URLs again` above the progress bar ignores the
stored results for that check. Each URL is still requested once per check,
however many records link to it.

Nothing is deleted beforehand. The results are replaced while the pages are
checked, so a check that is cancelled leaves the old results of the pages it
did not reach.

External requests
=================

*   Each URL is requested once and the result is reused for the
    :ref:`cache lifetime <confval-linkvalidator2-externalcachelifetime>`,
    however many records link to it.
*   `HEAD` is used first, `GET` follows on HTTP 405 and 501. Up to five
    redirects are followed.
*   `robots.txt` is not respected on purpose: these are single requests to
    linked URLs, not a crawler.
*   The User-Agent is :ref:`configurable
    <confval-linkvalidator2-externaluseragent>`.

Staging systems
===============

The tasks of the scheduler live in the database and a copy of the production
database brings them along. Do not run the worker and :bash:`fillqueue` on a
staging system with such a copy, otherwise it requests the external URLs of
the production site from there.
