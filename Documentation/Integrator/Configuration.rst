.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

All settings are in the extension configuration
(:guilabel:`Admin Tools > Settings > Extension Configuration >
linkvalidator2`). There is no Page TSconfig: the `mod.linkvalidator.*`
options of the system extension do not exist any more.

.. confval:: excludedFields
    :name: linkvalidator2-excludedfields
    :type: string
    :Default: (empty)

    Comma-separated list of :php:`table.field` or :php:`table.*` that are
    never checked, for example
    :php:`tt_content.header_link, tx_news_domain_model_news.*`.

.. confval:: allowManualCheck
    :name: linkvalidator2-allowmanualcheck
    :type: boolean
    :Default: 1

    Editors can start a check from the backend module. Switch it off if
    links are only checked in the background.

.. confval:: pageModuleCallout
    :name: linkvalidator2-pagemodulecallout
    :type: boolean
    :Default: 1

    Shows the number of broken links of a page in the page module. Page
    TSconfig :typoscript:`mod.linkvalidator2.pageModuleCallout = 0|1`
    overrides it for a page tree, in both directions.

.. confval:: externalCacheLifetime
    :name: linkvalidator2-externalcachelifetime
    :type: integer
    :Default: 168

    Hours a result of an external URL is reused before the URL is requested
    again. 168 hours are seven days. `0` requests every URL again in every run.

.. confval:: externalTimeout
    :name: linkvalidator2-externaltimeout
    :type: integer
    :Default: 10

    Seconds a request to an external URL may take in total. A URL that does
    not answer in time is reported as "unknown", not as broken.

.. confval:: externalUserAgent
    :name: linkvalidator2-externaluseragent
    :type: string
    :Default: TYPO3 LinkValidator

    Sent with every request to an external URL. Name your project in it if
    the linked sites should be able to tell who is requesting.

What counts as broken
=====================

A target is broken only if it is certainly missing: HTTP 404 and 410, an
unknown host, a missing, deleted or hidden page, a missing file. Everything
that may work for a visitor is "unknown": 401, 403, 429, 5xx, TLS problems and
timeouts. Targets that are reachable but restricted (login required, not
published yet, expired) are shown as "notice". Neither counts as a broken
link.
