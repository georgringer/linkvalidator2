.. include:: /Includes.rst.txt

.. _exclusion-types:

===============
Exclusion types
===============

The types of an :ref:`exclusion <exclusions>` are URL or domain, page and all
links. A matcher adds a type, for example a regular expression.

#.  Implement :php:`ExclusionMatcherInterface`. :php:`getType()` returns the
    value of the field `type`, :php:`matches()` decides for an exclusion and
    a link target. The class is tagged automatically.
#.  Add the item to the field `type` of
    `tx_linkvalidator2_exclusion` and a TCA type with the fields the matcher
    needs.

The scope of an exclusion, its page and the pages below, is handled for every
type. A matcher only decides whether a target matches.
