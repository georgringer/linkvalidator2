.. include:: /Includes.rst.txt

.. _background-checking:

===================
Background checking
===================

Editors can check a page tree from the module, but on a real site links are
checked in the background. There are two ways, and they share the code that
checks a record.

.. list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Way
        -   When to use it
    *   -   :ref:`The message queue <background-queue>`
        -   Production. Several workers, can be stopped at any time,
            large pages are split.
    *   -   :ref:`The check command <background-command>`
        -   Scripts, a first look, installations without a worker. One
            process, checks right away.

.. _background-queue:

The message queue
=================

The queue is the Messenger of the TYPO3 core (the command
`messenger:consume`). One command fills it, a worker takes the messages and
checks the records.

.. code-block:: bash
    :caption: Fill the queue, then run a worker

    vendor/bin/typo3 linkvalidator2:fillqueue 1287
    vendor/bin/typo3 messenger:consume linkvalidator2_priority doctrine \
        --time-limit=240

:bash:`fillqueue` takes the page to start at. It walks the page tree below
the page, level by level, and queues the records of every page. Records on
pages below it are included.

.. confval:: --depth
    :name: linkvalidator2-fillqueue-depth
    :type: integer
    :Default: 999

    Levels of subpages to include. `0` queues only the page itself.

.. confval:: --chunk-size
    :name: linkvalidator2-fillqueue-chunk-size
    :type: integer
    :Default: 25

    Records per message. A message is acknowledged when all its records are
    checked. A worker that is stopped in the middle of a message loses
    that message's work, and slow external URLs make a message take long,
    so keep it small. A page with many records, for example a folder with
    20,000 news, becomes many small messages and can be worked on by
    several workers at once.

Things worth knowing:

*   Records on the root level (`pid = 0`, for example redirects) belong to
    no page and are not checked.
*   Records that vanished from a page since the last run (deleted, moved,
    imported around the DataHandler) are cleaned up while the queue is
    filled, together with the results of pages that no longer exist.
*   Queueing a record twice is harmless: a check replaces the stored results
    and external URLs come from the cache, so there is no second request.
    You do not have to keep the queue free of duplicates.
*   Call :bash:`fillqueue` once per area. Areas with their own rhythm are
    separate calls, overlapping areas only cost time.

Routing
-------

`ext_localconf.php` routes the messages to the :php:`doctrine` transport.
Set the route to :php:`default` to check synchronously instead, which is the
same as the check command:

.. code-block:: php
    :caption: config/system/additional.php

    use GeorgRinger\Linkvalidator2\Message\CheckRecordsMessage;

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][
        CheckRecordsMessage::class
    ] = 'default';

Running the worker
------------------

The worker is :bash:`messenger:consume linkvalidator2_priority doctrine`, see
:ref:`background-priority` for the first transport. Start as many as the
database and the network allow, for example one per CPU core.

.. note::

    Workers do not know about each other. Two of them can request the same
    domain at the same time, there is no delay per domain yet. Keep the
    number of workers low if the linked sites are sensitive to that.

.. code-block:: text
    :caption: Cron, every five minutes, a worker lives for four

    */5 * * * * cd /var/www/site && vendor/bin/typo3 \
        messenger:consume linkvalidator2_priority doctrine \
        --time-limit=240 --memory-limit=256M

.. warning::

    `messenger:consume` blocks the scheduler while it runs. Do not run it as
    a scheduler task without a :bash:`--time-limit` below the cron interval.
    A cron job or a systemd service is the better home for a worker.
    :bash:`fillqueue` only queues and fits a scheduler task:
    :guilabel:`Execute console commands` with :bash:`linkvalidator2:fillqueue`
    and the page as argument, one task per area.

The time limit and the message limit of a worker are only checked between two
messages. A message that is working on slow external URLs is not interrupted.

.. _background-priority:

Records that are due
--------------------

A queue filled for a whole site can hold millions of records. Records that
became due should not wait for it. They are queued on a transport of their
own, :php:`linkvalidator2_priority`, and a worker that lists it first takes
its messages before the ones of the regular queue.

Records become due when

*   a record is saved with links to external URLs that have no result yet,
*   the target of a link changed: the records that reference a changed page
    (reference index) are queued,
*   an exclusion was hidden, deleted or moved: the records below its page
    are queued.

The messages are queued at that moment, there is nothing to schedule. If no
worker takes the priority transport, the records stay marked as due and are
checked by the next :bash:`fillqueue` run for their page, like before.

The route of the messages is set in `ext_localconf.php` and can be changed
like the one of the regular messages. Do not route them to :php:`default`:
the check would then run during the save in the backend.

What you see while a worker runs
--------------------------------

With :bash:`-vvv` the worker prints one line per message. Without it, it
prints nothing for the messages. The handler also logs, at level `info`,
how many records of which table a message held, how many broken links it
found and how long it took. Log it into a file:

.. code-block:: php
    :caption: config/system/additional.php

    use Psr\Log\LogLevel;
    use TYPO3\CMS\Core\Log\Writer\FileWriter;

    $GLOBALS['TYPO3_CONF_VARS']['LOG']['GeorgRinger']['Linkvalidator2'][
        'writerConfiguration'
    ][LogLevel::INFO] = [
        FileWriter::class => ['logFileInfix' => 'linkvalidator2'],
    ];

Whether the queue is empty shows the table `sys_messenger_messages`. A
message a worker took and did not finish (the worker was killed) stays
there, marked as delivered, and is given to a worker again after an hour,
the default of the Doctrine transport of Symfony.

.. _background-command:

The check command
=================

:bash:`linkvalidator2:check` checks right away, in the process that runs it.
It prints the progress while it works.

.. code-block:: bash
    :caption: Examples

    # all records of a page and its subpages
    vendor/bin/typo3 linkvalidator2:check --page=1287 --depth=99

    # one record, prints every link and its result
    vendor/bin/typo3 linkvalidator2:check --record=tt_content:471

    # without requests to external URLs, uses stored results only
    vendor/bin/typo3 linkvalidator2:check --page=1287 --no-external

Without :bash:`--page` and :bash:`--record` the command does nothing and
points to the queue. Without :bash:`--depth` only the page itself is
checked.

The command is not meant for the regular run of a large site: it checks one
record after the other in one process.
