/**
 * Agent Board Theme - Main JavaScript
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Agent Board behaviors.
   */
  Drupal.behaviors.agentBoard = {
    attach: function (context, settings) {
      // Initialize components
      once('agent-board-init', 'html', context).forEach(function () {
        console.log('Agent Board theme initialized');
      });
    }
  };

})(Drupal, once);
