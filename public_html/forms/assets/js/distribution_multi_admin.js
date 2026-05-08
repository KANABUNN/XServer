(() => {
  function esc(value = '') {
    if (typeof escapeHtml === 'function') return escapeHtml(value);
    return String(value ?? '').replace(/[&<>'"]/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
    }[ch]));
  }

  function fileSize(bytes = 0) {
    const size = Number(bytes || 0);
    if (!Number.isFinite(size) || size <= 0) return '';
    if (typeof formatFileSize === 'function') return formatFileSize(size);
    if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
    if (size >= 1024) return `${Math.ceil(size / 1024)} KB`;
    return `${size} B`;
  }

  function csrfToken() {
    if (window.App && window.App.csrfToken) return String(window.App.csrfToken);
    if (window.FORMS_CSRF_TOKEN) return String(window.FORMS_CSRF_TOKEN);
    if (window.CSRF_TOKEN) return String(window.CSRF_TOKEN);
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) return meta.content;
    const input = document.querySelector('input[name="csrf_token"]');
    if (input?.value) return input.value;
    return '';
  }

  function notify(message, type = 'info') {
    if (typeof showFlashMessage === 'function') {
      showFlashMessage(message, type, { title: type === 'error' ? 'エラー' : '完了' });
      return;
    }
    const box = document.getElementById('admin-message');
    if (box) {
      box.className = `alert ${type === 'error' ? 'danger' : type}`;
      box.textContent = message;
      box.classList.remove('hidden');
      return;
    }
    alert(message);
  }

  function distributionFiles(settings = {}) {
    const files = [];
    if (Array.isArray(settings.distribution_files)) {
      settings.distribution_files.forEach((file) => {
        if (!file || !file.relative_path) return;
        files.push({
          id: String(file.id || ''),
          original_name: String(file.original_name || file.stored_name || '配布ファイル'),
          size_bytes: Number(file.size_bytes || 0),
          uploaded_at: String(file.uploaded_at || ''),
        });
      });
    }

    if (!files.length && settings.distribution_file_relative_path) {
      files.push({
        id: String(settings.distribution_file_id || ''),
        original_name: String(settings.distribution_file_original_name || '配布ファイル'),
        size_bytes: Number(settings.distribution_file_size_bytes || 0),
        uploaded_at: String(settings.distribution_file_uploaded_at || ''),
      });
    }
    return files;
  }

  function activeForm() {
    if (typeof getActiveForm === 'function') return getActiveForm();
    return adminState?.forms?.find((form) => form.id === adminState.activeFormId) || null;
  }

  function renderDistributionFileStatusMulti(form = null) {
    const root = document.getElementById('distribution-file-status');
    if (!root) return;
    const settings = form?.settings || {};
    const files = distributionFiles(settings);

    if (!form || !form.id) {
      root.className = 'distribution-file-status empty-state';
      root.innerHTML = '保存済みフォームを選択すると配布ファイルを登録できます。';
      return;
    }
    if (!files.length) {
      root.className = 'distribution-file-status empty-state';
      root.innerHTML = '配布ファイルは未設定です。';
      return;
    }

    root.className = 'distribution-file-status file-ready-card';
    root.innerHTML = `
      <div class="stack-list distribution-admin-file-list">
        ${files.map((file, index) => {
          const params = new URLSearchParams({ form_id: String(form.id) });
          if (file.id) params.set('file_id', file.id);
          const meta = [fileSize(file.size_bytes), file.uploaded_at].filter(Boolean).join(' / ');
          return `
            <div class="list-item-header compact-row distribution-admin-file-item">
              <div class="file-ready-main">
                <strong>${esc(file.original_name || `配布ファイル${index + 1}`)}</strong>
                ${meta ? `<div class="small-note">${esc(meta)}</div>` : ''}
              </div>
              <div class="inline-actions">
                <a class="btn btn-small" href="api/admin_download_form_asset.php?${params.toString()}" target="_blank" rel="noopener">ダウンロード確認</a>
                <button type="button" class="btn danger btn-small" data-delete-distribution-file="${esc(file.id)}">削除</button>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `;
  }

  function applyUpdatedForm(result = {}) {
    if (typeof mergeUpdatedForm === 'function') {
      mergeUpdatedForm(result);
      return;
    }
    if (result.form?.id && window.adminState?.forms) {
      const index = adminState.forms.findIndex((form) => form.id === result.form.id);
      if (index >= 0) adminState.forms[index] = result.form;
      else adminState.forms.push(result.form);
      adminState.activeFormId = result.form.id;
    }
    renderDistributionFileStatusMulti(result.form || activeForm());
  }

  async function postForm(url, formData) {
    const response = await fetch(url, { method: 'POST', body: formData });
    const result = await response.json().catch(() => ({ ok: false, message: '応答の解析に失敗しました。' }));
    if (!response.ok || !result.ok) {
      throw new Error(result.message || '処理に失敗しました。');
    }
    return result;
  }

  async function uploadDistributionFiles() {
    const form = activeForm();
    const input = document.getElementById('distribution-file-input');
    if (!form?.id) {
      notify('保存済みフォームを選択してからアップロードしてください。', 'error');
      return;
    }
    if (!input?.files?.length) {
      notify('アップロードする配布ファイルを選択してください。', 'error');
      return;
    }

    const button = document.getElementById('upload-distribution-files-button') || document.getElementById('upload-distribution-file-button');
    const data = new FormData();
    data.append('csrf_token', csrfToken());
    data.append('form_id', String(form.id));
    Array.from(input.files).forEach((file) => data.append('distribution_files[]', file));

    try {
      if (button) button.disabled = true;
      const result = await postForm('api/admin_upload_distribution_files.php', data);
      input.value = '';
      applyUpdatedForm(result);
      notify(result.message || '配布ファイルを保存しました。', 'success');
    } catch (error) {
      notify(error.message || '配布ファイルの保存に失敗しました。', 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function deleteDistributionFile(fileId) {
    const form = activeForm();
    if (!form?.id || !fileId) return;
    if (!confirm('この配布ファイルを削除します。よろしいですか？')) return;

    const data = new FormData();
    data.append('csrf_token', csrfToken());
    data.append('form_id', String(form.id));
    data.append('file_id', String(fileId));

    try {
      const result = await postForm('api/admin_delete_distribution_file.php', data);
      applyUpdatedForm(result);
      notify(result.message || '配布ファイルを削除しました。', 'success');
    } catch (error) {
      notify(error.message || '配布ファイルの削除に失敗しました。', 'error');
    }
  }

  try {
    renderDistributionFileStatus = renderDistributionFileStatusMulti;
  } catch (error) {
    window.renderDistributionFileStatus = renderDistributionFileStatusMulti;
  }
  window.renderDistributionFileStatus = renderDistributionFileStatusMulti;

  function bind() {
    const input = document.getElementById('distribution-file-input');
    if (input) input.setAttribute('multiple', 'multiple');
    const uploadButton = document.getElementById('upload-distribution-files-button') || document.getElementById('upload-distribution-file-button');
    uploadButton?.setAttribute('type', 'button');
    uploadButton?.addEventListener('click', (event) => {
      event.preventDefault();
      uploadDistributionFiles();
    });
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-delete-distribution-file]');
      if (!button) return;
      event.preventDefault();
      deleteDistributionFile(button.dataset.deleteDistributionFile || '');
    });
    renderDistributionFileStatusMulti(activeForm());
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
