// Notification interactions are kept separate from shared page/session utilities.
const NotificationCenter = (() => {
  function setup({ user, controls, api, esc }) {
    if (!controls || !user || !['customer', 'owner'].includes(user.role)) return;

    const center = document.createElement('div');
    center.className = 'notification-center';
    center.innerHTML = `
      <button type="button" class="btn ghost light notification-toggle"
          aria-expanded="false" aria-controls="notificationPanel">
        Thông báo <span class="notification-count" hidden></span>
      </button>
      <section id="notificationPanel" class="notification-panel" aria-label="Thông báo" hidden>
        <div class="notification-heading">
          <div><small>HỘP THƯ CỦA BẠN</small><b>Thông báo</b></div>
          <button type="button" class="notification-bulk-action" disabled>Đánh dấu tất cả đã đọc</button>
        </div>
        <p class="notification-error error" role="alert" hidden></p>
        <div class="notification-list" role="list" aria-label="Danh sách thông báo">
          <p class="hint">Đang tải thông báo...</p>
        </div>
        <p class="notification-help">Tick để đánh dấu đã đọc. Bỏ tick để chuyển về chưa đọc.</p>
      </section>`;
    controls.append(center);

    const toggle = center.querySelector('.notification-toggle');
    const panel = center.querySelector('.notification-panel');
    const list = center.querySelector('.notification-list');
    const bulkAction = center.querySelector('.notification-bulk-action');
    const errorMessage = center.querySelector('.notification-error');
    let lastData = { items: [], unread_count: 0 };
    let updating = false;
    let refreshSequence = 0;

    function safeLink(link) {
      return /^(dat-san|chu-san)(?:\?[^#]*)?(?:#.*)?$/.test(String(link || '')) ? link : '';
    }

    function render(data, focusSelector) {
      lastData = data;
      const unreadCount = Number(data.unread_count || 0);
      const badge = center.querySelector('.notification-count');
      badge.hidden = unreadCount === 0;
      badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
      badge.setAttribute('aria-label', `${unreadCount} thông báo chưa đọc`);
      bulkAction.dataset.operation = unreadCount > 0 ? 'read-all' : 'unread-all';
      bulkAction.textContent =
        unreadCount > 0 ? 'Đánh dấu tất cả đã đọc' : 'Đánh dấu tất cả chưa đọc';
      bulkAction.disabled = updating || data.items.length === 0;

      list.innerHTML =
        data.items
          .map((item) => {
            const isRead = Boolean(item.read_at);
            const id = Number(item.id);
            const createdAt = new Date(String(item.created_at).replace(' ', 'T') + '+07:00');
            const dateText = Number.isNaN(createdAt.getTime())
              ? item.created_at
              : createdAt.toLocaleString('vi-VN');

            return `
          <article role="listitem" class="notification-item ${isRead ? '' : 'unread'}">
            <button type="button" class="notification-open" data-notification-open="${id}"
                data-notification-link="${esc(safeLink(item.link))}">
              <span class="notification-item-title">${esc(item.title)}</span>
              <span class="notification-item-message">${esc(item.message)}</span>
              <time>${esc(dateText)}</time>
            </button>
            <label class="notification-read-toggle">
              <input type="checkbox" data-notification-toggle="${id}" ${isRead ? 'checked' : ''}
                  aria-label="Đã đọc: ${esc(item.title)}">
              <span>${isRead ? 'Đánh dấu chưa đọc' : 'Đánh dấu đã đọc'}</span>
            </label>
          </article>`;
          })
          .join('') || '<p class="empty">Bạn chưa có thông báo nào.</p>';

      if (focusSelector) center.querySelector(focusSelector)?.focus({ preventScroll: true });
    }

    function showError(error) {
      errorMessage.textContent = error.message || 'Không thể cập nhật thông báo. Vui lòng thử lại.';
      errorMessage.hidden = false;
    }

    async function refresh(focusSelector) {
      if (updating) return;
      const sequence = ++refreshSequence;

      try {
        const data = await api('notifications');
        if (sequence === refreshSequence && !updating) {
          errorMessage.hidden = true;
          render(data, focusSelector);
        }
      } catch (error) {
        if (sequence === refreshSequence) showError(error);
      }
    }

    async function update(operation, body, focusSelector) {
      if (updating) return false;
      updating = true;
      refreshSequence++;
      errorMessage.hidden = true;
      center.setAttribute('aria-busy', 'true');
      center
        .querySelectorAll(
          '.notification-list button, .notification-list input, .notification-bulk-action'
        )
        .forEach((control) => {
          control.disabled = true;
        });

      try {
        const data = await api(`notifications/${operation}`, { method: 'POST', body });
        updating = false;
        render(data, focusSelector);
        return true;
      } catch (error) {
        updating = false;
        render(lastData, focusSelector);
        showError(error);
        return false;
      } finally {
        updating = false;
        center.removeAttribute('aria-busy');
        // Keep the current list usable if the refresh failed after saving.
        center
          .querySelectorAll('.notification-list button, .notification-list input')
          .forEach((control) => {
            control.disabled = false;
          });
        bulkAction.disabled = lastData.items.length === 0;
      }
    }

    toggle.addEventListener('click', () => {
      panel.hidden = !panel.hidden;
      toggle.setAttribute('aria-expanded', String(!panel.hidden));
      if (!panel.hidden) refresh();
    });

    bulkAction.addEventListener('click', () => {
      update(bulkAction.dataset.operation, {}, '.notification-bulk-action');
    });

    list.addEventListener('change', (event) => {
      const input = event.target.closest('[data-notification-toggle]');
      if (!input) return;
      const id = Number(input.dataset.notificationToggle);
      update(input.checked ? 'read' : 'unread', { id }, `[data-notification-toggle="${id}"]`);
    });

    list.addEventListener('click', async (event) => {
      const item = event.target.closest('[data-notification-open]');
      if (!item) return;
      const id = Number(item.dataset.notificationOpen);
      const link = safeLink(item.dataset.notificationLink);
      const saved = await update('read', { id }, `[data-notification-open="${id}"]`);
      if (saved && link) location.href = link;
    });

    document.addEventListener('click', (event) => {
      if (!center.contains(event.target)) {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      }
    });

    center.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });

    refresh();
    window.setInterval(() => {
      if (!document.hidden) refresh();
    }, 30000);
  }

  return { setup };
})();
