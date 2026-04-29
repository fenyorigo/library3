<template>
  <section class="card">

    <!-- Top toolbar -->
    <div class="toolbar">
      <div class="pager">
        <button :disabled="loading || page <= 1" @click="emit('change-page', 1)">« First</button>
        <button :disabled="loading || page <= 1" @click="emit('change-page', page - 1)">‹ Prev</button>
        <span>Page {{ page }} / {{ maxPage }}</span>
        <button :disabled="loading || page >= maxPage" @click="emit('change-page', page + 1)">Next ›</button>
        <button :disabled="loading || page >= maxPage" @click="emit('change-page', maxPage)">Last »</button>
        <form class="goto" @submit.prevent="goToPage">
          <input
            v-model.number="gotoPage"
            :disabled="loading || maxPage <= 1"
            type="number"
            min="1"
            :max="maxPage"
            placeholder="Page"
          />
          <button type="submit" :disabled="loading || maxPage <= 1">Go</button>
        </form>
      </div>

      <div class="pagesize">
        <label>Per page</label>
        <select :disabled="loading" :value="perPage" @change="emit('change-per-page', Number($event.target.value))">
          <option>10</option>
          <option>25</option>
          <option>50</option>
          <option>100</option>
        </select>
        <span class="muted">{{ resultsSummary }}</span>

      </div>
    </div>

    <!-- Loader -->
    <div v-if="loading">Loading…</div>

    <!-- Table -->
    <div v-else class="table-wrap">
      <table class="table">
        <thead>
        <tr>
          <th class="w-id" :aria-sort="ariaSort('id')">
            <button class="th-btn" @click.prevent="toggleSort('id')">
              <span>ID</span><span class="chev">{{ chevron('id') }}</span>
            </button>
          </th>

          <th v-if="columns.show_cover" class="w-cover" :aria-sort="ariaSort('cover')">
            <button class="th-btn" @click.prevent="toggleSort('cover')">
              <span>Cover</span><span class="chev">{{ chevron('cover') }}</span>
            </button>
          </th>

          <th :aria-sort="ariaSort('title')">
            <button class="th-btn" @click.prevent="toggleSort('title')">
              <span>Title</span><span class="chev">{{ chevron('title') }}</span>
            </button>
          </th>

          <th v-if="columns.show_subtitle" :aria-sort="ariaSort('subtitle')">
            <button class="th-btn" @click.prevent="toggleSort('subtitle')">
              <span>Subtitle</span><span class="chev">{{ chevron('subtitle') }}</span>
            </button>
          </th>

          <th v-if="columns.show_series" :aria-sort="ariaSort('series')">
            <button class="th-btn" @click.prevent="toggleSort('series')">
              <span>Series</span><span class="chev">{{ chevron('series') }}</span>
            </button>
          </th>

          <th :aria-sort="ariaSort('authors')">
            <button class="th-btn" @click.prevent="toggleSort('authors')">
              <span>Authors</span><span class="chev">{{ chevron('authors') }}</span>
            </button>
          </th>

          <th v-if="columns.show_is_hungarian" class="w-hu" :aria-sort="ariaSort('authors_hu')">
            <button class="th-btn" @click.prevent="toggleSort('authors_hu')">
              <span>HU</span><span class="chev">{{ chevron('authors_hu') }}</span>
            </button>
          </th>

          <th v-if="columns.show_publisher" :aria-sort="ariaSort('publisher')">
            <button class="th-btn" @click.prevent="toggleSort('publisher')">
              <span>Publisher</span><span class="chev">{{ chevron('publisher') }}</span>
            </button>
          </th>
          <th v-if="columns.show_language" :aria-sort="ariaSort('language')">
            <button class="th-btn" @click.prevent="toggleSort('language')">
              <span>Language</span><span class="chev">{{ chevron('language') }}</span>
            </button>
          </th>
          <th v-if="columns.show_format" :aria-sort="ariaSort('format')">
            <button class="th-btn" @click.prevent="toggleSort('format')">
              <span>Format</span><span class="chev">{{ chevron('format') }}</span>
            </button>
          </th>

          <th v-if="columns.show_year" class="w-year" :aria-sort="ariaSort('year')">
            <button class="th-btn" @click.prevent="toggleSort('year')">
              <span>Year</span><span class="chev">{{ chevron('year') }}</span>
            </button>
          </th>

          <th v-if="columns.show_copy_count" class="w-year" :aria-sort="ariaSort('copy_count')">
            <button class="th-btn" @click.prevent="toggleSort('copy_count')">
              <span>Copies</span><span class="chev">{{ chevron('copy_count') }}</span>
            </button>
          </th>

          <th v-if="columns.show_status" class="w-status" :aria-sort="ariaSort('status')">
            <button class="th-btn" @click.prevent="toggleSort('status')">
              <span>Status</span><span class="chev">{{ chevron('status') }}</span>
            </button>
          </th>

          <th v-if="columns.show_placement" :aria-sort="ariaSort('bookcase')">
            <button class="th-btn" @click.prevent="toggleSort('bookcase')">
              <span>Placement</span><span class="chev">{{ chevron('bookcase') }}</span>
            </button>
          </th>

          <th v-if="columns.show_isbn" :aria-sort="ariaSort('isbn')">
            <button class="th-btn" @click.prevent="toggleSort('isbn')">
              <span>ISBN</span><span class="chev">{{ chevron('isbn') }}</span>
            </button>
          </th>
          <th v-if="columns.show_loaned_to" :aria-sort="ariaSort('loaned_to')">
            <button class="th-btn" @click.prevent="toggleSort('loaned_to')">
              <span>Loaned to</span><span class="chev">{{ chevron('loaned_to') }}</span>
            </button>
          </th>
          <th v-if="columns.show_loaned_date" :aria-sort="ariaSort('loaned_date')">
            <button class="th-btn" @click.prevent="toggleSort('loaned_date')">
              <span>Loaned date</span><span class="chev">{{ chevron('loaned_date') }}</span>
            </button>
          </th>
          <th v-if="columns.show_subjects" :aria-sort="ariaSort('subjects')">
            <button class="th-btn" @click.prevent="toggleSort('subjects')">
              <span>Subjects</span><span class="chev">{{ chevron('subjects') }}</span>
            </button>
          </th>
          <th v-if="columns.show_notes" :aria-sort="ariaSort('notes')">
            <button class="th-btn" @click.prevent="toggleSort('notes')">
              <span>Notes</span><span class="chev">{{ chevron('notes') }}</span>
            </button>
          </th>

          <th class="w-actions">Actions</th>
        </tr>
        </thead>

        <tbody>

        <tr v-for="b in rows" :key="b.id">
          <td>{{ b.id }}</td>
          <td v-if="columns.show_cover" class="cover-flag">
            <span v-if="b.has_cover" class="cover-ok">✔</span>
            <span v-else class="cover-missing">✗</span>
          </td>

          <!-- Title (with cover thumbnail) -->
          <td>
            <div class="titlecell">
              <div
                class="thumbwrap"
                :class="{ 'missing-cover': !b.has_cover }"
                @click="emit('view', b)"
                title="View details"
              >
                <img :src="coverSrc(b)" class="thumb" alt="Cover" />
              </div>
              <div class="twrap">
                <div><strong>{{ b.title }}</strong></div>
                <div class="muted small" v-if="b.format_summary">{{ b.format_summary }}</div>
              </div>
            </div>
          </td>

          <!-- Subtitle column -->
          <td v-if="columns.show_subtitle" class="subtitle-cell">{{ b.subtitle || '—' }}</td>

          <td v-if="columns.show_series" class="series-cell">{{ b.series || '—' }}</td>

          <td class="authors-cell">{{ b.authors }}</td>
          <td v-if="columns.show_is_hungarian" class="hu-cell">
            <span v-if="formatHu(b) === 'Mixed'" class="badge mixed">Mixed</span>
            <span v-else>{{ formatHu(b) }}</span>
          </td>
          <td v-if="columns.show_publisher" class="publisher-cell">{{ b.publisher || '—' }}</td>
          <td v-if="columns.show_language">{{ b.language || 'unknown' }}</td>
          <td v-if="columns.show_format">{{ b.format_summary || '—' }}</td>
          <td v-if="columns.show_year">{{ b.year_published || '—' }}</td>
          <td v-if="columns.show_copy_count">{{ b.copy_count || 1 }}</td>

          <td v-if="columns.show_status" class="status-cell">{{ b.loan_status || '—' }}</td>

          <td v-if="columns.show_placement">
              <span v-if="b.bookcase_no != null && b.shelf_no != null">
                #{{ b.bookcase_no }}/{{ b.shelf_no }}
              </span>
          </td>

          <td v-if="columns.show_isbn" class="isbn-cell">{{ b.isbn || '—' }}</td>
          <td v-if="columns.show_loaned_to" class="loaned-to-cell">{{ b.loaned_to || '—' }}</td>
          <td v-if="columns.show_loaned_date" class="loaned-date-cell">{{ b.loaned_date || '—' }}</td>
          <td v-if="columns.show_subjects" class="subjects-cell">{{ b.subjects || '—' }}</td>
          <td v-if="columns.show_notes" class="notes-cell">{{ b.notes || '—' }}</td>

          <td class="actions">
            <button @click="emit('view', b)">View</button>
            <template v-if="isAdmin">
              <button @click="emit('edit', b)">Edit</button>
              <button @click="emit('duplicate', b)">Duplicate</button>
              <button v-if="b.record_status === 'deleted'" @click="emit('restore', b)">Restore</button>
              <button v-else @click="emit('delete', b)">Delete</button>
            </template>
          </td>
        </tr>

        <!-- empty -->
        <tr v-if="!rows || rows.length === 0">
          <td :colspan="visibleColCount" class="muted">No results.</td>
        </tr>

        </tbody>
      </table>
    </div>

    <!-- Bottom toolbar -->
    <div class="toolbar toolbar-bottom">
      <div class="pager">
        <button :disabled="loading || page <= 1" @click="emit('change-page', 1)">« First</button>
        <button :disabled="loading || page <= 1" @click="emit('change-page', page - 1)">‹ Prev</button>
        <span>Page {{ page }} / {{ maxPage }}</span>
        <button :disabled="loading || page >= maxPage" @click="emit('change-page', page + 1)">Next ›</button>
        <button :disabled="loading || page >= maxPage" @click="emit('change-page', maxPage)">Last »</button>
        <form class="goto" @submit.prevent="goToPage">
          <input
            v-model.number="gotoPage"
            :disabled="loading || maxPage <= 1"
            type="number"
            min="1"
            :max="maxPage"
            placeholder="Page"
          />
          <button type="submit" :disabled="loading || maxPage <= 1">Go</button>
        </form>
      </div>

      <div class="pagesize">
        <label>Per page</label>
        <select :disabled="loading" :value="perPage" @change="emit('change-per-page', Number($event.target.value))">
          <option>10</option>
          <option>25</option>
          <option>50</option>
          <option>100</option>
        </select>
        <span class="muted">{{ resultsSummary }}</span>
      </div>
    </div>

  </section>
</template>

<script setup lang="js">
import { computed, ref, toRefs } from "vue";
import { assetUrl } from "../api";

const emit = defineEmits([
  "change-page",
  "change-per-page",
  "change-sort",
  "view",
  "edit",
  "duplicate",
  "delete",
  "restore",
]);

const props = defineProps({
  rows: { type: Array, default: () => [] },
  total: { type: Number, default: 0 },
  page: { type: Number, default: 1 },
  perPage: { type: Number, default: 25 },
  sort: { type: String, default: "" },
  dir: { type: String, default: "" },
  loading: { type: Boolean, default: false },
  q: { type: String, default: "" },
  isAdmin: { type: Boolean, default: false },
  columns: { type: Object, default: () => ({}) },
});

const { rows, total, page, perPage, sort, dir, loading, isAdmin } = toRefs(props);

const columnDefaults = {
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

const columns = computed(() => {
  const prefs = props.columns || {};
  const normalized = { ...columnDefaults };
  Object.keys(columnDefaults).forEach((key) => {
    if (typeof prefs[key] === "boolean") normalized[key] = prefs[key];
  });
  return normalized;
});

const visibleColCount = computed(() => {
  let count = 4; // id, title, authors, actions
  Object.values(columns.value).forEach((val) => {
    if (val) count += 1;
  });
  return count;
});

const gotoPage = ref(null);

const maxPage = computed(() => {
  const per = perPage.value || 1;
  return Math.max(1, Math.ceil((total.value || 0) / per));
});

const resultsSummary = computed(() => {
  const totalCount = Number(total.value || 0);
  if (totalCount <= 0) return "Results: 0 of 0 (0 images)";

  const per = Math.max(1, Number(perPage.value || 1));
  const currentPage = Math.max(1, Number(page.value || 1));
  const start = ((currentPage - 1) * per) + 1;
  const end = Math.min(currentPage * per, totalCount);

  return `Results: ${start}-${end} of ${totalCount} (${totalCount} images)`;
});

const formatHu = (book) => {
  if (!book || !book.authors) return "—";
  if (book.authors_hu_flag === null || book.authors_hu_flag === undefined) return "Mixed";
  return book.authors_hu_flag ? "Yes" : "No";
};

const toggleSort = (col) => {
  let next = "asc";
  if (sort.value === col) next = dir.value === "asc" ? "desc" : "asc";
  emit("change-sort", { sort: col, dir: next });
};

const chevron = (col) => {
  if (sort.value !== col) return "";
  return dir.value === "asc" ? "▲" : "▼";
};

const ariaSort = (col) => {
  if (sort.value !== col) return "none";
  return dir.value === "asc" ? "ascending" : "descending";
};

const goToPage = () => {
  let target = parseInt(gotoPage.value, 10);
  if (!Number.isFinite(target)) return;
  if (target < 1) target = 1;
  if (target > maxPage.value) target = maxPage.value;
  gotoPage.value = null;
  if (target === page.value) return;
  emit("change-page", target);
};

const coverSrc = (book) => {
  const fallback = "uploads/default-cover.jpg";
  const raw = (book && (book.cover_thumb || book.cover_image)) || fallback;
  return assetUrl(raw);
};
</script>

<style>
.table-wrap {
  overflow-x: auto;
}

/* wide table */
.table {
  width: 100%;
  min-width: 1500px;
  border-collapse: collapse;
  background: var(--panel-bg);
}

th, td {
  border-bottom: 1px solid var(--line);
  padding: 0.45rem 0.55rem;
  text-align: left;
  vertical-align: top;
}
thead th {
  font-weight: 700;
  font-size: 0.9rem;
}

.w-id { width: 4rem; white-space: nowrap; }
.w-cover { width: 4.5rem; white-space: nowrap; }
.w-year { width: 5rem; white-space: nowrap; }
.w-status { width: 7.5rem; white-space: nowrap; }
.w-hu { width: 4rem; white-space: nowrap; text-align: center; }
.w-actions { width: 11rem; white-space: nowrap; }

.actions {
  display: flex;
  gap: 0.4rem;
  white-space: nowrap;
}
.actions button {
  background: var(--btn-bg);
  border: 1px solid var(--btn-border);
  padding: 0.2rem 0.55rem;
  border-radius: 8px;
}

.titlecell {
  display: flex;
  gap: 0.5rem;
}

.thumbwrap {
  border-radius: 6px;
  padding: 2px;
}

.thumbwrap.missing-cover {
  border: 1px dashed #c77a2a;
  background: rgba(230, 126, 34, 0.12);
}

.thumb {
  width: 40px;
  height: 56px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid #ddd;
}

.subtitle-cell,
.series-cell,
.authors-cell,
.publisher-cell,
.status-cell,
.hu-cell,
.isbn-cell,
.loaned-to-cell,
.loaned-date-cell,
.subjects-cell,
.notes-cell {
  max-width: 300px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hu-cell { text-align: center; }
.badge {
  display: inline-block;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  font-size: 0.78rem;
  line-height: 1.2;
}
.badge.mixed {
  background: #fff3cd;
  color: #7a5d00;
  border: 1px solid #d9c075;
}

.muted { opacity: 0.7; }
.small { font-size: 0.85em; }

.cover-flag {
  text-align: center;
  font-size: 1.1rem;
}

.cover-ok { color: #1fa660; }
.cover-missing { color: #c0392b; }

.th-btn {
  background: none;
  border: none;
  font: inherit;
  cursor: pointer;
  display: inline-flex;
  gap: 0.3rem;
}

.chev { opacity: 0.6; }
.toolbar {
  display: flex;
  justify-content: center;
  align-items: center;
  margin-bottom: .35rem;
  gap: 0.6rem;
  flex-wrap: wrap;
}
.toolbar-bottom {
  margin-top: .45rem;
  margin-bottom: 0;
}

.pagesize, .pager {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.pager .goto {
  display: flex;
  gap: 0.3rem;
  align-items: center;
}

.pager .goto input {
  width: 4.5rem;
  padding: 0.2rem 0.3rem;
}

button { padding: .28rem .55rem; border-radius: 8px; }

.pager button,
.pagesize select,
.pager input {
  border: 1px solid var(--btn-border);
  border-radius: 6px;
  background: var(--btn-bg);
}

</style>
