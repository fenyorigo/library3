<template>
  <div class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Authors">
      <header class="modal-header">
        <h3>Authors</h3>
        <div class="header-actions">
          <button class="ghost" @click="load">Refresh</button>
          <button class="icon" @click="$emit('close')" aria-label="Close">×</button>
        </div>
      </header>

      <section class="modal-body">
        <div class="toolbar">
          <input
            v-model.trim="q"
            type="search"
            placeholder="Search authors..."
            @keyup.enter="onSearch"
          />
          <button @click="onSearch">Search</button>
          <button class="ghost" :disabled="!q" @click="clearSearch">Clear</button>
          <label class="per">
            Per page
            <select v-model.number="per" @change="onPerChange">
              <option :value="25">25</option>
              <option :value="50">50</option>
              <option :value="100">100</option>
              <option :value="200">200</option>
            </select>
          </label>
        </div>

        <div class="create-row">
          <input v-model.trim="createDraft.name" placeholder="New author name" />
          <label class="inline">
            <input type="checkbox" v-model="createDraft.is_hungarian" />
            Hungarian name order
          </label>
          <button class="primary" @click="createNew" :disabled="creating">
            {{ creating ? "Adding..." : "Add author" }}
          </button>
          <div class="hint muted">New authors can exist without any books attached.</div>
        </div>

        <div v-if="loading" class="muted">Loading…</div>
        <template v-else>
          <table class="table" v-if="rows.length">
            <thead>
              <tr>
                <th class="w-id" :aria-sort="ariaSort('id')">
                  <button class="th-btn" @click="toggleSort('id')">ID<span class="chev">{{ chevron('id') }}</span></button>
                </th>
                <th :aria-sort="ariaSort('name')">
                  <button class="th-btn" @click="toggleSort('name')">Name<span class="chev">{{ chevron('name') }}</span></button>
                </th>
                <th :aria-sort="ariaSort('first_name')">
                  <button class="th-btn" @click="toggleSort('first_name')">First name<span class="chev">{{ chevron('first_name') }}</span></button>
                </th>
                <th :aria-sort="ariaSort('last_name')">
                  <button class="th-btn" @click="toggleSort('last_name')">Last name<span class="chev">{{ chevron('last_name') }}</span></button>
                </th>
                <th :aria-sort="ariaSort('sort_name')">
                  <button class="th-btn" @click="toggleSort('sort_name')">Sort name<span class="chev">{{ chevron('sort_name') }}</span></button>
                </th>
                <th>Aliases</th>
                <th class="w-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in rows" :key="`a-${a.author_id}`">
                <td>{{ a.author_id }}</td>
                <td>
                  <input v-if="editingAuthorId === a.author_id" v-model.trim="editDraft.name" />
                  <span v-else>{{ a.name || "—" }}</span>
                </td>
                <td>
                  <input v-if="editingAuthorId === a.author_id" v-model.trim="editDraft.first_name" />
                  <span v-else>{{ a.first_name || "—" }}</span>
                </td>
                <td>
                  <input v-if="editingAuthorId === a.author_id" v-model.trim="editDraft.last_name" />
                  <span v-else>{{ a.last_name || "—" }}</span>
                </td>
                <td>
                  <input v-if="editingAuthorId === a.author_id" v-model.trim="editDraft.sort_name" />
                  <span v-else>{{ a.sort_name || "—" }}</span>
                </td>
                <td class="aliases-cell">{{ a.author_aliases || "—" }}</td>
                <td class="actions">
                  <button v-if="editingAuthorId === a.author_id" @click="saveEdit(a.author_id)">Save</button>
                  <button v-if="editingAuthorId === a.author_id" class="ghost" @click="cancelEdit">Cancel</button>
                  <button v-else @click="startEdit(a)">Edit</button>
                  <button class="danger" @click="deleteOne(a.author_id)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="muted">No authors found.</div>

          <div class="pager" v-if="total > per">
            <button :disabled="page <= 1" @click="goPage(page - 1)">Prev</button>
            <div class="muted">Page {{ page }} / {{ totalPages }}</div>
            <button :disabled="page >= totalPages" @click="goPage(page + 1)">Next</button>
          </div>
        </template>
      </section>

      <footer class="modal-footer">
        <div class="muted small">
          Deleting an author clears all book links to that author.
        </div>
        <button @click="$emit('close')">Close</button>
      </footer>
    </div>
  </div>
</template>

<script lang="js">
import { createAuthor, deleteAuthor, fetchAuthors, updateAuthor } from "../api";

export default {
  name: "AuthorsMaintenance",
  data() {
    return {
      loading: false,
      creating: false,
      rows: [],
      total: 0,
      page: 1,
      per: 50,
      q: "",
      sort: "name",
      dir: "asc",
      createDraft: {
        name: "",
        is_hungarian: false,
      },
      editingAuthorId: null,
      editDraft: {
        name: "",
        first_name: "",
        last_name: "",
        sort_name: "",
      },
    };
  },
  computed: {
    totalPages() {
      return Math.max(1, Math.ceil(this.total / this.per));
    },
  },
  mounted() {
    this.load();
  },
  methods: {
    async load() {
      this.loading = true;
      try {
        const data = await fetchAuthors({
          q: this.q,
          page: this.page,
          per: this.per,
          sort: this.sort,
          dir: this.dir,
        });
        this.rows = data.rows || [];
        this.total = data.total || 0;
      } catch (e) {
        alert(e && e.message ? e.message : "Failed to load authors.");
      } finally {
        this.loading = false;
      }
    },
    onSearch() {
      this.page = 1;
      this.load();
    },
    clearSearch() {
      this.q = "";
      this.page = 1;
      this.load();
    },
    onPerChange() {
      this.page = 1;
      this.load();
    },
    toggleSort(key) {
      if (this.sort === key) {
        this.dir = this.dir === "asc" ? "desc" : "asc";
      } else {
        this.sort = key;
        this.dir = "asc";
      }
      this.page = 1;
      this.load();
    },
    ariaSort(key) {
      if (this.sort !== key) return "none";
      return this.dir === "asc" ? "ascending" : "descending";
    },
    chevron(key) {
      if (this.sort !== key) return "↕";
      return this.dir === "asc" ? "↑" : "↓";
    },
    goPage(p) {
      this.page = p;
      this.load();
    },
    async createNew() {
      const name = (this.createDraft.name || "").trim();
      if (!name) {
        alert("Author name is required.");
        return;
      }
      this.creating = true;
      try {
        await createAuthor({
          name,
          is_hungarian: !!this.createDraft.is_hungarian,
        });
        this.createDraft.name = "";
        this.createDraft.is_hungarian = false;
        this.page = 1;
        await this.load();
      } catch (e) {
        alert(e && e.message ? e.message : "Create author failed.");
      } finally {
        this.creating = false;
      }
    },
    async deleteOne(authorId) {
      if (!confirm(`Delete author #${authorId}? This will clear all book links.`)) return;
      try {
        await deleteAuthor(authorId);
        if (this.rows.length === 1 && this.page > 1) {
          this.page -= 1;
        }
        await this.load();
      } catch (e) {
        alert(e && e.message ? e.message : "Delete failed.");
      }
    },
    startEdit(author) {
      this.editingAuthorId = author.author_id;
      this.editDraft = {
        name: author.name || "",
        first_name: author.first_name || "",
        last_name: author.last_name || "",
        sort_name: author.sort_name || "",
      };
    },
    cancelEdit() {
      this.editingAuthorId = null;
    },
    async saveEdit(authorId) {
      try {
        await updateAuthor(authorId, { ...this.editDraft });
        this.editingAuthorId = null;
        await this.load();
      } catch (e) {
        alert(e && e.message ? e.message : "Save failed.");
      }
    },
  },
};
</script>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding: 1rem; z-index: 1000; }
.modal { background: var(--app-bg); border-radius:.75rem; width:min(1600px, 98vw); max-height: 92vh; overflow:auto; box-shadow: 0 14px 44px rgba(0,0,0,.25); border: 1px solid var(--btn-border); }
.modal-header, .modal-footer { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid var(--line); gap: 1rem; }
.modal-footer { border-top:1px solid var(--line); border-bottom:none; }
.modal-body { padding:1rem 1.25rem; display:grid; gap:1.1rem; }
.header-actions { display:flex; align-items:center; gap:.5rem; }
.toolbar { display:flex; flex-wrap: wrap; align-items:center; gap:.5rem; }
.toolbar input[type="search"] { min-width: 240px; }
.per { display:flex; align-items:center; gap:.4rem; }
.create-row { display:flex; flex-wrap: wrap; align-items:center; gap:.6rem; }
.inline { display:flex; align-items:center; gap:.4rem; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { border-bottom: 1px solid var(--line); padding: .45rem .55rem; text-align: left; }
.th-btn { display:inline-flex; align-items:center; gap:.35rem; font: inherit; background:none; border:none; color: inherit; cursor:pointer; padding:0; }
.chev { opacity:.6; font-size:.9em; }
.aliases-cell { max-width: 18rem; overflow-wrap: anywhere; }
.actions { display:flex; gap:.4rem; flex-wrap: wrap; }
.w-actions { width: 13rem; }
.w-id { width: 6rem; }
.muted { opacity: .7; }
.small { font-size: .9em; }
.pager { display:flex; align-items:center; gap:.8rem; justify-content: flex-end; }

button { padding:.35rem .7rem; border-radius:8px; border:1px solid var(--btn-border); background: var(--btn-bg); cursor:pointer; color: var(--btn-text); }
button.ghost { background: transparent; }
button.primary { background: #2f6feb; border-color: #2f6feb; color: #fff; }
button.danger { background:#c0392b; color:#fff; border-color:#c0392b; }
button:hover { filter: brightness(.98); }
.icon { font-size:1.5rem; line-height:1; background:none; border:none; cursor:pointer; }
input, select { padding: .35rem .45rem; border: 1px solid var(--btn-border); border-radius: 6px; font: inherit; background: #fff; }
</style>
