<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';
forms_bootstrap();
require_admin();

$user = current_user();
page_header('フォーム管理', 'forms-admin-page');
?>
<header class="topbar">
    <div>
        <h1>フォーム管理</h1>
        <p class="muted"><?= h($user['name'] ?? '') ?> さん</p>
    </div>
    <nav class="topbar-actions">
        <a class="btn" href="index.php">利用者画面</a>
        <a class="btn danger" href="logout.php">ログアウト</a>
    </nav>
</header>

<main class="page-shell admin-shell">
    <section class="card forms-list-card">
        <div class="section-title-row">
            <h2>フォーム一覧</h2>
            <button type="button" class="btn primary" id="new-form-button">新しいフォーム</button>
        </div>
        <div id="admin-message" class="alert hidden"></div>
        <div id="form-list" class="stack-list"></div>
    </section>

    <section class="card form-editor-card">
        <div class="section-title-row">
            <h2>フォーム設定</h2>
            <span class="muted small-inline">基本項目はメールアドレス・団体名です。</span>
        </div>
        <form id="form-editor" class="stack-form">
            <input type="hidden" name="id" value="0">
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
                <textarea name="description" rows="3" placeholder="フォームの説明を入力"></textarea>
            </label>

            <div class="two-col form-switch-grid">
                <label class="switch-card"><input type="checkbox" name="is_active" checked><span>公開する</span></label>
                <label>
                    <span>表示順</span>
                    <input type="number" name="sort_order" value="0">
                </label>
                <label class="switch-card"><input type="checkbox" name="enable_date_field" checked><span>日付入力を表示</span></label>
                <label class="switch-card"><input type="checkbox" name="date_required"><span>日付入力を必須にする</span></label>
                <label class="switch-card"><input type="checkbox" name="allow_file_upload"><span>ファイル添付を許可</span></label>
                <label class="switch-card"><input type="checkbox" name="file_required"><span>添付を必須にする</span></label>
            </div>

            <div class="two-col">
                <label>
                    <span>日付欄ラベル</span>
                    <input type="text" name="date_label" value="希望日">
                </label>
                <label>
                    <span>添付欄ラベル</span>
                    <input type="text" name="file_label" value="添付ファイル">
                </label>
            </div>

            <div class="two-col">
                <label>
                    <span>許可する拡張子</span>
                    <input type="text" name="allowed_extensions" value="pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip">
                </label>
                <label>
                    <span>最大ファイルサイズ (MB)</span>
                    <input type="number" name="max_upload_size_mb" min="1" max="30" value="5">
                </label>
            </div>

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

            <section class="field-builder">
                <div class="section-title-row">
                    <h3>追加項目</h3>
                    <button type="button" class="btn" id="add-field-button">項目を追加</button>
                </div>
                <div id="field-builder-list" class="field-builder-list"></div>
            </section>

            <div class="actions-row">
                <button type="submit" class="btn primary">フォームを保存</button>
            </div>
        </form>
    </section>

    <section class="card submissions-card">
        <div class="section-title-row">
            <h2>送信履歴（最新データ）</h2>
            <button type="button" class="btn" id="refresh-entries-button">再読込</button>
        </div>
        <div id="entry-summary" class="muted small-note">フォームを選択すると最新データと更新履歴を表示します。</div>
        <div id="entry-list" class="stack-list"></div>
        <div id="entry-history" class="history-panel hidden"></div>
    </section>
</main>

<template id="field-row-template">
    <article class="field-card" data-field-row>
        <div class="field-card-header">
            <strong>追加項目</strong>
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

<?php page_footer(['assets/js/common.js', 'assets/js/admin.js']); ?>
