(async () => {
  const { $, esc, money, pad, dmy, api, flash } = App;
  const user = await App.init();
  $('#accountMenu').hidden = !user;
  App.setupProfile(user);
  $('#accountBtn').addEventListener('click', () => {
    const opening = $('#accountDropdown').hidden;
    $('#accountDropdown').hidden = !opening;
    $('#accountBtn').setAttribute('aria-expanded', String(opening));
  });
  document.addEventListener('click', e => {
    if (!$('#accountMenu').contains(e.target)) {
      $('#accountDropdown').hidden = true;
      $('#accountBtn').setAttribute('aria-expanded', 'false');
    }
  });
  let filled = false;
  let amenityChoicesFilled = false;
  const notifiedMatchAlerts = new Set();
  const ymd = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

  async function load() {
    const d = await api('owner/overview'), p = d.profile;
    const matchAlerts = d.match_alerts || [];
    $('#matchAlertSection').hidden = matchAlerts.length === 0;
    $('#matchAlerts').innerHTML = matchAlerts.map(alert => {
      const id = Number(alert.id);
      const isNew = !notifiedMatchAlerts.has(id);
      notifiedMatchAlerts.add(id);
      if (isNew && 'Notification' in window && Notification.permission === 'granted') {
        new Notification('Kèo sắp bắt đầu chưa có đối thủ', {
          body: `${alert.team_name} · ${alert.venue_name} · còn khoảng ${alert.minutes_remaining} phút`,
          tag: `match-alert-${id}`
        });
      }
      return `<article class="match-alert-card">
        <div><b>${esc(alert.team_name)}</b>${isNew ? ' <span class="tag pending">Mới</span>' : ''}
          <p>${esc(alert.venue_name)} · ${esc(alert.pitch)} (${esc(alert.pitch_type)})</p>
          <p>${dmy(alert.date)} · ${pad(alert.start_hour)}–${pad(alert.end_hour)} · Còn khoảng ${+alert.minutes_remaining} phút</p>
          <p>${esc(alert.address || 'Chưa cập nhật địa chỉ')}</p>
          ${alert.description ? `<p>${esc(alert.description)}</p>` : ''}
        </div>
        <div class="match-alert-actions">
          <a class="btn sm" href="tel:${encodeURIComponent(alert.contact_phone)}">Gọi đội ${esc(alert.contact_phone)}</a>
          <a class="btn ghost sm" href="dat-san?show=matches">Mở danh sách kèo</a>
        </div>
      </article>`;
    }).join('');
    $('#paymentAutomationNotice').hidden = false;
    $('#paymentAutomationNotice').textContent = d.sepay_secret_configured
      ? 'Đã cấu hình nhận thông báo SePay. Phí hủy sẽ được xác nhận tự động khi khách chuyển khoản đúng số tiền và nội dung.'
      : 'Chưa cấu hình HMAC secret SePay. Khách vẫn đặt sân bình thường; khi phát sinh phí hủy, hãy đối chiếu giao dịch ngân hàng và xử lý thủ công.';
    if (!filled) {
      filled = true;
      $('#bankSel').innerHTML += Object.entries(d.banks).map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('');
      const f = $('#bankForm');
      ['venue_name', 'address', 'bank_bin', 'account_no', 'account_name'].forEach(n => { f.elements[n].value = p[n] || ''; });
    }
    $('#venueGallery').innerHTML = d.venue_images.map(image => `<figure><img src="${esc(image.url)}" alt="Ảnh cụm sân" loading="lazy">
      <button class="btn ghost sm" type="button" data-act="venue-image-delete" data-id="${+image.id}">Xóa ảnh</button></figure>`).join('')
      || '<p class="empty">Chưa có ảnh cụm sân.</p>';
    if (!amenityChoicesFilled) {
      $('#amenityChoices').innerHTML = Object.entries(d.amenity_options).map(([key, label]) =>
        `<label class="amenity-option"><input type="checkbox" name="amenities" value="${esc(key)}" ${d.amenities.includes(key) ? 'checked' : ''}>${esc(label)}</label>`).join('');
      amenityChoicesFilled = true;
    }
    $('#noBank').hidden = !!p.account_no;
    $('#pitchRows').innerHTML = d.pitches.map(x => `<tr><td>${x.image_url ? `<img class="pitch-thumb" src="${esc(x.image_url)}" alt="Ảnh ${esc(x.name)}">` : '<span class="hint">Chưa có ảnh</span>'}
      <label class="upload-btn">Tải ảnh<input type="file" accept="image/jpeg,image/png,image/webp" data-image-id="${+x.id}"></label></td>
      <td>${esc(x.name)}</td><td>${esc(x.type)}</td><td>${money(x.price)}</td><td>${money(x.peak_price)}</td>
      <td><span class="tag ${+x.active ? 'paid' : 'cancelled'}">${+x.active ? 'Đang mở' : 'Tạm ngưng'}</span></td>
      <td><button class="btn ghost sm" data-act="pitch-toggle" data-id="${+x.id}">${+x.active ? 'Tạm ngưng' : 'Mở lại'}</button></td></tr>`).join('');
    const schedulePitch = $('#schedulePitch');
    const selectedPitch = schedulePitch.value;
    schedulePitch.innerHTML = d.pitches.map(x => `<option value="${+x.id}">${esc(x.name)}</option>`).join('');
    schedulePitch.disabled = !d.pitches.length;
    if (d.pitches.some(x => String(x.id) === selectedPitch)) schedulePitch.value = selectedPitch;
    await loadSchedule();
    $('#bookingRows').innerHTML = d.bookings.map(b => {
      const act = [];
      if (b.status === 'pending' && +b.live) act.push(`<button class="btn ghost sm" data-act="confirm-paid" data-id="${+b.id}" title="Chỉ dùng để đối soát các đơn cũ">Đối soát đơn cũ</button>`);
      if (b.status === 'cancel_fee_pending') act.push(`<button class="btn ghost sm" data-act="confirm-cancel-fee" data-id="${+b.id}">Xác nhận đã nhận phí hủy</button>`);
      if (b.refund_status === 'pending') act.push(`<button class="btn ghost sm" data-act="refund-done" data-id="${+b.id}">Xác nhận đã hoàn</button>`);
      if (['confirmed', 'pending', 'paid', 'cancel_fee_pending'].includes(b.status) && +b.owner_seconds_to_start > 0) {
        act.push(`<button class="btn ghost sm" data-act="cancel-booking" data-id="${+b.id}">Hủy lịch</button>`);
      }
      const rf = b.refund_status === 'pending' ? `<br><small>Cần hoàn ${money(b.refund_amount)}</small>` : b.refund_status === 'done' ? `<br><small>Đã hoàn ${money(b.refund_amount)}</small>` : '';
      return `<tr><td>${esc(b.code)}</td><td>${esc(b.cname)}<br><small>${esc(b.cphone)}</small></td><td>${esc(b.pitch)}</td>
        <td>${dmy(b.date)}<br><small>${pad(b.start_hour)}–${pad(b.end_hour)}</small></td>
        <td>${money(b.total)}${b.status === 'cancel_fee_pending' ? `<br><small>Phí hủy: ${money(b.cancellation_fee)}</small>` : ''}</td>
        <td><span class="tag ${esc(b.status)}">${esc(b.status_text)}</span>${rf}</td><td>${act.join(' ')}</td></tr>`;
    }).join('') || '<tr><td colspan="7" class="empty">Chưa có đơn nào.</td></tr>';
  }

  async function loadSchedule() {
    const box = $('#scheduleSlots'), pitchId = $('#schedulePitch').value, date = $('#scheduleDate').value;
    if (!pitchId || !date) { box.innerHTML = '<p class="empty">Thêm sân để quản lý khung giờ.</p>'; return; }
    try {
      const { slots } = await api(`owner/pitch-slots?pitch_id=${encodeURIComponent(pitchId)}&date=${encodeURIComponent(date)}`);
      box.innerHTML = slots.map(s => {
        const label = `${pad(s.hour)}–${pad(s.hour + 1)}`;
        if (s.status === 'free') return `<button type="button" class="schedule-slot free" data-slot-action="close" data-hour="${s.hour}">${label}<small>Gỡ khung giờ</small></button>`;
        if (s.status === 'unavailable') return `<button type="button" class="schedule-slot unavailable" data-slot-action="open" data-hour="${s.hour}">${label}<small>Mở lại</small></button>`;
        return `<button type="button" class="schedule-slot ${s.status}" disabled>${label}<small>${s.status === 'booked' ? 'Đã đặt' : 'Đã qua'}</small></button>`;
      }).join('');
    } catch (e) { box.innerHTML = `<p class="error">${esc(e.message)}</p>`; }
  }

  const send = async (path, body, okMsg) => {
    try { await api(path, { method: 'POST', body }); if (okMsg) flash(okMsg); await load(); }
    catch (e) { flash(e.message, 'err'); }
  };
  const data = f => Object.fromEntries(new FormData(f));
  $('#bankForm').onsubmit = e => { e.preventDefault(); send('owner/bank', data(e.target), 'Đã lưu thông tin nhận tiền. Khách sẽ chuyển khoản vào tài khoản này.'); };
  $('#amenityForm').onsubmit = async e => {
    e.preventDefault();
    const amenities = [...e.target.querySelectorAll('input[name="amenities"]:checked')].map(input => input.value);
    try {
      await api('owner/venue-details', { method: 'POST', body: { amenities } });
      flash('Đã lưu tiện ích cụm sân.');
      amenityChoicesFilled = false;
      await load();
    } catch (error) { flash(error.message, 'err'); }
  };
  $('#pitchForm').onsubmit = async e => { e.preventDefault(); await send('owner/pitch', data(e.target), 'Đã thêm sân.'); e.target.reset(); };
  $('#scheduleDate').min = ymd(new Date());
  const maxDate = new Date(); maxDate.setDate(maxDate.getDate() + 13);
  $('#scheduleDate').max = ymd(maxDate); $('#scheduleDate').value = ymd(new Date());
  $('#scheduleDate').onchange = loadSchedule;
  $('#schedulePitch').onchange = loadSchedule;
  $('#scheduleSlots').addEventListener('click', async e => {
    const button = e.target.closest('button[data-slot-action]'); if (!button) return;
    button.disabled = true;
    const closing = button.dataset.slotAction === 'close';
    try {
      await api(`owner/${closing ? 'unavailable-slot' : 'available-slot'}`, {
        method: 'POST', body: { pitch_id: Number($('#schedulePitch').value), date: $('#scheduleDate').value, hour: Number(button.dataset.hour) }
      });
      flash(closing ? 'Đã gỡ khung giờ khỏi lịch đặt.' : 'Đã mở lại khung giờ.');
      await loadSchedule();
    } catch (e) { flash(e.message, 'err'); button.disabled = false; }
  });
  $('#pitchRows').addEventListener('change', async e => {
    const input = e.target.closest('input[type="file"][data-image-id]');
    if (!input || !input.files.length) return;
    const form = new FormData(); form.append('pitch_id', input.dataset.imageId); form.append('image', input.files[0]);
    input.disabled = true;
    try {
      await api('owner/pitch-image', { method: 'POST', body: form });
      flash('Đã cập nhật ảnh sân.'); await load();
    } catch (error) { flash(error.message, 'err'); input.value = ''; input.disabled = false; }
  });
  $('#venueImageFile').addEventListener('change', async e => {
    const input = e.target;
    if (!input.files.length) return;
    const form = new FormData(); form.append('image', input.files[0]);
    input.disabled = true;
    try {
      await api('owner/venue-image', { method: 'POST', body: form });
      flash('Đã tải ảnh cụm sân.'); await load();
    } catch (error) { flash(error.message, 'err'); }
    finally { input.value = ''; input.disabled = false; }
  });
  document.addEventListener('click', e => {
    const b = e.target.closest('button[data-act]'); if (!b) return;
    const id = +b.dataset.id;
    if (b.dataset.act === 'pitch-toggle') send('owner/pitch-toggle', { id }, 'Đã cập nhật trạng thái sân.');
    if (b.dataset.act === 'confirm-paid') send('owner/confirm-paid', { id }, 'Đã xác nhận nhận tiền.');
    if (b.dataset.act === 'confirm-cancel-fee' && confirm('Chỉ xác nhận nếu đã kiểm tra và nhận đủ phí hủy trong tài khoản ngân hàng.')) {
      send('owner/confirm-cancel-fee', { id }, 'Đã xác nhận phí hủy; khung giờ đã được mở lại.');
    }
    if (b.dataset.act === 'refund-done') send('owner/refund-done', { id }, 'Đã đánh dấu hoàn tiền.');
    if (b.dataset.act === 'cancel-booking' && confirm('Hủy lịch này? Khách hàng không phải chịu phí hủy. Nếu đơn cũ đã thanh toán, bạn cần hoàn đủ tiền cho khách.')) {
      send('owner/cancel-booking', { id }, 'Đã hủy lịch; khung giờ đã được mở lại.');
    }
    if (b.dataset.act === 'venue-image-delete') send('owner/venue-image-delete', { id }, 'Đã xóa ảnh cụm sân.');
  });
  load().catch(e => flash(e.message, 'err'));
  setInterval(() => load().catch(e => flash(e.message, 'err')), 10000);
})();
