.. include:: /Includes.rst.txt

.. _start:

=======================
LinkValidator (rewrite)
=======================

:Extension key:
    linkvalidator2

:Package name:
    georgringer/linkvalidator2

:Version:
    |release|

:Language:
    en

:Author:
    Georg Ringer

:License:
    This document is published under the
    `GNU General Public License v2.0 or later
    <https://www.gnu.org/licenses/gpl-2.0.html>`__.

----

Checks the links in records, stores the broken ones and reports them to
editors. It is a rewrite of the system extension `linkvalidator`: the
fields to check come from the TCA, targets are checked once and reused,
and large installations are checked in the background by several workers.

**Requirements:** TYPO3 14.3 or main (15), PHP 8.5.

.. card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    .. card:: For integrators

        Set up the extension: permissions, configuration, checking in the
        background, checkers and exclusions.

        .. card-footer:: :ref:`Read more <for-integrators>`
            :button-style: btn btn-secondary stretched-link

.. toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    ForIntegrators/Index
