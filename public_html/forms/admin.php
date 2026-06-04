<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';
forms_bootstrap();
require_admin();

$user = current_user();
page_header('フォーム管理', 'forms-admin-page');
?>
<header class="topbar admin-topbar">
    <div>
        <p class="eyebrow">Generic Forms Admin</p>
        <h1>フォーム管理</h1>
        <p class="muted"><?= h($user['name'] ?? '') ?> さん向けの管理画面です。フォーム設定、追加項目、回答一覧を機能別タブで整理しています。</p>
    </div>
    <nav class="topbar-actions">
        <a class="btn" href="index.php">利用者画面</a>
        <a class="btn danger" href="logout.php?csrf_token=<?= urlencode(csrf_token()) ?>">ログアウト</a>
    </nav>
</header>

<main class="page-shell admin-app-shell">
    <aside class="admin-sidebar">
        <section class="card sidebar-card">
            <div class="section-title-row">
                <div>
                    <h2>フォーム一覧</h2>
                    <p class="small-note">作成済みフォームの選択と新規作成を行います。</p>
                </div>
                <button type="button" class="btn primary" id="new-form-button">新しいフォーム</button>
            </div>

            <div id="admin-message" class="alert hidden"></div>

            <label class="search-box">
                <span class="small-note">フォームを検索</span>
                <input type="search" id="form-search" placeholder="フォーム名・slugで検索">
            </label>

            <div class="mini-stat-grid" id="forms-overall-stats">
                <article class="mini-stat-card">
                    <span class="mini-stat-label">総フォーム数</span>
                    <strong id="overall-form-count">0</strong>
                </article>
                <article class="mini-stat-card">
                    <span class="mini-stat-label">公開中</span>
                    <strong id="overall-active-form-count">0</strong>
                </article>
                <article class="mini-stat-card">
                    <span class="mini-stat-label">非公開</span>
                    <strong id="overall-inactive-form-count">0</strong>
                </article>
            </div>

            <div id="form-list" class="stack-list form-nav-list"></div>
        </section>

        <section class="card sidebar-card" id="selected-form-sidebar-card">
            <div class="section-title-row compact-row">
                <h3>選択中のフォーム</h3>
                <span id="selected-form-status-pill" class="pill">未選択</span>
            </div>
            <div id="selected-form-sidebar-body" class="stack-list"></div>
        </section>
    </aside>

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
                <button type="button" class="workspace-tab-button active" data-workspace-tab="overview">概要</button>
                <button type="button" class="workspace-tab-button" data-workspace-tab="settings">基本設定</button>
                <button type="button" class="workspace-tab-button" data-workspace-tab="fields">追加項目</button>
                <button type="button" class="workspace-tab-button" data-workspace-tab="responses">回答一覧</button>
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
                                <span>表示順</span>
                                <input type="number" name="sort_order" value="0">
                            </label>
                            <div class="toggle-stack">
                                <label class="switch-card"><input type="checkbox" name="is_active" checked><span>公開する</span></label>
                            </div>
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
    </section>
</main>

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

<?php page_footer(['assets/js/common.js', 'assets/js/admin.js', 'assets/js/distribution_multi_admin.js']); ?>
