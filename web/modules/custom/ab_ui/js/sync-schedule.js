/**
 * @file
 * Decides when the workspace screen polls and which regions change.
 */

(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  else {
    root.AbUiSyncSchedule = api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {

  const ACTIVE = 3000;
  const HIDDEN = 15000;
  const MAX = 60000;

  /**
   * Starts a poller with no roots and no timer.
   */
  function create() {
    return {
      roots: 0,
      timer: false,
      failures: 0,
      seen: {},
      cursor: '',
      stopped: false,
      status: '',
    };
  }

  /**
   * Arms one timer for the first region and ignores later attaches.
   */
  function attach(state) {
    const next = copy(state);
    next.roots += 1;
    next.stopped = false;
    next.timer = state.roots === 0 ? true : state.timer;
    return next;
  }

  /**
   * Drops one region and clears the timer when the page is gone.
   */
  function detach(state) {
    const next = copy(state);
    next.roots = Math.max(0, state.roots - 1);
    if (next.roots === 0) {
      next.timer = false;
    }
    return next;
  }

  /**
   * Delay until the next poll. A hidden tab waits longer. Failures back off.
   */
  function nextDelay(hidden, failures, random, limits) {
    const active = limit(limits, 'active', ACTIVE);
    const hiddenDelay = limit(limits, 'hidden', HIDDEN);
    const max = limit(limits, 'max', MAX);
    const base = hidden ? hiddenDelay : active;
    if (failures <= 0) {
      return base;
    }
    const doubled = Math.min(max, base * Math.pow(2, failures));
    const jitter = Math.floor((typeof random === 'function' ? random() : 0) * 1000);
    return Math.min(max, doubled + jitter);
  }

  /**
   * Keeps new event ids and names the regions they invalidate.
   */
  function absorb(state, events) {
    const next = copy(state);
    next.seen = Object.assign({}, state.seen);
    next.failures = 0;
    next.status = '';
    const regions = {};
    (events || []).forEach(function (event) {
      if (!event || typeof event.event_id !== 'string' || next.seen[event.event_id]) {
        return;
      }
      next.seen[event.event_id] = true;
      regionsFor(event.type).forEach(function (region) {
        regions[region] = true;
      });
    });
    return {
      state: next,
      regions: Object.keys(regions),
    };
  }

  /**
   * Moves the cursor to the last inspected sequence.
   */
  function advanceCursor(cursor, body) {
    if (!body || !body.page) {
      return cursor;
    }
    if (typeof body.page.cursor === 'string' && body.page.cursor !== '') {
      return body.page.cursor;
    }
    if (typeof body.page.next_cursor === 'string' && body.page.next_cursor !== '') {
      return body.page.next_cursor;
    }
    return cursor;
  }

  /**
   * Records a failed poll. Revocation and an expired session stop the timer.
   */
  function fail(state, kind) {
    const next = copy(state);
    if (kind === 'unauthorized' || kind === 'forbidden') {
      next.stopped = true;
      next.timer = false;
      next.status = kind === 'unauthorized' ? 'session' : 'revoked';
      return next;
    }
    if (kind === 'gone') {
      next.status = 'resync';
      next.failures = 0;
      return next;
    }
    next.failures += 1;
    next.status = 'retry';
    return next;
  }

  /**
   * A focused or dirty region keeps what the person typed.
   */
  function shouldReplace(hasFocus, dirty) {
    return !hasFocus && !dirty;
  }

  /**
   * Maps an event type to the screen regions it invalidates.
   */
  function regionsFor(type) {
    const name = typeof type === 'string' ? type : '';
    if (name.indexOf('message.') === 0 || name.indexOf('input.') === 0) {
      return ['messages', 'notifications'];
    }
    if (name.indexOf('membership.') === 0) {
      return ['tasks', 'messages', 'notifications'];
    }
    if (name.indexOf('task.') === 0 || name.indexOf('execution.') === 0 || name.indexOf('board.') === 0) {
      return ['tasks'];
    }
    return [];
  }

  /**
   * Copies the poller state.
   */
  function copy(state) {
    return {
      roots: state.roots,
      timer: state.timer,
      failures: state.failures,
      seen: state.seen,
      cursor: state.cursor,
      stopped: state.stopped,
      status: state.status,
    };
  }

  /**
   * Reads one configured delay.
   */
  function limit(limits, name, fallback) {
    if (!limits || typeof limits[name] !== 'number' || limits[name] < 1) {
      return fallback;
    }
    return limits[name];
  }

  return {
    ACTIVE: ACTIVE,
    HIDDEN: HIDDEN,
    MAX: MAX,
    create: create,
    attach: attach,
    detach: detach,
    nextDelay: nextDelay,
    absorb: absorb,
    advanceCursor: advanceCursor,
    fail: fail,
    shouldReplace: shouldReplace,
    regionsFor: regionsFor,
  };
});
