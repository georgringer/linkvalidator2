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
import Notification from '@typo3/backend/notification.js';
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
    this.labels = progress.dataset;
    this.progressBar = progress.querySelector('typo3-backend-progress-bar');
    this.cancelButton = progress.querySelector('[data-linkvalidator2-check-cancel]');
    this.cancelled = false;
    button.addEventListener('click', () => this.run());
    document.querySelectorAll('[data-linkvalidator2-check-depth]').forEach((item) => {
      item.addEventListener('click', () => this.selectDepth(item));
    });
    this.cancelButton.addEventListener('click', () => {
      this.cancelled = true;
      this.cancelButton.disabled = true;
      this.progressBar.label = this.labels.labelCancelling;
    });
  }

  async run() {
    this.cancelled = false;
    this.button.disabled = true;
    this.cancelButton.disabled = false;
    this.progress.hidden = false;
    this.update(0, 1, this.labels.labelStarting);
    try {
      const start = await (await new AjaxRequest(TYPO3.settings.ajaxUrls.linkvalidator2_check_start)
        .withQueryArguments({ id: this.button.dataset.pageId, depth: this.button.dataset.depth })
        .get()).resolve();
      const pageIds = start.pageIds;
      let checkedPages = 0;
      let brokenLinks = 0;
      let newBrokenLinks = 0;
      while (checkedPages < pageIds.length && !this.cancelled) {
        let result;
        do {
          result = await (await new AjaxRequest(TYPO3.settings.ajaxUrls.linkvalidator2_check_page)
            .post({ pageId: pageIds[checkedPages], runStartedAt: start.runStartedAt })).resolve();
        } while (!result.done && !this.cancelled);
        if (!result.done) {
          break;
        }
        checkedPages++;
        brokenLinks += result.brokenLinkCount;
        newBrokenLinks += result.newBrokenLinkCount;
        if (!this.cancelled) {
          this.update(checkedPages, pageIds.length, this.format(this.labels.labelProgress, checkedPages, pageIds.length, brokenLinks, newBrokenLinks));
        }
      }
      if (this.cancelled) {
        Notification.info(this.labels.labelDone, this.format(this.labels.labelCancelled, checkedPages, pageIds.length));
      } else {
        const message = this.format(this.labels.labelDoneMessage, checkedPages, brokenLinks, newBrokenLinks);
        if (newBrokenLinks > 0) {
          Notification.warning(this.labels.labelDone, message);
        } else {
          Notification.success(this.labels.labelDone, message);
        }
      }
      window.location.reload();
    } catch (error) {
      let message = '';
      if (typeof error?.resolve === 'function') {
        message = (await error.resolve().catch(() => ({})))?.error ?? '';
      }
      Notification.error(this.labels.labelFailed, message);
      this.progress.hidden = true;
      this.button.disabled = false;
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
  }

  format(label, ...values) {
    return values.reduce((text, value, index) => text.replace('%' + (index + 1) + '$d', String(value)), label);
  }
}

const button = document.querySelector('[data-linkvalidator2-check]');
const progress = document.querySelector('[data-linkvalidator2-check-progress]');
if (button && progress) {
  new CheckLinks(button, progress);
}
