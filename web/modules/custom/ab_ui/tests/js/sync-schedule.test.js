/**
 * @file
 * Checks the poller decisions without a browser.
 */

const assert = require('assert');
const schedule = require('../../js/sync-schedule');

const state = schedule.create();
assert.strictEqual(state.timer, false);
assert.strictEqual(state.roots, 0);

const first = schedule.attach(state);
assert.strictEqual(first.roots, 1);
assert.strictEqual(first.timer, true);
const second = schedule.attach(first);
assert.strictEqual(second.roots, 2);
assert.strictEqual(second.timer, true);
const one = schedule.detach(second);
assert.strictEqual(one.roots, 1);
assert.strictEqual(one.timer, true);
const none = schedule.detach(one);
assert.strictEqual(none.roots, 0);
assert.strictEqual(none.timer, false);

assert.strictEqual(schedule.nextDelay(false, 0, () => 0), schedule.ACTIVE);
assert.strictEqual(schedule.nextDelay(true, 0, () => 0), schedule.HIDDEN);
assert.ok(schedule.ACTIVE < 5000);
assert.ok(schedule.HIDDEN > schedule.ACTIVE);
const backed = schedule.nextDelay(false, 6, () => 0.9);
assert.ok(backed <= schedule.MAX);
assert.ok(backed > schedule.ACTIVE);

const seen = schedule.absorb(schedule.create(), [
  { event_id: '11111111-1111-4111-8111-111111111111', type: 'task.created', sequence: 4 },
]);
assert.deepStrictEqual(seen.regions, ['tasks']);
const again = schedule.absorb(seen.state, [
  { event_id: '11111111-1111-4111-8111-111111111111', type: 'task.created', sequence: 4 },
  { event_id: '22222222-2222-4222-8222-222222222222', type: 'message.created', sequence: 5 },
]);
assert.deepStrictEqual(again.regions, ['messages', 'notifications']);
assert.strictEqual(again.state.seen['11111111-1111-4111-8111-111111111111'], true);

const cursor = schedule.advanceCursor('3', {
  data: [],
  page: { limit: 50, next_cursor: null, cursor: '9' },
});
assert.strictEqual(cursor, '9');

const revoked = schedule.fail(schedule.create(), 'forbidden');
assert.strictEqual(revoked.stopped, true);
assert.strictEqual(revoked.timer, false);
assert.strictEqual(revoked.status, 'revoked');
const expired = schedule.fail(schedule.create(), 'unauthorized');
assert.strictEqual(expired.status, 'session');
const waiting = schedule.fail(schedule.create(), 'temporary');
assert.strictEqual(waiting.stopped, false);
assert.strictEqual(waiting.status, 'retry');
assert.strictEqual(waiting.failures, 1);

assert.strictEqual(schedule.shouldReplace(true, false), false);
assert.strictEqual(schedule.shouldReplace(false, true), false);
assert.strictEqual(schedule.shouldReplace(false, false), true);

console.log('ok');
