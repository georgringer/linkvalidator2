.. include:: /Includes.rst.txt

.. _mail-report:

===========
Mail report
===========

:bash:`linkvalidator2:report` mails the broken links of a page and its
subpages. One call sends one report to one group of recipients. For several
areas or recipients, make several calls, for example one scheduler task
:guilabel:`Execute console commands` each.

.. code-block:: bash
    :caption: Examples

    # every Monday: everything below page 1287 that is broken for a week
    vendor/bin/typo3 linkvalidator2:report 1287 --depth=99 \
        --to=editor@example.org --min-age="7 days"

    # every day: the links that became broken since yesterday
    vendor/bin/typo3 linkvalidator2:report 1287 --depth=99 \
        --to=editor@example.org --max-age="1 day"

The report uses the same filters as the module. Nothing is stored: choose the
interval of the task and the age to match, a missed run is not made up for.

.. note::

    The recipient gets everything the filters select, there is no permission
    check per recipient. Use :bash:`--include-tables` and
    :bash:`--exclude-tables` to decide what an address receives.

Options
=======

.. list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Option
        -   Meaning
    *   -   :bash:`<page>`
        -   Page to start at. Default depth is `0`, the page only.
    *   -   :bash:`--depth`
        -   Levels of subpages to include.
    *   -   :bash:`--to`
        -   Recipients, repeat the option or separate by comma.
    *   -   :bash:`--from`, :bash:`--subject`
        -   Sender and subject. Defaults: the system sender and
            :php:`Broken links: <page title>`.
    *   -   :bash:`--status`
        -   `broken` (default), `unknown`, `info` or `all`.
    *   -   :bash:`--link-type`, :bash:`--language`, :bash:`--search`
        -   Same as the filters of the module.
    *   -   :bash:`--include-tables`, :bash:`--exclude-tables`
        -   Comma-separated tables. Excluding is applied after including.
    *   -   :bash:`--min-age`, :bash:`--max-age`
        -   Only links broken for at least or at most this long, for example
            `7 days`. Together they form a range.
    *   -   :bash:`--content`
        -   `inline` (default) lists the links in the mail, `attach` sends a
            summary and all links as CSV, `both` does both.
    *   -   :bash:`--inline-limit`
        -   Most links listed in the text, default `50`.
    *   -   :bash:`--send-empty`
        -   Send the mail also if there is nothing to report.
    *   -   :bash:`--template`, :bash:`--mail-language`
        -   Fluid email template (default `BrokenLinksReport`) and language
            key of its texts.
    *   -   :bash:`--base-url`
        -   Host of the backend for pages that belong to no site.

Links in the mail
=================

The mail contains the complete target URL and links to edit the record and to
open the report. Both need the host of the backend. It is taken from the site
of the page, from :php:`BE/entryPoint` if that is a full URL, or from
:bash:`--base-url`.

To change the text, copy the template `BrokenLinksReport` and add its path to
:php:`$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths']`.

Staging
=======

A staging system with a database copied from live would mail the same links.
Do not set up the cron job there, or send the mails to a catcher with
:php:`MAIL.transport` of that environment.
