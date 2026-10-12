'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const {
  matchesLocalForm,
  normalizeFormIds,
  createOrganizationSearchController,
} = require('../public_html/forms/assets/js/admin_form_search.js');

test('既存のフォーム情報検索はフォーム名・slug・説明・フォルダーを対象にする', () => {
  const form = {
    name: '施設利用申請',
    slug: 'facility-request',
    description: '学生団体向けの申請フォーム',
  };

  assert.equal(matchesLocalForm(form, '施設', '課外活動 / 申請'), true);
  assert.equal(matchesLocalForm(form, 'FACILITY', '課外活動 / 申請'), true);
  assert.equal(matchesLocalForm(form, '学生団体', '課外活動 / 申請'), true);
  assert.equal(matchesLocalForm(form, '課外活動', '課外活動 / 申請'), true);
  assert.equal(matchesLocalForm(form, '備品購入', '課外活動 / 申請'), false);
});

test('APIのフォームIDは正の整数だけに正規化し重複を除く', () => {
  assert.deepEqual(
    [...normalizeFormIds([3, '2', 3, 0, -1, 'invalid', 2.5])],
    [3, 2]
  );
});

test('連続入力はデバウンスされ最後の団体名だけを検索する', async () => {
  const timers = new Map();
  let nextTimerId = 1;
  const calls = [];
  const states = [];
  const controller = createOrganizationSearchController({
    search: async (query) => {
      calls.push(query);
      return [7];
    },
    onStateChange: (state) => states.push(state),
    setTimer: (callback) => {
      const id = nextTimerId++;
      timers.set(id, callback);
      return id;
    },
    clearTimer: (id) => timers.delete(id),
  });

  controller.update('写真');
  controller.update('写真部');

  assert.equal(timers.size, 1);
  await [...timers.values()][0]();

  assert.deepEqual(calls, ['写真部']);
  assert.deepEqual([...controller.getState().matchingFormIds], [7]);
  assert.equal(controller.getState().pending, false);
  assert.equal(states.at(-1).query, '写真部');
});

test('遅れて返った古い団体検索の応答を無視する', async () => {
  const callbacks = [];
  const deferred = new Map();
  const search = (query) => new Promise((resolve) => deferred.set(query, resolve));
  const controller = createOrganizationSearchController({
    search,
    onStateChange: () => {},
    setTimer: (callback) => {
      callbacks.push(callback);
      return callbacks.length;
    },
    clearTimer: () => {},
  });

  controller.update('旧団体');
  const firstRequest = callbacks.shift()();
  controller.update('新団体');
  const secondRequest = callbacks.shift()();

  deferred.get('新団体')([22]);
  await secondRequest;
  deferred.get('旧団体')([11]);
  await firstRequest;

  assert.equal(controller.getState().query, '新団体');
  assert.deepEqual([...controller.getState().matchingFormIds], [22]);
});
