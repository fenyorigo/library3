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
            <option value="print">Print</option>
            <option value="ebooks">Ebooks</option>
            <option value="epub">EPUB</option>
            <option value="mobi">MOBI</option>
            <option value="pdf">PDF</option>
            <option value="djvu">DJVU</option>
            <option value="prc">PRC</option>
            <option value="rtf">RTF</option>
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
        <button v-if="isAdmin" @click="onExtractEbookCovers">Extract ebook covers</button>
        <button v-if="isAdmin" @click="onBuildEbookSha256">Build missing SHA256 checksums</button>
        <button v-if="isAdmin" @click="onIncrementalEbookRescan">Incremental ebook repository rescan</button>
        <button v-if="isAdmin" @click="onFullEbookIntegrityCheck">Full ebook integrity check</button>
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
        <button v-if="isAdmin" class="danger" :disabled="purgeBusy" @click="onPurgeCatalog">Purge catalog</button>
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
      @updated="onBookImageUpdated"
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
      :is-admin="isAdmin"
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

    <div v-if="extractCoversBusy" class="busy-overlay" aria-live="polite">
      <div class="busy-card">
        <div class="spinner" aria-hidden="true"></div>
        <div>
          Extracting ebook covers…
          <span v-if="extractCoversTotal"> {{ extractCoversDone }} / {{ extractCoversTotal }} ({{ extractCoversExtracted }} extracted)</span>
          <span v-else> {{ extractCoversDone }}</span>
        </div>
      </div>
    </div>

    <div v-if="sha256Busy" class="busy-overlay" aria-live="polite">
      <div class="busy-card busy-card-wide">
        <div class="spinner" aria-hidden="true"></div>
        <div>
          <div>Building ebook SHA256 checksums...</div>
          <div v-if="sha256Total">{{ sha256Processed }} / {{ sha256Total }} processed, {{ sha256Updated }} updated</div>
          <div v-else>{{ sha256Processed }} processed, {{ sha256Updated }} updated</div>
          <div class="busy-subline">Missing {{ sha256Missing }}, unreadable {{ sha256Unreadable }}, errors {{ sha256Errors }}</div>
        </div>
      </div>
    </div>

    <div v-if="rescanBusy" class="busy-overlay" aria-live="polite">
      <div class="busy-card busy-card-wide">
        <div class="spinner" aria-hidden="true"></div>
        <div>
          <div>Scanning ebook repository...</div>
          <div v-if="rescanTotal">{{ rescanProcessed }} / {{ rescanTotal }} files</div>
          <div v-else>Preparing scan...</div>
          <div class="busy-subline">New {{ rescanCounters.new_file_candidate || 0 }}, path changes {{ rescanCounters.same_sha_path_changed || 0 }}, metadata {{ rescanCounters.filename_metadata_mismatch || 0 }}, changed {{ rescanCounters.same_path_different_sha || 0 }}, missing {{ rescanCounters.missing_on_disk || 0 }}</div>
        </div>
      </div>
    </div>

    <div v-if="integrityBusy" class="busy-overlay" aria-live="polite">
      <div class="busy-card busy-card-wide">
        <div class="spinner" aria-hidden="true"></div>
        <div>
          <div>Checking ebook copy integrity...</div>
          <div v-if="integrityTotal">{{ integrityChecked }} / {{ integrityTotal }} copies</div>
          <div v-else>Preparing check...</div>
          <div class="busy-subline">OK {{ integrityCounters.ok || 0 }}, missing {{ integrityCounters.missing_on_disk || 0 }}, SHA mismatch {{ integrityCounters.sha_mismatch || 0 }}</div>
        </div>
      </div>
    </div>

    <div v-if="backupBusy" class="busy-overlay" aria-live="polite">
      <div class="busy-card">
        <div class="spinner" aria-hidden="true"></div>
        <div>{{ backupBusyMessage || "Preparing backup..." }}</div>
      </div>
    </div>

    <div v-if="purgeBusy" class="busy-overlay" aria-live="polite">
      <div class="busy-card">
        <div class="spinner" aria-hidden="true"></div>
        <div>Purging catalog...</div>
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
  bumpAssetCacheVersion,
  apiUrl,
  csrfHeader,
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
const extractCoversBusy = ref(false);
const extractCoversDone = ref(0);
const extractCoversTotal = ref(0);
const extractCoversExtracted = ref(0);
const sha256Busy = ref(false);
const sha256Total = ref(0);
const sha256Processed = ref(0);
const sha256Updated = ref(0);
const sha256Missing = ref(0);
const sha256Unreadable = ref(0);
const sha256Errors = ref(0);
const sha256Report = ref([]);
const rescanBusy = ref(false);
const rescanProcessed = ref(0);
const rescanTotal = ref(0);
const rescanCounters = ref({});
const rescanResults = ref({});
const integrityBusy = ref(false);
const integrityChecked = ref(0);
const integrityTotal = ref(0);
const integrityCounters = ref({});
const integrityResults = ref({});
const backupBusy = ref(false);
const backupBusyMessage = ref("");
const purgeBusy = ref(false);
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
    purgeBusy.value = true;
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
    bumpAssetCacheVersion();
    page.value = 1;
    await reload();
  } catch (err) {
    if (err && err.status === 401) {
      handleUnauthorized();
      return;
    }
    alert(err && err.message ? err.message : "Catalog purge failed.");
  } finally {
    purgeBusy.value = false;
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
    if (coverFile) bumpAssetCacheVersion();
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
  bumpAssetCacheVersion();
  await reload();
  if (!payload?.id_conflicts?.length) {
    showCsvImport.value = false;
  }
};

const onBookImageUpdated = async () => {
  bumpAssetCacheVersion();
  await reload();
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

const onExtractEbookCovers = async () => {
  if (!ensureAdmin()) return;
  if (!confirm("Extract covers from epub/pdf files for books that have no cover yet?")) return;
  extractCoversBusy.value = true;
  extractCoversDone.value = 0;
  extractCoversTotal.value = 0;
  extractCoversExtracted.value = 0;
  try {
    const batchSize = 5;
    let offset = 0;
    while (true) {
      const params = new URLSearchParams({
        limit: String(batchSize),
        offset: String(offset),
      });
      const url = apiUrl(`extract_ebook_covers.php?${params.toString()}`);
      const res = await fetch(url, { credentials: "same-origin" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) throw new Error(data.error || "Extraction failed");
      const payload = data?.data || {};
      const processed = payload.processed ?? 0;
      const extracted = payload.extracted ?? 0;
      const total = payload.total ?? 0;

      if (!extractCoversTotal.value && total) extractCoversTotal.value = total;
      extractCoversDone.value += processed;
      extractCoversExtracted.value += extracted;

      if (processed <= 0) break;
      if (total && extractCoversDone.value >= total) break;
      offset += batchSize;
    }
    alert(`Done.\nProcessed: ${extractCoversDone.value}\nExtracted: ${extractCoversExtracted.value}`);
  } catch (err) {
    alert(err && err.message ? err.message : "Extraction failed.");
  } finally {
    extractCoversBusy.value = false;
  }
};

const onBuildEbookSha256 = async () => {
  if (!ensureAdmin()) return;
  try {
    const checkUrl = apiUrl("build_ebook_sha256.php?check=1");
    const checkRes = await fetch(checkUrl, { credentials: "same-origin" });
    const checkJson = await checkRes.json().catch(() => ({}));
    if (!checkRes.ok || checkJson.ok === false) throw new Error(checkJson.error || "SHA256 check failed");
    const info = checkJson?.data || {};
    const root = info.ebook_library_root || "";
    const missing = Number(info.missing_sha256 || 0);
    if (missing <= 0) {
      alert(`No missing SHA256 checksums.\nRoot: ${root}`);
      return;
    }
    if (!confirm(`Build missing SHA256 checksums now?\n\nRoot: ${root}\nMissing checksums: ${missing}`)) return;

    sha256Busy.value = true;
    sha256Total.value = missing;
    sha256Processed.value = 0;
    sha256Updated.value = 0;
    sha256Missing.value = 0;
    sha256Unreadable.value = 0;
    sha256Errors.value = 0;
    sha256Report.value = [];

    const batchSize = 25;
    let afterCopyId = 0;
    let remaining = missing;
    while (true) {
      const res = await fetch(apiUrl("build_ebook_sha256.php"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          ...csrfHeader(),
        },
        body: JSON.stringify({ limit: batchSize, after_copy_id: afterCopyId }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json.ok === false) throw new Error(json.error || "SHA256 build failed");
      const payload = json?.data || {};
      const processed = Number(payload.processed || 0);
      sha256Processed.value += processed;
      sha256Updated.value += Number(payload.updated || 0);
      sha256Missing.value += Number(payload.missing || 0);
      sha256Unreadable.value += Number(payload.unreadable || 0);
      sha256Errors.value += Number(payload.errors || 0);
      remaining = Number(payload.remaining || 0);
      afterCopyId = Number(payload.next_after_copy_id || afterCopyId);
      if (Array.isArray(payload.report)) {
        sha256Report.value.push(...payload.report.filter((item) => item.status !== "updated"));
        if (sha256Report.value.length > 100) sha256Report.value = sha256Report.value.slice(0, 100);
      }

      if (payload.done || processed <= 0) break;
    }

    let msg = [
      "SHA256 build completed.",
      `Initial missing: ${sha256Total.value}`,
      `Processed: ${sha256Processed.value}`,
      `Updated: ${sha256Updated.value}`,
      `Missing files: ${sha256Missing.value}`,
      `Unreadable files: ${sha256Unreadable.value}`,
      `Errors: ${sha256Errors.value}`,
      `Remaining NULL SHA256: ${remaining}`,
    ].join("\n");
    if (sha256Report.value.length) {
      const lines = sha256Report.value.slice(0, 15).map((item) => `#${item.copy_id} ${item.status}: ${item.file_path}${item.error ? ` (${item.error})` : ""}`);
      const more = sha256Report.value.length > 15 ? `\n...and ${sha256Report.value.length - 15} more` : "";
      msg += `\n\nProblem report:\n- ${lines.join("\n- ")}${more}`;
    }
    alert(msg);
  } catch (err) {
    alert(err && err.message ? err.message : "SHA256 build failed.");
  } finally {
    sha256Busy.value = false;
  }
};

const rescanPost = async (payload) => {
  const res = await fetch(apiUrl("rescan_ebook_repository.php"), {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      ...csrfHeader(),
    },
    body: JSON.stringify(payload),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.ok === false) throw new Error(json.error || "Repository rescan failed");
  return json.data || {};
};

const mergeRescanResults = (items = []) => {
  for (const item of items) {
    const status = item.status || "errors";
    if (!Array.isArray(rescanResults.value[status])) rescanResults.value[status] = [];
    rescanResults.value[status].push(item);
    if (rescanResults.value[status].length > 200) rescanResults.value[status] = rescanResults.value[status].slice(0, 200);
  }
};

const rescanSummaryText = () => {
  const c = rescanCounters.value || {};
  const lines = [
    "Incremental ebook rescan completed.",
    `Scanned files: ${rescanProcessed.value}`,
    `Unchanged: ${c.unchanged || 0}`,
    `Same SHA path changed: ${c.same_sha_path_changed || 0}`,
    `Filename metadata mismatch: ${c.filename_metadata_mismatch || 0}`,
    `New file candidates: ${c.new_file_candidate || 0}`,
    `Same path, different SHA: ${c.same_path_different_sha || 0}`,
    `Duplicate SHA in database: ${c.duplicate_sha_in_database || 0}`,
    `Duplicate file on disk: ${c.duplicate_file_on_disk || 0}`,
    `Missing on disk: ${c.missing_on_disk || 0}`,
    `Errors: ${c.errors || 0}`,
  ];
  for (const group of ["same_sha_path_changed", "filename_metadata_mismatch", "new_file_candidate", "same_path_different_sha", "duplicate_sha_in_database", "duplicate_file_on_disk", "missing_on_disk", "errors"]) {
    const items = rescanResults.value[group] || [];
    if (!items.length) continue;
    lines.push("", `${group}:`);
    for (const item of items.slice(0, 8)) {
      lines.push(`- ${item.copy_id ? `#${item.copy_id} ` : ""}${item.old_file_path ? `${item.old_file_path} -> ${item.new_file_path}` : (item.file_path || item.absolute_path || item.error || "")}`);
    }
    if (items.length > 8) lines.push(`...and ${items.length - 8} more`);
  }
  return lines.join("\n");
};

const onIncrementalEbookRescan = async () => {
  if (!ensureAdmin()) return;
  try {
    const infoRes = await fetch(apiUrl("rescan_ebook_repository.php"), { credentials: "same-origin" });
    const infoJson = await infoRes.json().catch(() => ({}));
    if (!infoRes.ok || infoJson.ok === false) throw new Error(infoJson.error || "Repository rescan check failed");
    const info = infoJson.data || {};
    if (!confirm(`Run incremental ebook repository rescan?\n\nRoot: ${info.scan_root || ""}`)) return;

    rescanBusy.value = true;
    rescanProcessed.value = 0;
    rescanTotal.value = 0;
    rescanCounters.value = {};
    rescanResults.value = {};

    const start = await rescanPost({ action: "start" });
    const token = start.token;
    rescanTotal.value = Number(start.total_files || 0);
    rescanCounters.value = start.counters || {};

    const batchSize = 20;
    while (true) {
      const payload = await rescanPost({ action: "next", token, limit: batchSize });
      rescanProcessed.value = Number(payload.offset || rescanProcessed.value);
      rescanTotal.value = Number(payload.total_files || rescanTotal.value);
      rescanCounters.value = payload.counters || rescanCounters.value;
      mergeRescanResults(payload.results || []);
      if (payload.done || Number(payload.processed || 0) <= 0) break;
    }

    alert(rescanSummaryText());

    const newCandidates = rescanResults.value.new_file_candidate || [];
    if (newCandidates.length && confirm(`Export ${newCandidates.length} new file candidate(s) to an import CSV now?\n\nThe CSV can be imported with Import books; it includes the catalog path, file size, and SHA256.`)) {
      const exported = await rescanPost({ action: "export_new_candidates_csv", token, items: newCandidates });
      if (exported.csv) downloadIntegrityCsv(exported.filename || "ebook_new_candidates.csv", exported.csv);
      const warnings = exported.warnings || [];
      alert(`New candidate CSV rows: ${exported.rows || 0}${warnings.length ? `\nWarnings: ${warnings.length}` : ""}`);
    }

    if ((rescanCounters.value.duplicate_file_on_disk || 0) > 0 && confirm(`Download duplicate files on disk CSV report?\n\nDuplicates are grouped by identical SHA256 content.`)) {
      const exported = await rescanPost({ action: "export_duplicate_files_csv", token });
      if (exported.csv) downloadIntegrityCsv(exported.filename || "ebook_duplicate_files.csv", exported.csv);
      alert(`Duplicate SHA groups: ${exported.groups || 0}\nCSV rows: ${exported.rows || 0}`);
    }

    const moved = rescanResults.value.same_sha_path_changed || [];
    if (moved.length && confirm(`Apply ${moved.length} same-SHA path change updates now?\n\nThis only updates BookCopies.file_path, not bibliographic metadata.`)) {
      const applied = await rescanPost({ action: "apply_path_updates", token, items: moved });
      alert(`Path updates applied: ${applied.updated || 0}`);
    }

    const metadataMap = new Map();
    for (const item of rescanResults.value.filename_metadata_mismatch || []) {
      metadataMap.set(`${item.book_id || ""}:${item.file_path || item.new_file_path || ""}`, item);
    }
    for (const item of moved) {
      if (!item.metadata_review_recommended || item.metadata_parse_error) continue;
      metadataMap.set(`${item.book_id || ""}:${item.new_file_path || item.file_path || ""}`, item);
    }
    const metadataCandidates = Array.from(metadataMap.values());
    if (metadataCandidates.length && confirm(`Update title/subtitle/series/language from filename for ${metadataCandidates.length} book(s)?\n\nThis does not update authors, subjects, publisher, cover, or notes.`)) {
      const applied = await rescanPost({ action: "apply_filename_metadata_updates", token, items: metadataCandidates });
      const warnings = applied.warnings || [];
      alert(`Filename metadata rows processed: ${applied.processed || 0}\nBooks changed: ${applied.updated || 0}${warnings.length ? `\nWarnings: ${warnings.length}` : ""}`);
    }

    const changed = rescanResults.value.same_path_different_sha || [];
    if (changed.length && confirm(`Apply ${changed.length} same-path SHA/file_size updates now?\n\nUse only if these are intentional file content changes.`)) {
      const applied = await rescanPost({ action: "apply_sha_updates", token, items: changed });
      alert(`SHA updates applied: ${applied.updated || 0}`);
    }
  } catch (err) {
    alert(err && err.message ? err.message : "Repository rescan failed.");
  } finally {
    rescanBusy.value = false;
  }
};

const integrityPost = async (payload) => {
  const res = await fetch(apiUrl("check_ebook_integrity.php"), {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      ...csrfHeader(),
    },
    body: JSON.stringify(payload),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.ok === false) throw new Error(json.error || "Integrity check failed");
  return json.data || {};
};

const mergeIntegrityResults = (items = []) => {
  for (const item of items) {
    const status = item.status || "errors";
    if (!Array.isArray(integrityResults.value[status])) integrityResults.value[status] = [];
    integrityResults.value[status].push(item);
    if (integrityResults.value[status].length > 300) integrityResults.value[status] = integrityResults.value[status].slice(0, 300);
  }
};

const integritySummaryText = () => {
  const c = integrityCounters.value || {};
  const lines = [
    "Full ebook integrity check completed.",
    `Checked: ${integrityChecked.value}`,
    `OK: ${c.ok || 0}`,
    `SHA missing: ${c.sha_missing || 0}`,
    `Missing on disk: ${c.missing_on_disk || 0}`,
    `SHA mismatch: ${c.sha_mismatch || 0}`,
    `OK SHA, size mismatch: ${c.ok_sha_size_mismatch || 0}`,
    `Errors: ${c.errors || 0}`,
  ];
  for (const group of ["sha_missing", "missing_on_disk", "sha_mismatch", "ok_sha_size_mismatch", "errors"]) {
    const items = integrityResults.value[group] || [];
    if (!items.length) continue;
    lines.push("", `${group}:`);
    for (const item of items.slice(0, 8)) {
      lines.push(`- ${item.copy_id ? `#${item.copy_id} ` : ""}${item.file_path || item.error || ""}`);
    }
    if (items.length > 8) lines.push(`...and ${items.length - 8} more`);
  }
  return lines.join("\n");
};

const downloadIntegrityCsv = (filename, csv) => {
  const blob = new Blob([csv || ""], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename || "ebook_integrity_report.csv";
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
};

const onFullEbookIntegrityCheck = async () => {
  if (!ensureAdmin()) return;
  try {
    const infoRes = await fetch(apiUrl("check_ebook_integrity.php"), { credentials: "same-origin" });
    const infoJson = await infoRes.json().catch(() => ({}));
    if (!infoRes.ok || infoJson.ok === false) throw new Error(infoJson.error || "Integrity check setup failed");
    const info = infoJson.data || {};
    if (!confirm(`Run full ebook integrity check?\n\nRoot: ${info.scan_root || ""}\nCopies to check: ${info.total_copies || 0}`)) return;

    integrityBusy.value = true;
    integrityChecked.value = 0;
    integrityTotal.value = 0;
    integrityCounters.value = {};
    integrityResults.value = {};

    const start = await integrityPost({ action: "start" });
    const token = start.token;
    integrityTotal.value = Number(start.total_copies || 0);
    integrityCounters.value = start.counters || {};

    const batchSize = 25;
    while (true) {
      const payload = await integrityPost({ action: "next", token, limit: batchSize });
      integrityChecked.value = Number(payload.checked || integrityChecked.value);
      integrityTotal.value = Number(payload.total_copies || integrityTotal.value);
      integrityCounters.value = payload.counters || integrityCounters.value;
      mergeIntegrityResults(payload.results || []);
      if (payload.done || Number(payload.processed || 0) <= 0) break;
    }

    alert(integritySummaryText());

    const missingSha = integrityResults.value.sha_missing || [];
    if (missingSha.length && confirm(`Populate ${missingSha.length} missing SHA256 values now?`)) {
      const applied = await integrityPost({ action: "populate_missing_sha", token, items: missingSha });
      alert(`SHA values populated: ${applied.updated || 0}`);
    }

    const sizeMismatch = integrityResults.value.ok_sha_size_mismatch || [];
    if (sizeMismatch.length && confirm(`Refresh file_size for ${sizeMismatch.length} OK-SHA rows now?`)) {
      const applied = await integrityPost({ action: "refresh_file_size", token, items: sizeMismatch });
      alert(`File sizes refreshed: ${applied.updated || 0}`);
    }

    const shaMismatch = integrityResults.value.sha_mismatch || [];
    if (shaMismatch.length && confirm(`Update SHA256/file_size for ${shaMismatch.length} mismatched rows now?\n\nUse only after confirming these are intentional file content changes.`)) {
      const applied = await integrityPost({ action: "update_mismatched_sha", token, items: shaMismatch });
      alert(`Mismatched SHA rows updated: ${applied.updated || 0}`);
    }

    const missingDisk = integrityResults.value.missing_on_disk || [];
    if (missingDisk.length && confirm(`Mark ${missingDisk.length} missing-on-disk copies as unavailable in notes?\n\nThis does not delete records.`)) {
      const applied = await integrityPost({ action: "mark_missing_unavailable", token, items: missingDisk });
      alert(`Missing copies marked in notes: ${applied.updated || 0}`);
    }

    if (confirm("Download integrity report CSV?")) {
      const exported = await integrityPost({ action: "export_csv", token });
      downloadIntegrityCsv(exported.filename, exported.csv);
    }
  } catch (err) {
    alert(err && err.message ? err.message : "Integrity check failed.");
  } finally {
    integrityBusy.value = false;
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
.busy-card-wide { min-width: min(520px, 92vw); }
.busy-subline { margin-top: 0.25rem; font-size: 0.88rem; color: #555; }

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
