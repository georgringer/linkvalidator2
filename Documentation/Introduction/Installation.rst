.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

The extension can run next to the system extension `linkvalidator`. It uses
its own tables, module and commands.

.. code-block:: bash
    :caption: Install and create the tables

    composer require georgringer/linkvalidator2
    vendor/bin/typo3 extension:setup

Then:

1.  Give the backend user group access to the module
    :guilabel:`Link Management > Check Links (new)`.
2.  Select a page in the module and press :guilabel:`Check links`. This
    shows the result without any further setup.
3.  For a real site, set up the :ref:`background check
    <background-checking>`.

The optional :ref:`dashboard widget <dashboard-widget>` needs
`typo3/cms-dashboard`.
