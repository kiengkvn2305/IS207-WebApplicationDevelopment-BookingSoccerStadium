<?php
$r = new Router();
// --- Trang (HTML tĩnh, có kiểm tra quyền) ---
$r->add('GET', '/',        'PageController', 'home');
$r->add('GET', '/login',   'PageController', 'login');
$r->add('GET', '/dat-san', 'PageController', 'booking');
$r->add('GET', '/chu-san', 'PageController', 'owner');
$r->add('GET', '/admin',   'PageController', 'admin');
// --- API chung ---
$r->add('GET',  '/api/me',       'AuthController', 'me');
$r->add('POST', '/api/login',    'AuthController', 'login');
$r->add('POST', '/api/register', 'AuthController', 'register');
$r->add('POST', '/api/logout',   'AuthController', 'logout');
$r->add('POST', '/api/profile',  'AuthController', 'updateProfile');
$r->add('GET',  '/api/notifications', 'NotificationController', 'list');
$r->add('GET',  '/api/notifications/ws-ticket', 'NotificationController', 'wsTicket');
$r->add('POST', '/api/notifications/read', 'NotificationController', 'markRead');
$r->add('POST', '/api/notifications/read-all', 'NotificationController', 'markAllRead');
// --- API người đặt sân ---
$r->add('GET',  '/api/slots',          'BookingController', 'slots');
$r->add('GET',  '/api/reviews',        'BookingController', 'reviews');
$r->add('GET',  '/api/top-rated',      'BookingController', 'topRated');
$r->add('POST', '/api/review',         'BookingController', 'saveReview');
$r->add('GET',  '/api/matches',        'BookingController', 'matches');
$r->add('POST', '/api/match-create',   'BookingController', 'createMatch');
$r->add('POST', '/api/match-request',  'BookingController', 'requestMatch');
$r->add('POST', '/api/match-decision', 'BookingController', 'decideMatchRequest');
$r->add('POST', '/api/match-cancel',   'BookingController', 'cancelMatch');
$r->add('POST', '/api/match-player-request', 'BookingController', 'requestGuestPlayer');
$r->add('POST', '/api/match-player-decision', 'BookingController', 'decideGuestPlayer');
$r->add('POST', '/api/match-player-manage', 'BookingController', 'manageGuestPlayers');
$r->add('POST', '/api/book',           'BookingController', 'book');
$r->add('POST', '/api/voucher-quote',  'BookingController', 'quoteVoucher');
$r->add('GET',  '/api/pay',            'BookingController', 'pay');
$r->add('GET',  '/api/status',         'BookingController', 'status');
$r->add('GET',  '/api/cancel-fee-pay', 'BookingController', 'cancelFeePay');
$r->add('GET',  '/api/my-bookings',    'BookingController', 'mine');
$r->add('POST', '/api/webhooks/sepay', 'BookingController', 'sepayWebhook');
$r->add('POST', '/api/cancel-start',   'BookingController', 'cancelStart');
$r->add('POST', '/api/cancel-confirm', 'BookingController', 'cancelConfirm');
// --- API chủ sân ---
$r->add('GET',  '/api/owner/overview',      'OwnerController', 'overview');
$r->add('POST', '/api/owner/bank',          'OwnerController', 'saveBank');
$r->add('POST', '/api/owner/venue-details', 'OwnerController', 'saveVenueDetails');
$r->add('POST', '/api/owner/venue-image',   'OwnerController', 'venueImage');
$r->add('POST', '/api/owner/venue-image-delete', 'OwnerController', 'deleteVenueImage');
$r->add('POST', '/api/owner/pitch',         'OwnerController', 'addPitch');
$r->add('POST', '/api/owner/pitch-toggle',  'OwnerController', 'togglePitch');
$r->add('POST', '/api/owner/pitch-image',   'OwnerController', 'pitchImage');
$r->add('POST', '/api/owner/unavailable-slot', 'OwnerController', 'unavailableSlot');
$r->add('POST', '/api/owner/available-slot', 'OwnerController', 'availableSlot');
$r->add('GET',  '/api/owner/pitch-slots',   'OwnerController', 'pitchSlots');
$r->add('POST', '/api/owner/confirm-paid',  'OwnerController', 'confirmPaid');
$r->add('POST', '/api/owner/confirm-cancel-fee', 'OwnerController', 'confirmCancellationFee');
$r->add('POST', '/api/owner/refund-done',   'OwnerController', 'refundDone');
$r->add('POST', '/api/owner/cancel-booking', 'OwnerController', 'cancelBooking');
// --- API admin ---
$r->add('GET',  '/api/admin/overview', 'AdminController', 'overview');
$r->add('POST', '/api/admin/user',     'AdminController', 'userAction');
$r->add('POST', '/api/admin/review',   'AdminController', 'reviewAction');
$r->add('POST', '/api/admin/voucher',  'AdminController', 'createVoucher');
$r->add('POST', '/api/admin/voucher-toggle', 'AdminController', 'toggleVoucher');
return $r;
