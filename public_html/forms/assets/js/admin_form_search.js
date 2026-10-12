(function initializeFormsAdminFormSearch(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  if (root) {
    root.FormsAdminFormSearch = api;
  }
}(typeof globalThis !== 'undefined' ? globalThis : this, function createFormsAdminFormSearchApi() {
  'use strict';

  function normalizeText(value) {
    return String(value ?? '').trim().toLocaleLowerCase('ja');
  }

  function matchesLocalForm(form, query, folderPath = '') {
    const keyword = normalizeText(query);
    if (!keyword) return true;

    return [form?.name, form?.slug, form?.description, folderPath]
      .filter(Boolean)
      .some((value) => normalizeText(value).includes(keyword));
  }

  function normalizeFormIds(values) {
    const source = Array.isArray(values) ? values : [];
    return new Set(source
      .map(Number)
      .filter((value) => Number.isInteger(value) && value > 0));
  }

  function createOrganizationSearchController(options = {}) {
    const search = options.search;
    if (typeof search !== 'function') {
      throw new TypeError('search callback is required');
    }

    const onStateChange = typeof options.onStateChange === 'function'
      ? options.onStateChange
      : () => {};
    const schedule = typeof options.setTimer === 'function' ? options.setTimer : setTimeout;
    const cancel = typeof options.clearTimer === 'function' ? options.clearTimer : clearTimeout;
    const debounceMs = Number.isFinite(Number(options.debounceMs))
      ? Math.max(0, Number(options.debounceMs))
      : 250;

    let timerId = null;
    let requestSequence = 0;
    let state = {
      query: '',
      matchingFormIds: new Set(),
      pending: false,
      error: '',
    };

    const snapshot = () => ({
      query: state.query,
      matchingFormIds: new Set(state.matchingFormIds),
      pending: state.pending,
      error: state.error,
    });

    const emit = () => {
      onStateChange(snapshot());
    };

    const update = (value) => {
      const query = String(value ?? '').trim();
      requestSequence += 1;
      const sequence = requestSequence;

      if (timerId !== null) {
        cancel(timerId);
        timerId = null;
      }

      state = {
        query,
        matchingFormIds: new Set(),
        pending: query !== '',
        error: '',
      };
      emit();

      if (query === '') {
        return;
      }

      timerId = schedule(async () => {
        timerId = null;
        try {
          const matchingFormIds = normalizeFormIds(await search(query));
          if (sequence !== requestSequence) return;
          state = {
            query,
            matchingFormIds,
            pending: false,
            error: '',
          };
        } catch (error) {
          if (sequence !== requestSequence) return;
          state = {
            query,
            matchingFormIds: new Set(),
            pending: false,
            error: error instanceof Error ? error.message : String(error || '検索に失敗しました。'),
          };
        }
        emit();
      }, debounceMs);
    };

    const dispose = () => {
      requestSequence += 1;
      if (timerId !== null) {
        cancel(timerId);
        timerId = null;
      }
    };

    return {
      update,
      dispose,
      getState: snapshot,
    };
  }

  return {
    normalizeText,
    matchesLocalForm,
    normalizeFormIds,
    createOrganizationSearchController,
  };
}));
