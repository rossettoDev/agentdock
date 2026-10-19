/**
 * @file
 * Polls workspace events and refreshes cards, conversations and notifications.
 */

(function (Drupal, drupalSettings, once, schedule) {

  'use strict';

  let state = schedule.create();
  let pending = null;
  let busy = false;

  /**
   * Reads the poll settings printed with the page.
   */
  function settings() {
    return drupalSettings.abUiSync || {};
  }

  /**
   * Arms the single timer. A request already running is left alone.
   */
  function arm() {
    if (busy || pending !== null || state.roots < 1 || state.stopped) {
      return;
    }
    const hidden = document.visibilityState === 'hidden';
    pending = window.setTimeout(tick, schedule.nextDelay(hidden, state.failures, Math.random, settings()));
  }

  /**
   * Cancels the timer without starting another.
   */
  function disarm() {
    if (pending !== null) {
      window.clearTimeout(pending);
      pending = null;
    }
  }

  /**
   * Polls once, then arms the next wait.
   */
  function tick() {
    pending = null;
    if (state.stopped || state.roots < 1) {
      return;
    }
    busy = true;
    poll().finally(function () {
      busy = false;
      arm();
    });
  }

  /**
   * Reads events after the cursor and refreshes the regions they name.
   */
  function poll() {
    const config = settings();
    if (!config.eventsUrl) {
      return Promise.resolve();
    }
    if (state.cursor === '' && typeof config.cursor === 'string') {
      state.cursor = config.cursor;
    }
    return readEvents(config.eventsUrl, state.cursor, 0).then(function (result) {
      if (result.kind === 'gone') {
        state = schedule.fail(state, 'gone');
        showStatus();
        return resync(config);
      }
      if (result.kind !== 'ok') {
        state = schedule.fail(state, result.kind);
        showStatus();
        if (state.status === 'revoked' || state.status === 'session') {
          clearRegions();
        }
        return null;
      }
      state.cursor = result.cursor;
      const absorbed = schedule.absorb(state, result.events);
      state = absorbed.state;
      showStatus();
      if (absorbed.regions.length === 0) {
        return null;
      }
      return refreshRegions(absorbed.regions);
    });
  }

  /**
   * Follows event pages without overlapping another poll.
   */
  function readEvents(url, cursor, hops) {
    const separator = url.indexOf('?') === -1 ? '?' : '&';
    const target = url + separator + 'limit=50&cursor=' + encodeURIComponent(cursor);
    return window.fetch(target, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    }).then(function (response) {
      if (response.status === 401) {
        return { kind: 'unauthorized', cursor: cursor, events: [] };
      }
      if (response.status === 403 || response.status === 404) {
        return { kind: 'forbidden', cursor: cursor, events: [] };
      }
      if (response.status === 410) {
        return { kind: 'gone', cursor: cursor, events: [] };
      }
      if (!response.ok) {
        return { kind: 'temporary', cursor: cursor, events: [] };
      }
      return response.json().then(function (body) {
        const events = body && Array.isArray(body.data) ? body.data : [];
        const nextCursor = schedule.advanceCursor(cursor, body);
        const more = body && body.page && typeof body.page.next_cursor === 'string' && body.page.next_cursor !== '';
        if (more && hops < 5) {
          return readEvents(url, nextCursor, hops + 1).then(function (rest) {
            if (rest.kind !== 'ok') {
              return rest;
            }
            return {
              kind: 'ok',
              cursor: rest.cursor,
              events: events.concat(rest.events),
            };
          });
        }
        return { kind: 'ok', cursor: nextCursor, events: events };
      });
    }).catch(function () {
      return { kind: 'temporary', cursor: cursor, events: [] };
    });
  }

  /**
   * Loads a fresh cursor after the previous one expired.
   */
  function resync(config) {
    const snapshot = config.snapshotUrl
      ? window.fetch(config.snapshotUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      })
      : Promise.resolve(null);
    return snapshot.then(function (response) {
      if (response && (response.status === 401 || response.status === 403 || response.status === 404)) {
        state = schedule.fail(state, response.status === 401 ? 'unauthorized' : 'forbidden');
        showStatus();
        clearRegions();
        return null;
      }
      if (response && response.ok) {
        return response.json().then(function (body) {
          if (body && typeof body.cursor === 'string') {
            state.cursor = body.cursor;
          }
          return refreshRegions(['tasks', 'messages', 'notifications']);
        });
      }
      return refreshRegions(['tasks', 'messages', 'notifications']);
    }).catch(function () {
      state = schedule.fail(state, 'temporary');
      showStatus();
      return null;
    });
  }

  /**
   * Replaces each named region when it does not hold a draft.
   */
  function refreshRegions(regions) {
    return window.fetch(window.location.href, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'text/html' },
    }).then(function (response) {
      if (response.status === 401 || response.status === 403 || response.status === 404) {
        state = schedule.fail(state, response.status === 401 ? 'unauthorized' : 'forbidden');
        showStatus();
        clearRegions();
        return null;
      }
      if (!response.ok) {
        state = schedule.fail(state, 'temporary');
        showStatus();
        return null;
      }
      return response.text();
    }).then(function (html) {
      if (typeof html !== 'string') {
        return null;
      }
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const meta = parsed.querySelector('meta[name="ab-sync-cursor"]');
      if (meta && meta.getAttribute('content')) {
        state.cursor = meta.getAttribute('content');
      }
      if (!parsed.querySelector('[data-ab-sync-root]')) {
        state = schedule.fail(state, 'unauthorized');
        showStatus();
        clearRegions();
        return null;
      }
      regions.forEach(function (region) {
        replaceRegion(parsed, region);
      });
      showStatus();
      return null;
    }).catch(function () {
      state = schedule.fail(state, 'temporary');
      showStatus();
      return null;
    });
  }

  /**
   * Swaps one region from the freshly rendered page.
   */
  function replaceRegion(parsed, region) {
    const selector = '[data-ab-sync="' + region + '"]';
    const current = document.querySelectorAll(selector);
    const fresh = parsed.querySelectorAll(selector);
    current.forEach(function (node, index) {
      const next = fresh[index];
      if (!next) {
        return;
      }
      const focused = node.contains(document.activeElement);
      const dirty = hasDraft(node);
      if (!schedule.shouldReplace(focused, dirty)) {
        node.setAttribute('data-ab-sync-pending', 'true');
        return;
      }
      const parent = node.parentNode;
      if (parent) {
        Drupal.detachBehaviors(parent);
      }
      const imported = document.importNode(next, true);
      node.replaceWith(imported);
      if (imported.parentNode) {
        Drupal.attachBehaviors(imported.parentNode);
      }
    });
  }

  /**
   * Reports whether a field in the region was edited.
   */
  function hasDraft(node) {
    const fields = node.querySelectorAll('textarea, input[type="text"], input[type="search"]');
    for (let index = 0; index < fields.length; index++) {
      if (fields[index].value !== fields[index].defaultValue) {
        return true;
      }
    }
    return false;
  }

  /**
   * Removes synced content after access is gone.
   */
  function clearRegions() {
    document.querySelectorAll('[data-ab-sync]').forEach(function (node) {
      Drupal.detachBehaviors(node);
      node.replaceChildren();
    });
  }

  /**
   * Shows the current poll state without replacing a draft.
   */
  function showStatus() {
    const node = document.querySelector('.ab-sync-status');
    if (!node) {
      return;
    }
    const messages = {
      session: Drupal.t('A sessão expirou. Entre de novo para continuar.'),
      revoked: Drupal.t('O acesso a este conteúdo acabou.'),
      resync: Drupal.t('A sincronização expirou. Atualizando o estado.'),
      retry: Drupal.t('Não foi possível atualizar agora.'),
    };
    const text = messages[state.status] || '';
    node.textContent = text;
    node.classList.remove('is-session', 'is-revoked', 'is-resync', 'is-retry');
    if (text === '') {
      node.setAttribute('hidden', 'hidden');
      return;
    }
    node.classList.add('is-' + state.status);
    node.removeAttribute('hidden');
  }

  /**
   * Applies a deferred refresh after the person leaves the field.
   */
  function flushPending(event) {
    const region = event.target.closest ? event.target.closest('[data-ab-sync]') : null;
    if (!region || region.getAttribute('data-ab-sync-pending') !== 'true') {
      return;
    }
    if (region.contains(document.activeElement) || hasDraft(region)) {
      return;
    }
    region.removeAttribute('data-ab-sync-pending');
    refreshRegions([region.getAttribute('data-ab-sync')]);
  }

  Drupal.behaviors.abUiSync = {
    attach: function (context) {
      once('ab-ui-sync-visibility', 'html', context).forEach(function () {
        document.addEventListener('visibilitychange', function () {
          disarm();
          arm();
        });
        document.addEventListener('focusout', flushPending);
      });
      once('ab-ui-sync', '[data-ab-sync-root]', context).forEach(function () {
        state = schedule.attach(state);
      });
      arm();
    },
    detach: function (context) {
      once.remove('ab-ui-sync', '[data-ab-sync-root]', context).forEach(function () {
        state = schedule.detach(state);
      });
      if (state.roots === 0) {
        disarm();
      }
    },
  };

})(Drupal, drupalSettings, once, window.AbUiSyncSchedule);
