// frontend/src/api.js
// Canonical API helper – OS-agnostic, works on macOS + Fedora

const API_BASE = import.meta.env.DEV
  ? `${window.location.origin}/bookcatalog/`
  : `${window.location.origin}/`;

export function apiUrl(path) {
  return new URL(path, API_BASE).toString();
}

async function parseJsonResponse(res) {
  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.ok === false) {
    const err = new Error(json.error || `HTTP ${res.status}`);
    err.status = res.status;
    err.payload = json;
    throw err;
  }
  return json;
}

export function assetUrl(path) {
  if (!path) return '';
  const raw = String(path).trim();
  if (/^https?:\/\//i.test(raw)) return raw;
  const cleaned = raw.replace(/^\/+/, '');
  return new URL(cleaned, API_BASE).toString();
}

/* -------------------- LIST -------------------- */

export async function fetchBooks(params = {}) {
  const u = new URL('list_books.php', API_BASE);
  const p = u.searchParams;

  if (params.q)        p.set('q', params.q);
  if (params.page)     p.set('page', String(params.page));
  const per = params.per ?? params.per_page ?? params.perPage;
  if (per) p.set('per', String(per));
  if (params.format)   p.set('format', params.format);
  if (params.language) p.set('language', params.language);
  if (params.record_status) p.set('record_status', params.record_status);
  if (params.sort)     p.set('sort', params.sort);
  if (params.dir)      p.set('dir', params.dir);

  const res = await fetch(u.toString(), { credentials: 'same-origin' });
  return parseJsonResponse(res);
}

export async function fetchBook(id) {
  const u = new URL('get_book.php', API_BASE);
  u.searchParams.set('id', String(id));
  const res = await fetch(u.toString(), { credentials: 'same-origin' });
  return parseJsonResponse(res);
}

/* -------------------- CREATE -------------------- */

export async function addBook(payload = {}, coverFile = null) {
  if (coverFile) {
    const fd = new FormData();
    fd.append("payload", JSON.stringify(payload));
    fd.append("image", coverFile);
    const res = await fetch(apiUrl("addBook.php"), {
      method: "POST",
      body: fd,
      credentials: "same-origin",
    });
    return parseJsonResponse(res);
  }

  const res = await fetch(apiUrl("addBook.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(payload),
  });

  return parseJsonResponse(res);
}

/* -------------------- UPDATE -------------------- */

export async function updateBook(payload = {}) {
  const id = payload.id ?? payload.book_id;
  const body = { ...payload, id, book_id: id };

  const res = await fetch(apiUrl('update_book.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  });

  return parseJsonResponse(res);
}

/* -------------------- DELETE -------------------- */

export async function deleteBook(id) {
  const u = new URL('delete_book.php', API_BASE);
  u.searchParams.set('id', String(id));

  const res = await fetch(u.toString(), {
    method: 'POST',
    credentials: 'same-origin',
  });

  return parseJsonResponse(res);
}

export async function restoreBook(id) {
  const u = new URL("restore_book.php", API_BASE);
  u.searchParams.set("id", String(id));

  const res = await fetch(u.toString(), {
    method: "POST",
    credentials: "same-origin",
  });

  return parseJsonResponse(res);
}

export async function deleteBookCopy(copyId) {
  const res = await fetch(apiUrl("delete_book_copy.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify({ copy_id: copyId }),
  });

  return parseJsonResponse(res);
}

/* -------------------- SUGGEST -------------------- */

async function getJSON(path) {
  const res = await fetch(apiUrl(path), { credentials: 'same-origin' });
  const json = await parseJsonResponse(res);
  return json.data;
}

export async function suggestPublishers(q) {
  const data = await getJSON(`suggest_publishers.php?q=${encodeURIComponent(q)}`);
  return Array.isArray(data) ? data : [];
}

export async function suggestAuthors(q) {
  const data = await getJSON(`search_authors.php?q=${encodeURIComponent(q)}`);
  return Array.isArray(data) ? data : [];
}

export async function createAuthor(payload = {}) {
  const res = await fetch(apiUrl('create_author.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  return parseJsonResponse(res);
}

export async function fetchAuthors({ q = "", page = 1, per = 50, sort = "name", dir = "asc" } = {}) {
  const params = new URLSearchParams();
  if (q) params.set("q", q);
  params.set("page", String(page));
  params.set("per", String(per));
  if (sort) params.set("sort", sort);
  if (dir) params.set("dir", dir);
  const data = await getJSON(`list_authors.php?${params.toString()}`);
  return data || {};
}

export async function deleteAuthor(authorId) {
  const res = await fetch(apiUrl("delete_author.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify({ author_id: authorId }),
  });
  return parseJsonResponse(res);
}

export async function updateAuthor(authorId, payload = {}) {
  const res = await fetch(apiUrl("update_author.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify({ author_id: authorId, ...payload }),
  });
  return parseJsonResponse(res);
}

/* -------------------- AUTH -------------------- */

export async function login(username, password) {
  const res = await fetch(apiUrl('login.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ username, password }),
  });

  return parseJsonResponse(res);
}

export async function logout() {
  const res = await fetch(apiUrl('logout.php'), {
    method: 'POST',
    credentials: 'same-origin',
  });

  return parseJsonResponse(res);
}

export async function me() {
  const res = await fetch(apiUrl('me.php'), {
    credentials: 'same-origin',
  });

  return parseJsonResponse(res);
}

/* -------------------- USERS -------------------- */

export async function listUsers() {
  const res = await fetch(apiUrl('list_users.php'), {
    credentials: 'same-origin',
  });
  return parseJsonResponse(res);
}

export async function listAuthEvents(params = {}) {
  const u = new URL('list_auth_events.php', API_BASE);
  const p = u.searchParams;

  if (params.page) p.set('page', String(params.page));
  const per = params.per ?? params.per_page ?? params.perPage;
  if (per) p.set('per', String(per));
  if (params.event_type) p.set('event_type', String(params.event_type));
  if (params.user_id) p.set('user_id', String(params.user_id));
  if (params.q) p.set('q', String(params.q));

  const res = await fetch(u.toString(), { credentials: 'same-origin' });
  return parseJsonResponse(res);
}

export async function purgeAuthEvents(months) {
  const res = await fetch(apiUrl('purge_auth_events.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ months }),
  });
  return parseJsonResponse(res);
}

export async function purgeCatalog(confirm = "DELETE") {
  const res = await fetch(apiUrl("purge_catalog.php"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify({ confirm }),
  });
  return parseJsonResponse(res);
}

export async function createUser(payload = {}) {
  const res = await fetch(apiUrl('create_user_api.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  return parseJsonResponse(res);
}

export async function updateUser(payload = {}) {
  const res = await fetch(apiUrl('update_user.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  return parseJsonResponse(res);
}

export async function deleteUser(userId) {
  const res = await fetch(apiUrl('delete_user.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ user_id: userId }),
  });
  return parseJsonResponse(res);
}

export async function changePassword(payload = {}) {
  const res = await fetch(apiUrl('change_password.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  return parseJsonResponse(res);
}

export async function adminResetPassword(payload = {}) {
  const res = await fetch(apiUrl('admin_reset_password.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  return parseJsonResponse(res);
}

/* -------------------- PREFERENCES -------------------- */

export async function updateUserPreferences(payload = {}, logoFile = null) {
  const fd = new FormData();
  if (payload.bg_color !== undefined) fd.append('bg_color', payload.bg_color || '');
  if (payload.fg_color !== undefined) fd.append('fg_color', payload.fg_color || '');
  if (payload.text_size !== undefined) fd.append('text_size', payload.text_size || '');
  if (payload.per_page !== undefined) fd.append('per_page', String(payload.per_page ?? ''));
  if (payload.show_cover !== undefined) fd.append('show_cover', payload.show_cover ? '1' : '0');
  if (payload.show_subtitle !== undefined) fd.append('show_subtitle', payload.show_subtitle ? '1' : '0');
  if (payload.show_series !== undefined) fd.append('show_series', payload.show_series ? '1' : '0');
  if (payload.show_is_hungarian !== undefined) fd.append('show_is_hungarian', payload.show_is_hungarian ? '1' : '0');
  if (payload.show_publisher !== undefined) fd.append('show_publisher', payload.show_publisher ? '1' : '0');
  if (payload.show_language !== undefined) fd.append('show_language', payload.show_language ? '1' : '0');
  if (payload.show_format !== undefined) fd.append('show_format', payload.show_format ? '1' : '0');
  if (payload.show_year !== undefined) fd.append('show_year', payload.show_year ? '1' : '0');
  if (payload.show_copy_count !== undefined) fd.append('show_copy_count', payload.show_copy_count ? '1' : '0');
  if (payload.show_status !== undefined) fd.append('show_status', payload.show_status ? '1' : '0');
  if (payload.show_placement !== undefined) fd.append('show_placement', payload.show_placement ? '1' : '0');
  if (payload.show_isbn !== undefined) fd.append('show_isbn', payload.show_isbn ? '1' : '0');
  if (payload.show_loaned_to !== undefined) fd.append('show_loaned_to', payload.show_loaned_to ? '1' : '0');
  if (payload.show_loaned_date !== undefined) fd.append('show_loaned_date', payload.show_loaned_date ? '1' : '0');
  if (payload.show_subjects !== undefined) fd.append('show_subjects', payload.show_subjects ? '1' : '0');
  if (payload.show_notes !== undefined) fd.append('show_notes', payload.show_notes ? '1' : '0');
  if (payload.remove_logo) fd.append('remove_logo', '1');
  if (logoFile) fd.append('logo', logoFile);

  const res = await fetch(apiUrl('user_preferences.php'), {
    method: 'POST',
    credentials: 'same-origin',
    body: fd,
  });

  return parseJsonResponse(res);
}

export async function fetchUserPreferences() {
  const res = await fetch(apiUrl("user_preferences.php"), {
    credentials: "same-origin",
  });
  return parseJsonResponse(res);
}

/* -------------------- MAINTENANCE -------------------- */

export async function fetchOrphanMaintenance() {
  return getJSON('orphan_maintenance.php');
}

async function postMaintenance(payload) {
  const res = await fetch(apiUrl('orphan_maintenance.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  return parseJsonResponse(res);
}

export async function deleteOrphanAuthor(authorId) {
  return postMaintenance({ action: 'delete_author', author_id: authorId });
}

export async function deleteOrphanPublisher(publisherId) {
  return postMaintenance({ action: 'delete_publisher', publisher_id: publisherId });
}

export async function deleteOrphanLink(bookId, authorId) {
  return postMaintenance({ action: 'delete_link', book_id: bookId, author_id: authorId });
}

export async function reassignOrphanLink(bookId, authorId, newAuthorId) {
  return postMaintenance({
    action: 'reassign_link',
    book_id: bookId,
    author_id: authorId,
    new_author_id: newAuthorId,
  });
}

export async function updateOrphanAuthor(authorId, payload = {}) {
  return postMaintenance({
    action: 'update_author',
    author_id: authorId,
    name: payload.name,
    first_name: payload.first_name,
    last_name: payload.last_name,
    sort_name: payload.sort_name,
    is_hungarian: payload.is_hungarian,
  });
}

export async function updateOrphanPublisher(publisherId, payload = {}) {
  return postMaintenance({
    action: 'update_publisher',
    publisher_id: publisherId,
    name: payload.name,
  });
}
