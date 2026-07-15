<?php
declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';

$user = admin_auth_require_login();
$csrfToken = admin_auth_get_csrf_token();

function admin_index_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_index_time_options(string $selected = '', bool $allow2400 = false): string
{
    $html = '';
    $maxMinutes = $allow2400 ? 24 * 60 : (24 * 60) - 15;
    for ($minutes = 0; $minutes <= $maxMinutes; $minutes += 15) {
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;
        $value = sprintf('%02d:%02d', $hour, $minute);
        $isSelected = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . admin_index_h($value) . '"' . $isSelected . '>' . admin_index_h($value) . '</option>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>貸し部屋予約 管理画面</title>
  <script>
    window.AdminBootstrap = <?php echo json_encode([
      'currentUser' => [
        'id' => (int)($user['id'] ?? 0),
        'login_id' => (string)($user['login_id'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? ''),
        'role_key' => (string)($user['role_key'] ?? 'viewer'),
        'role_label' => (string)($user['role_label'] ?? '閲覧者'),
        'permissions' => array_values(array_map('strval', admin_auth_user_permissions($user))),
      ],
      'csrfToken' => $csrfToken,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  </script>
  <link rel="stylesheet" href="./assets/common/tokens.css?v=20260716a">
  <link rel="stylesheet" href="./css/reservation-admin.css?v=20260715a">
  <link rel="stylesheet" href="./assets/common/fit-sc-skin.css?v=20260716a">
  <link rel="stylesheet" href="./css/reservation-admin-responsive.css?v=20260713">
  <script src="./assets/common/context-menu-guard.js?v=20260713" defer></script>
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar">
      <div class="sidebar-brand">
        <h1>貸し部屋予約</h1>
        <p>自動予約システム 管理画面</p>
      </div>

      <nav class="sidebar-nav">
        <button type="button" class="sidebar-link is-active" data-view-target="dashboardView">予約一覧</button>
        <button type="button" class="sidebar-link" data-view-target="calendarView">月間カレンダー</button>
        <button type="button" class="sidebar-link" data-view-target="passcodeView">パスコード状況</button>
        <button type="button" class="sidebar-link" data-view-target="adminView">管理設定</button>
      </nav>

      <div class="sidebar-user">
        <div class="sidebar-user-card">
          <strong><?php echo admin_index_h((string)($user['display_name'] ?? '')); ?></strong>
          <span><?php echo admin_index_h((string)($user['role_label'] ?? '閲覧者')); ?> / <?php echo admin_index_h((string)($user['login_id'] ?? '')); ?></span>
        </div>
        <form method="post" action="logout.php" class="sidebar-logout-form">
          <?php echo admin_auth_csrf_field(); ?>
          <button type="submit" class="sidebar-logout-link">ログアウト</button>
        </form>
      </div>
    </aside>

    <main class="content-shell">
      <section id="dashboardView" class="content-view is-active">
        <div class="wrap">
          <header class="page-head">
            <div>
              <h1>予約一覧</h1>
              <p class="lead">利用者側から自動処理された予約と、管理者画面から追加した予約の一覧です。複数日予約、利用時間、確定 / 却下 / 要確認 / 外部連携状況を確認できます。</p>
            </div>
            <div class="head-actions">
              <button id="dashboardReloadBtn" type="button" class="secondary">再読込</button>
            </div>
          </header>

          <section class="panel">
            <div class="summary-grid">
              <article class="summary-card">
                <span class="summary-label">今後の確定予約</span>
                <strong id="summaryConfirmedCount">-</strong>
              </article>
              <article class="summary-card">
                <span class="summary-label">SwitchBot 要確認</span>
                <strong id="summarySwitchbotIssueCount">-</strong>
              </article>
              <article class="summary-card">
                <span class="summary-label">本日の新規受付</span>
                <strong id="summaryTodayCount">-</strong>
              </article>
              <article class="summary-card">
                <span class="summary-label">未送信メール</span>
                <strong id="summaryMailIssueCount">-</strong>
              </article>
            </div>

            <div class="filters-grid">
              <label class="field">
                <span>利用月</span>
                <input type="month" id="dashboardMonth">
              </label>

              <label class="field">
                <span>部屋</span>
                <select id="dashboardRoom">
                  <option value="">すべて</option>
                  <option value="tamoku">多目的室</option>
                  <option value="orange">オレンジの部屋</option>
                </select>
              </label>

              <label class="field">
                <span>予約状態</span>
                <select id="dashboardStatus">
                  <option value="">すべて</option>
                  <option value="confirmed">確定</option>
                  <option value="rejected">却下</option>
                  <option value="error">要確認</option>
                  <option value="pending">保留</option>
                  <option value="replaced">上書き済み</option>
                  <option value="deleted">削除済み</option>
                </select>
              </label>

              <label class="field field-wide">
                <span>キーワード</span>
                <input type="search" id="dashboardKeyword" placeholder="メールアドレス・団体名で検索">
              </label>
            </div>

            <div class="toolbar">
              <button id="dashboardSearchBtn" type="button">検索</button>
              <button id="dashboardClearBtn" type="button" class="secondary">条件クリア</button>
            </div>

            <div class="meta-row">
              <div class="meta" id="dashboardMetaText">読み込み前です。</div>
              <div class="status" id="dashboardStatusText" aria-live="polite"></div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>受付日時</th>
                    <th>利用日</th>
                    <th>利用時間</th>
                    <th>部屋</th>
                    <th>団体名</th>
                    <th>メール</th>
                    <th>予約状態</th>
                    <th>パスコード</th>
                    <th>外部連携</th>
                    <th>メール</th>
                  </tr>
                </thead>
                <tbody id="dashboardTableBody">
                  <tr><td colspan="11" class="empty">読み込み前です。</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </section>

      <section id="calendarView" class="content-view">
        <div class="wrap">
          <header class="page-head">
            <div>
              <h1>月間カレンダー</h1>
              <p class="lead">確定済みまたは確認中の予約日を月単位で確認できます。日付セルをクリックすると部屋ごとの予約詳細を表示し、そのまま追加 / 上書きや削除も行えます。</p>
            </div>
            <div class="head-actions">
              <button id="calendarReloadBtn" type="button" class="secondary">再読込</button>
            </div>
          </header>

          <section class="panel">
            <div class="filters-grid">
              <label class="field">
                <span>表示月</span>
                <input type="month" id="calendarMonth">
              </label>
            </div>

            <div class="meta-row">
              <div class="meta" id="calendarMetaText">読み込み前です。</div>
              <div class="status" id="calendarStatusText" aria-live="polite"></div>
            </div>

            <div class="calendar-admin-grid" id="calendarAdminGrid"></div>

            <div class="modal-overlay calendar-detail-modal" id="calendarDetailDialog" hidden>
              <div class="modal-dialog calendar-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="calendarDetailTitle">
                <div class="calendar-detail-dialog-head">
                  <div class="page-head page-head-compact">
                    <div>
                      <h2 id="calendarDetailTitle">日付を選択してください</h2>
                      <p class="lead" id="calendarDetailLead">予約が入っている日をクリックすると、部屋ごとの詳細を表示します。</p>
                    </div>
                    <div class="head-actions">
                      <button type="button" class="secondary modal-close-btn" id="calendarDetailCloseBtn" aria-label="閉じる">閉じる</button>
                    </div>
                  </div>

                  <div class="meta-row">
                    <div class="meta" id="calendarDetailMetaText">まだ日付が選択されていません。</div>
                    <div class="status" id="calendarDetailStatusText" aria-live="polite"></div>
                  </div>
                </div>

                <div class="calendar-detail-cards" id="calendarDetailCards">
                  <article class="calendar-detail-empty">日付を選択すると詳細が表示されます。</article>
                </div>
              </div>
            </div>

            <div class="calendar-manual-section" id="calendarManualSection">
              <div class="page-head page-head-compact">
                <div>
                  <h2>カレンダーへ追加 / 上書き</h2>
                  <p class="lead">日付セルまたは「追加」ボタンを押すと利用日が入ります。同日・同室に既存予約がある場合は、その枠を上書きします。</p>
                </div>
              </div>

              <div class="calendar-form-grid calendar-manual-grid">
                <label class="field">
                  <span>利用日</span>
                  <input type="date" id="manualUseDate">
                </label>

                <label class="field">
                  <span>部屋</span>
                  <select id="manualRoomCode">
                    <option value="tamoku">多目的室</option>
                    <option value="orange">オレンジの部屋</option>
                  </select>
                </label>

                <label class="field field-wide">
                  <span>団体名</span>
                  <input type="text" id="manualOrganizationName" maxlength="150" placeholder="例：総合管理事務局">
                </label>

                <label class="field">
                  <span>利用開始時刻</span>
                  <select id="manualUsageStartTime"><?php echo admin_index_time_options('09:00', false); ?></select>
                </label>

                <label class="field">
                  <span>利用終了時刻</span>
                  <select id="manualUsageEndTime"><?php echo admin_index_time_options('10:00', true); ?></select>
                </label>

                <label class="field field-wide">
                  <span>利用者向けメールアドレス <small>※入力されている場合のみ送信</small></span>
                  <input type="email" id="manualEmail" maxlength="255" placeholder="例：group@example.jp">
                </label>
              </div>

              <div class="checkbox-grid">
                <label class="check-card">
                  <input type="checkbox" id="manualSyncGoogle" checked>
                  <span>
                    <strong>Google カレンダーに反映する</strong>
                    <small>部屋に対応する共有カレンダーへ予定を追加します。</small>
                  </span>
                </label>

                <label class="check-card">
                  <input type="checkbox" id="manualIssueSwitchbot" checked>
                  <span>
                    <strong>SwitchBot でパスコードを発行する</strong>
                    <small>有効期間付きのパスコードを発行します。</small>
                  </span>
                </label>
              </div>

              <div class="toolbar">
                <button id="calendarManualCreateBtn" type="button">追加 / 上書き登録</button>
                <button id="calendarManualResetBtn" type="button" class="secondary">入力クリア</button>
              </div>

              <div class="meta-row">
                <div class="meta" id="calendarManualMetaText">利用者向けメールはメールアドレス入力時のみ送信されます。sogokanri@bene.fit.ac.jp への通知は Google / SwitchBot の両方を選択したときのみ送信します。</div>
                <div class="status" id="calendarManualStatusText" aria-live="polite"></div>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>利用日</th>
                    <th>利用時間</th>
                    <th>部屋</th>
                    <th>団体名</th>
                    <th>メール</th>
                    <th>パスコード</th>
                    <th>SwitchBot</th>
                    <th>Google</th>
                  </tr>
                </thead>
                <tbody id="calendarListBody">
                  <tr><td colspan="8" class="empty">読み込み前です。</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </section>

      <section id="passcodeView" class="content-view">
        <div class="wrap">
          <header class="page-head">
            <div>
              <h1>パスコード状況</h1>
              <p class="lead">発行済みパスコードと SwitchBot 反映状況の最新一覧です。</p>
            </div>
            <div class="head-actions">
              <button id="passcodeReloadBtn" type="button" class="secondary">再読込</button>
            </div>
          </header>

          <section class="panel">
            <div class="meta-row">
              <div class="meta" id="passcodeMetaText">読み込み前です。</div>
              <div class="status" id="passcodeStatusText" aria-live="polite"></div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>利用日</th>
                    <th>利用時間</th>
                    <th>部屋</th>
                    <th>団体名</th>
                    <th>メール</th>
                    <th>パスコード</th>
                    <th>有効期間</th>
                    <th>SwitchBot</th>
                    <th>request_id</th>
                  </tr>
                </thead>
                <tbody id="passcodeTableBody">
                  <tr><td colspan="9" class="empty">読み込み前です。</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </section>

      <section id="adminView" class="content-view">
        <div class="wrap">
          <header class="page-head">
            <div>
              <h1>管理設定</h1>
              <p class="lead">管理者アカウントと監査ログを確認できます。</p>
            </div>
            <div class="head-actions">
              <button id="adminReloadBtn" type="button" class="secondary">再読込</button>
            </div>
          </header>

          <section class="panel admin-users-section">
            <div class="page-head page-head-compact">
              <div>
                <h2>管理者アカウント</h2>
                <p class="lead">viewer / user / admin の管理を行えます。</p>
              </div>
            </div>

            <div class="calendar-form-grid">
              <input type="hidden" id="adminUserId">

              <label class="field">
                <span>ログインID</span>
                <input type="text" id="adminLoginId" maxlength="100" placeholder="例：admin.taro">
              </label>

              <label class="field">
                <span>表示名</span>
                <input type="text" id="adminDisplayName" maxlength="100" placeholder="例：総務 太郎">
              </label>

              <label class="field field-wide">
                <span>メールアドレス</span>
                <input type="email" id="adminEmail" maxlength="255" placeholder="例：admin@example.jp">
              </label>

              <label class="field">
                <span>ロール</span>
                <select id="adminRoleKey">
                  <option value="viewer">閲覧者 (viewer)</option>
                  <option value="user">編集者 (user)</option>
                  <option value="admin">管理者 (admin)</option>
                </select>
              </label>

              <label class="field">
                <span>状態</span>
                <select id="adminIsActive">
                  <option value="1">有効</option>
                  <option value="0">無効</option>
                </select>
              </label>

              <label class="field field-wide">
                <span>パスワード <small>※更新時は空欄で変更なし</small></span>
                <input type="password" id="adminPassword" minlength="10" placeholder="10文字以上">
              </label>
            </div>

            <div class="toolbar admin-toolbar">
              <button id="adminUserCreateBtn" type="button">新規作成</button>
              <button id="adminUserUpdateBtn" type="button">更新保存</button>
              <button id="adminUserResetBtn" type="button" class="secondary">入力をクリア</button>
            </div>

            <div class="meta-row">
              <div class="meta" id="adminUsersMetaText">読み込み前です。</div>
              <div class="status" id="adminUsersStatusText" aria-live="polite"></div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>ログインID</th>
                    <th>表示名</th>
                    <th>メールアドレス</th>
                    <th>ロール</th>
                    <th>状態</th>
                    <th>最終ログイン</th>
                  </tr>
                </thead>
                <tbody id="adminUsersTableBody">
                  <tr><td colspan="7" class="empty">読み込み前です。</td></tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="panel admin-audit-section">
            <div class="page-head page-head-compact">
              <div>
                <h2>監査ログ</h2>
                <p class="lead">ログイン、管理者操作などの直近ログです。</p>
              </div>
            </div>

            <div class="meta-row">
              <div class="meta" id="auditMetaText">読み込み前です。</div>
              <div class="status" id="auditStatusText" aria-live="polite"></div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>日時</th>
                    <th>操作</th>
                    <th>操作者</th>
                    <th>対象</th>
                    <th>概要</th>
                  </tr>
                </thead>
                <tbody id="auditTableBody">
                  <tr><td colspan="5" class="empty">読み込み前です。</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </section>
    </main>
  </div>

  <script src="./js/admin-app.js?v=20260625" defer></script>
</body>
</html>
