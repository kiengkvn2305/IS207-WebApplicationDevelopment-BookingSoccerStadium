/* Trang đặt sân (khách).
   Bố cục file: 1) Hằng số & trạng thái  2) Chọn ngày  3) Cụm sân & khung giờ
                4) Thanh chọn  5) Đặt sân  6) Thanh toán QR  7) Lịch của tôi  8) Hủy 2 bước */
(async () => {
  const { $, esc, money, pad, dmy, api, flash } = App;
  const user = await App.init();
  $('#accountMenu').hidden = !user;
  $('#loginNavBtn').hidden = !!user;
  $('#historyBtn').hidden = !user || user.role !== 'customer';
  $('#openBook').textContent = user ? 'Đặt sân' : 'Đăng nhập để đặt';
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
  $('#historyBtn').onclick = () => {
    $('#accountDropdown').hidden = true;
    $('#accountBtn').setAttribute('aria-expanded', 'false');
    $('#matchSection').hidden = true;
    $('#bookingCatalog').hidden = true;
    $('#bookingHistory').hidden = false;
    clearSelection();
    loadMine();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  ['matchSearch', 'matchTypeFilter', 'matchDateFilter'].forEach(id => {
    const input = $('#' + id);
    input.addEventListener(id === 'matchSearch' ? 'input' : 'change', renderPublicMatches);
  });
  $('#clearMatchFilters').onclick = () => {
    $('#matchSearch').value = '';
    $('#matchTypeFilter').value = '';
    $('#matchDateFilter').value = '';
    renderPublicMatches();
  };
  function openMatches() {
    $('#bookingCatalog').hidden = true;
    $('#bookingHistory').hidden = true;
    $('#matchSection').hidden = false;
    $('#matchSection').scrollIntoView({ behavior: 'smooth' });
  }
  $('#matchesNavBtn').onclick = openMatches;
  $('#backFromMatches').onclick = () => {
    $('#matchSection').hidden = true;
    $('#bookingCatalog').hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  $('#backToBooking').onclick = () => {
    $('#bookingHistory').hidden = true;
    $('#bookingCatalog').hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  /* ========== 1) Hằng số & trạng thái ========== */
  const DOW = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
  const PERIODS = [                                   // nhóm khung giờ theo buổi
    { label: 'Sáng',               from: 6,  to: 12 },
    { label: 'Chiều',              from: 12, to: 17 },
    { label: 'Tối · giờ cao điểm', from: 17, to: 22 },
  ];
  const state = {
    date: null,
    venues: [],            // [{id, name, address, owner_name, owner_phone, pitches:[...]}]
    venueId: null,         // Chọn cụm sân trước khi hiển thị danh sách giờ
    pitchId: null,         // sân đang chọn giờ
    hours: new Set(),      // các giờ đang chọn
  };
  let myMatches = [];
  let matchCache = [];
  const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const make = (tag, cls = '', html = '') => { const e = document.createElement(tag); e.className = cls; e.innerHTML = html; return e; };
  const allPitches = () => state.venues.flatMap(v => v.pitches);

  /* ========== 2) Lịch sân ========== */
  function renderDays() {
    for (let i = 0; i < 14; i++) {
      const d = new Date(); d.setDate(d.getDate() + i);
      const b = make('button', 'day', `<span>${i === 0 ? 'Hôm nay' : DOW[d.getDay()]}</span><b>${d.getDate()}</b><span>Th${d.getMonth() + 1}</span>`);
      b.type = 'button'; b.dataset.date = ymd(d);
      b.onclick = () => selectDate(b.dataset.date);
      $('#days').appendChild(b);
    }
  }
  async function selectDate(date) {
    state.date = date; clearSelection();
    document.querySelectorAll('.day').forEach(b => b.setAttribute('aria-pressed', b.dataset.date === date));
    await loadSlots();
  }

  /* ========== 3) Cụm sân & khung giờ ========== */
  async function loadSlots() {
    try {
      state.venues = (await api('slots?date=' + state.date)).venues;
      if (!state.venues.some(v => v.id === state.venueId)) state.venueId = null;
      renderVenues();
    } catch (e) { $('#pitches').innerHTML = `<p class="error">${esc(e.message)}</p>`; }
  }

  async function loadTopRated() {
    const box = $('#topRatedVenues');
    try {
      const { venues } = await api('top-rated');
      box.innerHTML = venues.length ? venues.map(v => `<article class="top-rated-card">
        ${v.images.length ? `<img src="${esc(v.images[0].url)}" alt="Ảnh ${esc(v.name)}" loading="lazy">` : ''}
        <div><h3>${esc(v.name)}</h3><p class="top-rated-score">${'★'.repeat(Math.round(v.rating_average))}${'☆'.repeat(5 - Math.round(v.rating_average))}
          <b>${esc(v.rating_average)} / 5</b> · ${v.rating_count} lượt</p>
          <p>${esc(v.address || 'Chưa cập nhật địa chỉ')}</p><small>${esc(v.pitch_types || '')}</small>
          ${v.amenities.length ? `<p class="hint">${v.amenities.map(esc).join(' · ')}</p>` : ''}
          <button type="button" class="btn ghost sm" data-top-venue="${+v.id}">Xem cụm sân</button></div></article>`).join('')
        : '<p class="empty">Chưa có cụm sân được đánh giá. Hãy là người đầu tiên để lại đánh giá!</p>';
    } catch (error) {
      box.innerHTML = `<p class="error">${esc(error.message)}</p>`;
    }
  }

  function renderVenues() {
    const box = $('#pitches'); box.innerHTML = '';
    $('#h-slot').textContent = state.venueId === null ? 'Chọn cụm sân' : 'Chọn sân và giờ đá';
    $('.legend').hidden = state.venueId === null;
    const pitchQuery = $('#pitchSearch').value.trim().toLocaleLowerCase('vi');
    const areaQuery = $('#areaSearch').value.trim().toLocaleLowerCase('vi');
    const typeFilter = $('#typeFilter').value;
    const hourFilter = $('#hourFilter').value;
    const venues = state.venues.map(v => ({
      ...v,
      pitches: v.pitches.filter(p => {
        const name = `${v.name} ${p.name}`.toLocaleLowerCase('vi');
        const area = (v.address || '').toLocaleLowerCase('vi');
        const available = p.slots.some(s => s.status === 'free' && (hourFilter === '' || s.hour === Number(hourFilter)));
        return name.includes(pitchQuery) && area.includes(areaQuery) && (!typeFilter || p.type === typeFilter) && available;
      })
    })).filter(v => v.pitches.length);
    const availableCount = venues.length;
    $('#availabilitySummary').textContent = hourFilter === ''
      ? `${dmy(state.date)} · ${availableCount} cụm sân có ít nhất một giờ còn trống. Chọn giờ cụ thể để lọc nhanh.`
      : `${dmy(state.date)} · ${availableCount} cụm sân còn giờ ${pad(Number(hourFilter))}–${pad(Number(hourFilter) + 1)}.`;
    if (state.venueId === null) {
      if (!venues.length) { box.innerHTML = '<p class="empty">Không tìm thấy cụm sân phù hợp với bộ lọc.</p>'; return; }
      venues.forEach(v => {
        const card = make('article', 'venue-card');
        const open = make('button', 'venue-card-open',
          `<span class="venue-card-title">${esc(v.name)}</span><span class="venue-card-address">${v.address ? `📍 ${esc(v.address)}` : 'Chưa cập nhật địa chỉ'}</span>` +
          `<span class="venue-card-rating">${v.rating_count ? `★ ${esc(v.rating_average)} · ${v.rating_count} đánh giá` : 'Chưa có đánh giá'}</span>` +
          `<span class="venue-card-action">Xem sân và giờ còn trống →</span>`);
        open.type = 'button';
        open.onclick = () => {
          state.venueId = v.id; clearSelection();
          renderVenues();
        };
        const contact = make('a', 'venue-contact', `Liên hệ ${esc(v.owner_name)} · ${esc(v.owner_phone)}`);
        contact.href = `tel:${encodeURIComponent(v.owner_phone)}`;
        card.append(open, contact); box.appendChild(card);
      });
      return;
    }

    const back = make('button', 'btn ghost back-to-venues', '← Danh sách cụm sân');
    back.type = 'button';
    back.onclick = () => {
      state.venueId = null; clearSelection(); renderVenues();
    };
    box.appendChild(back);
    const venue = venues.find(v => v.id === state.venueId) || state.venues.find(v => v.id === state.venueId);
    if (!venue) { box.appendChild(make('p', 'empty', 'Cụm sân không còn sân phù hợp với bộ lọc.')); return; }

    const phone = esc(venue.owner_phone);
    box.appendChild(make('div', 'venue-details',
      `<h3>${esc(venue.name)}</h3><p><b>Chủ sân:</b> ${esc(venue.owner_name)}</p>` +
      `<p><b>Địa chỉ:</b> ${esc(venue.address || 'Chưa cập nhật')}</p>` +
      `<p><b>Điện thoại:</b> <a href="tel:${encodeURIComponent(venue.owner_phone)}">${phone}</a></p>` +
      `<p><b>Đánh giá:</b> ${venue.rating_count ? `★ ${esc(venue.rating_average)} / 5 · ${venue.rating_count} lượt` : 'Chưa có đánh giá'}</p>` +
      (venue.amenities.length ? `<p><b>Tiện ích:</b> ${venue.amenities.map(esc).join(' · ')}</p>` : '<p><b>Tiện ích:</b> Chưa cập nhật</p>') +
      (venue.images.length ? `<div class="venue-photo-gallery" aria-label="Ảnh cụm sân">${venue.images.map((image, index) =>
        `<img src="${esc(image.url)}" alt="Góc nhìn ${index + 1} của ${esc(venue.name)}" loading="lazy">`).join('')}</div>` : '')));
    const reviews = make('section', 'venue-reviews');
    reviews.dataset.ownerId = String(venue.id);
    reviews.innerHTML = '<h3 class="subheading">Đánh giá và bình luận</h3><p class="hint">Đang tải đánh giá...</p>';
    box.appendChild(reviews);
    loadVenueReviews(reviews, venue.id);
    const section = make('section', 'venue');
    venue.pitches.forEach(p => section.appendChild(renderPitch(p)));
    box.appendChild(section);
  }

  async function loadVenueReviews(container, ownerId) {
    try {
      const data = await api(`reviews?owner_id=${encodeURIComponent(ownerId)}`);
      if (!container.isConnected || Number(container.dataset.ownerId) !== ownerId) return;
      const rows = data.reviews.length
        ? data.reviews.map(review => `<article class="review-card"><header><b>${esc(review.name)}</b><span>${'★'.repeat(Number(review.rating))}${'☆'.repeat(5 - Number(review.rating))}</span></header>
          <p>${esc(review.comment)}</p><small>${esc(String(review.created_at).slice(0, 10))}</small></article>`).join('')
        : '<p class="empty">Chưa có bình luận nào cho cụm sân này.</p>';
      let form = '';
      if (user && user.role === 'customer') {
        const mine = data.my_review;
        form = `<form class="review-form" data-review-owner="${ownerId}">
          <h4>${mine ? 'Cập nhật đánh giá của bạn' : 'Chia sẻ trải nghiệm'}</h4>
          <label>Đánh giá<select name="rating" required>${[5, 4, 3, 2, 1].map(n => `<option value="${n}" ${Number(mine?.rating || 5) === n ? 'selected' : ''}>${'★'.repeat(n)}${'☆'.repeat(5 - n)} · ${n} sao</option>`).join('')}</select></label>
          <label>Bình luận<textarea name="comment" rows="3" maxlength="1000" required placeholder="Chia sẻ trải nghiệm của bạn...">${esc(mine?.comment || '')}</textarea></label>
          <button class="btn sm" type="submit">${mine ? 'Cập nhật đánh giá' : 'Gửi đánh giá'}</button>
        </form>`;
      } else if (!user) {
        form = '<p class="hint"><a href="login">Đăng nhập</a> để chấm sao và bình luận. Không cần đặt sân trước.</p>';
      }
      container.innerHTML = `<h3 class="subheading">Đánh giá và bình luận ${data.count ? `· ★ ${esc(data.average)} (${data.count})` : ''}</h3>${form}<div class="review-list">${rows}</div>`;
    } catch (error) {
      if (container.isConnected) container.innerHTML = `<h3 class="subheading">Đánh giá và bình luận</h3><p class="error">${esc(error.message)}</p>`;
    }
  }

  function openCreateMatch(booking) {
    $('#matchBookingId').value = String(booking.id);
    $('#matchBookingInfo').textContent = `${booking.venue} · ${booking.pitch} · ${dmy(booking.date)} · ${pad(booking.from)}–${pad(booking.to)}`;
    $('#matchTeamName').value = user.name || '';
    $('#matchPhone').value = user.phone || '';
    $('#matchPlayersNeeded').value = '1';
    $('#matchDescription').value = '';
    $('#createMatchError').textContent = '';
    $('#createMatchDlg').showModal();
  }

  function openRequestMatch(match) {
    $('#requestChallengeId').value = String(match.id);
    $('#requestMatchInfo').textContent = `${match.team_name} đang tìm đối thủ · ${match.venue_name} · ${match.pitch} · ${dmy(match.date)} ${pad(match.start_hour)}–${pad(match.end_hour)}`;
    $('#requestTeamName').value = user?.name || '';
    $('#requestPhone').value = user?.phone || '';
    $('#requestMessage').value = '';
    $('#requestMatchError').textContent = '';
    $('#requestMatchDlg').showModal();
  }

  function openGuestPlayer(match) {
    $('#guestPlayerChallengeId').value = String(match.id);
    $('#guestPlayerInfo').textContent = `${match.team_name} · ${match.venue_name} · ${match.pitch} · ${dmy(match.date)} ${pad(match.start_hour)}–${pad(match.end_hour)} · Còn ${match.players_needed_remaining} chỗ`;
    $('#guestPlayerName').value = user?.name || '';
    $('#guestPlayerPhone').value = user?.phone || '';
    $('#guestPlayerMessage').value = '';
    $('#guestPlayerError').textContent = '';
    $('#guestPlayerDlg').showModal();
  }

  const matchStatuses = {
    open: 'Đang tìm đối thủ', matched: 'Đã ghép đội', cancelled: 'Đã đóng',
    pending: 'Đang chờ phản hồi', accepted: 'Đã được chấp nhận', declined: 'Không được chọn'
  };
  function visibleMatches() {
    const query = $('#matchSearch').value.trim().toLocaleLowerCase('vi');
    const pitchType = $('#matchTypeFilter').value;
    const date = $('#matchDateFilter').value;
    return matchCache.filter(match => {
      const searchable = `${match.team_name} ${match.venue_name} ${match.pitch} ${match.address || ''}`.toLocaleLowerCase('vi');
      return (!query || searchable.includes(query))
        && (!pitchType || match.pitch_type === pitchType)
        && (!date || match.date === date);
    });
  }
  function renderPublicMatches() {
    const matches = visibleMatches();
    $('#matches').innerHTML = matches.length ? matches.map(match => {
      const action = match.status !== 'open'
        ? ''
        : match.request_status
        ? `<span class="tag ${match.request_status === 'accepted' ? 'paid' : 'pending'}">${esc(matchStatuses[match.request_status] || match.request_status)}</span>`
        : user?.role === 'customer'
          ? `<button type="button" class="btn sm" data-request-match="${+match.id}">Gửi lời thách đấu</button>`
          : '<a class="btn ghost sm" href="login">Đăng nhập để tham gia</a>';
      const canJoinAsPlayer = user?.role === 'customer' && Number(match.join_open)
        && Number(match.players_needed_remaining) > 0;
      const playerAction = match.player_request_status
        ? `<span class="tag ${match.player_request_status === 'accepted' ? 'paid' : match.player_request_status === 'pending' ? 'pending' : 'cancelled'}">${match.player_request_status === 'accepted' ? 'Bạn đã được nhận vào đội' : match.player_request_status === 'pending' ? 'Đang chờ duyệt đá vãng lai' : 'Yêu cầu trước đã bị từ chối'}</span>`
        : canJoinAsPlayer
          ? `<button type="button" class="btn ghost sm" data-join-player="${+match.id}">Xin đá vãng lai</button>`
          : !user && Number(match.join_open) && +match.players_needed_remaining > 0
            ? '<a class="btn ghost sm" href="login">Đăng nhập để xin đá vãng lai</a>'
            : '';
      return `<article class="match-card"><div class="match-card-head"><div><p class="eyebrow">${match.status === 'matched' ? 'ĐỘI ĐANG NHẬN CẦU THỦ VÃNG LAI' : 'ĐỘI ĐANG TÌM ĐỐI THỦ'}</p><h3>${esc(match.team_name)}</h3></div><span class="tag pending">${+match.request_count} lời mời đội</span></div>
        <p><b>${esc(match.venue_name)} · ${esc(match.pitch)} (${esc(match.pitch_type)})</b></p>
        <p>${dmy(match.date)} · ${pad(match.start_hour)}–${pad(match.end_hour)}</p>
        <p>Đá vãng lai: ${+match.accepted_players}/${+match.players_needed} người đã được nhận${Number(match.join_open) && +match.players_needed_remaining > 0 ? ` · còn ${+match.players_needed_remaining} chỗ` : ' · hiện không nhận thêm người'}</p>
        <p>${esc(match.address || 'Chưa cập nhật địa chỉ')}</p>
        ${match.description ? `<p>${esc(match.description)}</p>` : ''}
        <p class="match-contact">Liên hệ đội ${esc(match.team_name)}: <a href="tel:${encodeURIComponent(match.contact_phone)}">${esc(match.contact_phone)}</a></p>
        <div class="match-actions">${action}${playerAction}</div></article>`;
    }).join('') : '<p class="empty">Không tìm thấy kèo phù hợp. Hãy thử đổi bộ lọc.</p>';
  }
  function renderMatches(data) {
    matchCache = data.matches || [];
    renderPublicMatches();

    myMatches = data.my_matches || [];
    const requestsByChallenge = new Map();
    (data.applications || []).forEach(request => {
      const list = requestsByChallenge.get(Number(request.challenge_id)) || [];
      list.push(request);
      requestsByChallenge.set(Number(request.challenge_id), list);
    });
    const playerRequestsByChallenge = new Map();
    (data.player_requests || []).forEach(request => {
      const list = playerRequestsByChallenge.get(Number(request.challenge_id)) || [];
      list.push(request);
      playerRequestsByChallenge.set(Number(request.challenge_id), list);
    });
    const ownCards = myMatches.map(match => `<article class="match-card my-match-card">
      <div class="match-card-head"><div><h3>${esc(match.team_name)}</h3><p>${esc(match.venue_name)} · ${esc(match.pitch)} · ${dmy(match.date)} ${pad(match.start_hour)}–${pad(match.end_hour)}</p></div>
        <span class="tag ${match.status === 'matched' ? 'paid' : match.status === 'open' ? 'pending' : 'cancelled'}">${esc(matchStatuses[match.status] || match.status)}</span></div>
      <p>Đá vãng lai: ${+(match.accepted_players || 0)}/${+match.players_needed} người đã được nhận.</p>
      ${match.status !== 'cancelled' ? `<div class="match-player-controls">
        <label>Số chỗ cần thêm<input type="number" min="${Math.max(1, +match.accepted_players)}" max="30" value="${+match.players_needed}" data-player-capacity="${+match.id}"></label>
        <button class="btn ghost sm" type="button" data-save-player-capacity="${+match.id}">Cập nhật số chỗ</button>
        ${+match.join_open
          ? `<button class="btn ghost sm" type="button" data-manage-players="close" data-id="${+match.id}">Ngừng nhận người</button>`
          : +match.accepted_players < +match.players_needed
            ? `<button class="btn ghost sm" type="button" data-manage-players="open" data-id="${+match.id}">Mở nhận người</button>`
            : '<span class="hint">Đã đủ số người cần tìm</span>'}
      </div>` : ''}
      ${(playerRequestsByChallenge.get(Number(match.id)) || []).length
        ? `<h4 class="subheading">Yêu cầu đá vãng lai</h4>${(playerRequestsByChallenge.get(Number(match.id)) || []).map(request => `<div class="match-request-row">
          <b>${esc(request.team_name)}</b> · <a href="tel:${encodeURIComponent(request.contact_phone)}">${esc(request.contact_phone)}</a>
          ${request.message ? `<p>${esc(request.message)}</p>` : ''}
          <span class="tag ${request.status === 'accepted' ? 'paid' : request.status === 'pending' ? 'pending' : 'cancelled'}">${request.status === 'accepted' ? 'Đã nhận' : request.status === 'pending' ? 'Chờ duyệt' : 'Đã từ chối'}</span>
          ${request.status === 'pending' ? `<button class="btn sm" data-player-decision="accept" data-id="${+request.request_id}">Duyệt</button><button class="btn ghost sm" data-player-decision="decline" data-id="${+request.request_id}">Từ chối</button>` : ''}
          ${request.status === 'accepted' ? `<button class="btn ghost sm" data-player-decision="remove" data-id="${+request.request_id}">Xóa khỏi đội</button>` : ''}
        </div>`).join('')}` : '<p class="hint">Chưa có yêu cầu đá vãng lai.</p>'}
      ${(requestsByChallenge.get(Number(match.id)) || []).map(request => `<div class="match-request-row"><b>${esc(request.team_name)}</b> · <a href="tel:${encodeURIComponent(request.contact_phone)}">${esc(request.contact_phone)}</a>
        ${request.message ? `<p>${esc(request.message)}</p>` : ''}
        <span class="tag ${request.status === 'accepted' ? 'paid' : request.status === 'pending' ? 'pending' : 'cancelled'}">${esc(matchStatuses[request.status] || request.status)}</span>
        ${match.status === 'open' && request.status === 'pending' ? `<button class="btn sm" data-match-decision="accept" data-id="${+request.request_id}">Chấp nhận</button><button class="btn ghost sm" data-match-decision="decline" data-id="${+request.request_id}">Từ chối</button>` : ''}</div>`).join('')}
      ${match.status === 'open' ? `<button class="btn ghost sm" data-cancel-match="${+match.id}">Đóng kèo</button>` : ''}
    </article>`).join('');
    const applications = (data.my_applications || []).map(application => `<article class="match-request-row">
      Bạn đã gửi lời thách đấu cho <b>${esc(application.opponent_team)}</b> · ${esc(application.venue_name)} · ${dmy(application.date)} ${pad(application.start_hour)}–${pad(application.end_hour)}
      <span class="tag ${application.request_status === 'accepted' ? 'paid' : application.request_status === 'pending' ? 'pending' : 'cancelled'}">${esc(matchStatuses[application.request_status] || application.request_status)}</span>
      ${application.request_status === 'accepted' ? `<p>Liên hệ đội đối thủ: <a href="tel:${encodeURIComponent(application.opponent_phone)}">${esc(application.opponent_phone)}</a></p>` : ''}
    </article>`).join('');
    const playerApplications = (data.my_player_requests || []).map(application => `<article class="match-request-row">
      Xin đá vãng lai cùng <b>${esc(application.host_team)}</b> · ${esc(application.venue_name)} · ${dmy(application.date)} ${pad(application.start_hour)}–${pad(application.end_hour)}
      <span class="tag ${application.request_status === 'accepted' ? 'paid' : application.request_status === 'pending' ? 'pending' : 'cancelled'}">${application.request_status === 'accepted' ? 'Đã được nhận' : application.request_status === 'pending' ? 'Đang chờ duyệt' : 'Đã từ chối'}</span>
      ${application.request_status === 'accepted' ? `<p>Liên hệ đội trưởng: <a href="tel:${encodeURIComponent(application.host_phone)}">${esc(application.host_phone)}</a></p>` : ''}
    </article>`).join('');
    $('#myMatches').innerHTML = (ownCards || applications || playerApplications)
      ? `<h3 class="subheading">Kèo và lời mời của tôi</h3>${ownCards}${applications}${playerApplications}`
      : (user?.role === 'customer' ? '<h3 class="subheading">Kèo của tôi</h3><p class="hint">Kèo bạn đăng và lời thách đấu sẽ hiển thị tại đây.</p>' : '');
  }

  async function loadMatches() {
    const box = $('#matches');
    try {
      renderMatches(await api('matches'));
      if (myBookings.length) renderBookingList();
    }
    catch (error) { box.innerHTML = `<p class="error">${esc(error.message)}</p>`; }
  }

  function renderPitch(p) {
    const freeCount = p.slots.filter(s => s.status === 'free').length;
    const card = make('article', 'pitch', `${p.image_url ? `<img class="pitch-image" src="${esc(p.image_url)}" alt="Ảnh ${esc(p.name)}" loading="lazy">` : ''}
      <div class="pitch-head"><div><h3>${esc(p.name)}</h3><span>${esc(p.type)}</span></div><b class="availability">${freeCount} giờ còn trống</b></div>`);
    let shown = 0;
    PERIODS.forEach(per => {
      const slots = p.slots.filter(s => s.hour >= per.from && s.hour < per.to);
      if (!slots.length || slots.every(s => s.status === 'past')) return;   // ẩn buổi đã qua
      const grid = make('div', 'slots');
      slots.forEach(s => grid.appendChild(slotButton(p, s)));
      const row = make('div', 'period', `<div class="period-label">${per.label}</div>`);
      row.appendChild(grid); card.appendChild(row); shown++;
    });
    if (!shown) card.appendChild(make('p', 'empty', 'Đã hết giờ đặt trong hôm nay.'));
    return card;
  }

  function slotButton(p, s) {
    const b = make('button', 'slot ' + (s.status === 'free' ? '' : s.status),
      `<b>${pad(s.hour)}–${pad(s.hour + 1)}</b><small>${s.status === 'booked' ? 'Đã đặt' : s.status === 'unavailable' ? 'Sân đóng' : money(s.price)}</small>`);
    b.type = 'button'; b.disabled = s.status !== 'free';
    b.setAttribute('aria-pressed', state.pitchId === p.id && state.hours.has(s.hour));
    b.onclick = () => toggleSlot(p, s);
    return b;
  }

  /* ========== 4) Chọn giờ & thanh tổng tiền ========== */
  function clearSelection() { state.pitchId = null; state.hours.clear(); updateBar(); }
  function toggleSlot(p, s) {
    if (state.pitchId !== p.id) { state.pitchId = p.id; state.hours.clear(); }   // mỗi lần chỉ chọn trên 1 sân
    state.hours.has(s.hour) ? state.hours.delete(s.hour) : state.hours.add(s.hour);
    if (!state.hours.size) state.pitchId = null;
    renderVenues(); updateBar();
  }
  function currentSelection() {
    const p = allPitches().find(x => x.id === state.pitchId);
    if (!p) return null;
    const hours = [...state.hours].sort((a, b) => a - b);
    const total = hours.reduce((t, h) => t + p.slots.find(s => s.hour === h).price, 0);
    return { p, hours, total, contiguous: hours.every((h, i) => !i || h === hours[i - 1] + 1) };
  }
  function updateBar() {
    const sel = currentSelection(); $('#bar').hidden = !sel; if (!sel) return;
    $('#barText').textContent = `${sel.p.name} · ${sel.hours.map(pad).join(', ')}`;
    $('#barTotal').textContent = `${sel.hours.length} giờ · ${money(sel.total)}`;
  }

  /* ========== 5) Đặt sân ========== */
  $('#openBook').onclick = () => {
    const sel = currentSelection(); if (!sel) return;
    if (!user) { location.href = 'login'; return; }
    $('#summary').textContent = `${sel.p.name}, ${dmy(state.date)}, ${pad(sel.hours[0])}–${pad(sel.hours.at(-1) + 1)} – ${money(sel.total)}`;
    $('#bookError').textContent = sel.contiguous ? '' : 'Vui lòng chọn các giờ liền nhau.';
    $('#bookGo').disabled = !sel.contiguous; $('#bookDlg').showModal();
  };
  $('#bookBack').onclick = () => $('#bookDlg').close();
  $('#bookGo').onclick = async () => {
    const sel = currentSelection(); if (!sel) return;
    $('#bookGo').disabled = true; $('#bookError').textContent = '';
    try {
      const r = await api('book', { method: 'POST', body: { pitch_id: sel.p.id, date: state.date, hours: sel.hours } });
      $('#bookDlg').close(); clearSelection();
      flash(`Đặt sân thành công. Mã đặt sân: ${r.booking.code}`);
      loadSlots(); loadMine();
    } catch (e) {
      $('#bookError').textContent = e.message;
      if (/người khác/.test(e.message)) loadSlots();
    } finally { $('#bookGo').disabled = false; }
  };

  /* ========== 6) Thanh toán QR ========== */
  let payTimer, payPoll;
  const stopPay = () => { clearInterval(payTimer); clearInterval(payPoll); };

  function showPay(p, cancellationFee = false) {
    stopPay(); $('#payState').textContent = ''; $('#payBody').hidden = false; $('#qrImg').src = p.qr;
    $('#payTitle').textContent = cancellationFee ? 'Thanh toán phí hủy sân' : 'Quét mã để thanh toán';
    $('#payInfo').innerHTML = `Ngân hàng: <b>${esc(p.bank)}</b><br>Số tài khoản: <b>${esc(p.account_no)}</b><br>` +
      `Chủ tài khoản: <b>${esc(p.account_name)}</b><br>Số tiền: <b>${money(p.amount)}</b><br>Nội dung: <b>${esc(p.content)}</b>`;
    $('#autoPayWarning').hidden = !!p.sepay_secret_configured;
    const instruction = $('#payInstructionText').parentElement;
    instruction.hidden = cancellationFee;
    if (cancellationFee) {
      $('#autoPayWarning').textContent = p.sepay_secret_configured
        ? 'Chuyển đúng số tiền và nội dung để hệ thống tự xác nhận. Lịch chỉ được hủy và trả lại khung giờ sau khi nhận được thanh toán.'
        : 'Chuyển đúng số tiền và nội dung, sau đó liên hệ chủ sân để đối chiếu giao dịch. Lịch chỉ được hủy sau khi chủ sân xác nhận đã nhận phí.';
      $('#autoPayWarning').hidden = false;
    } else {
      const holdMinutes = Math.max(1, Math.ceil(p.seconds_left / 60));
      $('#autoPayWarning').textContent = `Tự động xác nhận chưa hoạt động vì máy chủ chưa cấu hình HMAC secret SePay. Sau khi chuyển khoản, hãy liên hệ chủ sân để họ đối chiếu giao dịch và xác nhận thủ công. Đơn được giữ tối đa ${holdMinutes} phút.`;
      $('#payInstructionText').textContent = p.sepay_secret_configured ? 'Giữ chỗ còn' : 'Liên hệ chủ sân để đối chiếu · chỗ giữ còn';
      let left = p.seconds_left;
      const tick = () => {
        $('#countdown').textContent = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
        if (left-- <= 0) endPay('Đã hết thời gian giữ chỗ. Vui lòng đặt lại.');
      };
      tick(); payTimer = setInterval(tick, 1000);
    }
    payPoll = setInterval(async () => {
      try {
        const { status, cancelled_by: cancelledBy } = await api('status?id=' + p.booking_id);
        if (cancellationFee && status === 'cancelled' && cancelledBy === 'owner') endPay('Chủ sân đã hủy lịch. Bạn không phải thanh toán phí hủy.');
        else if (cancellationFee && status === 'cancelled') endPay('Đã nhận phí hủy. Lịch đã hủy và khung giờ được mở lại.');
        else if (!cancellationFee && status === 'paid') endPay('Đã nhận được thanh toán. Đơn đặt sân được xác nhận!');
        else if (cancellationFee && status !== 'cancel_fee_pending') endPay('Lịch không còn chờ thanh toán phí hủy.');
        else if (!cancellationFee && status !== 'pending') endPay('Đơn không còn hiệu lực.');
      } catch (e) { /* lỗi mạng tạm thời: thử lại lần sau */ }
    }, 5000);
    $('#payDlg').showModal();
  }
  function endPay(msg) { stopPay(); $('#payBody').hidden = true; $('#payState').textContent = msg; loadMine(); loadSlots(); }
  $('#payClose').onclick = () => $('#payDlg').close();
  $('#payDlg').addEventListener('close', () => { stopPay(); loadMine(); });

  /* ========== 7) Lịch đặt của tôi ========== */
  const button = (text, onClick, ghost = false) => {
    const b = make('button', 'btn' + (ghost ? ' ghost' : ''), esc(text)); b.onclick = onClick; return b;
  };
  let myBookings = [];
  let bookingFilter = 'all';
  const statusNames = {
    confirmed: 'Đã xác nhận · chưa thanh toán',
    paid: 'Đã thanh toán',
    pending: 'Chưa thanh toán',
    cancel_fee_pending: 'Chờ phí hủy',
    cancelled: 'Đã hủy',
    expired: 'Hết hạn giữ chỗ'
  };
  const groupedStatuses = {
    paid: ['paid'],
    pending: ['confirmed', 'pending', 'cancel_fee_pending'],
    cancelled: ['cancelled'],
    expired: ['expired']
  };
  const dateTime = value => {
    if (!value) return '';
    const [date, time = ''] = String(value).split(' ');
    return `${dmy(date)}${time ? ` · ${time.slice(0, 5)}` : ''}`;
  };
  function renderBookingStats() {
    const counts = Object.fromEntries(Object.entries(groupedStatuses)
      .map(([group, statuses]) => [group, myBookings.filter(b => statuses.includes(b.status)).length]));
    $('#bookingStats').innerHTML = [
      ['all', 'Tổng lịch đặt', myBookings.length],
      ['paid', 'Đã thanh toán', counts.paid],
      ['pending', 'Chưa thanh toán', counts.pending],
      ['cancelled', 'Đã hủy', counts.cancelled],
      ['expired', 'Hết hạn', counts.expired]
    ].map(([status, label, count]) => `<button class="booking-stat ${status}" type="button" data-booking-filter="${status}" aria-pressed="${bookingFilter === status}"><span>${esc(label)}</span><b>${count}</b></button>`).join('');
  }
  function renderBookingList() {
    renderBookingStats();
    document.querySelectorAll('[data-booking-filter]').forEach(b =>
      b.setAttribute('aria-pressed', String(b.dataset.bookingFilter === bookingFilter)));
    const filtered = bookingFilter === 'all' ? myBookings
      : myBookings.filter(b => (groupedStatuses[bookingFilter] || [bookingFilter]).includes(b.status));
    const box = $('#myBookings');
    box.innerHTML = '';
    if (!filtered.length) {
      box.innerHTML = myBookings.length
        ? `<div class="history-empty"><b>Chưa có đơn ${esc(statusNames[bookingFilter]?.toLocaleLowerCase('vi') || 'phù hợp')}.</b><span>Chọn trạng thái khác để xem các lịch đặt của bạn.</span></div>`
        : '<div class="history-empty"><b>Bạn chưa có lịch đặt nào.</b><span>Sau khi chọn sân và đặt giờ, các đơn sẽ được lưu tại đây để bạn tiện theo dõi.</span></div>';
      return;
    }
    filtered.forEach(b => box.appendChild(bookingRow(b)));
  }
  document.addEventListener('click', e => {
    const filter = e.target.closest('[data-booking-filter]');
    if (filter) {
      bookingFilter = filter.dataset.bookingFilter;
      renderBookingList();
    }
  });
  async function loadMine() {
    const box = $('#myBookings');
    box.innerHTML = '<p class="history-loading" role="status">Đang tải lịch đặt...</p>';
    try {
      const { bookings } = await api('my-bookings');
      myBookings = bookings;
      renderBookingList();
    } catch (e) { box.innerHTML = `<p class="error">${esc(e.message)}</p>`; }
  }
  function bookingRow(b) {
    const refund = { pending: 'Chủ sân đang xử lý hoàn tiền', done: 'Chủ sân đã hoàn tiền' }[b.refund];
    const descriptions = {
      paid: 'Thanh toán đã được ghi nhận. Suất sân của bạn đã được xác nhận.',
      pending: 'Sân đang được giữ tạm thời. Hoàn tất thanh toán trước thời hạn bên dưới.',
      confirmed: 'Đặt sân thành công. Bạn chưa cần thanh toán; hãy thanh toán trực tiếp tại sân.',
      cancel_fee_pending: `Yêu cầu hủy đã ghi nhận. Thanh toán ${money(b.cancellation_fee)} (70% tiền sân) để hủy và mở lại khung giờ.`,
      cancelled: b.refund === 'pending' ? 'Lịch đã hủy. Chủ sân đang xử lý hoàn tiền.' :
        b.refund === 'done' ? 'Lịch đã hủy và khoản tiền đã được hoàn.' : 'Lịch đã hủy, không phát sinh hoàn tiền.',
      expired: 'Đã hết thời gian giữ chỗ mà đơn chưa được thanh toán.'
    };
    const row = make('article', `booking-card ${esc(b.status)}`, `
      <header class="booking-card-head">
        <div><span class="booking-venue">${esc(b.venue)}</span><h3>${esc(b.pitch)} <small>${esc(b.pitch_type || '')}</small></h3></div>
        <span class="tag ${esc(b.status)}">${esc(statusNames[b.status] || b.status_text)}</span>
      </header>
      <div class="booking-detail-grid">
        <div><span>Ngày đá</span><b>${dmy(b.date)}</b></div>
        <div><span>Khung giờ</span><b>${pad(b.from)} – ${pad(b.to)}</b></div>
        <div><span>Thời lượng</span><b>${b.to - b.from} giờ</b></div>
        <div><span>Tổng tiền</span><b class="booking-total">${money(b.total)}</b></div>
      </div>
      <div class="booking-meta"><span>Mã đặt sân <b>${esc(b.code)}</b></span><span>Đặt lúc ${dateTime(b.created_at)}</span></div>
      <div class="booking-status-note"><b>${esc(descriptions[b.status] || '')}</b>
        ${b.status === 'pending' ? `<span>Hạn thanh toán: ${dateTime(b.expires_at)}</span>` : ''}
        ${b.paid_at ? `<span>Thanh toán lúc: ${dateTime(b.paid_at)}</span>` : ''}
        ${b.cancelled_at ? `<span>Hủy lúc: ${dateTime(b.cancelled_at)}</span>` : ''}
        ${b.refund_amount > 0 ? `<span>${esc(refund || '')}: ${money(b.refund_amount)}</span>` : refund ? `<span>${esc(refund)}</span>` : ''}
      </div>
      <div class="booking-contact">
        <div><span>Cụm sân</span><b>${esc(b.venue)}</b><small>${esc(b.venue_address || 'Chủ sân chưa cập nhật địa chỉ')}</small></div>
        <div><span>Liên hệ chủ sân</span><b>${esc(b.owner_name)}</b><a href="tel:${encodeURIComponent(b.owner_phone)}">${esc(b.owner_phone)}</a></div>
      </div>
      <div class="booking-card-actions"></div>`);
    const actions = row.querySelector('.booking-card-actions');
    if (b.status === 'pending') actions.appendChild(button('Thanh toán', async () => {
      try { showPay((await api('pay?id=' + b.id)).pay); } catch (e) { alert(e.message); loadMine(); }
    }));
    if (b.status === 'cancel_fee_pending') actions.appendChild(button('Thanh toán phí hủy', async () => {
      try { showPay((await api('cancel-fee-pay?id=' + b.id)).pay, true); } catch (e) { alert(e.message); loadMine(); }
    }));
    const matchAlreadyPosted = myMatches.some(match => Number(match.booking_id) === Number(b.id));
    if (['confirmed', 'paid'].includes(b.status) && new Date(`${b.date}T${pad(b.to)}:00`).getTime() > Date.now()) {
      actions.appendChild(button(matchAlreadyPosted ? 'Đã đăng tìm đối thủ' : 'Tìm đối thủ', () => openCreateMatch(b), true));
      actions.lastChild.disabled = matchAlreadyPosted;
    }
    if (b.can_cancel) actions.appendChild(button('Hủy lịch', () => openCancel(b), true));
    return row;
  }

  /* ========== 8) Hủy lịch 2 bước ========== */
  let cancelCtx = null;   // { id, token }
  function openCancel(b) {
    cancelCtx = { id: b.id, token: null };
    $('#cInfo').textContent = `${b.pitch}, ${dmy(b.date)}, ${pad(b.from)}–${pad(b.to)} – ${money(b.total)}` +
      (b.status === 'paid' ? '. Đơn cũ đã thanh toán: chủ sân sẽ hoàn lại toàn bộ tiền.' :
        b.status === 'confirmed' ? '. Hủy trước giờ đá hơn 2 giờ: không mất phí; còn từ 2 giờ trở xuống: cần trả 70% tiền sân.' : '');
    $('#cStep1').hidden = false; $('#cStep2').hidden = true; $('#cError').textContent = '';
    $('#cPass').value = ''; $('#cPhrase').value = ''; $('#cancelDlg').showModal();
  }
  $('#cBack1').onclick = $('#cBack2').onclick = () => $('#cancelDlg').close();
  $('#cNext').onclick = async () => {                  // bước 1: xin token dùng một lần
    try {
      cancelCtx.token = (await api('cancel-start', { method: 'POST', body: { booking_id: cancelCtx.id } })).token;
      $('#cStep1').hidden = true; $('#cStep2').hidden = false; $('#cError').textContent = ''; $('#cPass').focus();
    } catch (e) { $('#cError').textContent = e.message; }
  };
  $('#cGo').onclick = async () => {                    // bước 2: token + mật khẩu + gõ HUY
    $('#cGo').disabled = true;
    try {
      const result = await api('cancel-confirm', { method: 'POST', body: { booking_id: cancelCtx.id, token: cancelCtx.token,
        password: $('#cPass').value, phrase: $('#cPhrase').value } });
      $('#cancelDlg').close();
      await loadMine(); await loadSlots();
      if (result.cancellation_fee_due) showPay(result.pay, true);
      else flash(result.refund ? `Đã hủy lịch. Chủ sân sẽ hoàn ${money(result.refund_amount)}.` : 'Đã hủy lịch.');
    } catch (e) { $('#cError').textContent = e.message; }
    finally { $('#cGo').disabled = false; }
  };

  $('#closeCreateMatch').onclick = () => $('#createMatchDlg').close();
  $('#closeRequestMatch').onclick = () => $('#requestMatchDlg').close();
  $('#createMatchForm').onsubmit = async e => {
    e.preventDefault();
    const submit = e.target.querySelector('button[type="submit"]');
    submit.disabled = true;
    $('#createMatchError').textContent = '';
    try {
      await api('match-create', { method: 'POST', body: {
        booking_id: Number($('#matchBookingId').value),
        team_name: $('#matchTeamName').value,
        contact_phone: $('#matchPhone').value,
        players_needed: Number($('#matchPlayersNeeded').value),
        description: $('#matchDescription').value
      } });
      $('#createMatchDlg').close();
      await loadMatches();
      await loadMine();
      flash('Đã đăng kèo tìm đối thủ.');
    } catch (error) { $('#createMatchError').textContent = error.message; }
    finally { submit.disabled = false; }
  };
  $('#requestMatchForm').onsubmit = async e => {
    e.preventDefault();
    const submit = e.target.querySelector('button[type="submit"]');
    submit.disabled = true;
    $('#requestMatchError').textContent = '';
    try {
      await api('match-request', { method: 'POST', body: {
        challenge_id: Number($('#requestChallengeId').value),
        team_name: $('#requestTeamName').value,
        contact_phone: $('#requestPhone').value,
        message: $('#requestMessage').value
      } });
      $('#requestMatchDlg').close();
      await loadMatches();
      flash('Đã gửi lời thách đấu. Chờ đội tạo kèo phản hồi.');
    } catch (error) { $('#requestMatchError').textContent = error.message; }
    finally { submit.disabled = false; }
  };
  $('#closeGuestPlayer').onclick = () => $('#guestPlayerDlg').close();
  $('#guestPlayerForm').onsubmit = async e => {
    e.preventDefault();
    const submit = e.target.querySelector('button[type="submit"]');
    submit.disabled = true;
    $('#guestPlayerError').textContent = '';
    try {
      await api('match-player-request', { method: 'POST', body: {
        challenge_id: Number($('#guestPlayerChallengeId').value),
        team_name: $('#guestPlayerName').value,
        contact_phone: $('#guestPlayerPhone').value,
        message: $('#guestPlayerMessage').value
      } });
      $('#guestPlayerDlg').close();
      await loadMatches();
      flash('Đã gửi yêu cầu đá vãng lai. Đội trưởng sẽ phản hồi sau khi xem yêu cầu.');
    } catch (error) { $('#guestPlayerError').textContent = error.message; }
    finally { submit.disabled = false; }
  };
  document.addEventListener('click', async e => {
    const topVenue = e.target.closest('[data-top-venue]');
    if (topVenue) {
      const ownerId = Number(topVenue.dataset.topVenue);
      if (!state.venues.some(venue => Number(venue.id) === ownerId)) await loadSlots();
      $('#pitchSearch').value = '';
      $('#areaSearch').value = '';
      $('#typeFilter').value = '';
      $('#hourFilter').value = '';
      state.venueId = ownerId;
      clearSelection();
      renderVenues();
      $('#pitches').scrollIntoView({ behavior: 'smooth' });
      return;
    }
    const request = e.target.closest('[data-request-match]');
    if (request) {
      if (user?.role !== 'customer') { location.href = 'login'; return; }
      const match = matchCache.find(item => Number(item.id) === Number(request.dataset.requestMatch));
      if (match) openRequestMatch(match);
      return;
    }
    const guestPlayer = e.target.closest('[data-join-player]');
    if (guestPlayer) {
      if (user?.role !== 'customer') { location.href = 'login'; return; }
      const match = matchCache.find(item => Number(item.id) === Number(guestPlayer.dataset.joinPlayer));
      if (match) openGuestPlayer(match);
      return;
    }
    const capacityButton = e.target.closest('[data-save-player-capacity]');
    if (capacityButton) {
      const challengeId = Number(capacityButton.dataset.savePlayerCapacity);
      const capacity = Number(document.querySelector(`[data-player-capacity="${challengeId}"]`).value);
      capacityButton.disabled = true;
      try {
        await api('match-player-manage', { method: 'POST', body: { challenge_id: challengeId, action: 'capacity', players_needed: capacity } });
        await loadMatches();
        flash('Đã cập nhật số chỗ đá vãng lai.');
      } catch (error) { flash(error.message, 'err'); capacityButton.disabled = false; }
      return;
    }
    const managePlayers = e.target.closest('[data-manage-players]');
    if (managePlayers) {
      managePlayers.disabled = true;
      try {
        const action = managePlayers.dataset.managePlayers;
        await api('match-player-manage', { method: 'POST', body: { challenge_id: Number(managePlayers.dataset.id), action } });
        await loadMatches();
        flash(action === 'open' ? 'Đã mở nhận người đá vãng lai.' : 'Đã ngừng nhận người đá vãng lai.');
      } catch (error) { flash(error.message, 'err'); managePlayers.disabled = false; }
      return;
    }
    const playerDecision = e.target.closest('[data-player-decision]');
    if (playerDecision) {
      playerDecision.disabled = true;
      try {
        const action = playerDecision.dataset.playerDecision;
        await api('match-player-decision', { method: 'POST', body: { request_id: Number(playerDecision.dataset.id), action } });
        await loadMatches();
        flash(action === 'accept' ? 'Đã duyệt người đá vãng lai.' : action === 'remove' ? 'Đã xóa người chơi khỏi đội.' : 'Đã từ chối yêu cầu.');
      } catch (error) { flash(error.message, 'err'); playerDecision.disabled = false; }
      return;
    }
    const decision = e.target.closest('[data-match-decision]');
    const cancelMatch = e.target.closest('[data-cancel-match]');
    if (decision || cancelMatch) {
      const target = decision || cancelMatch;
      target.disabled = true;
      try {
        if (decision) {
          await api('match-decision', { method: 'POST', body: { request_id: Number(decision.dataset.id), action: decision.dataset.matchDecision } });
          flash(decision.dataset.matchDecision === 'accept' ? 'Đã chấp nhận đội đối thủ.' : 'Đã từ chối lời thách đấu.');
        } else {
          await api('match-cancel', { method: 'POST', body: { challenge_id: Number(cancelMatch.dataset.cancelMatch) } });
          flash('Đã đóng kèo.');
        }
        await loadMatches();
      } catch (error) { flash(error.message, 'err'); target.disabled = false; }
    }
  });

  /* ========== Khởi động ========== */
  for (let hour = 6; hour < 22; hour++) {
    const option = document.createElement('option');
    option.value = String(hour); option.textContent = `${pad(hour)}–${pad(hour + 1)}`;
    $('#hourFilter').appendChild(option);
  }
  ['pitchSearch', 'areaSearch', 'hourFilter', 'typeFilter'].forEach(id => {
    $('#' + id).addEventListener(id === 'hourFilter' || id === 'typeFilter' ? 'change' : 'input', () => {
      clearSelection(); renderVenues();
    });
  });
  $('#pitches').addEventListener('submit', async e => {
    const form = e.target.closest('.review-form');
    if (!form) return;
    e.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      await api('review', { method: 'POST', body: {
        owner_id: Number(form.dataset.reviewOwner),
        rating: Number(form.elements.rating.value),
        comment: form.elements.comment.value
      } });
      flash('Đã lưu đánh giá của bạn.');
      loadSlots();
    } catch (error) { flash(error.message, 'err'); button.disabled = false; }
  });
  renderDays();
  loadTopRated();
  loadMatches();
  selectDate(ymd(new Date()));
  if (new URLSearchParams(location.search).get('show') === 'matches') openMatches();
  if (user) loadMine();
})();
