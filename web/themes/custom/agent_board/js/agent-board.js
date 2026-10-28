/**
 * @file
 * Collapses the navigation and opens the notification panel.
 */

(function (Drupal, once) {

  Drupal.behaviors.abShell = {
    attach(context) {
      once('ab-shell', '.ab-app', context).forEach((app) => {
        const navButton = app.querySelector('[data-ab-nav-toggle]');
        const sidebar = app.querySelector('#ab-sidebar');
        const narrow = window.matchMedia('(max-width: 768px)');
        const collapseForNarrow = () => {
          if (!navButton || !narrow.matches) {
            return;
          }
          app.classList.add('is-nav-collapsed');
          navButton.setAttribute('aria-expanded', 'false');
        };
        collapseForNarrow();
        if (typeof narrow.addEventListener === 'function') {
          narrow.addEventListener('change', collapseForNarrow);
        }
        if (navButton) {
          navButton.addEventListener('click', () => {
            const collapsed = app.classList.toggle('is-nav-collapsed');
            navButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            if (!collapsed && sidebar) {
              const first = sidebar.querySelector('a');
              if (first) {
                first.focus();
              }
            }
            else {
              navButton.focus();
            }
          });
        }

        const bell = app.querySelector('[data-ab-bell]');
        const panel = app.querySelector('#ab-context');
        const close = panel ? panel.querySelector('[data-ab-context-close]') : null;
        const closePanel = () => {
          if (!panel || !bell) {
            return;
          }
          panel.hidden = true;
          bell.setAttribute('aria-expanded', 'false');
          bell.focus();
        };
        if (bell && panel) {
          bell.addEventListener('click', () => {
            const opening = panel.hidden;
            panel.hidden = !opening;
            bell.setAttribute('aria-expanded', opening ? 'true' : 'false');
            if (opening && close) {
              close.focus();
            }
            if (!opening) {
              bell.focus();
            }
          });
        }
        if (close) {
          close.addEventListener('click', closePanel);
        }
        if (panel) {
          panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
              event.preventDefault();
              closePanel();
            }
          });
        }

        const layout = app.closest('.ab-layout');
        const choices = app.querySelectorAll('[data-ab-layout]');
        const storageKey = 'ab-layout';
        const applyLayout = (mode) => {
          const fluid = mode !== 'boxed';
          if (layout) {
            layout.classList.toggle('is-fluid', fluid);
            layout.classList.toggle('is-boxed', !fluid);
          }
          choices.forEach((choice) => {
            const selected = choice.getAttribute('data-ab-layout') === (fluid ? 'fluid' : 'boxed');
            choice.setAttribute('aria-pressed', selected ? 'true' : 'false');
          });
        };
        let stored = 'fluid';
        try {
          stored = window.localStorage.getItem(storageKey) || 'fluid';
        }
        catch (error) {
          stored = 'fluid';
        }
        applyLayout(stored);
        choices.forEach((choice) => {
          choice.addEventListener('click', () => {
            const mode = choice.getAttribute('data-ab-layout') === 'boxed' ? 'boxed' : 'fluid';
            try {
              window.localStorage.setItem(storageKey, mode);
            }
            catch (error) {
              // The choice still applies for this page when storage is blocked.
            }
            applyLayout(mode);
          });
        });
      });
    },
  };

})(Drupal, once);
