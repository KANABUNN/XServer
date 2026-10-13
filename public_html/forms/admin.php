<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';
forms_bootstrap();
require_admin();
$user = current_user();
$userName = (string)($user['name'] ?? '');
page_header('フォーム管理', 'forms-admin-page', ['app_name' => 'フォーム管理']);
?>
<div class="app-shell fa-shell">
    <aside class="sidebar" id="app-sidebar" aria-label="フォーム一覧">
        <a class="sidebar__brand" href="admin.php">
            <span class="brand-mark"><?= fitsc_icon('file-text') ?></span>
            <span class="brand-text"><strong>フォーム管理</strong><span>FIT-SC Forms</span></span>
        </a>

        <div class="fa-browser-actions">
            <button type="button" class="btn btn--primary" id="new-form-button"><?= fitsc_icon('plus') ?>新しいフォーム</button>
            <button type="button" class="btn btn--icon" id="new-folder-button" aria-label="フォルダーを作成" data-tip="フォルダーを作成" data-tip-pos="bottom"><?= fitsc_icon('folder-plus') ?></button>
        </div>

        <div class="fa-tree-wrap">
            <section id="recent-forms-section" class="hidden" aria-labelledby="recent-forms-heading">
                <div class="fa-group-title"><span id="recent-forms-heading">最近使ったフォーム</span></div>
                <div id="recent-form-list" class="recent-form-list"></div>
            </section>

            <section aria-labelledby="directory-heading">
                <div class="fa-tree-head">
                    <div class="fa-group-title"><span id="directory-heading">すべてのフォーム</span></div>
                    <div class="fa-tree-tools">
                        <div class="search-toggle fa-search" data-search-toggle id="form-search-toggle">
                            <button type="button" class="btn btn--ghost btn--icon btn--sm search-toggle__button" aria-label="フォームを検索（Ctrl+K）" title="フォームを検索（Ctrl+K）"><?= fitsc_icon('search') ?></button>
                            <div class="search-toggle__field">
                                <?= fitsc_icon('search') ?>
                                <input type="search" id="form-search" placeholder="フォーム名・提出団体名" maxlength="200" autocomplete="off" aria-label="フォーム名・フォルダー名・提出団体名で検索" aria-describedby="form-search-status">
                                <button type="button" class="btn btn--ghost btn--icon search-toggle__clear" aria-label="検索を閉じる"><?= fitsc_icon('x') ?></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" id="expand-all-folders-button" aria-label="すべて展開" title="すべて展開"><?= fitsc_icon('layers') ?></button>
                    </div>
                </div>
                <p id="form-search-status" class="fa-search-status" aria-live="polite"></p>
                <div id="form-list" class="form-directory-tree"></div>
            </section>
        </div>

        <div class="fa-stats" id="forms-overall-stats" aria-label="フォーム集計">
            <span>全<strong id="overall-form-count">0</strong></span>
            <span><span class="dot dot--success" aria-hidden="true"></span>公開<strong id="overall-active-form-count">0</strong></span>
            <span><span class="dot" aria-hidden="true"></span>非公開<strong id="overall-inactive-form-count">0</strong></span>
        </div>

        <div class="sidebar__footer">
            <a class="nav-item" href="index.php" target="_blank" rel="noopener"><?= fitsc_icon('external') ?><span class="nav-item__label">利用者画面を開く</span></a>
            <div class="user-chip">
                <span class="avatar"><?= h(fitsc_initials($userName)) ?></span>
                <span class="user-chip__text"><strong><?= h($userName) ?></strong><span><?= h($user['role_label'] ?? '管理者') ?> / <?= h($user['login_id'] ?? '') ?></span></span>
            </div>
            <a class="nav-item" href="logout.php?csrf_token=<?= urlencode(csrf_token()) ?>"><?= fitsc_icon('log-out') ?><span class="nav-item__label">ログアウト</span></a>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <div class="app-main">
        <header class="topbar fa-topbar">
            <button type="button" class="btn btn--ghost btn--icon topbar__menu" data-sidebar-toggle aria-label="フォーム一覧を開く"><?= fitsc_icon('menu') ?></button>
            <div class="topbar__title">
                <h1 id="workspace-form-name">フォームを選択してください</h1>
                <p id="workspace-form-description">左の一覧からフォームを選択します</p>
            </div>
            <div class="topbar__actions">
                <div class="fa-meta hide-mobile" id="workspace-meta"></div>
                <?= fitsc_theme_toggle() ?>
            </div>
        </header>

        <nav class="fa-tabs" aria-label="管理タブ">
            <div class="tabs" role="tablist">
                <button type="button" class="tab active" role="tab" aria-selected="true" data-workspace-tab="overview"><?= fitsc_icon('dashboard') ?>概要</button>
                <button type="button" class="tab" role="tab" aria-selected="false" data-workspace-tab="settings"><?= fitsc_icon('sliders') ?>基本設定</button>
                <button type="button" class="tab" role="tab" aria-selected="false" data-workspace-tab="fields"><?= fitsc_icon('list-checks') ?>追加項目</button>
                <button type="button" class="tab" role="tab" aria-selected="false" data-workspace-tab="responses"><?= fitsc_icon('inbox') ?>回答一覧<span class="badge badge--info" id="responses-tab-count" hidden>0</span></button>
            </div>
        </nav>

        <main class="page">
            <div id="admin-message" class="alert hidden" role="status"></div>

            <!-- 概要 -->
            <section class="fa-panel" data-workspace-panel="overview">
                <div class="stat-grid">
                    <div class="stat stat--success">
                        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('eye') ?></span>公開状態</span>
                        <span class="stat__value fa-stat-text" id="overview-kpi-status">未選択</span>
                    </div>
                    <div class="stat stat--teal">
                        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('list-checks') ?></span>追加項目</span>
                        <span class="stat__value" id="overview-kpi-fields">0</span>
                    </div>
                    <div class="stat stat--info">
                        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('inbox') ?></span>回答数</span>
                        <span class="stat__value" id="overview-kpi-entries">0</span>
                    </div>
                    <div class="stat stat--warning">
                        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('bell') ?></span>未確認</span>
                        <span class="stat__value" id="overview-kpi-new">0</span>
                    </div>
                </div>

                <div class="fa-overview-grid">
                    <section class="card">
                        <div class="card__header">
                            <div class="card__title"><?= fitsc_icon('info') ?>フォーム情報</div>
                            <button type="button" class="btn btn--ghost btn--sm" data-switch-workspace="settings"><?= fitsc_icon('pen') ?>編集</button>
                        </div>
                        <div class="card__body"><dl class="dl" id="overview-form-info"></dl></div>
                    </section>

                    <div class="stack">
                        <section class="card">
                            <div class="card__header">
                                <div class="card__title"><?= fitsc_icon('chart') ?>回答状況</div>
                                <button type="button" class="btn btn--ghost btn--sm" data-switch-workspace="responses">回答一覧へ<?= fitsc_icon('arrow-right') ?></button>
                            </div>
                            <div class="card__body"><div id="overview-status-summary" class="status-card-grid"></div></div>
                        </section>
                        <section class="card">
                            <div class="card__header"><div class="card__title"><?= fitsc_icon('sliders') ?>入力設定</div></div>
                            <div class="card__body stack stack--sm">
                                <div id="overview-setting-pills" class="fa-setting-chips"></div>
                                <p id="overview-period-note" class="small-note"></p>
                            </div>
                        </section>
                        <section class="card">
                            <div class="card__header">
                                <div class="card__title"><?= fitsc_icon('list-checks') ?>追加項目</div>
                                <button type="button" class="btn btn--ghost btn--sm" data-switch-workspace="fields"><?= fitsc_icon('pen') ?>編集</button>
                            </div>
                            <div id="overview-field-preview" class="list"></div>
                        </section>
                    </div>
                </div>
            </section>

            <form id="form-editor" class="stack" autocomplete="off">
                <input type="hidden" name="id" value="0">

                <!-- 基本設定 -->
                <section class="fa-panel hidden" data-workspace-panel="settings">
                    <div class="fa-settings-grid">
                        <section class="card">
                            <div class="card__header"><div class="card__title"><?= fitsc_icon('file-text') ?>基本情報</div></div>
                            <div class="card__body form-grid">
                                <label class="field span-full"><span>フォーム名 <span class="req">必須</span></span><input type="text" name="name" required maxlength="150"></label>
                                <label class="field"><span>スラッグ</span><input type="text" name="slug" placeholder="form-general" spellcheck="false"></label>
                                <label class="field"><span>表示順</span><input type="number" name="sort_order" value="0"></label>
                                <label class="field span-full"><span>説明</span><textarea name="description" rows="3" placeholder="利用者画面に表示される説明文"></textarea></label>
                                <label class="field"><span>保存先フォルダー</span><select name="folder_id" id="form-folder-select"><option value="">未分類</option></select></label>
                                <div class="field"><span>公開</span><label class="switch"><input type="checkbox" name="is_active" checked>利用者画面に公開する</label></div>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card__header"><div class="card__title"><?= fitsc_icon('calendar') ?>公開期間</div></div>
                            <div class="card__body form-grid">
                                <label class="field"><span>開始日</span><input type="date" name="public_start_date"></label>
                                <label class="field"><span>開始時刻</span><input type="time" name="public_start_time" step="60"></label>
                                <label class="field"><span>終了日</span><input type="date" name="public_end_date"></label>
                                <label class="field"><span>終了時刻</span><input type="time" name="public_end_time" step="60"></label>
                                <p class="field__hint span-full">未設定の場合は常時公開。日付のみの場合、開始日は 0:00 から、終了日は 23:59 まで受け付けます。</p>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card__header"><div class="card__title"><?= fitsc_icon('calendar-plus') ?>日付入力</div></div>
                            <div class="card__body stack">
                                <div class="cluster">
                                    <label class="switch"><input type="checkbox" name="enable_date_field" checked>日付入力を表示</label>
                                    <label class="switch"><input type="checkbox" name="date_required">必須にする</label>
                                </div>
                                <label class="field"><span>ラベル</span><input type="text" name="date_label" value="希望日"></label>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card__header"><div class="card__title"><?= fitsc_icon('paperclip') ?>添付ファイル</div></div>
                            <div class="card__body stack">
                                <div class="cluster">
                                    <label class="switch"><input type="checkbox" name="allow_file_upload">添付を許可</label>
                                    <label class="switch"><input type="checkbox" name="file_required">必須にする</label>
                                </div>
                                <div class="form-grid form-grid--3">
                                    <label class="field"><span>ラベル</span><input type="text" name="file_label" value="添付ファイル"></label>
                                    <label class="field"><span>最大サイズ (MB)</span><input type="number" name="max_upload_size_mb" min="1" max="30" value="5"></label>
                                    <label class="field"><span>最大ファイル数</span><input type="number" name="max_upload_files" min="1" max="10" value="1"></label>
                                    <label class="field span-full"><span>許可する拡張子</span><input type="text" name="allowed_extensions" value="pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip" spellcheck="false"></label>
                                </div>
                            </div>
                        </section>

                        <section class="card span-full">
                            <div class="card__header">
                                <div>
                                    <div class="card__title"><?= fitsc_icon('download') ?>利用者への配布資料</div>
                                    <p class="card__subtitle">様式などのファイルと案内文を、利用者画面の入力欄の上に表示します。</p>
                                </div>
                                <label class="switch"><input type="checkbox" name="distribution_enabled">表示する</label>
                            </div>
                            <div class="card__body">
                                <div class="split">
                                    <div class="stack">
                                        <label class="field"><span>見出し</span><input type="text" name="distribution_title" placeholder="例：提出用様式"></label>
                                        <label class="field"><span>案内文</span><textarea name="distribution_body" rows="4" placeholder="例：下記の様式を必要に応じて使用してください。"></textarea></label>
                                        <label class="field"><span>ダウンロードボタンの表示</span><input type="text" name="distribution_download_label" value="資料をダウンロード"></label>
                                    </div>
                                    <div class="stack">
                                        <div class="field">
                                            <span>登録済みファイル</span>
                                            <div id="distribution-file-status" class="distribution-file-status"></div>
                                        </div>
                                        <label class="field">
                                            <span>ファイルを追加</span>
                                            <input type="file" id="distribution-file-input" multiple>
                                            <span class="field__hint">PDF / Office / 画像 / ZIP / CSV / TXT、1ファイル30MBまで。保存済みのフォームにのみ追加できます。</span>
                                        </label>
                                        <div><button type="button" class="btn" id="upload-distribution-files-button"><?= fitsc_icon('upload') ?>アップロード</button></div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card__header"><div class="card__title"><?= fitsc_icon('send') ?>送信時の表示</div></div>
                            <div class="card__body form-grid">
                                <label class="field"><span>送信ボタン</span><input type="text" name="submit_button_label" value="送信する"></label>
                                <label class="field"><span>完了メッセージ</span><input type="text" name="completion_message" value="送信を受け付けました。"></label>
                            </div>
                        </section>

                        <section class="card card--danger">
                            <div class="card__header"><div class="card__title"><?= fitsc_icon('alert-triangle') ?>フォームの削除</div></div>
                            <div class="card__body">
                                <div class="danger-zone">
                                    <div class="danger-zone__text"><strong>このフォームを削除</strong><span>回答・更新履歴・状態ログもまとめて削除され、元に戻せません。</span></div>
                                    <button type="button" class="btn btn--danger-ghost" id="delete-form-button"><?= fitsc_icon('trash') ?>削除</button>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="fa-save-bar">
                        <p>変更は「設定を保存」を押すと反映されます。</p>
                        <button type="submit" class="btn btn--primary"><?= fitsc_icon('save') ?>設定を保存</button>
                    </div>
                </section>

                <!-- 追加項目 -->
                <section class="fa-panel hidden" data-workspace-panel="fields">
                    <div class="alert alert--neutral">
                        <?= fitsc_icon('info') ?>
                        <div class="alert__body">メールアドレスと団体名は常に表示されます。ここでは追加で入力してもらう項目を設定します（上から順に表示）。</div>
                    </div>
                    <div id="field-builder-list" class="field-builder-list"></div>
                    <button type="button" class="btn btn--block fa-add-field" id="add-field-button"><?= fitsc_icon('plus') ?>項目を追加</button>
                    <div class="fa-save-bar">
                        <p>項目は表示順のまま保存されます。</p>
                        <button type="submit" class="btn btn--primary"><?= fitsc_icon('save') ?>項目を保存</button>
                    </div>
                </section>
            </form>

            <!-- 回答一覧 -->
            <section class="fa-panel hidden" data-workspace-panel="responses">
                <section class="card">
                    <div class="card__header">
                        <div>
                            <div class="card__title"><?= fitsc_icon('inbox') ?>回答一覧</div>
                            <p class="card__subtitle" id="entry-summary">フォームを選択すると回答を表示します。</p>
                        </div>
                        <div class="card__actions">
                            <button type="button" class="btn btn--ghost btn--icon" id="refresh-entries-button" aria-label="再読込" data-tip="再読込"><?= fitsc_icon('refresh') ?></button>
                            <button type="button" class="btn" id="download-latest-attachments-button"><?= fitsc_icon('paperclip') ?>最新添付をZIPで取得</button>
                            <button type="button" class="btn btn--primary" id="export-csv-button"><?= fitsc_icon('download') ?>CSV出力</button>
                        </div>
                    </div>
                    <div class="card__body stack">
                        <form id="entry-filter-form" class="fa-filter" autocomplete="off">
                            <label class="field field--query"><span>キーワード</span><span class="input-icon"><?= fitsc_icon('search') ?><input type="search" name="query" placeholder="団体名・メール・回答内容・メモ"></span></label>
                            <label class="field"><span>状態</span><select name="status" id="entry-status-filter"></select></label>
                            <label class="field"><span>更新日（から）</span><input type="date" name="date_from"></label>
                            <label class="field"><span>更新日（まで）</span><input type="date" name="date_to"></label>
                            <label class="field"><span>表示件数</span>
                                <select name="limit">
                                    <option value="25">25件</option>
                                    <option value="50">50件</option>
                                    <option value="100" selected>100件</option>
                                    <option value="200">200件</option>
                                    <option value="500">500件</option>
                                </select>
                            </label>
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary"><?= fitsc_icon('filter') ?>絞り込む</button>
                                <button type="button" class="btn btn--ghost" id="reset-filter-button">リセット</button>
                            </div>
                        </form>
                        <div id="entry-status-summary" class="status-card-grid"></div>
                    </div>
                </section>

                <div class="fa-responses">
                    <div id="entry-list" class="response-list" aria-label="回答"></div>
                    <section class="card fa-entry-detail">
                        <div id="entry-detail-empty" class="empty">
                            <span class="empty__icon"><?= fitsc_icon('inbox') ?></span>
                            <span>回答を選択すると、内容・状態・履歴を表示します。</span>
                        </div>
                        <div id="entry-detail" class="card__body stack hidden"></div>
                    </section>
                </div>
            </section>
        </main>
    </div>
</div>

<dialog id="new-form-dialog" class="modal" aria-labelledby="new-form-dialog-title">
    <form id="new-form-dialog-form" class="modal__panel">
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon('plus', 'ico--lg') ?></span>
            <div><h2 class="modal__title" id="new-form-dialog-title">新しいフォーム</h2><p class="modal__desc">空のフォームから作るか、既存のフォームをコピーします。</p></div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-close-dialog="new-form-dialog" aria-label="閉じる"><?= fitsc_icon('x') ?></button>
        </div>
        <div class="modal__body stack">
            <label class="field"><span>フォーム名 <span class="req">必須</span></span><input type="text" name="name" maxlength="150" required placeholder="例：2026年度 活動報告" data-autofocus></label>
            <label class="field"><span>保存先フォルダー</span><select name="folder_id" id="new-form-folder-select"><option value="">未分類</option></select></label>
            <fieldset class="field">
                <legend class="field__label">作成方法</legend>
                <div class="creation-mode-grid">
                    <label class="check-card"><input type="radio" name="creation_mode" value="blank" checked><span><strong>空のフォーム</strong><small>初期設定から作成</small></span></label>
                    <label class="check-card"><input type="radio" name="creation_mode" value="copy"><span><strong>既存をコピー</strong><small>設定と追加項目を引き継ぐ</small></span></label>
                </div>
            </fieldset>
            <label id="copy-source-field" class="field hidden">
                <span>コピー元</span>
                <select name="source_form_id" id="copy-source-form-select"></select>
                <span class="field__hint">回答・履歴・ファイルはコピーされません。コピーは非公開で作成されます。</span>
            </label>
            <div id="new-form-dialog-message" class="alert hidden"></div>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-close-dialog="new-form-dialog">キャンセル</button>
            <button type="submit" class="btn btn--primary" id="create-form-confirm-button">作成を開始</button>
        </div>
    </form>
</dialog>

<dialog id="folder-dialog" class="modal" aria-labelledby="folder-dialog-title">
    <form id="folder-editor" class="modal__panel">
        <input type="hidden" name="id" value="0">
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon('folder', 'ico--lg') ?></span>
            <div><h2 class="modal__title" id="folder-dialog-title">フォルダーを作成</h2><p class="modal__desc">フォームの移動は「基本設定」または一覧の右クリックから行えます。</p></div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-close-dialog="folder-dialog" aria-label="閉じる"><?= fitsc_icon('x') ?></button>
        </div>
        <div class="modal__body form-grid">
            <label class="field span-full"><span>フォルダー名 <span class="req">必須</span></span><input type="text" name="name" maxlength="120" required data-autofocus></label>
            <label class="field"><span>親フォルダー</span><select name="parent_id" id="folder-parent-select"><option value="">最上位</option></select></label>
            <label class="field"><span>表示順</span><input type="number" name="sort_order" value="0"></label>
            <div id="folder-dialog-message" class="alert hidden span-full"></div>
        </div>
        <div class="modal__footer modal__footer--split">
            <button type="button" class="btn btn--danger-ghost hidden" id="delete-folder-button"><?= fitsc_icon('trash') ?>空のフォルダーを削除</button>
            <span class="cluster ml-auto">
                <button type="button" class="btn btn--ghost" data-close-dialog="folder-dialog">キャンセル</button>
                <button type="submit" class="btn btn--primary">保存</button>
            </span>
        </div>
    </form>
</dialog>

<template id="field-row-template">
    <article class="card field-card" data-field-row>
        <div class="card__header">
            <div class="card__title"><span data-field-title>新しい項目</span><span class="badge" data-field-type-badge>1行テキスト</span></div>
            <div class="card__actions">
                <label class="switch"><input type="checkbox" data-field="is_required">必須</label>
                <label class="switch"><input type="checkbox" data-field="is_enabled" checked>有効</label>
                <span class="btn-group">
                    <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move-field="up" aria-label="上へ移動"><?= fitsc_icon('chevron-up') ?></button>
                    <button type="button" class="btn btn--ghost btn--icon btn--sm" data-move-field="down" aria-label="下へ移動"><?= fitsc_icon('chevron-down') ?></button>
                    <button type="button" class="btn btn--danger-ghost btn--icon btn--sm" data-remove-field aria-label="この項目を削除"><?= fitsc_icon('trash') ?></button>
                </span>
            </div>
        </div>
        <div class="card__body">
            <div class="form-grid form-grid--3">
                <label class="field"><span>項目名</span><input type="text" data-field="field_label" placeholder="例：利用目的"></label>
                <label class="field"><span>種類</span>
                    <select data-field="field_type">
                        <option value="text">1行テキスト</option>
                        <option value="textarea">複数行テキスト</option>
                        <option value="date">日付</option>
                        <option value="number">数値</option>
                        <option value="select">選択</option>
                        <option value="checkbox">チェック</option>
                    </select>
                </label>
                <label class="field"><span>キー</span><input type="text" data-field="field_key" placeholder="purpose" spellcheck="false"></label>
                <label class="field"><span>プレースホルダー</span><input type="text" data-field="placeholder"></label>
                <label class="field"><span>初期値</span><input type="text" data-field="default_value"></label>
                <label class="field"><span>補足説明</span><input type="text" data-field="help_text"></label>
                <label class="field span-full" data-field-options-wrap hidden><span>選択肢 <span class="opt">改行またはカンマ区切り</span></span><textarea rows="3" data-field="options_text"></textarea></label>
            </div>
        </div>
    </article>
</template>

<?php page_footer(['assets/js/common.js', 'assets/js/admin_form_search.js', 'assets/js/admin.js']); ?>
