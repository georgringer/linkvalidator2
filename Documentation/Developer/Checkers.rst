.. include:: /Includes.rst.txt

.. _checkers:

========
Checkers
========

A checker decides whether the target of a link is fine. There is one for every
link type that is resolved by the :php:`LinkService`: pages, records, files
and external URLs. Extensions add checkers for their own link types or for
types that need a different approach, for example an external service that
checks URLs which refuse the own request.

Writing a checker
=================

A checker implements :php:`LinkCheckerInterface`. It is tagged automatically,
a class in an extension with the usual :file:`Configuration/Services.yaml`
(:yaml:`autoconfigure: true`) is enough.

..  code-block:: php

    final readonly class ProductLinkChecker implements LinkCheckerInterface
    {
        public function getSupportedTypes(): array
        {
            return ['product'];
        }

        public function check(LinkTarget $target, LinkCandidate $source): CheckResult
        {
            return $this->productExists((int)$target->resolved['uid'])
                ? CheckResult::ok()
                : CheckResult::broken('product.missing');
        }
    }

The result is one of:

.. list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Result
        -   Meaning
    *   -   :php:`CheckResult::ok()`
        -   The target is fine.
    *   -   :php:`CheckResult::broken()`
        -   The target is broken. It takes an error type and details; the
            details are shown in the report.
    *   -   :php:`CheckResult::unknown()`
        -   The checker could not decide, for example because the server
            refuses the request.
    *   -   :php:`CheckResult::info()`
        -   The target can be reached, with a restriction editors should know
            about.
    *   -   :php:`CheckResult::skip()`
        -   Not responsible or not available. Nothing is stored.

Several checkers for a type
===========================

More than one checker can be registered for a type. They are asked in the
order of the :yaml:`priority` of their service tag, the highest first. The core
checker of external URLs has the priority 0.

..  code-block:: yaml

    MyVendor\MyExtension\Check\MyUrlChecker:
        autoconfigure: false
        tags:
            -   name: 'linkvalidator2.checker'
                priority: -100

The chain ends with the first result that is not :php:`unknown` or
:php:`skip`. If a checker returns :php:`unknown`, the next one is asked and
the last :php:`unknown` is kept if nobody decides. If every checker skips, the
link is neither checked nor reported.

.. note::

    Set :yaml:`autoconfigure: false` when the tag is written out. Otherwise the
    checker is tagged a second time without the priority and asked twice.

Caching
=======

Results of external URLs (link type :php:`url`) are stored per target and
reused for the time set in the extension configuration
:ref:`externalCacheLifetime
<confval-linkvalidator2-externalcachelifetime>`, whichever checker produced them. Checks of
other link types always run.

Example: an external service
============================

The extension :file:`linkvalidator2_httpstatus_io` is an experiment for URLs
that refuse the own request, for example behind Cloudflare. It asks the API of
`httpstatus.io <https://httpstatus.io/api/documentation>`__ with the priority
-100, so it is only reached if the own check ended with :php:`unknown`. It
skips itself without an access key, for domains that are not allowed and when
the API gives no usable answer. Answers are stored as files in
:file:`var/statuscode_io/`, which flushing the caches does not remove.
