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
    const targets = (card.getAttribute('data-targets') || '').split(' ');
    return targets.indexOf(column.getAttribute('data-status')) !== -1;
  }

  Drupal.behaviors.abUiKanban = {
    attach: function (context) {
      once('ab-ui-kanban', '.ab-kanban', context).forEach(function (board) {
        let dragged = null;

        board.addEventListener('dragstart', function (event) {
          const card = event.target.closest('[data-targets]');
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
            if (!dragged || !allows(dragged, column)) {
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
            if (!dragged || !allows(dragged, column)) {
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
