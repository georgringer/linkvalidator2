.. include:: /Includes.rst.txt

.. _permissions:

===========
Permissions
===========

Editors get the module :guilabel:`Link Management > Check Links` through
their backend user group, like any other module.

An editor sees only the broken links they may fix:

*   For fields of pages: edit permission on the page.
*   For fields of other records: edit permission on the content of the page.
*   The table must be in the allowed tables to modify of the user.
*   Excluded fields, :php:`authMode` values and languages of the user are
    respected.

.. note::

    All records are checked, whoever starts the check. The permissions only
    filter what the report shows. A check started by an editor therefore
    updates results other editors see.

Restricting manual checks
=========================

If links should only be checked in the background, switch off
:ref:`allowManualCheck <confval-linkvalidator2-allowmanualcheck>`. The
level selection and the button :guilabel:`Check links` disappear from the
module and the AJAX routes answer with HTTP 403. The report itself stays
available.
