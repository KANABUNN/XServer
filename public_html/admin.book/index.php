<?php
declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';
require_once __DIR__ . '/../../apps/ui_kit.php';

$user = admin_auth_require_login();
$csrfToken = admin_auth_get_csrf_token();
$permissions = array_values(array_map('strval', admin_auth_user_permissions($user)));
$canCreate = in_array('calendar.create', $permissions, true);
$canAdminView = in_array('admin.user.manage', $permissions, true) || in_array('admin.audit.view', $permissions, true);
$canManageUsers = in_array('admin.user.manage', $permissions, true);
$displayName = (string)($user['display_name'] ?? '');
// 予約サイト（admin.book.<domain> → book.<domain>）へのリンク
$requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$publicSiteUrl = str_starts_with($requestHost, 'admin.') ? '//' . substr($requestHost, 6) . '/' : '../book/';

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
<html lang="ja" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>貸し部屋予約 管理</title>
  <script>
    window.AdminBootstrap = <?php echo json_encode([
      'currentUser' => [
        'id' => (int)($user['id'] ?? 0),
        'login_id' => (string)($user['login_id'] ?? ''),
        'display_name' => $displayName,
        'role_key' => (string)($user['role_key'] ?? 'viewer'),
        'role_label' => (string)($user['role_label'] ?? '閲覧者'),
        'permissions' => $permissions,
      ],
      'csrfToken' => $csrfToken,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  </script>
  <?php echo fitsc_ui_head([
      'css' => ['./css/app.css'],
      'guard' => './assets/common/context-menu-guard.js',
      'js' => ['./js/admin-app.js'],
  ]); ?>
</head>
<body>
<div class="app-shell ab-shell">
  <aside class="sidebar" id="app-sidebar" aria-label="メニュー">
    <a class="sidebar__brand" href="./">
      <span class="brand-mark"><?php echo fitsc_icon('door'); ?></span>
      <span class="brand-text"><strong>貸し部屋予約</strong><span>Admin</span></span>
    </a>
    <nav class="sidebar__nav" aria-label="管理機能">
      <button type="button" class="nav-item sidebar-link is-active" data-view-target="dashboardView" data-tip="予約一覧" data-tip-pos="right"><?php echo fitsc_icon('list'); ?><span class="nav-item__label">予約一覧</span></button>
      <button type="button" class="nav-item sidebar-link" data-view-target="calendarView" data-tip="月間カレンダー" data-tip-pos="right"><?php echo fitsc_icon('calendar'); ?><span class="nav-item__label">月間カレンダー</span></button>
      <button type="button" class="nav-item sidebar-link" data-view-target="passcodeView" data-tip="パスコード" data-tip-pos="right"><?php echo fitsc_icon('key'); ?><span class="nav-item__label">パスコード</span><span class="badge badge--warning nav-item__badge" id="navSwitchbotIssue" hidden>0</span></button>
      <?php if ($canAdminView): ?>
        <div class="nav-section">管理</div>
        <button type="button" class="nav-item sidebar-link" data-view-target="adminView" data-tip="アカウント・ログ" data-tip-pos="right"><?php echo fitsc_icon('users'); ?><span class="nav-item__label">アカウント・ログ</span></button>
      <?php endif; ?>
    </nav>
    <div class="sidebar__footer">
      <a class="nav-item" href="<?php echo admin_index_h($publicSiteUrl); ?>" target="_blank" rel="noopener" data-tip="予約サイト" data-tip-pos="right" id="publicSiteLink"><?php echo fitsc_icon('external'); ?><span class="nav-item__label">予約サイトを開く</span></a>
      <button type="button" class="nav-item sidebar__collapse" data-sidebar-collapse data-tip="サイドバーを開閉" data-tip-pos="right"><?php echo fitsc_icon('chevrons-left'); ?><span class="nav-item__label">折りたたむ</span></button>
      <div class="user-chip">
        <span class="avatar"><?php echo admin_index_h(fitsc_initials($displayName)); ?></span>
        <span class="user-chip__text"><strong><?php echo admin_index_h($displayName); ?></strong><span><?php echo admin_index_h((string)($user['role_label'] ?? '閲覧者')); ?> / <?php echo admin_index_h((string)($user['login_id'] ?? '')); ?></span></span>
      </div>
      <form method="post" action="logout.php">
        <?php echo admin_auth_csrf_field(); ?>
        <button type="submit" class="nav-item" data-tip="ログアウト" data-tip-pos="right"><?php echo fitsc_icon('log-out'); ?><span class="nav-item__label">ログアウト</span></button>
      </form>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>

  <div class="app-main">
    <header class="topbar">
      <button type="button" class="btn btn--ghost btn--icon topbar__menu" data-sidebar-toggle aria-label="メニュー"><?php echo fitsc_icon('menu'); ?></button>
      <div class="topbar__title">
        <h1 id="viewTitle">予約一覧</h1>
        <p id="viewSubtitle">利用者からの申請と管理画面からの登録</p>
      </div>
      <div class="topbar__actions">
        <button type="button" class="btn btn--ghost btn--icon" id="viewReloadBtn" aria-label="再読込" title="再読込"><?php echo fitsc_icon('refresh'); ?></button>
        <?php echo fitsc_theme_toggle(); ?>
      </div>
    </header>

    <main class="page page--wide">
      <!-- ============ 予約一覧 ============ -->
      <section id="dashboardView" class="content-view is-active" data-title="予約一覧" data-subtitle="利用者からの申請と管理画面からの登録">
        <div class="stack stack--lg">
          <div class="stat-grid">
            <div class="stat stat--success">
              <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('check-circle'); ?></span>今後の確定予約</span>
              <span class="stat__value" id="summaryConfirmedCount">–</span>
            </div>
            <div class="stat stat--warning">
              <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('key'); ?></span>SwitchBot 要確認</span>
              <span class="stat__value" id="summarySwitchbotIssueCount">–</span>
            </div>
            <div class="stat stat--info">
              <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('inbox'); ?></span>本日の新規受付</span>
              <span class="stat__value" id="summaryTodayCount">–</span>
            </div>
            <div class="stat stat--danger">
              <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('mail'); ?></span>未送信メール</span>
              <span class="stat__value" id="summaryMailIssueCount">–</span>
            </div>
          </div>

          <section class="card">
            <div class="card__header">
              <div>
                <div class="card__title"><?php echo fitsc_icon('list'); ?>予約 <small id="dashboardMetaText"></small></div>
              </div>
              <form class="ab-filters" id="dashboardFilterForm" autocomplete="off">
                <label class="sr-only" for="dashboardMonth">利用月</label>
                <input type="month" id="dashboardMonth" class="ab-month">
                <div class="segmented" role="radiogroup" aria-label="部屋">
                  <button type="button" class="is-active" data-room-filter="" aria-pressed="true">すべて</button>
                  <button type="button" data-room-filter="tamoku" aria-pressed="false">多目的室</button>
                  <button type="button" data-room-filter="orange" aria-pressed="false">オレンジ</button>
                </div>
                <input type="hidden" id="dashboardRoom" value="">
                <label class="sr-only" for="dashboardStatus">予約状態</label>
                <select id="dashboardStatus" class="ab-select">
                  <option value="">すべての状態</option>
                  <option value="confirmed">確定</option>
                  <option value="rejected">却下</option>
                  <option value="error">要確認</option>
                  <option value="pending">保留</option>
                  <option value="replaced">上書き済み</option>
                  <option value="deleted">削除済み</option>
                </select>
                <div class="search-toggle" data-search-toggle>
                  <button type="button" class="btn btn--ghost btn--icon search-toggle__button" aria-label="団体名・メールで検索"><?php echo fitsc_icon('search'); ?></button>
                  <div class="search-toggle__field">
                    <?php echo fitsc_icon('search'); ?>
                    <input type="search" id="dashboardKeyword" placeholder="団体名・メールで検索" aria-label="団体名・メールで検索">
                    <button type="button" class="btn btn--ghost btn--icon search-toggle__clear" aria-label="検索を閉じる"><?php echo fitsc_icon('x'); ?></button>
                  </div>
                </div>
                <button type="button" id="dashboardClearBtn" class="btn btn--ghost btn--sm" title="条件をクリア"><?php echo fitsc_icon('rotate-ccw'); ?><span class="hide-mobile">クリア</span></button>
                <button type="submit" id="dashboardSearchBtn" class="sr-only">検索</button>
              </form>
            </div>
            <div class="table-wrap is-stacked">
              <table class="table">
                <thead>
                  <tr>
                    <th>利用日・時間</th>
                    <th>部屋</th>
                    <th>団体 / メール</th>
                    <th>状態</th>
                    <th>パスコード</th>
                    <th>連携</th>
                    <th>メール通知</th>
                    <th>受付</th>
                  </tr>
                </thead>
                <tbody id="dashboardTableBody">
                  <tr><td colspan="8" class="empty"><span class="spinner"></span></td></tr>
                </tbody>
              </table>
            </div>
          </section>
          <p class="sr-only" id="dashboardStatusText" aria-live="polite"></p>
        </div>
      </section>

      <!-- ============ 月間カレンダー ============ -->
      <section id="calendarView" class="content-view" data-title="月間カレンダー" data-subtitle="日付をクリックすると予約の詳細を表示します">
        <section class="card">
          <div class="card__header">
            <div class="ab-monthnav">
              <button type="button" class="btn btn--ghost btn--icon" id="calendarPrevBtn" aria-label="前の月"><?php echo fitsc_icon('chevron-left'); ?></button>
              <label class="sr-only" for="calendarMonth">表示月</label>
              <input type="month" id="calendarMonth" class="ab-month ab-month--title">
              <button type="button" class="btn btn--ghost btn--icon" id="calendarNextBtn" aria-label="次の月"><?php echo fitsc_icon('chevron-right'); ?></button>
              <button type="button" class="btn btn--sm" id="calendarTodayBtn">今月</button>
              <span class="card__meta" id="calendarMetaText"></span>
            </div>
            <div class="card__actions">
              <div class="segmented" role="tablist" aria-label="表示切替">
                <button type="button" class="is-active" data-calendar-mode="grid" aria-selected="true"><?php echo fitsc_icon('calendar'); ?>カレンダー</button>
                <button type="button" data-calendar-mode="list" aria-selected="false"><?php echo fitsc_icon('list'); ?>リスト</button>
              </div>
              <?php if ($canCreate): ?>
                <button type="button" class="btn btn--primary" id="calendarAddBtn"><?php echo fitsc_icon('plus'); ?>予約を追加</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="card__body" data-calendar-panel="grid">
            <div class="ab-legend">
              <span><i class="ab-room-dot ab-room-dot--tamoku"></i>多目的室</span>
              <span><i class="ab-room-dot ab-room-dot--orange"></i>オレンジの部屋</span>
            </div>
            <div class="calendar-admin-grid" id="calendarAdminGrid"></div>
          </div>
          <div class="table-wrap is-stacked hidden" data-calendar-panel="list">
            <table class="table">
              <thead>
                <tr>
                  <th>利用日・時間</th>
                  <th>部屋</th>
                  <th>団体 / メール</th>
                  <th>パスコード</th>
                  <th>SwitchBot</th>
                  <th>Google</th>
                </tr>
              </thead>
              <tbody id="calendarListBody">
                <tr><td colspan="6" class="empty"><span class="spinner"></span></td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <p class="sr-only" id="calendarStatusText" aria-live="polite"></p>
      </section>

      <!-- ============ パスコード ============ -->
      <section id="passcodeView" class="content-view" data-title="パスコード" data-subtitle="発行済みパスコードと SwitchBot への反映状況">
        <section class="card">
          <div class="card__header">
            <div class="card__title"><?php echo fitsc_icon('key'); ?>パスコード <small id="passcodeMetaText"></small></div>
            <div class="segmented" role="radiogroup" aria-label="絞り込み">
              <button type="button" class="is-active" data-passcode-filter="all" aria-pressed="true">すべて</button>
              <button type="button" data-passcode-filter="issue" aria-pressed="false">要確認のみ</button>
            </div>
          </div>
          <div class="table-wrap is-stacked">
            <table class="table">
              <thead>
                <tr>
                  <th>利用日・時間</th>
                  <th>部屋</th>
                  <th>団体 / メール</th>
                  <th>パスコード</th>
                  <th>有効期間</th>
                  <th>SwitchBot</th>
                  <th class="col-actions">操作</th>
                </tr>
              </thead>
              <tbody id="passcodeTableBody">
                <tr><td colspan="7" class="empty"><span class="spinner"></span></td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <p class="sr-only" id="passcodeStatusText" aria-live="polite"></p>
      </section>

      <!-- ============ アカウント・ログ ============ -->
      <section id="adminView" class="content-view" data-title="アカウント・ログ" data-subtitle="管理者アカウントと操作の記録">
        <div class="stack stack--lg">
          <section class="card admin-users-section">
            <div class="card__header">
              <div class="card__title"><?php echo fitsc_icon('users'); ?>管理者アカウント <small id="adminUsersMetaText"></small></div>
              <?php if ($canManageUsers): ?>
                <button type="button" class="btn btn--primary" id="adminUserNewBtn"><?php echo fitsc_icon('plus'); ?>アカウントを追加</button>
              <?php endif; ?>
            </div>
            <div class="table-wrap is-stacked">
              <table class="table">
                <thead>
                  <tr>
                    <th>表示名 / ログインID</th>
                    <th>メールアドレス</th>
                    <th>ロール</th>
                    <th>状態</th>
                    <th>最終ログイン</th>
                    <th class="col-actions"></th>
                  </tr>
                </thead>
                <tbody id="adminUsersTableBody">
                  <tr><td colspan="6" class="empty"><span class="spinner"></span></td></tr>
                </tbody>
              </table>
            </div>
            <p class="sr-only" id="adminUsersStatusText" aria-live="polite"></p>
          </section>

          <section class="card admin-audit-section">
            <div class="card__header">
              <div class="card__title"><?php echo fitsc_icon('history'); ?>監査ログ <small id="auditMetaText"></small></div>
            </div>
            <div class="table-wrap is-stacked">
              <table class="table table--compact">
                <thead>
                  <tr>
                    <th>日時</th>
                    <th>操作</th>
                    <th>操作者</th>
                    <th>対象</th>
                    <th>内容</th>
                  </tr>
                </thead>
                <tbody id="auditTableBody">
                  <tr><td colspan="5" class="empty"><span class="spinner"></span></td></tr>
                </tbody>
              </table>
            </div>
            <p class="sr-only" id="auditStatusText" aria-live="polite"></p>
          </section>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- 日付の予約詳細 -->
<dialog class="modal modal--lg" id="calendarDetailDialog" aria-labelledby="calendarDetailTitle">
  <div class="modal__panel">
    <div class="modal__header">
      <span class="modal__icon"><?php echo fitsc_icon('calendar', 'ico--lg'); ?></span>
      <div>
        <h2 class="modal__title" id="calendarDetailTitle">日付を選択してください</h2>
        <p class="modal__desc" id="calendarDetailLead"></p>
      </div>
      <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?php echo fitsc_icon('x'); ?></button>
    </div>
    <div class="modal__body">
      <div class="calendar-detail-cards" id="calendarDetailCards"></div>
      <p class="sr-only" id="calendarDetailMetaText"></p>
      <p class="small-note mt-2" id="calendarDetailStatusText" aria-live="polite"></p>
    </div>
    <div class="modal__footer">
      <?php if ($canCreate): ?>
        <button type="button" class="btn" id="calendarDetailAddBtn"><?php echo fitsc_icon('plus'); ?>この日に追加</button>
      <?php endif; ?>
      <button type="button" class="btn btn--primary" data-modal-close>閉じる</button>
    </div>
  </div>
</dialog>

<?php if ($canCreate): ?>
<!-- 予約の追加 / 上書き -->
<dialog class="modal modal--lg" id="calendarManualDialog" aria-labelledby="calendarManualTitle">
  <form class="modal__panel" id="calendarManualSection" autocomplete="off">
    <div class="modal__header">
      <span class="modal__icon"><?php echo fitsc_icon('calendar-plus', 'ico--lg'); ?></span>
      <div>
        <h2 class="modal__title" id="calendarManualTitle">予約を追加</h2>
        <p class="modal__desc">同じ日・同じ部屋に予約がある場合は上書きします。</p>
      </div>
      <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?php echo fitsc_icon('x'); ?></button>
    </div>
    <div class="modal__body stack">
      <div class="form-grid">
        <label class="field"><span>利用日 <span class="req">必須</span></span><input type="date" id="manualUseDate" required></label>
        <label class="field"><span>部屋</span>
          <select id="manualRoomCode">
            <option value="tamoku">多目的室</option>
            <option value="orange">オレンジの部屋</option>
          </select>
        </label>
        <label class="field span-full"><span>団体名 <span class="req">必須</span></span><input type="text" id="manualOrganizationName" maxlength="150" placeholder="例：総合管理事務局" required data-autofocus></label>
        <label class="field"><span>開始</span><select id="manualUsageStartTime"><?php echo admin_index_time_options('09:00', false); ?></select></label>
        <label class="field"><span>終了</span><select id="manualUsageEndTime"><?php echo admin_index_time_options('10:00', true); ?></select></label>
        <label class="field span-full"><span>利用者のメールアドレス <span class="opt">入力した場合のみ通知</span></span><input type="email" id="manualEmail" maxlength="255" placeholder="group@example.jp"></label>
      </div>
      <div class="grid grid--2">
        <label class="check-card">
          <input type="checkbox" id="manualSyncGoogle" checked>
          <span><strong>Google カレンダーに反映</strong><small>部屋の共有カレンダーに予定を追加</small></span>
        </label>
        <label class="check-card">
          <input type="checkbox" id="manualIssueSwitchbot" checked>
          <span><strong>SwitchBot パスコードを発行</strong><small>有効期間付きのパスコード</small></span>
        </label>
      </div>
      <p class="small-note" id="calendarManualMetaText">事務局（sogokanri@bene.fit.ac.jp）への通知は、Google と SwitchBot の両方を選んだときのみ送信されます。</p>
      <p class="small-note" id="calendarManualStatusText" aria-live="polite"></p>
    </div>
    <div class="modal__footer">
      <button type="button" class="btn btn--ghost" id="calendarManualResetBtn">クリア</button>
      <button type="submit" class="btn btn--primary" id="calendarManualCreateBtn"><?php echo fitsc_icon('save'); ?>登録する</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canManageUsers): ?>
<!-- 管理者アカウント -->
<dialog class="modal" id="adminUserDialog" aria-labelledby="adminUserDialogTitle">
  <form class="modal__panel" id="adminUserForm" autocomplete="off">
    <input type="hidden" id="adminUserId">
    <div class="modal__header">
      <span class="modal__icon"><?php echo fitsc_icon('user', 'ico--lg'); ?></span>
      <div><h2 class="modal__title" id="adminUserDialogTitle">アカウントを追加</h2></div>
      <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?php echo fitsc_icon('x'); ?></button>
    </div>
    <div class="modal__body form-grid">
      <label class="field"><span>ログインID</span><input type="text" id="adminLoginId" maxlength="100" placeholder="admin.taro" data-autofocus></label>
      <label class="field"><span>表示名</span><input type="text" id="adminDisplayName" maxlength="100" placeholder="総務 太郎"></label>
      <label class="field span-full"><span>メールアドレス</span><input type="email" id="adminEmail" maxlength="255" placeholder="admin@example.jp"></label>
      <label class="field"><span>ロール</span>
        <select id="adminRoleKey">
          <option value="viewer">閲覧者 (viewer)</option>
          <option value="user">編集者 (user)</option>
          <option value="admin">管理者 (admin)</option>
        </select>
      </label>
      <label class="field"><span>状態</span>
        <select id="adminIsActive">
          <option value="1">有効</option>
          <option value="0">無効</option>
        </select>
      </label>
      <label class="field span-full"><span>パスワード <span class="opt" id="adminPasswordHint">10文字以上</span></span><input type="password" id="adminPassword" minlength="10" autocomplete="new-password"></label>
      <p class="small-note span-full" id="adminUsersFormStatus" aria-live="polite"></p>
    </div>
    <div class="modal__footer">
      <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
      <button type="submit" class="btn btn--primary" id="adminUserSaveBtn"><?php echo fitsc_icon('save'); ?>保存</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
</body>
</html>
