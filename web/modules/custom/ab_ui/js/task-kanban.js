/**
 * @file
 * Moves a card by submitting the same transition buttons the keyboard uses.
 */

(function (Drupal, once) {

  'use strict';

  /**
   * Reports whether the card may enter that column.
   */
  function allows(card, column) {
    const raw = card.getAttribute('data-targets') || '';
    const targets = raw === '' ? [] : raw.split(' ');
    return targets.indexOf(column.getAttribute('data-status')) !== -1;
  }

  /**
   * Returns the card that should follow the dropped one.
   */
  function cardAfter(cards, clientY, dragged) {
    const articles = cards.querySelectorAll('article[data-uuid]');
    for (let index = 0; index < articles.length; index++) {
      if (articles[index] === dragged) {
        continue;
      }
      const box = articles[index].getBoundingClientRect();
      if (clientY < box.top + (box.height / 2)) {
        return articles[index];
      }
    }
    return null;
  }

  Drupal.behaviors.abUiKanban = {
    attach: function (context) {
      once('ab-ui-kanban', '.ab-kanban', context).forEach(function (board) {
        let dragged = null;

        board.addEventListener('dragstart', function (event) {
          const card = event.target.closest('[data-uuid]');
          if (!card || event.target.closest('button, summary, a, input, select')) {
            event.preventDefault();
            return;
          }
          dragged = card;
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', card.getAttribute('data-uuid') || '');
        });

        board.addEventListener('dragend', function () {
          dragged = null;
          board.querySelectorAll('.is-drop-target').forEach(function (column) {
            column.classList.remove('is-drop-target');
          });
        });

        board.querySelectorAll('[data-status]').forEach(function (column) {
          column.addEventListener('dragover', function (event) {
            if (!dragged) {
              return;
            }
            const same = dragged.closest('[data-status]') === column;
            if (!same && !allows(dragged, column)) {
              return;
            }
            event.preventDefault();
            column.classList.add('is-drop-target');
          });
          column.addEventListener('dragleave', function () {
            column.classList.remove('is-drop-target');
          });
          column.addEventListener('drop', function (event) {
            column.classList.remove('is-drop-target');
            if (!dragged) {
              return;
            }
            const same = dragged.closest('[data-status]') === column;
            if (same) {
              event.preventDefault();
              const cards = column.querySelector('.ab-kanban__cards');
              const after = cards ? cardAfter(cards, event.clientY, dragged) : dragged;
              const input = dragged.querySelector('input[name="before_uuid"]');
              const button = dragged.querySelector('[name="reorder"]');
              if (!cards || !input || !button || after === dragged) {
                dragged = null;
                return;
              }
              if (after) {
                cards.insertBefore(dragged, after);
                input.value = after.getAttribute('data-uuid') || '';
              }
              else {
                cards.appendChild(dragged);
                input.value = '';
              }
              button.click();
              dragged = null;
              return;
            }
            if (!allows(dragged, column)) {
              return;
            }
            event.preventDefault();
            const status = column.getAttribute('data-status');
            const button = dragged.querySelector('[name="transition_' + status + '"]');
            const cards = column.querySelector('.ab-kanban__cards');
            if (!button || !cards) {
              dragged = null;
              return;
            }
            cards.appendChild(dragged);
            button.click();
            dragged = null;
          });
        });
      });
    }
  };

})(Drupal, once);
