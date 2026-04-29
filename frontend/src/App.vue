<template>
  <main class="container">
    <header class="topbar">
      <div class="logo-slot">
        <img v-if="logoUrl" :src="logoUrl" alt="Logo" />
      </div>
      <div class="title-slot">
        <div class="brand-title">
          My Book Catalog
          <span v-if="appVersion" class="app-version">v{{ appVersion }}</span>
        </div>
        <div v-if="user" class="signed-in">Signed in as {{ user.username }} ({{ user.role }})</div>
      </div>
      <div class="top-actions">
        <button v-if="user" @click="openPreferences">Personalize</button>
        <button v-if="user" @click="onLogout" :disabled="loginLoading">Logout</button>
        <button v-else class="primary" @click="openLoginPrompt">Sign in</button>
      </div>
    </header>

    <section class="search" v-if="user">
      <div class="search-row">
        <input
          v-model.trim="q"
          :disabled="loading"
          type="search"
          name="book_search"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          data-lpignore="true"
          data-1p-ignore="true"
          placeholder="Search title / author / subject..."
          @keyup.enter="onSearch"
        />
        <button :disabled="loading" @click="onSearch">Search</button>
        <button :disabled="loading || !q" @click="clearSearch">Clear</button>
        <label class="inline-filter">
          <span>Format</span>
          <select v-model="formatFilter" :disabled="loading" @change="onFormatFilterChange">
            <option value="">All</option>
            <option value="print">print</option>
            <option value="epub">epub</option>
            <option value="mobi">mobi</option>
            <option value="azw3">azw3</option>
            <option value="pdf">pdf</option>
            <option value="djvu">djvu</option>
            <option value="lit">lit</option>
            <option value="prc">prc</option>
            <option value="rtf">rtf</option>
            <option value="odt">odt</option>
          </select>
        </label>
        <label class="inline-filter">
          <span>Language</span>
          <select v-model="languageFilter" :disabled="loading" @change="onLanguageFilterChange">
            <option value="">All</option>
            <option value="unknown">unknown</option>
            <option value="hu">hu</option>
            <option value="en">en</option>
            <option value="de">de</option>
            <option value="fr">fr</option>
          </select>
        </label>
        <label v-if="isAdmin" class="inline-filter">
          <span>Records</span>
          <select v-model="recordStatusFilter" :disabled="loading" @change="onRecordStatusFilterChange">
            <option value="active">Active</option>
            <option value="deleted">Deleted</option>
            <option value="all">All</option>
          </select>
        </label>
        <button @click="resetSort">Reset sort</button>
        <button v-if="isAdmin" class="primary" @click="openAdd">+ Add Book</button>
        <button v-if="isAdmin" @click="openCsvImport">Import books</button>
        <button v-if="isAdmin" @click="onRebuildThumbs">Rebuild thumbs</button>
        <button
          v-if="isAdmin"
          class="link-btn"
          type="button"
          @click="onExportSelectedBundle"
        >Export selected books (CSV + covers)</button>
        <button
          v-if="isAdmin"
          class="link-btn"
          type="button"
          @click="onExportFullBackup"
        >Full backup (ZIP)</button>
        <button v-if="isAdmin" @click="openAuthorsMaintenance">Authors</button>
        <button v-if="isAdmin" @click="openUserManagement">Users</button>
        <button v-if="isAdmin" @click="openOrphanMaintenance">Orphan maintenance</button>
        <button v-if="isAdmin" @click="openDuplicateCandidates">Duplicate candidates</button>
        <button v-if="isAdmin" @click="openAuthLogs">Logs</button>
        <button v-if="isAdmin" class="danger" @click="onPurgeCatalog">Purge catalog</button>
      </div>
    </section>

    <section class="status" v-if="!user">
      <p>Sign in to continue.</p>
    </section>

    <BookList
      v-if="user"
      :rows="rows"
      :total="total"
      :page="page"
      :per-page="perPage"
      :sort="sort"
      :dir="dir"
      :loading="loading"
      :q="q"
      :is-admin="isAdmin"
      :columns="preferences"
      @change-page="onChangePage"
      @change-per-page="onChangePerPage"
      @change-sort="onChangeSort"
      @view="onView"
      @edit="onEdit"
      @duplicate="duplicateFrom"
      @delete="onDelete"
      @restore="onRestore"
    />

    <BookDetailModal
      :open="showDetail"
      :book="selectedBook"
      @close="closeDetail"
    />

    <BookDialog
      v-if="showDialog"
      :mode="dialogMode"
      :book="selected || {}"
      :can-manage="isAdmin"
      @close="onCloseDialog"
      @switch-edit="onEdit"
      @save="onSaveDialog"
      @create="onCreateDialog"
      @duplicate="duplicateFrom"
    />

    <CsvImportModal
      v-if="showCsvImport"
      @close="showCsvImport = false"
      @imported="onCsvImported"
    />

    <OrphanMaintenance
      v-if="showOrphanMaintenance"
      @close="showOrphanMaintenance = false"
    />
    <AuthorsMaintenance
      v-if="showAuthorsMaintenance"
      @close="showAuthorsMaintenance = false"
    />
    <UserManagement
      v-if="showUserManagement"
      :current-user="user"
      @close="showUserManagement = false"
    />
    <AuthLogsModal
      v-if="showAuthLogs"
      @close="showAuthLogs = false"
    />

    <PreferencesModal
      v-if="showPreferences"
      :preferences="preferences"
      @close="showPreferences = false"
      @saved="onPreferencesSaved"
    />

    <div v-if="rebuildThumbsBusy" class="busy-overlay" aria-live="polite">
      <div class="busy-card">
        <div class="spinner" aria-hidden="true"></div>
        <div>
          Rebuilding thumbnails…
          <span v-if="rebuildThumbsTotal"> {{ rebuildThumbsDone }} / {{ rebuildThumbsTotal }}</span>
          <span v-else> {{ rebuildThumbsDone }}</span>
        </div>
      </div>
    </div>

    <div v-if="backupBusy" class="busy-overlay" aria-live="polite">
      <div class="busy-card">
        <div class="spinner" aria-hidden="true"></div>
        <div>{{ backupBusyMessage || "Preparing backup..." }}</div>
      </div>
    </div>

    <div v-if="showLoginModal" class="login-overlay">
      <form class="login-card" @submit.prevent="onSubmitLogin">
        <h2>Sign in</h2>
        <label>
          Username
          <input
            v-model.trim="loginForm.username"
            :disabled="loginLoading"
            type="text"
            autocomplete="username"
            autofocus
          />
        </label>
        <label>
          Password
          <input
            v-model="loginForm.password"
            :disabled="loginLoading"
            type="password"
            autocomplete="current-password"
          />
        </label>
        <div class="error" v-if="loginError">{{ loginError }}</div>
        <div class="actions">
          <button class="primary" type="submit" :disabled="loginLoading">
            {{ loginLoading ? 'Signing in...' : 'Sign in' }}
          </button>
        </div>
        <div class="hint">Admin role required for data changes.</div>
      </form>
    </div>

    <div v-if="needsPasswordChange" class="login-overlay">
      <div class="login-card force-card">
        <h2>Update your password</h2>
        <ChangePassword :force="true" @changed="onForcedPasswordChanged" />
      </div>
    </div>
  </main>
</template>

<script setup lang="js">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import BookDetailModal from "./components/BookDetailModal.vue";
import BookDialog from "./components/BookDialog.vue";
import BookList from "./components/BookList.vue";
import CsvImportModal from "./components/CsvImportModal.vue";
import OrphanMaintenance from "./components/OrphanMaintenance.vue";
import AuthorsMaintenance from "./components/AuthorsMaintenance.vue";
import UserManagement from "./components/UserManagement.vue";
import AuthLogsModal from "./components/AuthLogsModal.vue";
import PreferencesModal from "./components/PreferencesModal.vue";
import ChangePassword from "./components/ChangePassword.vue";
import {
  addBook,
  deleteBook,
  deleteBookCopy,
  fetchBook,
  fetchBooks,
  fetchUserPreferences,
  purgeCatalog,
  restoreBook,
  updateBook,
  updateUserPreferences,
  assetUrl,
  apiUrl,
} from "./api";
import { useAuth } from "./composables/useAuth";
import { APP_VERSION_DISPLAY } from "./version";

const rows = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const perPageSource = ref("default");
const sort = ref("title");
const dir = ref("asc");
const q = ref("");
const formatFilter = ref("");
const languageFilter = ref("");
const recordStatusFilter = ref("active");
const loading = ref(false);
const ignorePopStateOnce = ref(false);
const showDetail = ref(false);
const selectedBook = ref(null);
const showDialog = ref(false);
const dialogMode = ref("view");
const selected = ref(null);
const appVersion = APP_VERSION_DISPLAY;
const showCsvImport = ref(false);
const showOrphanMaintenance = ref(false);
const showAuthorsMaintenance = ref(false);
const showUserManagement = ref(false);
const showAuthLogs = ref(false);
const showPreferences = ref(false);
const rebuildThumbsBusy = ref(false);
const rebuildThumbsDone = ref(0);
const rebuildThumbsTotal = ref(0);
const rebuildThumbsUpdated = ref(0);
const rebuildThumbsErrors = ref(0);
const rebuildThumbsErrorList = ref([]);
const backupBusy = ref(false);
const backupBusyMessage = ref("");
const preferences = ref({
  logo_url: null,
  bg_color: null,
  fg_color: null,
  text_size: "medium",
  per_page: 25,
  show_cover: true,
  show_subtitle: true,
  show_series: true,
  show_is_hungarian: true,
  show_publisher: true,
  show_language: false,
  show_format: false,
  show_year: true,
  show_copy_count: false,
  show_status: true,
  show_placement: true,
  show_isbn: false,
  show_loaned_to: false,
  show_loaned_date: false,
  show_subjects: false,
  show_notes: false,
});
const initialQueryParam = ref(null);
const searchAutofillTimers = ref([]);

const onUnauthorized = () => {
  rows.value = [];
  total.value = 0;
  showDetail.value = false;
  selectedBook.value = null;
  showDialog.value = false;
  selected.value = null;
};

const {
  user,
  showLoginModal,
  loginForm,
  loginLoading,
  loginError,
  initAuth,
  fetchCurrentUser,
  handleUnauthorized,
  openLoginPrompt,
  onSubmitLogin,
  onLogout,
} = useAuth({ onUnauthorized });

const needsPasswordChange = computed(() => !!user.value?.force_password_change);

const onForcedPasswordChanged = async () => {
  await fetchCurrentUser();
};
const logoUrl = computed(() => {
  const raw = preferences.value?.logo_url;
  return raw ? assetUrl(raw) : "";
});

const isAdmin = computed(() => {
  const role = user.value && user.value.role ? String(user.value.role).toLowerCase() : "";
  return role === "admin";
});

const buildBackupUrl = (endpoint, params = {}) => {
  const u = new URL(apiUrl(endpoint));
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && String(value) !== "") {
      u.searchParams.set(key, String(value));
    }
  });
  return u;
};

const checkBackupMode = async (url) => {
  const checkUrl = new URL(url.toString());
  checkUrl.searchParams.set("check", "1");
  const res = await fetch(checkUrl.toString(), { credentials: "same-origin" });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `HTTP ${res.status}`);
  }
  return data;
};

const runServerBackup = async (url, label) => {
  backupBusyMessage.value = `Generating ${label} backup on the server...`;
  backupBusy.value = true;
  try {
    const res = await fetch(url.toString(), { credentials: "same-origin" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    const dir = data.dir || "";
    const filename = data.filename || "";
    const path = data.path || (dir && filename ? `${dir}/${filename}` : dir);
    const location = dir || path;
    const msg = location
      ? `The requested ${label} backup file is in ${location}${filename ? ` (filename: ${filename})` : ""}.`
      : `The requested ${label} backup file is ready.`;
    alert(msg);
  } finally {
    backupBusy.value = false;
    backupBusyMessage.value = "";
  }
};

const runBackupFlow = async (url, label) => {
  try {
    const mode = await checkBackupMode(url);
    if (mode.mode === "stream") {
      const popup = window.open(url.toString(), "_blank", "noopener");
      if (!popup) {
        window.location.assign(url.toString());
      }
      return;
    }
    if (mode.mode === "server") {
      await runServerBackup(url, label);
      return;
    }
    alert("Unexpected backup mode response.");
  } catch (err) {
    alert(err && err.message ? err.message : "Backup failed.");
  }
};

const ensureAdmin = () => {
  if (!isAdmin.value) {
    alert("Admin access required");
    return false;
  }
  return true;
};

const DEFAULT_THEME = {
  bg: "#f6e09f",
  fg: "#222222",
  btnBg: "#f9f3d4",
  btnBorder: "#ccb66b",
  primaryBg: "#2a72d4",
  primaryBorder: "#2a72d4",
  primaryText: "#ffffff",
};

const normalizeHex = (value) => {
  if (!value) return null;
  const raw = String(value).trim();
  const match = raw.match(/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/);
  if (!match) return null;
  let hex = match[1];
  if (hex.length === 3) {
    hex = hex.split("").map((ch) => ch + ch).join("");
  }
  return `#${hex.toLowerCase()}`;
};

const hexToRgb = (hex) => {
  const normalized = normalizeHex(hex);
  if (!normalized) return null;
  const value = normalized.slice(1);
  return [
    parseInt(value.slice(0, 2), 16),
    parseInt(value.slice(2, 4), 16),
    parseInt(value.slice(4, 6), 16),
  ];
};

const clamp = (value) => Math.max(0, Math.min(255, Math.round(value)));

const blendRgb = (base, target, amount) => ([
  clamp(base[0] + (target[0] - base[0]) * amount),
  clamp(base[1] + (target[1] - base[1]) * amount),
  clamp(base[2] + (target[2] - base[2]) * amount),
]);

const rgbToHex = (rgb) => `#${rgb.map((c) => c.toString(16).padStart(2, "0")).join("")}`;
const luminance = (rgb) => (0.2126 * rgb[0]) + (0.7152 * rgb[1]) + (0.0722 * rgb[2]);

const applyPreferences = (prefs) => {
  const bg = normalizeHex(prefs?.bg_color) || DEFAULT_THEME.bg;
  const fg = normalizeHex(prefs?.fg_color) || DEFAULT_THEME.fg;
  const size = prefs?.text_size || "medium";
  const sizeMap = { small: "13px", medium: "15px", large: "17px" };
  document.documentElement.style.setProperty("--app-bg", bg);
  document.documentElement.style.setProperty("--app-fg", fg);
  document.documentElement.style.setProperty("--panel-bg", bg);
  document.documentElement.style.setProperty("--app-font-size", sizeMap[size] || "15px");

  const bgRgb = hexToRgb(bg);
  if (bgRgb) {
    const btnBg = rgbToHex(blendRgb(bgRgb, [255, 255, 255], 0.22));
    const btnBorder = rgbToHex(blendRgb(bgRgb, [0, 0, 0], 0.22));
    const primaryRgb = blendRgb(bgRgb, [0, 0, 0], 0.35);
    const primaryBorder = rgbToHex(blendRgb(bgRgb, [0, 0, 0], 0.5));
    const primaryText = luminance(primaryRgb) < 140 ? "#ffffff" : fg;
    document.documentElement.style.setProperty("--btn-bg", btnBg);
    document.documentElement.style.setProperty("--btn-border", btnBorder);
    document.documentElement.style.setProperty("--btn-text", fg);
    document.documentElement.style.setProperty("--btn-primary-bg", rgbToHex(primaryRgb));
    document.documentElement.style.setProperty("--btn-primary-border", primaryBorder);
    document.documentElement.style.setProperty("--btn-primary-text", primaryText);
  } else {
    document.documentElement.style.setProperty("--btn-bg", DEFAULT_THEME.btnBg);
    document.documentElement.style.setProperty("--btn-border", DEFAULT_THEME.btnBorder);
    document.documentElement.style.setProperty("--btn-text", DEFAULT_THEME.fg);
    document.documentElement.style.setProperty("--btn-primary-bg", DEFAULT_THEME.primaryBg);
    document.documentElement.style.setProperty("--btn-primary-border", DEFAULT_THEME.primaryBorder);
    document.documentElement.style.setProperty("--btn-primary-text", DEFAULT_THEME.primaryText);
  }
};

const resetPreferences = () => {
  const defaults = {
    logo_url: null,
    bg_color: null,
    fg_color: null,
    text_size: "medium",
    per_page: 25,
    show_cover: true,
    show_subtitle: true,
    show_series: true,
    show_is_hungarian: true,
    show_publisher: true,
    show_language: false,
    show_format: false,
    show_year: true,
    show_copy_count: false,
    show_status: true,
    show_placement: true,
    show_isbn: false,
    show_loaned_to: false,
    show_loaned_date: false,
    show_subjects: false,
    show_notes: false,
  };
  preferences.value = defaults;
  applyPreferences(defaults);
  perPage.value = 25;
  perPageSource.value = "default";
  page.value = 1;
};

const loadPreferences = async () => {
  try {
    const res = await fetchUserPreferences();
    const prefs = res?.data?.preferences || null;
    if (prefs) {
      preferences.value = { ...preferences.value, ...prefs };
      applyPreferences(preferences.value);
      if (perPageSource.value === "default" && prefs.per_page) {
        perPage.value = prefs.per_page;
      }
    }
  } catch (err) {
    console.warn("Preferences load failed", err);
  }
};

const reload = async () => {
  if (!user.value) return;
  loading.value = true;
  try {
    const resp = await fetchBooks({
      q: q.value || undefined,
      format: formatFilter.value || undefined,
      language: languageFilter.value || undefined,
      record_status: isAdmin.value ? recordStatusFilter.value : "active",
      page: page.value,
      per: perPage.value,
      sort: sort.value,
      dir: dir.value,
    });

    const data = resp && Array.isArray(resp.data) ? resp.data : [];
    const meta = resp && resp.meta ? resp.meta : {};

    rows.value = data;
    total.value = Number.isFinite(meta.total) ? meta.total : 0;
    page.value = Number.isFinite(meta.page) ? meta.page : 1;
    perPage.value = Number.isFinite(meta.per_page) ? meta.per_page : perPage.value;

    if (!ignorePopStateOnce.value) {
      const p = new URLSearchParams();
      if (q.value) p.set("q", q.value);
      if (formatFilter.value) p.set("format", formatFilter.value);
      if (languageFilter.value) p.set("language", languageFilter.value);
      if (isAdmin.value && recordStatusFilter.value !== "active") p.set("record_status", recordStatusFilter.value);
      if (page.value !== 1) p.set("page", String(page.value));
      if (perPage.value !== 25) p.set("per_page", String(perPage.value));
      if (sort.value !== "id") p.set("sort", sort.value);
      if (dir.value !== "desc") p.set("dir", dir.value);
      const s = p.toString();
      window.history.replaceState(null, "", `${window.location.pathname}${s ? "?" + s : ""}`);
    } else {
      ignorePopStateOnce.value = false;
    }
  } catch (err) {
    if (err && err.status === 401) {
      handleUnauthorized();
    } else {
      console.error("Reload failed", err);
    }
  } finally {
    loading.value = false;
  }
};

const applyUrlParams = () => {
  const p = new URLSearchParams(window.location.search);
  if (p.has("q")) {
    const qp = p.get("q") || "";
    q.value = qp;
    initialQueryParam.value = qp;
  } else {
    initialQueryParam.value = null;
  }
  formatFilter.value = p.get("format") || "";
  languageFilter.value = p.get("language") || "";
  recordStatusFilter.value = p.get("record_status") || "active";
  if (p.has("page")) page.value = Math.max(1, parseInt(p.get("page") || "1", 10) || 1);
  if (p.has("per_page")) {
    perPage.value = Math.max(1, parseInt(p.get("per_page") || "25", 10) || 25);
    perPageSource.value = "url";
  }
  if (p.has("sort")) sort.value = p.get("sort") || "id";
  if (p.has("dir")) dir.value = (p.get("dir") || "desc").toLowerCase() === "asc" ? "asc" : "desc";
};

const onPopState = () => {
  const sp = new URLSearchParams(location.search);
  ignorePopStateOnce.value = true;
  q.value = sp.get("q") || "";
  formatFilter.value = sp.get("format") || "";
  languageFilter.value = sp.get("language") || "";
  recordStatusFilter.value = sp.get("record_status") || "active";
  page.value = Math.max(1, parseInt(sp.get("page") || "1", 10) || 1);
  perPage.value = Math.max(1, parseInt(sp.get("per_page") || "25", 10) || 25);
  perPageSource.value = "url";
  sort.value = sp.get("sort") || "id";
  dir.value = (sp.get("dir") || "desc").toLowerCase() === "asc" ? "asc" : "desc";
  reload();
};

const onSearch = () => {
  page.value = 1;
  reload();
};

const clearSearch = () => {
  q.value = "";
  formatFilter.value = "";
  languageFilter.value = "";
  page.value = 1;
  reload();
};

const onFormatFilterChange = () => {
  page.value = 1;
  reload();
};

const onLanguageFilterChange = () => {
  page.value = 1;
  reload();
};

const onRecordStatusFilterChange = () => {
  page.value = 1;
  reload();
};

const scrubSearchAutofill = () => {
  if (initialQueryParam.value !== null) return false;
  const username = user.value?.username;
  if (!username) return false;
  const current = String(q.value || "").trim();
  if (current && current.toLowerCase() === String(username).toLowerCase()) {
    q.value = "";
    return true;
  }
  return false;
};

const scheduleSearchScrub = () => {
  searchAutofillTimers.value.forEach((timer) => window.clearTimeout(timer));
  searchAutofillTimers.value = [];
  searchAutofillTimers.value.push(window.setTimeout(() => {
    if (scrubSearchAutofill()) reload();
  }, 0));
  searchAutofillTimers.value.push(window.setTimeout(() => {
    if (scrubSearchAutofill()) reload();
  }, 250));
};

const onChangePage = (newPage) => {
  if (newPage === page.value) return;
  page.value = newPage;
  reload();
};

const onChangePerPage = (newPer) => {
  const n = parseInt(newPer, 10) || 25;
  if (n === perPage.value) return;
  perPage.value = n;
  perPageSource.value = "user";
  page.value = 1;
  if (user.value) {
    updateUserPreferences({ per_page: n }).catch((err) => {
      console.warn("Per-page save failed", err);
    });
    preferences.value = { ...preferences.value, per_page: n };
  }
  reload();
};

const onChangeSort = ({ sort: newSort, dir: newDir }) => {
  sort.value = newSort;
  dir.value = newDir;
  reload();
};

const resetSort = () => {
  sort.value = "title";
  dir.value = "asc";
  page.value = 1;
  reload();
};

const buildExportTimestamp = () => {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
};

const onExportSelectedBundle = async () => {
  if (!ensureAdmin()) return;
  const params = {
    ts: buildExportTimestamp(),
    sort: sort.value || "title",
    dir: dir.value || "asc",
  };
  if (q.value) params.q = q.value;
  if (recordStatusFilter.value) params.record_status = recordStatusFilter.value;
  const url = buildBackupUrl("export_selected_bundle.php", params);
  await runBackupFlow(url, "selected CSV + covers");
};

const onExportFullBackup = async () => {
  if (!ensureAdmin()) return;
  const url = buildBackupUrl("backup_full.php");
  await runBackupFlow(url, "full backup");
};

const loadFullBook = async (book) => {
  const id = book?.id || book?.book_id;
  if (!id) return book;
  const res = await fetchBook(id);
  return res && res.data ? res.data : book;
};

const onView = async (book) => {
  showDetail.value = true;
  selectedBook.value = null;
  try {
    selectedBook.value = await loadFullBook(book);
  } catch (err) {
    if (err && err.status === 401) {
      handleUnauthorized();
      return;
    }
    selectedBook.value = book;
    alert("Could not load full book details. Showing list data.");
  }
};

const closeDetail = () => {
  showDetail.value = false;
  selectedBook.value = null;
};

const onEdit = async (book) => {
  if (!ensureAdmin()) return;
  try {
    selected.value = await loadFullBook(book);
  } catch (err) {
    if (err && err.status === 401) {
      handleUnauthorized();
      return;
    }
    selected.value = book;
    alert("Could not load full book details. Editing list data.");
  }
  dialogMode.value = "edit";
  showDialog.value = true;
};

const openCsvImport = () => {
  if (!ensureAdmin()) return;
  showCsvImport.value = true;
};

const openOrphanMaintenance = () => {
  if (!ensureAdmin()) return;
  showOrphanMaintenance.value = true;
};

const openDuplicateCandidates = () => {
  if (!ensureAdmin()) return;
  const url = buildBackupUrl("duplicate_candidates.php", { status: "NEW" });
  window.location.href = url.toString();
};

const openAuthorsMaintenance = () => {
  if (!ensureAdmin()) return;
  showAuthorsMaintenance.value = true;
};

const openUserManagement = () => {
  if (!ensureAdmin()) return;
  showUserManagement.value = true;
};

const openAuthLogs = () => {
  if (!ensureAdmin()) return;
  showAuthLogs.value = true;
};

const onPurgeCatalog = async () => {
  if (!ensureAdmin()) return;

  const step1 = confirm(
    "This will delete ALL catalog records and ALL cover/thumbnail files. This cannot be undone. Continue?"
  );
  if (!step1) return;

  const typed = prompt("Type DELETE to confirm catalog purge:", "");
  if (typed === null) return;
  if (String(typed).trim() !== "DELETE") {
    alert("Confirmation text mismatch. Purge cancelled.");
    return;
  }

  try {
    const res = await purgeCatalog("DELETE");
    const data = res?.data || {};
    const deletedRows = data.deleted_rows || {};
    const removedBookRecords = Number(deletedRows.Books || 0);
    const removedItemInstances = Number(deletedRows.BookCopies || 0);
    const removedFiles = Number(data.deleted_upload_files || 0);
    const removedCoverFiles = Number(data.deleted_upload_cover_files || 0);
    const removedThumbFiles = Number(data.deleted_upload_thumb_files || 0);
    const removedOtherFiles = Number(data.deleted_upload_other_files || 0);
    const removedDirs = Number(data.deleted_upload_dirs || 0);
    alert(
      `Catalog purge completed.\nRemoved bibliographic records: ${removedBookRecords}\nRemoved item instances (print + ebook): ${removedItemInstances}\nRemoved cover files: ${removedCoverFiles}\nRemoved thumbnail files: ${removedThumbFiles}\nRemoved other upload files: ${removedOtherFiles}\nRemoved upload files total: ${removedFiles}\nRemoved upload dirs: ${removedDirs}`
    );
    page.value = 1;
    await reload();
  } catch (err) {
    if (err && err.status === 401) {
      handleUnauthorized();
      return;
    }
    alert(err && err.message ? err.message : "Catalog purge failed.");
  }
};

const openPreferences = () => {
  if (!user.value) return;
  showPreferences.value = true;
};

const onPreferencesSaved = (prefs) => {
  preferences.value = { ...preferences.value, ...prefs };
  applyPreferences(preferences.value);
  if (prefs?.per_page) {
    perPage.value = prefs.per_page;
    perPageSource.value = "user";
    if (user.value) reload();
  }
};

const openAdd = () => {
  if (!ensureAdmin()) return;
  selected.value = null;
  dialogMode.value = "create";
  showDialog.value = true;
};

const duplicateFrom = (book) => {
  if (!ensureAdmin()) return;
  const seed = {
    title: book.title || "",
    subtitle: book.subtitle || "",
    series: book.series || "",
    year_published: book.year_published ?? null,
    isbn: "",
    authors: book.authors || "",
    authors_hu_flag: book.authors_hu_flag ?? null,
    publisher: book.publisher || "",
    publisher_id: book.publisher_id ?? null,
  };
  selected.value = seed;
  dialogMode.value = "create";
  showDialog.value = true;
};

const onCloseDialog = () => {
  showDialog.value = false;
  selected.value = null;
};

const onSaveDialog = async (updated) => {
  if (!ensureAdmin()) return;
  try {
    await updateBook(updated);
    onCloseDialog();
    await reload();
  } catch (e) {
    if (e && e.status === 401) {
      handleUnauthorized();
      return;
    }
    alert(`Update failed: ${e.message}`);
  }
};

const onCreateDialog = async (payload, coverFile = null) => {
  if (!ensureAdmin()) return;
  try {
    const res = await addBook(payload, coverFile);
    alert(res.message || "Book created.");
    onCloseDialog();
    await reload();
  } catch (e) {
    if (e && e.status === 401) {
      handleUnauthorized();
      return;
    }
    alert(`Create failed: ${e.message}`);
  }
};

const onCsvImported = async (payload) => {
  await reload();
  if (!payload?.id_conflicts?.length) {
    showCsvImport.value = false;
  }
};

const describeCopyForDelete = (copy, index) => {
  const format = String(copy?.format || "print");
  const qty = Math.max(1, Number(copy?.quantity || 1));
  const location = String(copy?.physical_location || "").trim();
  const filePath = String(copy?.file_path || "").trim();
  const parts = [format === "print" ? `print x${qty}` : (qty > 1 ? `${format} x${qty}` : format)];
  if (location) parts.push(location);
  if (filePath) parts.push(filePath);
  return `${index + 1}. ${parts.join(" | ")}`;
};

const onDelete = async (book) => {
  if (!ensureAdmin()) return;
  const id = typeof book === "object" ? (book?.id ?? book?.book_id) : book;
  let fullBook = book;
  try {
    fullBook = await loadFullBook(book);
  } catch (e) {
    if (e && e.status === 401) {
      handleUnauthorized();
      return;
    }
  }

  const loaned_to = fullBook?.loaned_to ? String(fullBook.loaned_to).trim() : "";
  const loaned_date = fullBook?.loaned_date ? String(fullBook.loaned_date).trim() : "";
  const loaned = !!(loaned_to || loaned_date);
  const copies = Array.isArray(fullBook?.copies) ? fullBook.copies : [];
  let deleteMode = "book";
  let selectedCopy = null;

  if (copies.length > 1) {
    const options = copies.map((copy, index) => describeCopyForDelete(copy, index)).join("\n");
    const choice = prompt(
      `Choose what to delete for book #${id}:\n${options}\nA. Mark bibliographic record deleted\n\nEnter copy number or A. Leave empty to cancel.`,
      ""
    );
    if (choice === null) return;
    const trimmed = String(choice).trim();
    if (trimmed === "") return;
    if (/^a$/i.test(trimmed)) {
      deleteMode = "book";
    } else {
      const idx = Number.parseInt(trimmed, 10);
      if (!Number.isFinite(idx) || idx < 1 || idx > copies.length) {
        alert("Invalid selection.");
        return;
      }
      selectedCopy = copies[idx - 1];
      deleteMode = "copy";
    }
  }

  let msg = deleteMode === "copy"
    ? `Delete selected copy from book #${id}?`
    : `Mark book #${id} as deleted?`;
  if (loaned) {
    const parts = [];
    if (loaned_to) parts.push(`to ${loaned_to}`);
    if (loaned_date) parts.push(`on ${loaned_date}`);
    const extra = parts.length ? ` (${parts.join(" ")})` : "";
    msg = `${msg} Book is loaned and not in collection${extra}.`;
  }
  if (!confirm(msg)) return;
  try {
    if (deleteMode === "copy" && selectedCopy?.copy_id) {
      const res = await deleteBookCopy(Number(selectedCopy.copy_id));
      if (res?.data?.book_removed) {
        alert(`Book #${id}: last remaining copy removed, bibliographic record deleted.`);
      } else if (res?.data?.decremented) {
        alert(`Book #${id}: copy quantity decremented.`);
      } else {
        alert(`Book #${id}: selected copy removed. Remaining copies: ${res?.data?.copy_count ?? 0}.`);
      }
    } else {
      const res = await deleteBook(id);
      alert(res?.message || `Book #${id} marked deleted.`);
    }
    await reload();
  } catch (e) {
    if (e && e.status === 401) {
      handleUnauthorized();
      return;
    }
    alert("Delete failed. See console.");
    console.error(e);
  }
};

const onRestore = async (book) => {
  if (!ensureAdmin()) return;
  const id = typeof book === "object" ? (book?.id ?? book?.book_id) : book;
  if (!id) return;
  if (!confirm(`Restore book #${id}?`)) return;
  try {
    const res = await restoreBook(id);
    alert(res?.message || `Book #${id} restored.`);
    await reload();
  } catch (e) {
    if (e && e.status === 401) {
      handleUnauthorized();
      return;
    }
    alert(e && e.message ? e.message : "Restore failed.");
  }
};

const runThumbRebuild = async ({ limitOverride = null } = {}) => {
  rebuildThumbsDone.value = 0;
  rebuildThumbsTotal.value = 0;
  rebuildThumbsUpdated.value = 0;
  rebuildThumbsErrors.value = 0;
  rebuildThumbsErrorList.value = [];

  const batchSize = 10;
  let offset = 0;
  const limit = limitOverride ?? batchSize;
  while (true) {
    const params = new URLSearchParams({
      re: "1",
      h: "200",
      limit: String(limit),
      offset: String(offset),
    });
    const url = apiUrl(`rebuild_thumbs.php?${params.toString()}`);
    const res = await fetch(url, { credentials: "same-origin" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) throw new Error(data.error || "Rebuild failed");
    const payload = data?.data || {};
    const scanned = payload.scanned ?? 0;
    const updated = payload.updated ?? 0;
    const total = payload.total_dirs ?? 0;
    const errors = Array.isArray(payload.errors) ? payload.errors : [];

    if (!rebuildThumbsTotal.value && total) rebuildThumbsTotal.value = total;
    rebuildThumbsDone.value += scanned;
    rebuildThumbsUpdated.value += updated;
    if (errors.length) {
      rebuildThumbsErrors.value += errors.length;
      rebuildThumbsErrorList.value.push(...errors);
    }

    if (scanned <= 0) break;
    if (total && rebuildThumbsDone.value >= total) break;
    offset += batchSize;
  }
};

const onRebuildThumbs = async () => {
  if (!ensureAdmin()) return;
  if (!confirm("Rebuild cover thumbnails now?")) return;
  rebuildThumbsBusy.value = true;
  try {
    await runThumbRebuild({ limitOverride: 10 });

    let msg = [
      `Scanned: ${rebuildThumbsDone.value}`,
      `Updated: ${rebuildThumbsUpdated.value}`,
      `Errors: ${rebuildThumbsErrors.value}`,
    ].filter(Boolean).join("\n");
    if (rebuildThumbsErrorList.value.length) {
      const max = 10;
      const list = rebuildThumbsErrorList.value.slice(0, max);
      const more = rebuildThumbsErrorList.value.length > max
        ? `\n...and ${rebuildThumbsErrorList.value.length - max} more`
        : "";
      msg += `\n\nError details:\n- ${list.join("\n- ")}${more}`;
    }
    alert(msg || "Rebuild completed.");
  } catch (err) {
    alert(err && err.message ? err.message : "Rebuild failed.");
  } finally {
    rebuildThumbsBusy.value = false;
  }
};

onMounted(async () => {
  applyUrlParams();
  await initAuth();
  applyPreferences(preferences.value);
  window.addEventListener("popstate", onPopState);
  window.addEventListener("focus", scheduleSearchScrub);
});

onBeforeUnmount(() => {
  window.removeEventListener("popstate", onPopState);
  window.removeEventListener("focus", scheduleSearchScrub);
  searchAutofillTimers.value.forEach((timer) => window.clearTimeout(timer));
  searchAutofillTimers.value = [];
});

watch(user, async (next, prev) => {
  if (next && !prev) {
    await loadPreferences();
    reload();
    scheduleSearchScrub();
  } else if (!next) {
    resetPreferences();
  }
});

watch(showPreferences, (open, wasOpen) => {
  if (wasOpen && !open) {
    scheduleSearchScrub();
  }
});
</script>

<style>
* { box-sizing: border-box; }

:root {
  --app-bg: #f6e09f;
  --app-fg: #222222;
  --app-font-size: 15px;
  --panel-bg: var(--app-bg);
  --line: rgba(0,0,0,0.15);
  --btn-bg: #f9f3d4;
  --btn-border: #ccb66b;
  --btn-text: #222222;
  --btn-primary-bg: #2a72d4;
  --btn-primary-border: #2a72d4;
  --btn-primary-text: #ffffff;
}

body {
  margin: 0;
  font-family: "Trebuchet MS", "Verdana", "Arial", sans-serif;
  font-size: var(--app-font-size);
  background: var(--app-bg);
  color: var(--app-fg);
}

.container {
  max-width: min(1850px, 96vw);
  margin: 0 auto;
  padding: 0.8rem 1rem 1.25rem;
}

.topbar {
  display: grid;
  grid-template-columns: 120px 1fr 260px;
  align-items: center;
  gap: 0.5rem 1rem;
  margin-bottom: 0.6rem;
}

.logo-slot img {
  max-width: 96px;
  max-height: 96px;
  object-fit: contain;
  display: block;
}

.title-slot {
  text-align: center;
}

.brand-title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 700;
}

.app-version {
  font-size: 0.75em;
  font-weight: 600;
  color: rgba(0,0,0,0.6);
  margin-left: 0.35rem;
}

.signed-in {
  font-size: 0.85rem;
  opacity: 0.8;
}

.top-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.search {
  margin-bottom: 0.75rem;
}

.search-row {
  display: flex;
  gap: 0.4rem;
  align-items: center;
  flex-wrap: wrap;
  justify-content: center;
}

.search input {
  padding: 0.35rem 0.55rem;
  min-width: 260px;
  border: 1px solid var(--btn-border);
  border-radius: 6px;
  background: #fff;
}

.inline-filter {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.inline-filter select {
  padding: 0.35rem 0.45rem;
  border: 1px solid var(--btn-border);
  border-radius: 6px;
  background: #fff;
}

button,
.link-btn {
  padding: 0.3rem 0.6rem;
  cursor: pointer;
  border: 1px solid var(--btn-border);
  border-radius: 8px;
  background: var(--btn-bg);
  color: var(--btn-text);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

button.primary {
  background: var(--btn-primary-bg);
  border-color: var(--btn-primary-border);
  color: var(--btn-primary-text);
}

button.danger {
  background: #a8262f;
  border-color: #7e1c23;
  color: #ffffff;
}

button.ghost,
.link-btn.ghost {
  background: transparent;
}

.status {
  padding: 0.75rem 1rem;
  background: var(--panel-bg);
  border-radius: 10px;
}

.login-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2500;
}

.login-card {
  background: #fff;
  border-radius: 12px;
  padding: 1.5rem;
  width: min(360px, 92vw);
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.force-card {
  width: min(520px, 92vw);
}
.force-card .pw-section {
  margin-top: 0;
  padding-top: 0;
  border-top: none;
}

.login-card h2 {
  margin: 0;
}

.login-card label {
  display: flex;
  flex-direction: column;
  font-size: 0.9rem;
  gap: 0.3rem;
}

.login-card input {
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--btn-border);
  border-radius: 6px;
}

.login-card .actions {
  display: flex;
  justify-content: flex-end;
}

.login-card .error {
  color: #c0392b;
  font-size: 0.9rem;
}

.login-card .hint {
  font-size: 0.8rem;
  color: #666;
  text-align: center;
}

.busy-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.25);
  display: grid;
  place-items: center;
  z-index: 2600;
}

.busy-card {
  background: #fff;
  padding: 0.9rem 1.2rem;
  border-radius: 10px;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  box-shadow: 0 12px 30px rgba(0,0,0,0.2);
  border: 1px solid rgba(0,0,0,0.1);
}

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid #c9c9c9;
  border-top-color: #2a72d4;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@media (max-width: 900px) {
  .topbar {
    grid-template-columns: 1fr;
    text-align: center;
  }
  .logo-slot {
    display: flex;
    justify-content: center;
  }
  .top-actions {
    justify-content: center;
  }
  .title-slot {
    order: 2;
  }
}
</style>
