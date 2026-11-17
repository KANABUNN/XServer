/* =====================================================================
   部員名簿フォーム（利用者画面）
   - 団体確認（ID7 の団体メールへ確認コード）→ 名簿の編集 → 提出前の確認 → 提出
   - 下書きはサーバーに保存する（個人情報をブラウザに残さない）
   - 提出しても kintone へは書き込まれない。総合管理事務局が確認・accept した内容だけが反映される。
   public.js から FitscRoster.mount(host, form) で呼ばれる。common.js の apiGet / apiPost を使う。
   ===================================================================== */
(function () {
  'use strict';

  const API = 'api/roster.php';
  const AUTOSAVE_MS = 2500;
  const icon = (name, cls) => (window.UI ? UI.icon(name, cls) : '');
  const esc = (value) => escapeHtml(value == null ? '' : String(value));
  const toast = (message, opts) => (window.UI ? UI.toast(message, opts) : window.alert(message));

  function newKey() {
    return `n${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
  }

  function mount(host, form) {
    const app = {
      host,
      form,
      view: 'loading',
      state: null,
      rows: [],
      draftVersion: 0,
      dirty: false,
      saving: false,
      saveTimer: null,
      lastSavedAt: '',
      errorsByKey: {},
      preview: null,
      pendingCode: null,
      directory: null,
      options: null,
    };
    host.dataset.rosterMounted = '1';
    load(app);
    return app;
  }

  /* ---------------- 通信 ---------------- */
  async function call(app, action, payload = {}) {
    return apiPost(API, { action, form_id: app.form.id, ...payload });
  }

  async function load(app) {
    app.view = 'loading';
    render(app);
    let result;
    try {
      result = await apiGet(`${API}?action=state&form_id=${encodeURIComponent(app.form.id)}`);
    } catch (error) {
      const info = describeApiError(error);
      renderFatal(app, info.message);
      return;
    }
    if (!result.ok) {
      renderFatal(app, result.message || '名簿の状態を取得できませんでした。');
      return;
    }
    if (!result.authenticated) {
      app.view = 'auth';
      app.state = result;
      render(app);
      loadDirectory(app);
      return;
    }
    app.state = result;
    app.options = result.options;
    cachedRoles = result.options?.roles || [];
    applyDraft(app, result.draft);
    app.view = (result.draft && result.draft.exists) || !result.latest ? 'edit' : 'summary';
    app.preview = null;
    render(app);
  }

  function applyDraft(app, draft) {
    app.rows = (draft?.rows || []).map((row) => ({ ...row, key: row.key || newKey() }));
    app.draftVersion = Number(draft?.draft_version || 0);
    app.dirty = false;
    app.lastSavedAt = draft?.updated_at || '';
    app.errorsByKey = {};
    app.officers = normalizeOfficers(draft?.officers);
    app.officerTouched = {};
  }

  async function loadDirectory(app) {
    try {
      const result = await apiGet(`${API}?action=directory&form_id=${encodeURIComponent(app.form.id)}`);
      app.directory = result.ok ? (result.groups || []) : [];
      if (!result.ok && result.message) toast(result.message, { type: 'error', title: '団体一覧を取得できませんでした' });
    } catch (error) {
      app.directory = [];
    }
    const list = app.host.querySelector('#roster-group-list');
    if (list) {
      list.innerHTML = app.directory.map((g) => `<option value="${esc(g.code)}">${esc(g.name)}（${esc(g.rank)}）</option>`).join('');
    }
  }

  function handleApiFailure(app, result, title) {
    if (result.auth_required) {
      toast(result.message || '団体の確認が必要です。', { type: 'warning', title: '団体確認の有効期限切れ' });
      load(app);
      return;
    }
    if (result.reason === 'stale') {
      toast(result.message, { type: 'warning', title: '最新の状態を読み込み直します', duration: 7000 });
      load(app);
      return;
    }
    if (result.reload_required) {
      UI.confirm({ title: 'ページの再読み込みが必要です', message: result.message, okText: '再読み込み', tone: 'warning' }).then((ok) => { if (ok) window.location.reload(); });
      return;
    }
    toast(result.message || '処理に失敗しました。', { type: 'error', title, duration: 7000 });
  }

  /* ---------------- 描画 ---------------- */
  function renderFatal(app, message) {
    app.host.innerHTML = `
      <div class="fp-state fp-state--muted" role="alert">
        <span class="fp-state__icon">${icon('alert-triangle', 'ico--xl')}</span>
        <h2>名簿フォームを表示できません</h2>
        <p>${esc(message)}</p>
        <button type="button" class="btn" data-rs="reload">${icon('refresh')}再読み込み</button>
      </div>`;
    app.host.querySelector('[data-rs="reload"]')?.addEventListener('click', () => load(app));
  }

  function render(app) {
    if (app.view === 'loading') {
      app.host.innerHTML = '<div class="rs-pad stack"><span class="skeleton" style="height:22px;width:40%"></span><span class="skeleton" style="height:160px"></span></div>';
      return;
    }
    if (app.view === 'auth') { renderAuth(app); return; }
    app.host.innerHTML = `
      <div class="rs" data-roster>
        ${groupHeaderHtml(app)}
        ${statusPanelHtml(app)}
        <div data-rs-body></div>
        ${historyHtml(app)}
      </div>`;
    app.host.querySelector('[data-rs="logout"]')?.addEventListener('click', () => logout(app));
    const body = app.host.querySelector('[data-rs-body]');
    if (app.view === 'summary') renderSummary(app, body);
    else if (app.view === 'preview') renderPreview(app, body);
    else renderEditor(app, body);
  }

  function renderAuth(app) {
    const pending = app.pendingCode;
    const year = app.state?.fiscal_year ? `${app.state.fiscal_year}年度` : '';
    app.host.innerHTML = `
      <div class="rs rs-pad stack">
        <div class="alert alert--info" role="note">
          ${icon('shield')}
          <div class="alert__body">
            <strong>${esc(year)} 部員名簿の提出には団体の確認が必要です</strong>
            <p>団体識別記号を選ぶと、総合管理事務局に登録されている<strong>団体メールアドレス</strong>へ確認コードを送ります。団体メールを確認できる方が入力してください。</p>
          </div>
        </div>
        <div class="steps rs-steps" aria-label="手順">
          <div class="step ${pending ? 'is-done' : 'is-active'}"><span class="step__label">1. 団体を選ぶ</span></div>
          <div class="step ${pending ? 'is-active' : ''}"><span class="step__label">2. 確認コードを入力</span></div>
          <div class="step"><span class="step__label">3. 名簿を入力・提出</span></div>
        </div>
        ${pending ? `
          <form class="stack" data-rs="verify-form" autocomplete="off">
            <p><strong>${esc(pending.group_name)}</strong>（${esc(pending.group_code)}）の団体メール <span class="mono">${esc(pending.email_masked)}</span> へ確認コードを送りました。</p>
            <label class="field rs-code-field">
              <span class="field__label">確認コード（6桁）</span>
              <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9０-９]*" required placeholder="123456">
            </label>
            <div class="cluster">
              <button type="submit" class="btn btn--primary">${icon('check')}確認する</button>
              <button type="button" class="btn btn--ghost" data-rs="back-to-group">${icon('arrow-left')}団体を選び直す</button>
            </div>
            <p class="small-note">コードの有効期限は${esc(String(pending.expires_at || '').slice(11, 16))}までです。届かない場合は迷惑メールフォルダもご確認ください。</p>
          </form>` : `
          <form class="stack" data-rs="request-form" autocomplete="off">
            <label class="field">
              <span class="field__label">団体識別記号${'<span class="req">必須</span>'}</span>
              <input type="text" name="group_code" list="roster-group-list" required placeholder="例：CA056" spellcheck="false" autocapitalize="characters">
              <datalist id="roster-group-list">${(app.directory || []).map((g) => `<option value="${esc(g.code)}">${esc(g.name)}（${esc(g.rank)}）</option>`).join('')}</datalist>
              <span class="field__hint" data-rs="group-hint">入力すると団体名の候補が表示されます。</span>
            </label>
            <div><button type="submit" class="btn btn--primary">${icon('mail')}確認コードを団体メールへ送る</button></div>
          </form>`}
      </div>`;

    const requestForm = app.host.querySelector('[data-rs="request-form"]');
    if (requestForm) {
      const input = requestForm.elements.group_code;
      const hint = app.host.querySelector('[data-rs="group-hint"]');
      input.addEventListener('input', () => {
        const code = input.value.trim().toUpperCase();
        const match = (app.directory || []).find((g) => g.code === code);
        hint.textContent = match ? `${match.name}（${match.rank}）` : '入力すると団体名の候補が表示されます。';
      });
      requestForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = requestForm.querySelector('button[type="submit"]');
        button.classList.add('is-loading');
        button.disabled = true;
        try {
          const result = await call(app, 'request_code', { group_code: input.value.trim() });
          if (!result.ok) { handleApiFailure(app, result, '確認コードを送れませんでした'); return; }
          app.pendingCode = result;
          renderAuth(app);
          app.host.querySelector('input[name="code"]')?.focus();
          toast(result.message, { type: 'success', title: '確認コードを送信しました', duration: 6000 });
        } catch (error) {
          const info = describeApiError(error);
          toast(info.message, { type: 'error', title: '確認コードを送れませんでした', detail: info.detail });
        } finally {
          button.classList.remove('is-loading');
          button.disabled = false;
        }
      });
    }
    const verifyForm = app.host.querySelector('[data-rs="verify-form"]');
    if (verifyForm) {
      verifyForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = verifyForm.querySelector('button[type="submit"]');
        button.classList.add('is-loading');
        button.disabled = true;
        try {
          const result = await call(app, 'verify_code', { code: verifyForm.elements.code.value });
          if (!result.ok) {
            if (result.reason === 'expired') { app.pendingCode = null; renderAuth(app); }
            handleApiFailure(app, result, '確認できませんでした');
            return;
          }
          app.pendingCode = null;
          toast('', { type: 'success', title: `${result.group_name} を確認しました`, duration: 3000 });
          load(app);
        } catch (error) {
          const info = describeApiError(error);
          toast(info.message, { type: 'error', title: '確認できませんでした', detail: info.detail });
        } finally {
          if (document.contains(button)) { button.classList.remove('is-loading'); button.disabled = false; }
        }
      });
      app.host.querySelector('[data-rs="back-to-group"]')?.addEventListener('click', () => { app.pendingCode = null; renderAuth(app); });
    }
  }

  function groupHeaderHtml(app) {
    const g = app.state.group || {};
    return `
      <div class="rs-group">
        <div class="rs-group__main">
          <span class="rs-group__icon">${icon('users')}</span>
          <div>
            <div class="rs-group__name">${esc(g.name)} <span class="mono text-subtle">${esc(g.code)}</span></div>
            <div class="rs-group__meta">${esc(app.state.fiscal_year)}年度 ・ ${esc(g.rank)} ・ 団体メール ${esc(g.email_masked)} で確認済み（${esc(g.auth_expires_at)} まで有効）</div>
          </div>
        </div>
        <button type="button" class="btn btn--ghost btn--sm" data-rs="logout">${icon('log-out')}団体確認を終了</button>
      </div>`;
  }

  function stateTone(state) {
    return {
      submitted: 'info', returned: 'warning', accepted: 'accent', draft: '',
      not_applicable: '', queued: 'info', running: 'info', applied: 'success', failed: 'danger', conflict: 'warning', unknown: 'warning',
    }[state] ?? '';
  }

  function badge(label, tone) {
    return `<span class="badge badge--dot${tone ? ` badge--${tone}` : ''}">${esc(label)}</span>`;
  }

  function statusPanelHtml(app) {
    const c = app.state.case;
    if (!c || !c.latest_revision_number) {
      return `<div class="alert alert--neutral rs-note">${icon('info')}<div class="alert__body">まだ提出していません。kintoneに登録済みの名簿を読み込むか、部員を追加して入力してください。</div></div>`;
    }
    const latest = app.state.latest;
    const returnReason = latest && latest.review_state === 'returned' ? (latest.reviews || []).slice(-1)[0] : null;
    let applyNote = '';
    if (c.apply_state === 'queued') applyNote = '承認されました。kintoneの部員名簿へ自動で反映されるまでお待ちください（数分〜十数分）。';
    else if (c.apply_state === 'running') applyNote = 'kintoneの部員名簿へ反映しています。';
    else if (c.apply_state === 'applied') applyNote = `第${esc(c.last_applied_revision_number)}版の内容がkintoneの部員名簿に反映されました（${esc(c.last_applied_at)}）。`;
    else if (['failed', 'conflict', 'unknown'].includes(c.apply_state)) applyNote = '承認後の反映処理で確認が必要になりました。総合管理事務局が対応しています。';
    return `
      <div class="rs-status">
        <div class="cluster">
          <span class="text-subtle text-sm">第${esc(c.latest_revision_number)}版</span>
          ${badge(c.review_label, stateTone(c.review_state))}
          ${c.review_state === 'accepted' || c.apply_state !== 'not_applicable' ? badge(c.apply_label, stateTone(c.apply_state)) : ''}
        </div>
        ${returnReason ? `
          <div class="alert alert--warning" role="status">
            ${icon('return')}
            <div class="alert__body">
              <strong>第${esc(latest.revision_number)}版の提出が差し戻されました（${esc(returnReason.at)}）</strong>
              <p class="pre-line allow-select">${esc(returnReason.reason)}</p>
              <p class="small-note">内容を修正して、もう一度提出してください。</p>
            </div>
          </div>` : ''}
        ${applyNote ? `<p class="small-note">${icon('info', 'ico--sm')}${applyNote}</p>` : ''}
        ${c.submit_blocked ? `<div class="alert alert--info">${icon('clock')}<div class="alert__body">前回承認された名簿を反映中のため、反映が終わるまで新しい提出はできません。下書きの保存はできます。</div></div>` : ''}
        ${c.pending_acceptance ? `<p class="small-note text-warning">${icon('alert-triangle', 'ico--sm')}いま新しく提出すると、承認済み・反映待ちの第${esc(c.latest_revision_number)}版は反映されず、新しい提出が確認待ちになります。</p>` : ''}
      </div>`;
  }

  function historyHtml(app) {
    const history = app.state.history || [];
    if (!history.length) return '';
    return `
      <details class="rs-history">
        <summary>${icon('history')}提出履歴（${history.length}件）</summary>
        <ol class="rs-history__list">
          ${history.map((h) => `
            <li>
              <div class="cluster">
                <strong>第${esc(h.revision_number)}版</strong>
                ${badge(h.review_label, stateTone(h.review_state))}
                <span class="text-subtle text-sm">${esc(h.submitted_at)} ・ ${esc(h.submitter_name)} ・ 在籍・入部 ${esc(h.active_count)}名 / 卒部・退部 ${esc(h.inactive_count)}名</span>
              </div>
              ${(h.reviews || []).map((r) => `<p class="rs-history__reason pre-line allow-select">${icon('return', 'ico--sm')}差し戻し理由（${esc(r.at)}）：${esc(r.reason)}</p>`).join('')}
            </li>`).join('')}
        </ol>
      </details>`;
  }

  /* ---------------- 提出済みの表示 ---------------- */
  function renderSummary(app, body) {
    const latest = app.state.latest;
    body.innerHTML = `
      <section class="rs-section">
        <div class="cluster cluster--between">
          <h3 class="rs-section__title">${icon('file-check')}第${esc(latest.revision_number)}版の提出内容</h3>
          <button type="button" class="btn btn--primary" data-rs="edit-latest">${icon('pen')}修正して再提出する</button>
        </div>
        ${countsHtml(latest.rows, app)}
        ${readonlyTableHtml(latest.rows)}
        ${latest.officers ? `<h3 class="rs-section__title">${icon('shield')}役員の割り当て</h3>${officerSummaryHtml(latest.officers)}` : ''}
      </section>`;
    body.querySelector('[data-rs="edit-latest"]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      button.classList.add('is-loading');
      try {
        const result = await call(app, 'edit_from_latest', { draft_version: app.draftVersion });
        if (!result.ok) { handleApiFailure(app, result, '編集を始められませんでした'); return; }
        await load(app);
      } catch (error) {
        toast(describeApiError(error).message, { type: 'error' });
      } finally {
        button.classList.remove('is-loading');
      }
    });
  }

  function readonlyTableHtml(rows) {
    return `
      <div class="table-wrap rs-table-wrap">
        <table class="table table--compact rs-table rs-table--readonly">
          <thead><tr><th>No.</th><th>状態</th><th>氏名</th><th>学籍番号</th><th>役職</th><th>電話番号</th><th>団体メール</th><th>HP更新</th></tr></thead>
          <tbody>
            ${rows.map((r, i) => `
              <tr>
                <td data-label="No." class="num">${i + 1}</td>
                <td data-label="状態">${esc(r.membership_status)}</td>
                <td data-label="氏名">${esc(r.name)}</td>
                <td data-label="学籍番号" class="mono">${esc(r.student_id)}</td>
                <td data-label="役職">${esc(roleLabel(r.role))}</td>
                <td data-label="電話番号" class="mono">${esc(r.phone_number)}</td>
                <td data-label="団体メール">${r.uses_group_email ? '使用' : '—'}</td>
                <td data-label="HP更新">${r.is_website_editor ? '担当' : '—'}</td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  }


  /* ---------------- 役員枠（役員連携の対象フォームだけ） ---------------- */
  function officerEnabled(app) { return Boolean(app.state?.settings?.officer_sync); }

  function normalizeOfficers(raw) {
    return {
      slots: { ...(raw?.slots && !Array.isArray(raw.slots) ? raw.slots : {}) },
      people: Array.isArray(raw?.people) ? raw.people.map((p) => ({ ...p })) : [],
    };
  }

  function officerSlots(app) { return app.options?.officer_slots || []; }

  /** 役員候補は同じ提出内の入部・在籍の部員全員（兼務できるよう名簿の役職では絞らない）。同じ役職の別の枠の人は除く */
  function officerCandidates(app, slot) {
    const active = activeStatuses(app);
    const others = officerSlots(app)
      .filter((s) => s.role === slot.role && s.key !== slot.key)
      .map((s) => app.officers.slots[s.key])
      .filter(Boolean);
    return app.rows.filter((r) => active.includes(r.membership_status)
      && String(r.student_id || '').trim() !== ''
      && !others.includes(r.student_id));
  }

  /** 枠が1つの役職で、名簿のその役職の部員が1人だけなら初期候補にする（複数枠は明示的に割り当てる） */
  function applySingleOfficerDefaults(app) {
    const slots = officerSlots(app);
    const active = activeStatuses(app);
    slots.forEach((slot) => {
      const capacity = slots.filter((s) => s.role === slot.role).length;
      if (capacity !== 1 || app.officers.slots[slot.key] || app.officerTouched[slot.key]) return;
      const candidates = app.rows.filter((r) => r.role === slot.role && active.includes(r.membership_status) && String(r.student_id || '').trim() !== '');
      if (candidates.length === 1) app.officers.slots[slot.key] = candidates[0].student_id;
    });
  }

  function heldSlots(app, studentId) {
    return officerSlots(app).filter((s) => app.officers.slots[s.key] === studentId);
  }

  function heldRoles(app, studentId) {
    return [...new Set(heldSlots(app, studentId).map((s) => s.role))];
  }

  function reflectRowRole(app, row) {
    const tr = app.host.querySelector(`tr[data-key="${CSS.escape(row.key)}"]`);
    if (!tr) return;
    const select = tr.querySelector('[data-f="role"]');
    if (select) select.value = row.role;
    const phone = tr.querySelector('[data-f="phone_number"]');
    if (phone) phone.placeholder = phoneRequired(app, row) ? '必須' : '任意';
  }

  /** 名簿の役職（ID20 は単一選択）を、実際に担当する役職のどれかにそろえる。マネージャーは名簿側の役職なのでそのまま */
  function syncPrimaryRoles(app) {
    app.rows.forEach((row) => {
      const held = heldRoles(app, row.student_id);
      if (!row.student_id || !held.length || held.includes(row.role) || row.role === 'マネージャー') return;
      row.role = held[0];
      reflectRowRole(app, row);
    });
  }

  function renameOfficerPerson(app, before, after) {
    if (!before || before === after) return;
    Object.keys(app.officers.slots).forEach((key) => { if (app.officers.slots[key] === before) app.officers.slots[key] = after; });
    app.officers.people.forEach((p) => { if (p.student_id === before) p.student_id = after; });
  }

  function clearOfficerPerson(app, studentId) {
    if (!studentId) return;
    Object.keys(app.officers.slots).forEach((key) => { if (app.officers.slots[key] === studentId) app.officers.slots[key] = ''; });
  }

  function officerPerson(app, studentId) {
    return app.officers.people.find((p) => p.student_id === studentId) || null;
  }

  function ensureOfficerPerson(app, studentId) {
    let person = officerPerson(app, studentId);
    if (!person) {
      person = { student_id: studentId, family_name: '', personal_name: '', mail: '', depend: '', type: '', org_depend_state: '' };
      app.officers.people.push(person);
    }
    return person;
  }

  function officerPersonHtml(app, studentId) {
    const row = app.rows.find((r) => r.student_id === studentId);
    const person = ensureOfficerPerson(app, studentId);
    const slots = heldSlots(app, studentId);
    const roles = heldRoles(app, studentId);
    const concurrent = roles.length > 1;
    const primaryRoles = row?.role === 'マネージャー' ? [...roles, 'マネージャー'] : roles;
    const selectOptions = (options, value) => `<option value="">選択</option>${options.map((o) => `<option${o === value ? ' selected' : ''}>${esc(o)}</option>`).join('')}`;
    return `
      <div class="rs-officer-person" data-person="${esc(studentId)}">
        <div class="rs-officer-person__head">
          <strong>${esc(row?.name || studentId)}</strong> <span class="mono text-subtle">${esc(studentId)}</span>
          <span class="chip">${esc(slots.map((s) => s.label).join('・'))}${concurrent ? '（兼務）' : ''}</span>
        </div>
        <div class="form-grid rs-officer-person__fields">
          <label class="field"><span class="field__label">姓<span class="req">必須</span></span><input type="text" data-person-field="family_name" maxlength="50" value="${esc(person.family_name || '')}" autocomplete="off"></label>
          <label class="field"><span class="field__label">名<span class="req">必須</span></span><input type="text" data-person-field="personal_name" maxlength="50" value="${esc(person.personal_name || '')}" autocomplete="off"></label>
          <label class="field"><span class="field__label">本人の大学メールアドレス<span class="req">必須</span></span><input type="email" data-person-field="mail" maxlength="200" value="${esc(person.mail || '')}" autocomplete="off" inputmode="email"></label>
          <label class="field"><span class="field__label">所属（学科等）<span class="req">必須</span></span><select data-person-field="depend">${selectOptions(app.options?.depend_options || [], person.depend)}</select></label>
          <label class="field"><span class="field__label">学生区分<span class="req">必須</span></span><select data-person-field="type">${selectOptions(app.options?.student_types || [], person.type)}</select></label>
          <label class="field"><span class="field__label">執行・議会組織への所属<span class="req">必須</span></span><select data-person-field="org_depend_state">${selectOptions(app.options?.org_depend_states || [], person.org_depend_state)}</select></label>
          ${concurrent ? `<label class="field"><span class="field__label">名簿に載せる役職（主役職）</span><select data-person-primary>${primaryRoles.map((r) => `<option value="${esc(r)}"${r === row?.role ? ' selected' : ''}>${esc(roleLabel(r))}</option>`).join('')}</select></label>` : ''}
        </div>
      </div>`;
  }

  function renderOfficers(app) {
    const node = app.host.querySelector('[data-rs="officers"]');
    if (!node || !officerEnabled(app)) return;
    applySingleOfficerDefaults(app);
    const slots = officerSlots(app);
    const assigned = [...new Set(slots.map((s) => app.officers.slots[s.key]).filter(Boolean))];
    const minimum = Number(app.options?.officer_min_active_members || 0);
    const activeCount = new Set(app.rows.filter((r) => activeStatuses(app).includes(r.membership_status) && r.student_id).map((r) => r.student_id)).size;
    node.innerHTML = `
      <h3 class="rs-section__title">${icon('shield')}役員の割り当て</h3>
      <p class="small-note">提出時点で実際に就任している役員を、入部・在籍の部員から選びます。代表者・副代表者1・会計長は必須です。異なる役職を兼務する場合は、同じ人を選べます。担当者がいない枠は「空席」のままにしてください。${minimum ? `入部・在籍の部員は${minimum}人以上必要です（現在${activeCount}人）。` : ''}</p>
      <div class="rs-officer-grid">
        ${slots.map((slot) => {
    const current = app.officers.slots[slot.key] || '';
    const candidates = officerCandidates(app, slot);
    const known = candidates.some((r) => r.student_id === current);
    const currentRow = app.rows.find((r) => r.student_id === current);
    return `
          <label class="field">
            <span class="field__label">${esc(slot.label)}${slot.required ? '<span class="req">必須</span>' : ''}</span>
            <select data-slot="${esc(slot.key)}">
              <option value="">${slot.required ? '選択してください' : '空席'}</option>
              ${candidates.map((r) => `<option value="${esc(r.student_id)}"${r.student_id === current ? ' selected' : ''}>${esc(r.name || r.student_id)}（${esc(r.student_id)}）</option>`).join('')}
              ${current && !known ? `<option value="${esc(current)}" selected>${esc(currentRow?.name || current)}（状態を確認してください）</option>` : ''}
            </select>
          </label>`;
  }).join('')}
      </div>
      ${assigned.length ? `
        <div class="rs-officer-people">
          <p class="small-note">大学の登録者情報との照合と新規登録に使います。兼務する人の入力は1人分です。登録済みかどうかは総合管理事務局が確認します。</p>
          ${assigned.map((sid) => officerPersonHtml(app, sid)).join('')}
        </div>` : ''}`;
  }

  function bindOfficers(app) {
    const node = app.host.querySelector('[data-rs="officers"]');
    if (!node) return;
    node.addEventListener('change', (event) => {
      const target = event.target;
      if (target.dataset.slot) {
        app.officers.slots[target.dataset.slot] = target.value;
        app.officerTouched[target.dataset.slot] = true;
        syncPrimaryRoles(app);
        renderOfficers(app);
        markDirty(app);
        return;
      }
      if (target.hasAttribute('data-person-primary')) {
        const row = app.rows.find((r) => r.student_id === target.closest('[data-person]').dataset.person);
        if (row) { row.role = target.value; reflectRowRole(app, row); markDirty(app); }
      }
    });
    const onPersonField = (event) => {
      const field = event.target.dataset.personField;
      if (!field) return;
      const person = officerPerson(app, event.target.closest('[data-person]').dataset.person);
      if (person) { person[field] = event.target.value; markDirty(app); }
    };
    node.addEventListener('input', onPersonField);
    node.addEventListener('change', onPersonField);
  }

  function officerSummaryHtml(items) {
    if (!items) return '';
    return `
      <div class="table-wrap rs-table-wrap">
        <table class="table table--compact rs-officer-table">
          <thead><tr><th>役員枠</th><th>部員</th><th>学籍番号</th><th>兼務</th></tr></thead>
          <tbody>${items.map((o) => `
            <tr><td>${esc(o.label)}${o.required ? ' <span class="req">必須</span>' : ''}</td><td>${o.student_id ? esc(o.name) : '<span class="text-subtle">空席</span>'}</td><td class="mono">${esc(o.student_id)}</td><td>${(o.concurrent || []).length ? esc(o.concurrent.join('・')) : '—'}</td></tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  }

  /* ---------------- 編集 ---------------- */
  let cachedRoles = [];
  function roleDefsFrom() { return cachedRoles; }
  function roleLabel(value) {
    const def = roleDefsFrom().find((d) => d.value === value);
    return def ? def.label : (value || '');
  }
  function activeStatuses(app) { return app.options?.active_statuses || ['入部', '在籍']; }

  function phoneRequired(app, row) {
    const s = app.state.settings || {};
    if (!activeStatuses(app).includes(row.membership_status)) return false;
    if (s.phone_required_scope === 'none') return false;
    if (s.phone_required_scope === 'all') return true;
    if (!row.role || row.role === '-') return false;
    if (row.role === 'マネージャー') return Boolean(s.phone_required_manager);
    return true;
  }

  function counts(app, rows) {
    const active = activeStatuses(app);
    const c = { total: rows.length, active: 0, inactive: 0, unset: 0 };
    rows.forEach((r) => {
      if (active.includes(r.membership_status)) c.active += 1;
      else if (!r.membership_status) c.unset += 1;
      else c.inactive += 1;
    });
    return c;
  }

  function countsHtml(rows, app) {
    const c = counts(app, rows);
    return `
      <div class="rs-counts" aria-live="polite">
        <span class="rs-count rs-count--active"><small>在籍・入部</small><strong>${c.active}</strong>名</span>
        <span class="rs-count"><small>卒部・退部</small><strong>${c.inactive}</strong>名</span>
        ${c.unset ? `<span class="rs-count rs-count--warn"><small>状態未設定</small><strong>${c.unset}</strong>名</span>` : ''}
        <span class="rs-count rs-count--total"><small>合計</small><strong>${c.total}</strong>名</span>
      </div>`;
  }

  function renderEditor(app, body) {
    const base = app.state.draft?.base;
    const s = app.state.settings || {};
    const phoneRule = s.phone_required_scope === 'all' ? '在籍・入部の全員'
      : s.phone_required_scope === 'none' ? '必須なし'
        : `役職のある在籍・入部の部員${s.phone_required_manager ? '（マネージャーを含む）' : '（マネージャーを除く）'}`;
    body.innerHTML = `
      <section class="rs-section">
        <div class="rs-toolbar">
          <div class="cluster">
            <button type="button" class="btn" data-rs="load-kintone">${icon('download')}kintoneの名簿を読み込む</button>
            <button type="button" class="btn" data-rs="add-row">${icon('plus')}部員を追加</button>
            <button type="button" class="btn btn--ghost" data-rs="sort">${icon('list')}役職順に並べる</button>
          </div>
          <div data-rs="counts">${countsHtml(app.rows, app)}</div>
        </div>
        <p class="small-note">${base && base.loaded
          ? `${icon('cloud', 'ico--sm')}kintoneの名簿（${esc(base.fetched_at)} 読込・${esc(base.row_count)}名）をもとに編集しています。登録済みの部員は削除できません。卒部・退部した部員は「状態」を変更してください。`
          : `${icon('info', 'ico--sm')}kintoneの名簿は読み込んでいません。登録済みの部員がいる場合は、先に「kintoneの名簿を読み込む」を押すと二重登録を防げます。`}
          電話番号の必須：${esc(phoneRule)}。</p>
        <div class="table-wrap rs-table-wrap">
          <table class="table table--compact rs-table" data-rs="table">
            <thead><tr>
              <th class="rs-col-no">No.</th><th>状態<span class="req">必須</span></th><th>氏名<span class="req">必須</span></th><th>学籍番号<span class="req">必須</span></th>
              <th>役職<span class="req">必須</span></th><th>電話番号</th><th class="rs-col-check">団体メール<br>使用者</th><th class="rs-col-check">HP更新<br>担当者</th><th class="rs-col-op"><span class="sr-only">操作</span></th>
            </tr></thead>
            <tbody data-rs="tbody"></tbody>
          </table>
        </div>
        <div data-rs="empty" class="empty"${app.rows.length ? ' hidden' : ''}><span class="empty__icon">${icon('users')}</span><span>部員がまだいません。「部員を追加」またはkintoneの名簿の読み込みから始めてください。</span></div>
        ${officerEnabled(app) ? '<div class="rs-officers" data-rs="officers"></div>' : ''}
        <div class="fp-submit rs-submit">
          <div class="fp-submit__status"><span class="fp-saved" data-rs="save-status">${saveStatusText(app)}</span></div>
          <div class="fp-submit__actions">
            <button type="button" class="btn btn--ghost" data-rs="save">${icon('save')}<span>下書きを保存</span></button>
            <button type="button" class="btn btn--primary btn--lg" data-rs="preview">${icon('eye')}<span>提出内容を確認</span></button>
          </div>
        </div>
      </section>`;
    renderRows(app);
    bindEditor(app, body);
    bindOfficers(app);
  }

  function saveStatusText(app) {
    if (app.saving) return `${icon('refresh', 'ico--sm')}保存しています…`;
    if (app.dirty) return `${icon('pen', 'ico--sm')}未保存の変更があります`;
    if (app.lastSavedAt) return `${icon('check', 'ico--sm')}下書きをサーバーに保存済み（${esc(String(app.lastSavedAt).slice(5, 16))}）`;
    return `${icon('save', 'ico--sm')}入力内容はサーバーに下書き保存されます`;
  }

  function updateSaveStatus(app) {
    const node = app.host.querySelector('[data-rs="save-status"]');
    if (node) node.innerHTML = saveStatusText(app);
  }

  function rowHtml(app, row, index) {
    const statuses = app.options?.statuses || ['入部', '在籍', '卒部', '退部'];
    const errors = app.errorsByKey[row.key] || {};
    const fromKintone = Boolean(row.kintone_row_id);
    const required = phoneRequired(app, row);
    const invalid = (field) => (errors[field] ? ' aria-invalid="true"' : '');
    const errorText = Object.values(errors).join(' ');
    return `
      <tr data-key="${esc(row.key)}" class="${errorText ? 'is-invalid' : ''}">
        <td class="rs-col-no num" data-label="No.">${index + 1}${fromKintone ? `<span class="rs-origin" title="kintoneに登録済みの部員">${icon('cloud', 'ico--sm')}</span>` : ''}</td>
        <td data-label="状態"><select data-f="membership_status"${invalid('membership_status')} aria-label="${index + 1}行目の状態">
          <option value="">選択</option>${statuses.map((s) => `<option${s === row.membership_status ? ' selected' : ''}>${esc(s)}</option>`).join('')}
        </select></td>
        <td data-label="氏名"><input type="text" data-f="name" value="${esc(row.name)}" maxlength="100"${invalid('name')} aria-label="${index + 1}行目の氏名" autocomplete="off"></td>
        <td data-label="学籍番号"><input type="text" data-f="student_id" value="${esc(row.student_id)}" maxlength="32" class="mono"${invalid('student_id')} aria-label="${index + 1}行目の学籍番号" inputmode="text" autocomplete="off" spellcheck="false"></td>
        <td data-label="役職"><select data-f="role"${invalid('role')} aria-label="${index + 1}行目の役職">
          ${roleDefsFrom().map((d) => `<option value="${esc(d.value)}"${d.value === (row.role || '-') ? ' selected' : ''}>${esc(d.label)}</option>`).join('')}
        </select></td>
        <td data-label="電話番号"><input type="tel" data-f="phone_number" value="${esc(row.phone_number)}" maxlength="20" class="mono"${invalid('phone_number')} placeholder="${required ? '必須' : '任意'}" aria-label="${index + 1}行目の電話番号" autocomplete="off"></td>
        <td data-label="団体メール使用者" class="rs-col-check"><label class="check"><input type="checkbox" data-f="uses_group_email"${row.uses_group_email ? ' checked' : ''}><span class="rs-check-label">使用</span></label></td>
        <td data-label="HP更新担当者" class="rs-col-check"><label class="check"><input type="checkbox" data-f="is_website_editor"${row.is_website_editor ? ' checked' : ''}><span class="rs-check-label">担当</span></label></td>
        <td class="rs-col-op">${fromKintone ? '' : `<button type="button" class="btn btn--ghost btn--icon btn--sm" data-rs="remove" aria-label="${index + 1}行目を削除" title="この行を削除">${icon('trash')}</button>`}</td>
      </tr>
      ${errorText ? `<tr class="rs-row-error" data-error-for="${esc(row.key)}"><td colspan="9">${icon('alert-circle', 'ico--sm')}${esc(errorText)}</td></tr>` : ''}`;
  }

  function renderRows(app) {
    const tbody = app.host.querySelector('[data-rs="tbody"]');
    if (!tbody) return;
    tbody.innerHTML = app.rows.map((row, i) => rowHtml(app, row, i)).join('');
    const empty = app.host.querySelector('[data-rs="empty"]');
    if (empty) empty.hidden = app.rows.length > 0;
    refreshCounts(app);
    renderOfficers(app);
  }

  function refreshCounts(app) {
    const node = app.host.querySelector('[data-rs="counts"]');
    if (node) node.innerHTML = countsHtml(app.rows, app);
  }

  function markDirty(app) {
    app.dirty = true;
    updateSaveStatus(app);
    window.clearTimeout(app.saveTimer);
    app.saveTimer = window.setTimeout(() => saveDraft(app), AUTOSAVE_MS);
  }

  async function saveDraft(app, { quiet = true } = {}) {
    window.clearTimeout(app.saveTimer);
    if (app.saving) { app.saveTimer = window.setTimeout(() => saveDraft(app, { quiet }), 800); return true; }
    if (!app.dirty && quiet) return true;
    app.saving = true;
    updateSaveStatus(app);
    try {
      const result = await call(app, 'save_draft', { rows: app.rows, officers: app.officers, draft_version: app.draftVersion });
      if (!result.ok) { handleApiFailure(app, result, '下書きを保存できませんでした'); return false; }
      app.draftVersion = result.draft_version;
      app.lastSavedAt = result.saved_at || '';
      app.dirty = false;
      if (!quiet) toast('', { type: 'success', title: '下書きを保存しました', duration: 2200 });
      return true;
    } catch (error) {
      const info = describeApiError(error);
      toast(info.message, { type: 'error', title: '下書きを保存できませんでした', detail: info.detail });
      return false;
    } finally {
      app.saving = false;
      updateSaveStatus(app);
    }
  }

  function bindEditor(app, body) {
    const tbody = body.querySelector('[data-rs="tbody"]');
    const onField = (event) => {
      const target = event.target;
      const field = target?.dataset?.f;
      const tr = target?.closest('tr[data-key]');
      if (!field || !tr) return;
      const row = app.rows.find((r) => r.key === tr.dataset.key);
      if (!row) return;
      const previousStudentId = row.student_id;
      row[field] = target.type === 'checkbox' ? target.checked : target.value;
      if (field === 'student_id') renameOfficerPerson(app, previousStudentId, row.student_id);
      if (['student_id', 'name', 'role', 'membership_status'].includes(field)) renderOfficers(app);
      if (target.getAttribute('aria-invalid') === 'true') target.removeAttribute('aria-invalid');
      if (field === 'membership_status' || field === 'role') {
        const phone = tr.querySelector('[data-f="phone_number"]');
        if (phone) phone.placeholder = phoneRequired(app, row) ? '必須' : '任意';
        refreshCounts(app);
      }
      markDirty(app);
    };
    tbody.addEventListener('input', onField);
    tbody.addEventListener('change', onField);
    tbody.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-rs="remove"]');
      if (!button) return;
      const tr = button.closest('tr[data-key]');
      const row = app.rows.find((r) => r.key === tr?.dataset.key);
      if (!row) return;
      if (row.name || row.student_id) {
        const ok = await UI.confirm({ title: 'この行を削除しますか？', message: `${row.name || '（氏名未入力）'} ${row.student_id || ''}`, okText: '削除する', tone: 'danger' });
        if (!ok) return;
      }
      app.rows = app.rows.filter((r) => r !== row);
      clearOfficerPerson(app, row.student_id);
      delete app.errorsByKey[row.key];
      renderRows(app);
      markDirty(app);
    });

    body.querySelector('[data-rs="add-row"]').addEventListener('click', () => {
      const max = Number(app.state.settings?.max_rows || 500);
      if (app.rows.length >= max) { toast(`名簿は${max}行までです。`, { type: 'warning' }); return; }
      const row = { key: newKey(), kintone_row_id: null, membership_status: '入部', name: '', student_id: '', role: '-', phone_number: '', uses_group_email: false, is_website_editor: false };
      app.rows.push(row);
      renderRows(app);
      markDirty(app);
      const input = app.host.querySelector(`tr[data-key="${CSS.escape(row.key)}"] [data-f="name"]`);
      input?.focus();
      input?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });

    body.querySelector('[data-rs="sort"]').addEventListener('click', () => {
      const active = activeStatuses(app);
      const order = (role) => (roleDefsFrom().find((d) => d.value === role)?.order ?? 100);
      app.rows = app.rows.map((r, i) => [r, i]).sort((a, b) => {
        const aa = active.includes(a[0].membership_status) ? 0 : 1;
        const bb = active.includes(b[0].membership_status) ? 0 : 1;
        return aa - bb || order(a[0].role || '-') - order(b[0].role || '-') || a[1] - b[1];
      }).map((pair) => pair[0]);
      renderRows(app);
      markDirty(app);
      toast('', { type: 'info', title: '在籍・入部を先に、役職順に並べました', duration: 2600 });
    });

    body.querySelector('[data-rs="load-kintone"]').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      if (app.rows.length) {
        const ok = await UI.confirm({
          title: 'kintoneの名簿を読み込みますか？',
          message: '現在の下書きの内容は、kintoneに登録されている名簿で置き換わります。',
          okText: '読み込む',
          tone: 'warning',
        });
        if (!ok) return;
      }
      if (app.dirty && !(await saveDraft(app))) return;
      button.classList.add('is-loading');
      button.disabled = true;
      try {
        const result = await call(app, 'load_kintone', { draft_version: app.draftVersion });
        if (!result.ok) { handleApiFailure(app, result, '名簿を読み込めませんでした'); return; }
        toast('', { type: 'success', title: `kintoneの名簿を読み込みました（${result.rows.length}名）`, duration: 3000 });
        await load(app);
        const unset = app.rows.filter((r) => !r.membership_status).length;
        if (unset) toast(`${unset}名の「状態」が未設定です。提出前に選択してください。`, { type: 'warning', title: '状態を選んでください', duration: 8000 });
      } catch (error) {
        toast(describeApiError(error).message, { type: 'error', title: '名簿を読み込めませんでした' });
      } finally {
        if (document.contains(button)) { button.classList.remove('is-loading'); button.disabled = false; }
      }
    });

    body.querySelector('[data-rs="save"]').addEventListener('click', () => saveDraft(app, { quiet: false }));
    body.querySelector('[data-rs="preview"]').addEventListener('click', (event) => openPreview(app, event.currentTarget));
  }

  async function openPreview(app, button) {
    button.classList.add('is-loading');
    button.disabled = true;
    try {
      if (app.dirty && !(await saveDraft(app))) return;
      const result = await call(app, 'preview', { rows: app.rows, officers: app.officers });
      if (!result.ok) { handleApiFailure(app, result, '確認できませんでした'); return; }
      app.errorsByKey = {};
      (result.validation?.errors || []).forEach((e) => {
        if (!e.key) return;
        app.errorsByKey[e.key] = app.errorsByKey[e.key] || {};
        app.errorsByKey[e.key][e.field || 'row'] = e.message.replace(/^\d+行目：/, '');
      });
      app.preview = result;
      app.view = 'preview';
      render(app);
      app.host.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
      toast(describeApiError(error).message, { type: 'error', title: '確認できませんでした' });
    } finally {
      if (document.contains(button)) { button.classList.remove('is-loading'); button.disabled = false; }
    }
  }

  /* ---------------- 提出前の確認 ---------------- */
  function kindLabel(kind) {
    return { added: '追加', changed: '変更', unchanged: '変更なし', ambiguous: '要確認' }[kind] || kind;
  }

  function renderPreview(app, body) {
    const p = app.preview;
    const errors = p.validation?.errors || [];
    const warnings = p.validation?.warnings || [];
    const sum = p.diff?.summary || {};
    const changed = (p.diff?.entries || []).filter((e) => e.kind !== 'unchanged');
    const blocked = Boolean(app.state.case?.submit_blocked);
    body.innerHTML = `
      <section class="rs-section stack">
        <h3 class="rs-section__title">${icon('eye')}提出内容の確認</h3>
        ${errors.length ? `
          <div class="alert alert--danger" role="alert">${icon('alert-circle')}
            <div class="alert__body"><strong>${errors.length}件の不足・誤りがあります。修正してから提出してください。</strong>
              <ul class="rs-msg-list">${errors.slice(0, 30).map((e) => `<li>${esc(e.message)}</li>`).join('')}${errors.length > 30 ? `<li>ほか${errors.length - 30}件</li>` : ''}</ul>
            </div>
          </div>` : `<div class="alert alert--success">${icon('check-circle')}<div class="alert__body">必須項目はすべて入力されています。</div></div>`}
        ${warnings.length ? `<div class="alert alert--warning">${icon('alert-triangle')}<div class="alert__body"><ul class="rs-msg-list">${warnings.map((w) => `<li>${esc(w.message)}</li>`).join('')}</ul></div></div>` : ''}
        ${countsHtml(p.rows, app)}
        <div class="rs-diff-summary">
          ${p.base && p.base.loaded ? `<span>kintoneの名簿（${esc(p.base.fetched_at)} 読込）との違い：</span>` : '<span>kintoneの名簿を読み込んでいないため、すべて新規として表示しています：</span>'}
          <span class="chip">追加 ${sum.added || 0}</span><span class="chip">変更 ${sum.changed || 0}</span>
          <span class="chip">うち状態変更 ${sum.status_changed || 0}</span>${sum.student_id_corrected ? `<span class="chip">学籍番号の訂正 ${sum.student_id_corrected}</span>` : ''}
          ${sum.kept ? `<span class="chip">提出に含まれない登録済み部員 ${sum.kept}（削除されません）</span>` : ''}
        </div>
        ${changed.length ? `
          <div class="table-wrap rs-table-wrap">
            <table class="table table--compact rs-diff-table">
              <thead><tr><th>No.</th><th>区分</th><th>氏名</th><th>学籍番号</th><th>変更内容</th></tr></thead>
              <tbody>${changed.map((e) => `
                <tr><td class="num">${esc(e.no)}</td><td>${badge(kindLabel(e.kind), e.kind === 'added' ? 'success' : e.kind === 'ambiguous' ? 'warning' : 'info')}</td>
                <td>${esc(e.name)}</td><td class="mono">${esc(e.student_id)}</td>
                <td>${e.kind === 'added' ? `${esc(e.status)}・${esc(e.role_label)}` : e.changes.map((c) => `<div>${esc(c.label)}：<span class="text-subtle">${esc(c.from)}</span> → <strong>${esc(c.to)}</strong></div>`).join('')}</td></tr>`).join('')}
              </tbody>
            </table>
          </div>` : '<p class="small-note">kintoneの名簿から変更はありません。</p>'}
        ${p.diff?.kept?.length ? `<details><summary>提出に含まれない登録済みの部員（${p.diff.kept.length}名・kintoneにはそのまま残ります）</summary><ul class="rs-msg-list">${p.diff.kept.map((k) => `<li>${esc(k.name)} <span class="mono">${esc(k.student_id)}</span> ${esc(k.status || '')}</li>`).join('')}</ul></details>` : ''}
        ${p.officers ? `<div class="stack stack--sm"><h3 class="rs-section__title">${icon('shield')}役員の割り当て</h3>${officerSummaryHtml(p.officers)}<p class="small-note">役員の情報は、総合管理事務局が登録者・役員台帳と照合して確認します。</p></div>` : ''}
        <label class="field rs-submitter">
          <span class="field__label">提出者の氏名<span class="req">必須</span></span>
          <input type="text" name="submitter_name" maxlength="100" autocomplete="name" placeholder="例：福岡 太郎（部長）">
        </label>
        <div class="alert alert--neutral">${icon('info')}<div class="alert__body">提出しても、すぐにはkintoneへ反映されません。総合管理事務局が内容を確認して承認（accept）した後に、自動で反映されます。内容に問題があれば理由を添えて差し戻されます。</div></div>
        <div class="fp-submit rs-submit">
          <div class="fp-submit__status"></div>
          <div class="fp-submit__actions">
            <button type="button" class="btn btn--ghost" data-rs="back">${icon('arrow-left')}<span>戻って修正</span></button>
            <button type="button" class="btn btn--primary btn--lg" data-rs="submit"${errors.length || blocked ? ' disabled' : ''}>${icon('send')}<span>${esc(app.form.settings?.submit_button_label && app.form.settings.submit_button_label !== '送信する' ? app.form.settings.submit_button_label : 'この内容で提出する')}</span></button>
          </div>
        </div>
      </section>`;
    body.querySelector('[data-rs="back"]').addEventListener('click', () => { app.view = 'edit'; render(app); });
    body.querySelector('[data-rs="submit"]').addEventListener('click', (event) => submit(app, event.currentTarget));
  }

  async function submit(app, button) {
    const nameInput = app.host.querySelector('input[name="submitter_name"]');
    const submitterName = (nameInput?.value || '').trim();
    if (!submitterName) {
      nameInput?.setAttribute('aria-invalid', 'true');
      nameInput?.focus();
      toast('提出者の氏名を入力してください。', { type: 'error' });
      return;
    }
    const ok = await UI.confirm({ title: '部員名簿を提出しますか？', message: `在籍・入部 ${counts(app, app.preview.rows).active}名 / 合計 ${app.preview.rows.length}名で提出します。`, okText: '提出する' });
    if (!ok) return;
    button.classList.add('is-loading');
    button.disabled = true;
    try {
      const result = await call(app, 'submit', { rows: app.rows, officers: app.officers, draft_version: app.draftVersion, submitter_name: submitterName });
      if (!result.ok) {
        if (result.reason === 'validation') {
          app.view = 'edit';
          render(app);
        }
        if (result.reason === 'busy' && result.draft_version) app.draftVersion = result.draft_version;
        handleApiFailure(app, result, '提出できませんでした');
        return;
      }
      app.host.innerHTML = `
        <div class="fp-state fp-state--success" role="status" tabindex="-1" id="rs-success">
          <span class="fp-state__icon fp-state__icon--check"><svg viewBox="0 0 52 52" class="fp-check" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="m15 27 7.5 7.5L37 19"/></svg></span>
          <h2>第${esc(result.revision_number)}版を提出しました</h2>
          <p class="allow-select">${esc(result.message)}</p>
          <p class="small-note">承認後にkintoneの部員名簿へ自動で反映されます。状況はこの画面でいつでも確認できます。</p>
          <div class="cluster" style="justify-content:center"><button type="button" class="btn" data-rs="reload">${icon('history')}提出状況を見る</button></div>
        </div>`;
      app.host.querySelector('#rs-success')?.focus({ preventScroll: true });
      app.host.querySelector('[data-rs="reload"]').addEventListener('click', () => load(app));
    } catch (error) {
      const info = describeApiError(error);
      toast(info.message, { type: 'error', title: '提出できませんでした', detail: info.detail, duration: 8000 });
    } finally {
      if (document.contains(button)) { button.classList.remove('is-loading'); button.disabled = false; }
    }
  }

  async function logout(app) {
    if (app.dirty) await saveDraft(app);
    try { await call(app, 'logout'); } catch (error) { /* noop */ }
    app.pendingCode = null;
    load(app);
  }

  window.addEventListener('beforeunload', (event) => {
    const dirty = document.querySelector('[data-roster]') && window.FitscRoster?._dirty?.();
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });

  let current = null;
  window.FitscRoster = {
    mount(host, form) {
      current = mount(host, form);
      return current;
    },
    _dirty() { return Boolean(current && current.dirty); },
  };
})();
