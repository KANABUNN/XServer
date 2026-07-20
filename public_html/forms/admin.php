<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';
forms_bootstrap();
require_admin();
$user = current_user();
page_header('フォーム管理', 'forms-admin-page');
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/admin-shell.css')) ?>">

<div class="admin-shell">
    <aside class="sidebar forms-admin-sidebar" aria-label="フォーム管理">
        <div class="sidebar-brand">
            <p class="brand-kicker">forms.fit-sc.jp</p>
            <h1>フォーム管理</h1>
            <p>設定・追加項目・回答を機能別に管理します。</p>
        </div>

        <div class="sidebar-scroll">
            <section class="card sidebar-card form-browser-card">
                <div class="section-title-row">
                    <div>
                        <p class="eyebrow">Form browser</p>
                        <h2>フォームを選択</h2>
                    </div>
                    <div class="form-browser-actions">
                        <button type="button" class="icon-text-button" id="new-folder-button" title="フォルダーを作成">＋ フォルダー</button>
                        <button type="button" class="btn primary" id="new-form-button">＋ 新規</button>
                    </div>
                </div>

                <div id="admin-message" class="alert hidden"></div>

                <label class="search-box">
                    <span class="visually-hidden">フォームまたは提出団体を検索</span>
                    <input type="search" id="form-search" placeholder="フォーム名・フォルダー名・提出団体名を検索" maxlength="200" autocomplete="off" aria-describedby="form-search-status">
                </label>
                <p id="form-search-status" class="form-search-status small-note" aria-live="polite">フォーム名・説明・フォルダー名・提出団体名から検索できます。</p>

                <section id="recent-forms-section" class="form-browser-group hidden" aria-labelledby="recent-forms-heading">
                    <div class="form-browser-group-title">
                        <h3 id="recent-forms-heading">最近使ったフォーム</h3>
                        <span class="small-note">最大3件</span>
                    </div>
                    <div id="recent-form-list" class="recent-form-list"></div>
                </section>

                <section class="form-browser-group directory-browser" aria-labelledby="directory-heading">
                    <div class="form-browser-group-title">
                        <h3 id="directory-heading">すべてのフォーム</h3>
                        <button type="button" class="quiet-button" id="expand-all-folders-button">すべて展開</button>
                    </div>
                    <div id="form-list" class="form-directory-tree"></div>
                </section>

                <div class="form-browser-stats" id="forms-overall-stats" aria-label="フォーム集計">
                    <span>全 <strong id="overall-form-count">0</strong></span>
                    <span class="stat-dot stat-active" aria-hidden="true"></span><span>公開 <strong id="overall-active-form-count">0</strong></span>
                    <span class="stat-dot stat-inactive" aria-hidden="true"></span><span>非公開 <strong id="overall-inactive-form-count">0</strong></span>
                </div>
            </section>

            <section class="card sidebar-card" id="selected-form-sidebar-card">
                <div class="section-title-row compact-row">
                    <h3>選択中のフォーム</h3>
                    <span id="selected-form-status-pill" class="pill">未選択</span>
                </div>
                <div id="selected-form-sidebar-body" class="stack-list"></div>
            </section>
        </div><!-- /.sidebar-scroll -->

        <div class="sidebar-user">
            <a class="sidebar-link" data-nav="userview" href="index.php">利用者画面</a>
            <div class="sidebar-user-card">
                <strong><?= h($user['name'] ?? '') ?></strong>
                <span><?= h($user['role_label'] ?? '管理者') ?> / <?= h($user['login_id'] ?? '') ?></span>
            </div>
            <a class="sidebar-logout-link" data-nav="logout" href="logout.php?csrf_token=<?= urlencode(csrf_token()) ?>">ログアウト</a>
        </div>
    </aside>

    <main class="content-shell">
        <div class="wrap">
            <section class="admin-workspace">
                <section class="card workspace-header-card">
                    <div class="workspace-header-main">
                        <div>
                            <p class="eyebrow">Workspace</p>
                            <h2 id="workspace-form-name">フォームを選択してください</h2>
                            <p id="workspace-form-description" class="muted">左の一覧からフォームを選択すると、設定・項目・回答をこのエリアで切り替えて管理できます。</p>
                        </div>
                        <div class="workspace-meta" id="workspace-meta"></div>
                    </div>

                    <div class="workspace-tab-list" role="tablist" aria-label="管理タブ">
                        <button type="button" class="workspace-tab-button active" data-workspace-tab="overview" data-nav="overview">概要</button>
                        <button type="button" class="workspace-tab-button" data-workspace-tab="settings" data-nav="settings">基本設定</button>
                        <button type="button" class="workspace-tab-button" data-workspace-tab="fields" data-nav="fields">追加項目</button>
                        <button type="button" class="workspace-tab-button" data-workspace-tab="responses" data-nav="responses">回答一覧</button>
                    </div>
                </section>

                <section class="card workspace-panel" data-workspace-panel="overview">
                    <div class="section-title-row">
                        <div>
                            <h2>概要</h2>
                            <p class="small-note">現在の設定状況と回答状況をまとめて確認できます。</p>
                        </div>
                        <div class="inline-actions">
                            <button type="button" class="btn" data-switch-workspace="settings">基本設定を開く</button>
                            <button type="button" class="btn" data-switch-workspace="fields">追加項目を開く</button>
                            <button type="button" class="btn" data-switch-workspace="responses">回答一覧を開く</button>
                        </div>
                    </div>

                    <div class="overview-kpi-grid" id="overview-kpi-grid">
                        <article class="kpi-card">
                            <span class="kpi-label">フォーム状態</span>
                            <strong id="overview-kpi-status">未選択</strong>
                            <p class="small-note">公開状態と利用可否を表示します。</p>
                        </article>
                        <article class="kpi-card">
                            <span class="kpi-label">追加項目数</span>
                            <strong id="overview-kpi-fields">0</strong>
                            <p class="small-note">カスタム項目の総数です。</p>
                        </article>
                        <article class="kpi-card">
                            <span class="kpi-label">最新回答数</span>
                            <strong id="overview-kpi-entries">0</strong>
                            <p class="small-note">現在の最新データ件数です。</p>
                        </article>
                        <article class="kpi-card">
                            <span class="kpi-label">未確認</span>
                            <strong id="overview-kpi-new">0</strong>
                            <p class="small-note">状態が未確認の回答件数です。</p>
                        </article>
                    </div>

                    <div class="overview-grid">
                        <article class="subcard">
                            <h3>フォーム情報</h3>
                            <dl class="info-grid" id="overview-form-info"></dl>
                        </article>
                        <article class="subcard">
                            <h3>入力設定</h3>
                            <div id="overview-setting-pills" class="meta-line"></div>
                            <div id="overview-period-note" class="small-note"></div>
                        </article>
                        <article class="subcard">
                            <h3>回答状況</h3>
                            <div id="overview-status-summary" class="status-card-grid"></div>
                        </article>
                        <article class="subcard">
                            <h3>追加項目プレビュー</h3>
                            <div id="overview-field-preview" class="stack-list"></div>
                        </article>
                    </div>
                </section>

                <form id="form-editor" class="workspace-form">
                    <input type="hidden" name="id" value="0">

                    <section class="card workspace-panel hidden" data-workspace-panel="settings">
                        <div class="section-title-row">
                            <div>
                                <h2>基本設定</h2>
                                <p class="small-note">フォーム名、公開状態、提出設定をまとめて編集します。</p>
                            </div>
                            <button type="submit" class="btn primary">設定を保存</button>
                        </div>

                        <div class="editor-section-grid">
                            <section class="subcard">
                                <h3>基本情報</h3>
                                <div class="two-col">
                                    <label>
                                        <span>フォーム名</span>
                                        <input type="text" name="name" required>
                                    </label>
                                    <label>
                                        <span>スラッグ</span>
                                        <input type="text" name="slug" placeholder="form-general">
                                    </label>
                                </div>
                                <label>
                                    <span>説明</span>
                                    <textarea name="description" rows="4" placeholder="フォームの説明を入力"></textarea>
                                </label>
                                <div class="two-col">
                                    <label>
                                        <span>保存先フォルダー</span>
                                        <select name="folder_id" id="form-folder-select">
                                            <option value="">未分類</option>
                                        </select>
                                    </label>
                                    <label>
                                        <span>表示順</span>
                                        <input type="number" name="sort_order" value="0">
                                    </label>
                                </div>
                                <div class="toggle-stack">
                                    <label class="switch-card"><input type="checkbox" name="is_active" checked><span>公開する</span></label>
                                </div>
                            </section>

                            <section class="subcard">
                                <h3>日付入力</h3>
                                <div class="toggle-stack">
                                    <label class="switch-card"><input type="checkbox" name="enable_date_field" checked><span>日付入力を表示</span></label>
                                    <label class="switch-card"><input type="checkbox" name="date_required"><span>日付入力を必須にする</span></label>
                                </div>
                                <label>
                                    <span>日付欄ラベル</span>
                                    <input type="text" name="date_label" value="希望日">
                                </label>
                            </section>

                            <section class="subcard">
                                <h3>添付ファイル</h3>
                                <div class="toggle-stack">
                                    <label class="switch-card"><input type="checkbox" name="allow_file_upload"><span>ファイル添付を許可</span></label>
                                    <label class="switch-card"><input type="checkbox" name="file_required"><span>添付を必須にする</span></label>
                                </div>
                                <div class="two-col">
                                    <label>
                                        <span>添付欄ラベル</span>
                                        <input type="text" name="file_label" value="添付ファイル">
                                    </label>
                                    <label>
                                        <span>最大ファイルサイズ (MB)</span>
                                        <input type="number" name="max_upload_size_mb" min="1" max="30" value="5">
                                    </label>
                                    <label>
                                        <span>最大ファイル数</span>
                                        <input type="number" name="max_upload_files" min="1" max="10" value="1">
                                    </label>
                                </div>
                                <label>
                                    <span>許可する拡張子</span>
                                    <input type="text" name="allowed_extensions" value="pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip">
                                </label>
                            </section>

                            <section class="subcard distribution-file-card">
                                <h3>利用者配布ファイル</h3>
                                <p class="small-note">利用者にダウンロードしてもらう様式・資料と、利用者画面に表示する案内文を設定します。固定文は表示せず、ここで入力した文章だけを表示します。</p>
                                <div class="toggle-stack">
                                    <label class="switch-card"><input type="checkbox" name="distribution_enabled"><span>利用者画面に配布資料欄を表示</span></label>
                                </div>
                                <label>
                                    <span>配布欄タイトル</span>
                                    <input type="text" name="distribution_title" placeholder="例：提出用様式">
                                </label>
                                <label>
                                    <span>利用者に表示する案内文</span>
                                    <textarea name="distribution_body" rows="5" placeholder="例：下記の様式を必要に応じて使用してください。"></textarea>
                                </label>
                                <label>
                                    <span>ダウンロードボタン表示</span>
                                    <input type="text" name="distribution_download_label" value="資料をダウンロード">
                                </label>
                                <div class="distribution-upload-panel">
                                    <div id="distribution-file-status" class="distribution-file-status empty-state">配布ファイルは未設定です。</div>
                                    <label class="form-block">
                                        <span>配布ファイルを選択</span>
                                        <input type="file" id="distribution-file-input" multiple>
                                    </label>
                                    <div class="inline-actions">
                                        <button type="button" class="btn" id="upload-distribution-files-button">配布ファイルを保存</button>
                                    </div>
                                    <p class="small-note">保存済みフォームを選択してからアップロードしてください。複数ファイルを一括選択できます。対応拡張子は PDF / Office / 画像 / ZIP / CSV / TXT、上限は1ファイル30MBです。</p>
                                </div>
                            </section>

                            <section class="subcard">
                                <h3>公開期間</h3>
                                <div class="two-col">
                                    <label>
                                        <span>公開開始日</span>
                                        <input type="date" name="public_start_date">
                                    </label>
                                    <label>
                                        <span>受付開始時刻</span>
                                        <input type="time" name="public_start_time" step="60">
                                    </label>
                                </div>
                                <div class="two-col">
                                    <label>
                                        <span>公開終了日</span>
                                        <input type="date" name="public_end_date">
                                    </label>
                                    <label>
                                        <span>受付終了時刻</span>
                                        <input type="time" name="public_end_time" step="60">
                                    </label>
                                </div>
                                <p class="small-note">日付だけを設定した場合、開始日は 00:00 から、終了日は 23:59 まで受け付けます。時刻を指定する場合は対応する開始日または終了日も設定してください。</p>
                            </section>

                            <section class="subcard">
                                <h3>送信表示</h3>
                                <div class="two-col">
                                    <label>
                                        <span>送信ボタン表示</span>
                                        <input type="text" name="submit_button_label" value="送信する">
                                    </label>
                                    <label>
                                        <span>完了メッセージ</span>
                                        <input type="text" name="completion_message" value="送信を受け付けました。">
                                    </label>
                                </div>
                                <p class="small-note">利用者画面で表示される案内文です。</p>
                            </section>

                            <section class="subcard danger-zone-card">
                                <h3>フォーム削除</h3>
                                <p class="small-note">フォーム本体、回答一覧、更新履歴、状態ログをまとめて削除します。削除後は元に戻せません。</p>
                                <button type="button" class="btn danger" id="delete-form-button">このフォームを削除</button>
                            </section>
                        </div>

                        <div class="sticky-submit-bar">
                            <div>
                                <strong>基本設定を保存</strong>
                                <p class="small-note">フォーム情報と入力設定を反映します。</p>
                            </div>
                            <button type="submit" class="btn primary">設定を保存</button>
                        </div>
                    </section>

                    <section class="card workspace-panel hidden" data-workspace-panel="fields">
                        <div class="section-title-row">
                            <div>
                                <h2>追加項目</h2>
                                <p class="small-note">利用者に追加で入力させる項目を設計します。</p>
                            </div>
                            <div class="inline-actions">
                                <button type="button" class="btn" id="add-field-button">項目を追加</button>
                                <button type="submit" class="btn primary">項目を保存</button>
                            </div>
                        </div>

                        <div class="builder-callout">
                            <strong>基本項目</strong>
                            <span>メールアドレス・団体名は常に表示されます。ここでは追加項目のみを設定します。</span>
                        </div>

                        <div id="field-builder-list" class="field-builder-list"></div>

                        <div class="sticky-submit-bar">
                            <div>
                                <strong>追加項目を保存</strong>
                                <p class="small-note">項目の順序は表示順のまま保存されます。</p>
                            </div>
                            <button type="submit" class="btn primary">項目を保存</button>
                        </div>
                    </section>
                </form>

                <section class="card workspace-panel hidden" data-workspace-panel="responses">
                    <div class="section-title-row">
                        <div>
                            <h2>回答一覧</h2>
                            <p class="small-note">検索・絞り込み・状態更新・履歴確認を一つの画面で行えます。</p>
                        </div>
                        <div class="inline-actions">
                            <button type="button" class="btn" id="refresh-entries-button">再読込</button>
                            <button type="button" class="btn" id="download-latest-attachments-button">最新添付ZIP</button>
                            <button type="button" class="btn primary" id="export-csv-button">CSV出力</button>
                        </div>
                    </div>

                    <form id="entry-filter-form" class="filter-toolbar" autocomplete="off">
                        <label>
                            <span>検索</span>
                            <input type="search" name="query" placeholder="団体名・メール・回答内容・メモ">
                        </label>
                        <label>
                            <span>状態</span>
                            <select name="status" id="entry-status-filter"></select>
                        </label>
                        <label>
                            <span>更新日 From</span>
                            <input type="date" name="date_from">
                        </label>
                        <label>
                            <span>更新日 To</span>
                            <input type="date" name="date_to">
                        </label>
                        <label>
                            <span>表示件数</span>
                            <select name="limit">
                                <option value="25">25件</option>
                                <option value="50">50件</option>
                                <option value="100" selected>100件</option>
                                <option value="200">200件</option>
                                <option value="500">500件</option>
                            </select>
                        </label>
                        <div class="filter-toolbar-actions">
                            <button type="submit" class="btn primary">絞り込む</button>
                            <button type="button" class="btn" id="reset-filter-button">リセット</button>
                        </div>
                    </form>

                    <div id="entry-status-summary" class="status-card-grid"></div>
                    <div id="entry-summary" class="muted small-note">フォームを選択すると最新データと更新履歴を表示します。</div>

                    <div class="responses-layout">
                        <section class="responses-list-pane">
                            <div id="entry-list" class="stack-list response-list"></div>
                        </section>
                        <aside class="responses-detail-pane">
                            <div id="entry-detail-empty" class="empty-state">左の回答を選択すると詳細、状態更新、履歴を表示します。</div>
                            <div id="entry-detail" class="hidden"></div>
                        </aside>
                    </div>
                </section>
            </section><!-- /.admin-workspace -->
        </div><!-- /.wrap -->
    </main><!-- /.content-shell -->
</div><!-- /.admin-shell -->

<dialog id="new-form-dialog" class="admin-dialog" aria-labelledby="new-form-dialog-title">
    <form id="new-form-dialog-form" class="dialog-card">
        <div class="dialog-header">
            <div>
                <p class="eyebrow">Create form</p>
                <h2 id="new-form-dialog-title">新しいフォーム</h2>
            </div>
            <button type="button" class="dialog-close-button" data-close-dialog="new-form-dialog" aria-label="閉じる">×</button>
        </div>

        <label>
            <span>フォーム名</span>
            <input type="text" name="name" maxlength="150" required placeholder="例：2026年度 活動報告">
        </label>
        <label>
            <span>保存先フォルダー</span>
            <select name="folder_id" id="new-form-folder-select">
                <option value="">未分類</option>
            </select>
        </label>

        <fieldset class="creation-mode-fieldset">
            <legend>作成方法</legend>
            <label class="creation-mode-card">
                <input type="radio" name="creation_mode" value="blank" checked>
                <span><strong>空のフォーム</strong><small>初期設定から作成します。</small></span>
            </label>
            <label class="creation-mode-card">
                <input type="radio" name="creation_mode" value="copy">
                <span><strong>既存フォームをコピー</strong><small>設定と追加項目を引き継ぎます。</small></span>
            </label>
        </fieldset>

        <label id="copy-source-field" class="hidden">
            <span>コピー元フォーム</span>
            <select name="source_form_id" id="copy-source-form-select"></select>
            <small class="small-note">回答・履歴・提出ファイル・配布ファイル本体はコピーされません。コピーは非公開で作成されます。</small>
        </label>

        <div id="new-form-dialog-message" class="alert hidden"></div>
        <div class="dialog-actions">
            <button type="button" class="btn" data-close-dialog="new-form-dialog">キャンセル</button>
            <button type="submit" class="btn primary" id="create-form-confirm-button">作成を開始</button>
        </div>
    </form>
</dialog>

<dialog id="folder-dialog" class="admin-dialog" aria-labelledby="folder-dialog-title">
    <form id="folder-editor" class="dialog-card">
        <input type="hidden" name="id" value="0">
        <div class="dialog-header">
            <div>
                <p class="eyebrow">Directory</p>
                <h2 id="folder-dialog-title">フォルダーを作成</h2>
            </div>
            <button type="button" class="dialog-close-button" data-close-dialog="folder-dialog" aria-label="閉じる">×</button>
        </div>
        <label>
            <span>フォルダー名</span>
            <input type="text" name="name" maxlength="120" required>
        </label>
        <div class="two-col">
            <label>
                <span>親フォルダー</span>
                <select name="parent_id" id="folder-parent-select">
                    <option value="">最上位</option>
                </select>
            </label>
            <label>
                <span>表示順</span>
                <input type="number" name="sort_order" value="0">
            </label>
        </div>
        <p class="small-note">フォルダーの階層は必要な深さまで作成できます。フォームの移動は「基本設定」の保存先フォルダーから行います。</p>
        <div id="folder-dialog-message" class="alert hidden"></div>
        <div class="dialog-actions split-actions">
            <button type="button" class="btn danger hidden" id="delete-folder-button">空のフォルダーを削除</button>
            <span class="dialog-action-spacer"></span>
            <button type="button" class="btn" data-close-dialog="folder-dialog">キャンセル</button>
            <button type="submit" class="btn primary">保存</button>
        </div>
    </form>
</dialog>

<template id="field-row-template">
    <article class="field-card" data-field-row>
        <div class="field-card-header">
            <div>
                <strong>追加項目</strong>
                <div class="small-note">種類や必須設定を編集できます。</div>
            </div>
            <button type="button" class="btn danger btn-small" data-remove-field>削除</button>
        </div>

        <div class="two-col">
            <label><span>項目名</span><input type="text" data-field="field_label"></label>
            <label><span>キー</span><input type="text" data-field="field_key" placeholder="purpose"></label>
        </div>

        <div class="two-col">
            <label>
                <span>種類</span>
                <select data-field="field_type">
                    <option value="text">1行テキスト</option>
                    <option value="textarea">複数行テキスト</option>
                    <option value="date">日付</option>
                    <option value="number">数値</option>
                    <option value="select">選択</option>
                    <option value="checkbox">チェック</option>
                </select>
            </label>
            <label><span>プレースホルダー</span><input type="text" data-field="placeholder"></label>
        </div>

        <label><span>補足</span><input type="text" data-field="help_text"></label>
        <label><span>選択肢（改行またはカンマ区切り）</span><textarea rows="3" data-field="options_text"></textarea></label>

        <div class="two-col">
            <label><span>初期値</span><input type="text" data-field="default_value"></label>
            <div class="inline-flags">
                <label class="switch-card"><input type="checkbox" data-field="is_required"><span>必須</span></label>
                <label class="switch-card"><input type="checkbox" data-field="is_enabled" checked><span>有効</span></label>
            </div>
        </div>
    </article>
</template>

<?php page_footer(['assets/js/common.js', 'assets/js/admin_form_search.js', 'assets/js/admin.js', 'assets/js/distribution_multi_admin.js']); ?>
