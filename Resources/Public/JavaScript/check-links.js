/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';
import Notification from '@typo3/backend/notification.js';
import labels from '~labels/linkvalidator2.messages';
import '@typo3/backend/element/progress-bar-element.js';

/**
 * Checks the pages of the selected scope one by one. A page that runs out of the server's
 * time budget is requested again until it is done. At the end, also when cancelled, a
 * notification sums up the run and the report is reloaded.
 *
 * @todo Move to Build/Sources/TypeScript/linkvalidator/ when replacing the core extension.
 */
class CheckLinks {
  constructor(button, progress) {
    this.button = button;
    this.progress = progress;
    this.progressBar = progress.querySelector('typo3-backend-progress-bar');
    this.ignoreStored = document.querySelector('[data-linkvalidator2-check-ignore-stored]');
    this.cancelButton = progress.querySelector('[data-linkvalidator2-check-cancel]');
    // Screen readers get the progress in steps of 10 %, not per page
    this.status = progress.querySelector('[data-linkvalidator2-check-status]');
    this.announcedStep = -1;
    this.cancelled = false;
    button.addEventListener('click', () => this.run());
    document.querySelectorAll('[data-linkvalidator2-check-depth]').forEach((item) => {
      item.addEventListener('click', () => this.selectDepth(item));
    });
    this.cancelButton.addEventListener('click', () => {
      this.cancelled = true;
      this.cancelButton.disabled = true;
      this.progressBar.label = labels.get('check.cancelling');
      this.announce(labels.get('check.cancelling'));
    });
  }

  async run() {
    this.cancelled = false;
    this.button.disabled = true;
    this.cancelButton.disabled = false;
    this.progress.hidden = false;
    this.announcedStep = -1;
    // The check button is disabled now, keep the focus in reach
    this.cancelButton.focus();
    this.update(0, 1, labels.get('check.starting'));
    try {
      const start = await (await new AjaxRequest(TYPO3.settings.ajaxUrls.linkvalidator2_check_start)
        .withQueryArguments({ id: this.button.dataset.pageId, depth: this.button.dataset.depth })
        .get()).resolve();
      const pageIds = start.pageIds;
      let checkedPages = 0;
      let brokenLinks = 0;
      let newBrokenLinks = 0;
      let notices = 0;
      while (checkedPages < pageIds.length && !this.cancelled) {
        let result;
        do {
          result = await (await new AjaxRequest(TYPO3.settings.ajaxUrls.linkvalidator2_check_page)
            .post({ pageId: pageIds[checkedPages], runStartedAt: start.runStartedAt, ignoreStored: this.ignoreStored?.checked ?? false })).resolve();
        } while (!result.done && !this.cancelled);
        if (!result.done) {
          break;
        }
        checkedPages++;
        brokenLinks += result.brokenLinkCount;
        newBrokenLinks += result.newBrokenLinkCount;
        notices += result.noticeCount;
        if (!this.cancelled) {
          this.update(checkedPages, pageIds.length, labels.get('check.progress', { page: checkedPages, pages: pageIds.length, broken: brokenLinks, new: newBrokenLinks }));
        }
      }
      if (this.cancelled) {
        Notification.info(labels.get('check.done'), labels.get('check.cancelled', { checked: checkedPages, pages: pageIds.length }));
      } else {
        const message = labels.get('check.doneMessage', { pages: checkedPages, broken: brokenLinks, new: newBrokenLinks, notices });
        if (newBrokenLinks > 0) {
          Notification.warning(labels.get('check.done'), message);
        } else {
          Notification.success(labels.get('check.done'), message);
        }
      }
      window.location.reload();
    } catch (error) {
      let message = '';
      if (typeof error?.resolve === 'function') {
        message = (await error.resolve().catch(() => ({})))?.error ?? '';
      }
      Notification.error(labels.get('check.failed'), message);
      this.progress.hidden = true;
      this.button.disabled = false;
      this.button.focus();
    }
  }

  /**
   * Levels of the next check, chosen in the doc header without reloading the report
   */
  selectDepth(item) {
    const previous = item.closest('.dropdown-menu').querySelector('[aria-selected="true"]');
    if (previous && previous !== item) {
      // Swap the status icons (active dot / empty) of both items
      const previousStatus = previous.firstElementChild;
      const status = item.firstElementChild;
      const placeholder = document.createElement('span');
      previous.replaceChild(placeholder, previousStatus);
      item.replaceChild(previousStatus, status);
      previous.replaceChild(status, placeholder);
      previous.removeAttribute('aria-selected');
    }
    item.setAttribute('aria-selected', 'true');
    this.button.dataset.depth = item.dataset.linkvalidator2CheckDepth;
    const toggle = item.closest('.btn-group').querySelector('.dropdown-toggle');
    toggle.lastChild.textContent = ' ' + item.textContent.trim();
  }

  update(value, max, label) {
    this.progressBar.max = max;
    this.progressBar.value = value;
    this.progressBar.label = label;
    const step = Math.floor(value / max * 10);
    if (step !== this.announcedStep) {
      this.announcedStep = step;
      this.announce(label);
    }
  }

  announce(message) {
    if (this.status) {
      this.status.textContent = message;
    }
  }

}

// Modules load async in the head, the doc header button may not be parsed yet
DocumentService.ready().then(() => {
  const button = document.querySelector('[data-linkvalidator2-check]');
  const progress = document.querySelector('[data-linkvalidator2-check-progress]');
  if (button && progress) {
    new CheckLinks(button, progress);
  }
});
