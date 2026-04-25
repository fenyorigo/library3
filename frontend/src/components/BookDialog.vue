<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal" role="dialog" aria-modal="true">
      <header class="modal-header">
        <h3>
          <template v-if="mode === 'view'">View book #{{ safeId }}</template>
          <template v-else-if="mode === 'edit'">Edit book #{{ safeId }}</template>
          <template v-else>Add a new book</template>
        </h3>
        <button class="icon" @click="emit('close')" aria-label="Close">×</button>
      </header>

      <section class="modal-body">
        <!-- IMAGES -->
        <div class="images">
          <div class="imgbox">
            <div class="label">Cover</div>
            <div class="ph" v-if="!coverSrc">No image</div>
            <img v-else :src="coverSrc" alt="Cover image" />
            <div class="fn" v-if="coverFileName">{{ coverFileName }}</div>

            <!-- Uploads (edit uploads immediately, create attaches on save) -->
            <div class="upload" v-if="!readonly && canManage">
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                @change="mode === 'edit' ? onUpload($event, 'cover') : onCoverSelect($event)"
              />
              <button
                v-if="mode === 'edit' && (book.cover_image || book.cover_thumb)"
                class="linklike"
                @click.prevent="onDelete('cover')"
              >
                Remove cover
              </button>
              <button
                v-else-if="mode === 'create' && coverFile"
                class="linklike"
                @click.prevent="clearCoverSelection"
              >
                Clear selected cover
              </button>
            </div>
          </div>
          <!-- Intentionally no "back" image box for now -->
        </div>

        <!-- FIELDS -->
        <div class="fields">
          <!-- Title -->
          <label>Title</label>
          <div v-if="readonly" class="ro">{{ book.title }}</div>
          <input v-else v-model.trim="form.title" />

          <!-- Subtitle -->
          <label>Subtitle</label>
          <div v-if="readonly" class="ro">{{ book.subtitle || '—' }}</div>
          <input v-else v-model.trim="form.subtitle" />

          <!-- Series -->
          <label>Series</label>
          <div v-if="readonly" class="ro">{{ book.series || '—' }}</div>
          <input v-else v-model.trim="form.series" />

          <!-- Publisher + Year -->
          <label>Publisher</label>
          <div v-if="readonly" class="ro span-1">{{ book.publisher || '—' }}</div>
          <div v-else class="combo span-1" @keydown.esc="closePubList">
            <div class="combo-input">
              <input
                v-model.trim="pubQuery"
                @input="onPubInput"
                @focus="onPubFocus"
                @keydown.enter.prevent="onPubEnter"
                placeholder="Type to search…"
                autocomplete="off"
                aria-autocomplete="list"
                aria-expanded="true"
              />
              <button
                v-if="pubQuery || form.publisher_id"
                class="clear"
                type="button"
                title="Clear publisher"
                @click="clearPublisher"
              >×</button>
            </div>

            <!-- dynamic dropdown -->
            <ul v-if="showPubList" class="combo-list">
              <li
                v-for="(o, i) in pubOptions"
                :key="o.id || 'free-'+i"
                @click="choosePublisher(o)"
                class="opt"
              >
                {{ o.name }}
                <span v-if="o.id" class="muted small">#{{ o.id }}</span>
              </li>

              <!-- No matches – create new -->
              <li v-if="pubOptions.length === 0 && pubQuery" class="opt create"
                  @click="chooseFreeText()">
                No matches — use “{{ pubQuery }}”
              </li>
            </ul>

            <div class="hint small muted" v-if="form.publisher_id">selected: #{{ form.publisher_id }}</div>
          </div>

          <label class="right-label">Year</label>
          <div v-if="readonly" class="ro">{{ book.year_published ?? '—' }}</div>
          <input v-else type="number" v-model.number="form.year_published" min="1601" max="2155" />

          <label>Copies</label>
          <div v-if="readonly" class="ro">{{ book.copy_count ?? 1 }}</div>
          <input v-else type="number" v-model.number="form.copy_count" min="1" />

          <!-- ISBN + LCCN -->
          <label>ISBN</label>
          <div v-if="readonly" class="ro">{{ book.isbn || '—' }}</div>
          <input v-else v-model.trim="form.isbn" />

          <label class="right-label">LCCN</label>
          <div v-if="readonly" class="ro">{{ book.lccn || '—' }}</div>
          <input v-else v-model.trim="form.lccn" />

          <!-- Authors + HU -->
          <label>Authors</label>
          <div v-if="readonly" class="ro">{{ book.authors || '—' }}</div>
          <div v-else class="combo" @keydown.esc="closeAuthorList">
            <input
              v-model.trim="form.authors"
              @input="onAuthorsInput"
              @focus="onAuthorsFocus"
              placeholder="Type to search"
              autocomplete="off"
              aria-autocomplete="list"
              aria-expanded="true"
            />
            <ul v-if="showAuthorList" class="combo-list">
              <li
                v-for="o in authorOptions"
                :key="o.id"
                @click="chooseAuthor(o)"
                class="opt"
              >
                {{ o.name }}
              </li>
              <li v-if="allowAuthorCreate" class="opt create" @click="openAuthorCreate">
                Add new author…
              </li>
            </ul>
          </div>

          <label class="right-label">HU order</label>
          <div class="inline">
            <template v-if="readonly">{{ authorsHuLabel }}</template>
            <template v-else>
              <input type="checkbox" v-model="form.authors_is_hungarian" @change="onAuthorsHuChange" />
              <span>Hungarian name order</span>
            </template>
          </div>

          <div class="row-break"></div>

          <div v-if="book.authors_hu_flag === null" class="hint small muted span-4">
            Mixed authors in this book.
            <span v-if="!readonly">Checking will set all to HU.</span>
          </div>

          <!-- Subjects (full line) -->
          <label>Subjects</label>
          <div v-if="readonly" class="ro span-3">{{ book.subjects || '—' }}</div>
          <input v-else class="span-3" v-model.trim="form.subjects" placeholder="Subject1; Subject2" />

          <!-- Notes (full line) -->
          <label>Notes</label>
          <div v-if="readonly" class="ro span-3 prewrap">{{ book.notes || '—' }}</div>
          <textarea v-else class="span-3" v-model="form.notes" rows="3" placeholder="Notes"></textarea>

          <!-- Added + Placement -->
          <label>Added</label>
          <div class="ro">{{ book.added_date || '—' }}</div>
          <label class="right-label">Placement</label>
          <div v-if="readonly" class="ro">
            <template v-if="book.bookcase_no != null && book.shelf_no != null">
              #{{ book.bookcase_no }}/{{ book.shelf_no }}
            </template>
            <template v-else>—</template>
          </div>
          <div v-else class="placement-row">
            <input
              type="number"
              v-model.number="form.bookcase_no"
              min="1"
              placeholder="Bookcase"
            />
            <span class="slash">/</span>
            <input
              type="number"
              v-model.number="form.shelf_no"
              min="1"
              placeholder="Shelf"
            />
          </div>

          <!-- Status -->
          <label>Status</label>
          <div class="ro span-3">{{ loanStatus }}</div>

          <!-- Loaned to + Loaned date -->
          <label>Loaned to</label>
          <div v-if="readonly" class="ro">{{ book.loaned_to || '—' }}</div>
          <input v-else v-model.trim="form.loaned_to" />

          <label class="right-label">Loaned date</label>
          <div v-if="readonly" class="ro">{{ book.loaned_date || '—' }}</div>
          <input v-else type="date" v-model="form.loaned_date" />
        </div>
      </section>

      <footer class="modal-footer">
        <button @click="emit('close')">Close</button>

        <!-- Quick switch from View→Edit -->
        <button v-if="mode==='view' && canManage" class="ghost" @click="emit('switch-edit', book)">Edit</button>

        <button v-if="!readonly && canManage" class="primary" @click="save">
          {{ mode === 'create' ? 'Create' : 'Save' }}
        </button>

        <button v-if="mode==='view' && canManage" class="ghost" @click="emit('duplicate', book)">Duplicate</button>
      </footer>
    </div>
    <div v-if="authorCreateOpen" class="modal-backdrop" @click.self="authorCreateOpen = false">
      <div class="modal narrow" role="dialog" aria-modal="true" aria-label="Create author">
        <header class="modal-header">
          <h3>New author</h3>
          <button class="icon" @click="authorCreateOpen = false" aria-label="Close">×</button>
        </header>
        <section class="modal-body">
          <label>Name</label>
          <input v-model.trim="authorDraft.name" placeholder="Display name" />

          <label>First name</label>
          <input v-model.trim="authorDraft.first_name" />

          <label>Last name</label>
          <input v-model.trim="authorDraft.last_name" />

          <label>Sort name</label>
          <input v-model.trim="authorDraft.sort_name" placeholder="Last, First" />

          <label class="inline">
            <input type="checkbox" v-model="authorDraft.is_hungarian" />
            Hungarian name order (Last First)
          </label>
        </section>
        <footer class="modal-footer">
          <button @click="authorCreateOpen = false">Cancel</button>
          <button class="primary" @click="saveNewAuthor">Save author</button>
        </footer>
      </div>
    </div>
  </div>
</template>

<script setup lang="js">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { apiUrl, assetUrl } from "../api";

const emit = defineEmits([
  "close",
  "switch-edit",
  "save",
  "create",
  "duplicate",
  "updated",
]);

const props = defineProps({
  mode: { type: String, default: "view" },
  book: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
});

const initForm = (b = {}, mode = "view") => {
  if (mode === "create") {
    return {
      id: undefined,
      title: b.title || "",
      subtitle: b.subtitle || "",
      series: b.series || "",
      year_published: b.year_published ?? null,
      copy_count: b.copy_count ?? 1,
      isbn: b.isbn || "",
      lccn: b.lccn || "",
      notes: b.notes || "",
      authors: Array.isArray(b.authors) ? b.authors.join("; ") : (b.authors || ""),
      authors_is_hungarian: b.authors_hu_flag === 1,
      subjects: b.subjects || "",
      publisher: b.publisher || "",
      publisher_id: b.publisher_id ?? null,
      loaned_to: b.loaned_to || "",
      loaned_date: b.loaned_date || "",
      bookcase_no: b.bookcase_no ?? null,
      shelf_no: b.shelf_no ?? null,
    };
  }
  return {
    id: b.id || b.book_id || null,
    title: b.title || "",
    subtitle: b.subtitle || "",
    series: b.series || "",
    year_published: b.year_published ?? null,
    copy_count: b.copy_count ?? 1,
    isbn: b.isbn || "",
    lccn: b.lccn || "",
    notes: b.notes || "",
    authors: Array.isArray(b.authors) ? b.authors.join("; ") : (b.authors || ""),
    authors_is_hungarian: b.authors_hu_flag === 1,
    subjects: b.subjects || "",
    publisher: b.publisher || "",
    publisher_id: b.publisher_id ?? null,
    loaned_to: b.loaned_to || "",
    loaned_date: b.loaned_date || "",
    bookcase_no: b.bookcase_no ?? null,
    shelf_no: b.shelf_no ?? null,
  };
};

const form = ref(initForm(props.book, props.mode));
const pubQuery = ref(form.value.publisher || "");
const pubOptions = ref([]);
const showPubList = ref(false);
const authorOptions = ref([]);
const showAuthorList = ref(false);
const authorCreateOpen = ref(false);
const authorDraft = ref({
  name: "",
  first_name: "",
  last_name: "",
  sort_name: "",
  is_hungarian: false,
});
const authorsHuTouched = ref(false);
const coverImageOverride = ref(null);
const coverThumbOverride = ref(null);
const coverFile = ref(null);
const coverPreview = ref(null);

let pubTimer = null;
let authorTimer = null;

const readonly = computed(() => props.mode === "view" || !props.canManage);
const allowAuthorCreate = computed(() => (form.value.authors || "").trim().length >= 2);
const safeId = computed(() => props.book?.id || props.book?.book_id || null);
const authorsHuLabel = computed(() => {
  if (props.book?.authors_hu_flag === null || props.book?.authors_hu_flag === undefined) return "Mixed";
  return props.book.authors_hu_flag ? "Yes" : "No";
});
const loanStatus = computed(() => {
  const who = (form.value.loaned_to || "").trim();
  const when = (form.value.loaned_date || "").trim();
  return who || when ? "Loaned" : "In collection";
});

const coverSrc = computed(() => {
  const fallback = "uploads/default-cover.jpg";
  const raw = coverPreview.value
    || coverThumbOverride.value
    || coverImageOverride.value
    || props.book?.cover_thumb
    || props.book?.cover_image
    || fallback;
  if (raw && (raw.startsWith("blob:") || raw.startsWith("data:"))) return raw;
  return assetUrl(raw);
});

const coverFileName = computed(() => {
  if (coverFile.value && coverFile.value.name) return coverFile.value.name;
  const p = coverThumbOverride.value || coverImageOverride.value || props.book?.cover_thumb || props.book?.cover_image;
  return p ? p.split("/").pop() : "default-cover.jpg (fallback)";
});

const resetCoverSelection = () => {
  if (coverPreview.value) URL.revokeObjectURL(coverPreview.value);
  coverPreview.value = null;
  coverFile.value = null;
};

watch(
  () => props.book,
  (b) => {
    form.value = initForm(b, props.mode);
    pubQuery.value = form.value.publisher || "";
    pubOptions.value = [];
    authorOptions.value = [];
    showAuthorList.value = false;
    coverImageOverride.value = null;
    coverThumbOverride.value = null;
    resetCoverSelection();
  },
  { deep: true, immediate: true }
);

watch(
  () => props.mode,
  () => {
    form.value = initForm(props.book, props.mode);
    pubQuery.value = form.value.publisher || "";
    pubOptions.value = [];
    authorOptions.value = [];
    showAuthorList.value = false;
    authorsHuTouched.value = false;
    coverImageOverride.value = null;
    coverThumbOverride.value = null;
    resetCoverSelection();
  },
  { immediate: true }
);

const onKeydown = (e) => {
  if (e.key === "Escape") emit("close");
};

onMounted(() => {
  window.addEventListener("keydown", onKeydown);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", onKeydown);
  resetCoverSelection();
});

const onPubInput = () => {
  form.value.publisher_id = null;
  form.value.publisher = pubQuery.value || "";

  clearTimeout(pubTimer);
  const q = (pubQuery.value || "").trim();
  if (q.length < 2) {
    pubOptions.value = [];
    showPubList.value = false;
    return;
  }
  pubTimer = setTimeout(async () => {
    try {
      const { suggestPublishers } = await import("../api");
      pubOptions.value = await suggestPublishers(q);
      showPubList.value = true;
    } catch {
      pubOptions.value = [];
      showPubList.value = false;
    }
  }, 150);
};

const onPubFocus = () => {
  if (pubOptions.value.length) {
    showPubList.value = true;
  } else if ((pubQuery.value || "").trim().length >= 2) {
    onPubInput();
  }
  if (!pubQuery.value && form.value.publisher) pubQuery.value = form.value.publisher;
};

const closePubList = () => {
  showPubList.value = false;
};

const choosePublisher = (opt) => {
  form.value.publisher_id = opt.id ?? null;
  form.value.publisher = opt.name;
  pubQuery.value = opt.name;
  pubOptions.value = [];
  showPubList.value = false;
};

const clearPublisher = () => {
  form.value.publisher_id = null;
  form.value.publisher = "";
  pubQuery.value = "";
  pubOptions.value = [];
  showPubList.value = false;
};

const onPubEnter = () => {
  if (pubOptions.value.length > 0) {
    choosePublisher(pubOptions.value[0]);
  } else if ((pubQuery.value || "").trim()) {
    chooseFreeText();
  }
};

const chooseFreeText = () => {
  const name = (pubQuery.value || "").trim();
  if (!name) return;
  form.value.publisher_id = null;
  form.value.publisher = name;
  pubOptions.value = [];
  showPubList.value = false;
};

const onAuthorsInput = () => {
  clearTimeout(authorTimer);
  const raw = (form.value.authors || "").trim();
  const lastToken = raw.split(";").pop().trim();
  if (lastToken.length < 2) {
    authorOptions.value = [];
    showAuthorList.value = false;
    return;
  }
  authorTimer = setTimeout(async () => {
    try {
      const { suggestAuthors } = await import("../api");
      authorOptions.value = await suggestAuthors(lastToken);
      showAuthorList.value = true;
    } catch {
      authorOptions.value = [];
      showAuthorList.value = false;
    }
  }, 150);
};

const onAuthorsHuChange = () => {
  authorsHuTouched.value = true;
};

const onAuthorsFocus = () => {
  if (authorOptions.value.length) {
    showAuthorList.value = true;
  } else if ((form.value.authors || "").trim().length >= 2) {
    onAuthorsInput();
  }
};

const closeAuthorList = () => {
  showAuthorList.value = false;
};

const chooseAuthor = (opt) => {
  const raw = form.value.authors || "";
  const chunks = raw.split(";");
  const tail = chunks[chunks.length - 1];
  const hasEmptyTail = tail.trim() === "";
  const parts = chunks.map((s) => s.trim()).filter(Boolean);
  if (!hasEmptyTail && parts.length) {
    parts.pop();
  }
  parts.push(opt.name);
  form.value.authors = parts.join("; ");
  authorOptions.value = [];
  showAuthorList.value = false;
};

const openAuthorCreate = () => {
  const seed = (form.value.authors || "").trim().split(/[;,]\s*/).pop() || "";
  authorDraft.value = {
    name: seed,
    first_name: "",
    last_name: "",
    sort_name: "",
    is_hungarian: false,
  };
  if (seed.includes(",")) {
    const parts = seed.split(",");
    authorDraft.value.last_name = (parts[0] || "").trim();
    authorDraft.value.first_name = (parts.slice(1).join(" ") || "").trim();
    authorDraft.value.is_hungarian = true;
  }
  authorCreateOpen.value = true;
  showAuthorList.value = false;
};

const saveNewAuthor = async () => {
  try {
    const payload = { ...authorDraft.value };
    if (!payload.name) {
      const first = (payload.first_name || "").trim();
      const last = (payload.last_name || "").trim();
      payload.name = payload.is_hungarian
        ? `${last} ${first}`.trim()
        : `${first} ${last}`.trim();
    }
    if (!payload.sort_name) {
      const first = (payload.first_name || "").trim();
      const last = (payload.last_name || "").trim();
      payload.sort_name = first && last ? `${last}, ${first}` : (last || first || "");
    }
    const { createAuthor } = await import("../api");
    const res = await createAuthor(payload);
    const name = res && res.data ? res.data.name : "";
    chooseAuthor({ name });
    authorCreateOpen.value = false;
  } catch (e) {
    alert(e && e.message ? e.message : "Create author failed.");
  }
};

const save = () => {
  if (!form.value.title || !form.value.title.trim()) {
    alert("Title is required.");
    return;
  }

  const rawYear = form.value.year_published;
  const normYear = (rawYear === "" || rawYear == null)
    ? null
    : (Number.isFinite(Number(rawYear)) ? Number(rawYear) : null);

  const payload = {
    id: form.value.id ?? null,
    title: form.value.title || null,
    subtitle: form.value.subtitle || null,
    series: form.value.series || null,
    year_published: normYear,
    copy_count: Math.max(1, Number(form.value.copy_count || 1)),
    isbn: form.value.isbn || null,
    lccn: form.value.lccn || null,
  };

  payload.subjects = (form.value.subjects || "").trim();
  payload.notes = (form.value.notes || "").trim() || null;
  payload.loaned_to = (form.value.loaned_to || "").trim();
  payload.loaned_date = (form.value.loaned_date || "").trim() || null;
  if (!payload.loaned_to) {
    payload.loaned_date = null;
  }
  payload.placement = {
    bookcase_no: form.value.bookcase_no || null,
    shelf_no: form.value.shelf_no || null,
  };

  if (form.value.publisher_id != null) {
    payload.publisher_id = form.value.publisher_id;
  } else if ((form.value.publisher || "").trim() !== "") {
    payload.publisher = form.value.publisher.trim();
  } else {
    payload.publisher_id = null;
  }

  if (props.mode === "create") {
    if (form.value.authors && form.value.authors.trim()) {
      payload.authors = form.value.authors.trim();
      payload.authors_is_hungarian = !!form.value.authors_is_hungarian;
    }
    if (coverFile.value) {
      payload.thumb_max_w = browserThumbWidth();
    }
    emit("create", payload, coverFile.value);
  } else {
    payload.authors = (form.value.authors || "").trim();
    if (authorsHuTouched.value || props.book.authors_hu_flag !== null) {
      payload.authors_is_hungarian = !!form.value.authors_is_hungarian;
    }
    emit("save", payload);
  }
};

const onCoverSelect = (e) => {
  const file = e.target.files && e.target.files[0];
  if (!file) return;
  resetCoverSelection();
  coverFile.value = file;
  coverPreview.value = URL.createObjectURL(file);
  e.target.value = "";
};

const clearCoverSelection = () => {
  resetCoverSelection();
};

const browserThumbWidth = () => {
  if (typeof window === "undefined" || !window.innerWidth) return 200;
  const dpr = Number.isFinite(window.devicePixelRatio) ? window.devicePixelRatio : 1;
  const width = Math.round(window.innerWidth * dpr);
  return Math.max(64, Math.min(4096, width));
};

const onUpload = async (e, type) => {
  try {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    if (!safeId.value) { alert("Save the book first, then upload images."); return; }

    const fd = new FormData();
    fd.append("book_id", safeId.value);
    fd.append("type", type);
    fd.append("image", file);
    fd.append("thumb_max_w", String(browserThumbWidth()));

    const res = await fetch(apiUrl("upload_image.php"), {
      method: "POST",
      body: fd,
      credentials: "same-origin",
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) throw new Error(data.error || "Upload failed");
    const payload = data && data.data ? data.data : {};

    const path = payload.path;
    coverImageOverride.value = path || null;
    if (!coverThumbOverride.value) coverThumbOverride.value = path || null;

    emit("updated", { id: safeId.value, type, path });
  } catch (err) {
    alert("Upload failed: " + err.message);
  } finally {
    e.target.value = "";
  }
};

const onDelete = async (type) => {
  if (!safeId.value) return;
  if (!confirm(`Remove ${type} image?`)) return;

  const fd = new FormData();
  fd.append("book_id", safeId.value);
  fd.append("type", type);

  const res = await fetch(apiUrl("delete_image.php"), {
    method: "POST",
    body: fd,
    credentials: "same-origin",
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) {
    alert(data.error || "Delete failed");
    return;
  }

  coverImageOverride.value = null;
  coverThumbOverride.value = null;

  emit("updated", { id: safeId.value, type, path: null });
};
</script>

<style scoped>
.combo { position: relative; }
.combo-input { position: relative; }
.combo-input .clear {
  position: absolute; right: .35rem; top: 50%; transform: translateY(-50%);
  border: none; background: transparent; font-size: 1rem; line-height: 1;
  cursor: pointer; color: #888; padding: 0 .2rem;
}
.combo-input .clear:hover { color: var(--app-fg); }

.combo-list {
  position: absolute; left: 0; right: 0; z-index: 20;
  background: var(--app-bg); border: 1px solid rgba(0,0,0,.15); border-radius: 8px;
  margin: .25rem 0 0; padding: .25rem 0; max-height: 240px; overflow: auto;
  box-shadow: 0 8px 24px rgba(0,0,0,.08);
}
.combo-list .opt { padding: .45rem .6rem; cursor: pointer; display: flex; gap: .5rem; align-items: baseline; }
.combo-list .opt:hover { background: #f6f8fb; }
.combo-list .opt.create { font-style: italic; }
.small { font-size: .85em; }
.muted { opacity: .7; }

.hint { margin-top: .25rem; }

.modal-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,.45);
  display: grid; place-items: center; z-index: 1000;
}
.modal {
  width: min(1000px, 96vw);
  max-height: 92vh;
  overflow: auto;
  background: var(--app-bg); color: var(--app-fg); border-radius: 12px;
  box-shadow: 0 14px 44px rgba(0,0,0,.25);
}
.modal.narrow { width: min(560px, 96vw); }
.modal-header, .modal-footer {
  padding: 1rem 1.25rem; border-bottom: 1px solid rgba(0,0,0,.12);
  display: flex; align-items: center; justify-content: space-between;
}
.modal-footer { border-top: 1px solid rgba(0,0,0,.12); border-bottom: 0; }
.modal-body { padding: 1rem 1.25rem; display: grid; grid-template-columns: 1fr; gap: 1rem; }
.modal label { color: var(--app-fg); opacity: .75; }
.modal input,
.modal select,
.modal textarea {
  padding: .5rem .6rem;
  border: 1px solid rgba(0,0,0,.2);
  border-radius: 6px;
  font: inherit;
  background: var(--app-bg);
  color: var(--app-fg);
}

.images { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1rem; }
.imgbox { border: 1px dashed #ddd; border-radius: 8px; padding: .75rem; }
.imgbox img { max-width: 100%; height: auto; display: block; border-radius: 6px; border: 1px solid #eee; }
.imgbox .label { font-size: .9em; opacity: .75; margin-bottom: .25rem; }
.ph { padding: 1.2rem; text-align: center; background: #fafafa; border-radius: 6px; }
.fn { font-size: .85em; opacity: .7; margin-top: .25rem; }
.upload { margin-top: .5rem; }
.linklike { background: none; border: none; color: #0b5ed7; cursor: pointer; margin-left: .5rem; padding: 0; }
.linklike:hover { text-decoration: underline; }

.fields {
  display: grid;
  grid-template-columns: 140px minmax(0,1fr) 120px minmax(0,280px);
  gap: .45rem .9rem; align-items: center;
}
.fields label { color: var(--app-fg); font-size: .9em; opacity: .75; }
.inline { display:flex; align-items:center; gap:.45rem; }
.inline input { width: auto; }
.inline span { white-space: nowrap; }
.fields input,
.fields select,
.fields textarea {
  padding: .5rem .6rem;
  border: 1px solid rgba(0,0,0,.2);
  border-radius: 6px;
  font: inherit;
  background: var(--app-bg);
  color: var(--app-fg);
  width: 100%;
}
.fields .combo { min-width: 0; }
.span-3 { grid-column: 2 / span 3; }
.span-4 { grid-column: 1 / span 4; }
.right-label { text-align: left; }
.row-break { grid-column: 1 / -1; height: 0; }
.placement-row {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}
.placement-row input {
  width: 90px;
}
.placement-row .slash {
  opacity: 0.6;
}

.ro { padding: .2rem 0; }
.prewrap { white-space: pre-wrap; }

@media (max-width: 900px) {
  .fields {
    grid-template-columns: 140px 1fr;
  }
  .span-3 { grid-column: 2 / span 1; }
  .span-4 { grid-column: 1 / span 2; }
}

button { padding: .5rem .8rem; border-radius: 8px; border: 1px solid var(--btn-border); background: var(--btn-bg); cursor: pointer; color: var(--btn-text); }
button.primary { background: #1a73e8; color: #fff; border-color: #1a73e8; }
button.ghost { background: transparent; border-color: #bbb; }
button:hover { filter: brightness(.98); }

.icon { font-size: 1.5rem; line-height: 1; background: none; border: none; cursor: pointer; }
</style>
