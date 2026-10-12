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
    if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
    if (size >= 1024) return `${Math.ceil(size / 1024)} KB`;
    return `${size} B`;
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

  function renderDistributionSectionMulti(form) {
    const settings = form?.settings || {};
    const enabled = Boolean(settings.distribution_enabled);
    const files = distributionFiles(settings);
    const title = settings.distribution_title || '配布資料';
    const body = String(settings.distribution_body || '').trim();
    if (!enabled || (!files.length && !body && !settings.distribution_title)) {
      return '';
    }

    const bodyHtml = body ? `<div class="distribution-body allow-select">${esc(body).replaceAll('\n', '<br>')}</div>` : '';
    const downloadLabel = settings.distribution_download_label || '資料をダウンロード';
    const filesHtml = files.length ? `
      <div class="distribution-download-list">
        ${files.map((file, index) => {
          const params = new URLSearchParams({ form_id: String(form.id) });
          if (file.id) params.set('file_id', file.id);
          const meta = [fileSize(file.size_bytes), file.uploaded_at].filter(Boolean).join(' / ');
          const buttonText = files.length === 1 ? downloadLabel : 'ダウンロード';
          return `
            <div class="distribution-download-row distribution-download-item">
              <div class="file-ready-main">
                <strong>${esc(file.original_name || `配布ファイル${index + 1}`)}</strong>
                ${meta ? `<div class="small-note">${esc(meta)}</div>` : ''}
              </div>
              <a class="btn primary" href="api/download_form_asset.php?${params.toString()}">${esc(buttonText)}</a>
            </div>
          `;
        }).join('')}
      </div>
    ` : '';

    return `
      <section class="public-form-section distribution-public-section">
        <div class="section-title-row compact-row">
          <div>
            <h3>${esc(title)}</h3>
            ${bodyHtml}
          </div>
        </div>
        ${filesHtml}
      </section>
    `;
  }

  try {
    renderDistributionSection = renderDistributionSectionMulti;
  } catch (error) {
    window.renderDistributionSection = renderDistributionSectionMulti;
  }
  window.renderDistributionSection = renderDistributionSectionMulti;

  // public.js の初期描画後に読み込まれた場合でも、配布資料欄を新仕様で再描画する。
  setTimeout(() => {
    try {
      if (typeof renderActiveForm === 'function') renderActiveForm();
    } catch (error) {
      // noop
    }
  }, 0);
})();
