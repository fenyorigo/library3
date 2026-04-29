<template>
  <div v-if="open && book" class="overlay" @click.self="emit('close')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Book details">
      <header class="header">
        <h3>{{ book.title }}</h3>
        <button class="close" @click="emit('close')" aria-label="Close">×</button>
      </header>

      <section class="body">
        <div v-if="book.subtitle" class="muted">{{ book.subtitle }}</div>
        <div v-if="book.series" class="muted">Series: {{ book.series }}</div>

        <div class="grid" style="grid-template-columns: 180px 1fr; align-items: start; gap: 1rem;">
          <!-- Cover preview (read-only) -->
          <div>
            <img
              :src="coverSrc"
              class="cover-img"
              alt="Cover"
            />
            <div class="muted small">{{ coverFileName }}</div>
          </div>

          <!-- Metadata -->
          <div class="meta-grid">
            <div><strong>Authors</strong><div>{{ book.authors || "—" }}</div></div>
            <div><strong>Publisher</strong><div>{{ book.publisher || "—" }}</div></div>
            <div><strong>Language</strong><div>{{ book.language || "unknown" }}</div></div>
            <div><strong>Record</strong><div>{{ book.record_status || "active" }}</div></div>
            <div><strong>Year</strong><div>{{ book.year_published || "—" }}</div></div>
            <div><strong>Copies</strong><div>{{ book.copy_count || 1 }}</div></div>
            <div class="span-2">
              <strong>Formats</strong>
              <div v-if="book.copies && book.copies.length">
                <table class="copies-table">
                  <thead>
                    <tr>
                      <th>Format</th>
                      <th>Qty</th>
                      <th>Location</th>
                      <th>File path</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="copy in book.copies" :key="copy.copy_id || `${copy.format}-${copy.file_path || ''}`">
                      <td>{{ copy.format }}</td>
                      <td>{{ copy.quantity || 1 }}</td>
                      <td>{{ copy.physical_location || "—" }}</td>
                      <td>{{ copy.file_path || "—" }}</td>
                      <td>{{ copy.notes || "—" }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-else>—</div>
            </div>
            <div><strong>ISBN</strong><div>{{ book.isbn || "—" }}</div></div>
            <div><strong>LCCN</strong><div>{{ book.lccn || "—" }}</div></div>
            <div><strong>Subjects</strong><div>{{ book.subjects || "—" }}</div></div>
            <div class="span-2"><strong>Notes</strong><div class="notes">{{ book.notes || "—" }}</div></div>
            <div>
              <strong>Placement</strong>
              <div v-if="book.bookcase_no!=null && book.shelf_no!=null">#{{ book.bookcase_no }}/{{ book.shelf_no }}</div>
              <div v-else>—</div>
            </div>
            <div><strong>Added</strong><div>{{ book.added_date || "—" }}</div></div>
            <div><strong>Status</strong><div>{{ book.loan_status || (book.loaned_to || book.loaned_date ? "Loaned" : "In collection") }}</div></div>
            <div><strong>Loaned to</strong><div>{{ book.loaned_to || "—" }}</div></div>
            <div><strong>Loaned date</strong><div>{{ book.loaned_date || "—" }}</div></div>
          </div>
        </div>
      </section>

      <footer class="footer">
        <button @click="emit('close')">Close</button>
        <button v-if="showEdit" @click="emit('edit', book)">Edit</button>
      </footer>
    </div>
  </div>
</template>

<script setup lang="js">
import { computed, onBeforeUnmount, onMounted, toRefs } from "vue";
import { assetUrl } from "../api";

const emit = defineEmits(["close", "edit"]);

const props = defineProps({
  open: { type: Boolean, default: false },
  book: { type: Object, default: () => ({}) },
  showEdit: { type: Boolean, default: false },
});

const { book } = toRefs(props);

const coverSrc = computed(() => {
  const fallback = "uploads/default-cover.jpg";
  const raw = (book.value && (book.value.cover_thumb || book.value.cover_image)) || fallback;
  return assetUrl(raw);
});

const coverFileName = computed(() => {
  const p = (book.value && (book.value.cover_thumb || book.value.cover_image)) || "";
  if (!p) return "default-cover.jpg (fallback)";
  try { return p.split("/").pop(); } catch { return "cover.jpg"; }
});

const onKeydown = (e) => {
  if (e.key === "Escape") emit("close");
};

onMounted(() => {
  window.addEventListener("keydown", onKeydown);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", onKeydown);
});
</script>

<style>
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding: 1rem; z-index: 1000; }
.modal { background: var(--app-bg); border-radius:.75rem; width:min(900px, 95vw); max-height: 90vh; overflow:auto; box-shadow: 0 10px 30px rgba(0,0,0,.2); border: 1px solid var(--btn-border); }
.header, .footer { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid var(--line); }
.footer { border-top:1px solid var(--line); border-bottom:none; }
.body { padding:1rem 1.25rem; }
.close { font-size:1.5rem; line-height:1; background:none; border:none; cursor:pointer; }
.muted { opacity:.75; margin:.25rem 0; }
.meta-grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:.75rem 1rem; }
.meta-grid .span-2 { grid-column: 1 / span 2; }
.meta-grid .notes { white-space: pre-wrap; }
.small { font-size: .9em; }
.copies-table { width: 100%; border-collapse: collapse; margin-top: .35rem; }
.copies-table th, .copies-table td { border-bottom: 1px solid var(--btn-border); padding: .3rem .4rem; text-align: left; vertical-align: top; }
.cover-img {
  width: 160px;
  height: 220px;
  object-fit: cover;
  border: 1px solid var(--btn-border);
  border-radius: 6px;
  margin-bottom: 0.3rem;
}
</style>
