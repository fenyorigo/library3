<template>
  <div class="overlay" @click.self="$emit('close')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Import books">
      <header class="header">
        <h3>Import books</h3>
        <button class="close" @click="$emit('close')" aria-label="Close">×</button>
      </header>

      <section class="body">
        <div v-if="loading" class="busy-box" aria-live="polite">
          <span class="spinner" aria-hidden="true"></span>
          <span>Import in progress… this may take a few minutes for large bundles.</span>
        </div>

        <p class="muted small">
          Supported formats: <code>books_export.csv</code> or ZIP bundle from
          <code>Export selected books (CSV + covers)</code>.
        </p>

        <label class="block">Import File
          <input type="file"
                 accept=".csv,.zip,text/csv,text/plain,application/zip"
                 @change="onPick"
                 :disabled="loading" />
        </label>

        <label class="block">Import mode
          <select v-model="restoreMode" :disabled="loading">
            <option value="csv_only">CSV only (keep existing covers)</option>
            <option value="csv_and_covers">CSV + covers (overwrite covers if present)</option>
          </select>
        </label>

        <label class="block">Book ID handling
          <select v-model="idMode" :disabled="loading">
            <option value="keep_ids">Use IDs from import file (DR/full restore)</option>
            <option value="new_catalog">Use the next free ID (ignore imported IDs)</option>
          </select>
        </label>

        <div v-if="result" class="panel">
          <div class="grid2">
            <div><b>Total lines:</b> {{ result.total }}</div>
            <div><b>Inserted:</b> {{ result.inserted }}</div>
            <div><b>Skipped:</b> {{ result.skipped }}</div>
            <div><b>Dry run:</b> {{ result.dry_run ? 'yes' : 'no' }}</div>
          </div>
          <div v-if="result.id_conflicts && result.id_conflicts.length" class="conflicts">
            <div class="row between">
              <b>ID conflicts:</b>
              <button class="ghost small-btn" @click="openConflicts">View</button>
            </div>
            <div class="muted small">
              {{ result.id_conflicts.length }} remapped IDs
            </div>
          </div>
          <div v-if="result.errors && result.errors.length" class="errors">
            <b>Errors (sample):</b>
            <ul>
              <li v-for="(e, i) in result.errors" :key="i">
                line {{ e.line }} — {{ e.error }}
              </li>
            </ul>
          </div>
        </div>
      </section>

      <footer class="footer">
        <button @click="$emit('close')" :disabled="loading">Close</button>
        <button class="ghost" @click="run(true)" :disabled="loading || !file">Dry run</button>
        <button class="primary" @click="run(false)" :disabled="loading || !file">Import</button>
      </footer>
    </div>
  </div>

  <div v-if="showConflicts" class="overlay" @click.self="closeConflicts">
    <div class="modal" role="dialog" aria-modal="true" aria-label="ID Conflicts">
      <header class="header">
        <h3>ID Conflicts</h3>
        <button class="close" @click="closeConflicts" aria-label="Close">×</button>
      </header>
      <section class="body">
        <div class="row between">
          <div class="muted small">
            {{ conflicts.length }} remapped IDs
          </div>
          <button class="ghost" @click="exportConflicts">Export CSV</button>
        </div>
        <div class="conflict-table">
          <div class="conflict-row header-row">
            <div>Existing ID</div>
            <div>New ID</div>
            <div>Title</div>
            <div>Authors</div>
            <div>Line</div>
          </div>
          <div v-for="(c, i) in conflicts" :key="i" class="conflict-row">
            <div>{{ c.existing_id }}</div>
            <div>{{ c.new_id }}</div>
            <div>{{ c.title }}</div>
            <div>{{ c.authors }}</div>
            <div>{{ c.line }}</div>
          </div>
        </div>
      </section>
      <footer class="footer">
        <button @click="closeConflicts">Close</button>
      </footer>
    </div>
  </div>
</template>

<script lang="ts">
import { apiUrl } from "../api";

type CsvImportError = {
  line: number;
  error: string;
};

type CsvImportResult = {
  total: number;
  inserted: number;
  skipped: number;
  dry_run: boolean;
  errors?: CsvImportError[];
  id_conflicts?: CsvIdConflict[];
};

type CsvIdConflict = {
  line: number;
  existing_id: number;
  new_id: number;
  title: string;
  authors: string | null;
};

export default {
  name: 'CsvImportModal',
  data() {
    return {
      file: null as File | null,
      loading: false,
      result: null as CsvImportResult | null,
      showConflicts: false,
      restoreMode: "csv_only",
      idMode: "keep_ids",
    };
  },
  computed: {
    conflicts(): CsvIdConflict[] {
      return this.result?.id_conflicts || [];
    },
  },
  methods: {
    onPick(e: Event) {
      const input = e.target as HTMLInputElement | null;
      const f = input && input.files ? input.files[0] : null;
      this.file = f || null;
      this.result = null;
    },
    async run(forceDryRun?: boolean) {
      if (!this.file) return;
      const dry = forceDryRun ?? false;
      this.loading = true;
      this.result = null;
      try {
        const fd = new FormData();
        fd.append('file', this.file);
        fd.append('dry_run', dry ? '1' : '0');
        fd.append('with_covers', this.restoreMode === 'csv_and_covers' ? '1' : '0');
        fd.append('id_mode', this.idMode);

        const res = await fetch(apiUrl("import_csv.php"), {
          method: 'POST',
          credentials: 'same-origin',
          body: fd,
        });
        const raw = await res.text();
        let data: any = {};
        try {
          data = raw ? JSON.parse(raw) : {};
        } catch {
          data = {};
        }
        if (!res.ok || data.ok === false) {
          const gatewayTimeout =
            res.status === 504
            || /<title>\s*504 Gateway Timeout/i.test(raw)
            || /Gateway Timeout/i.test(raw);
          if (gatewayTimeout && !dry) {
            alert(
              "Import request timed out at the gateway (504), but the backend may still be finishing in the background. Wait a bit, then reload the list and verify counts/covers."
            );
            this.$emit("imported", { dry_run: false });
            return;
          }
          const fallback = raw && raw.trim() ? raw.trim().slice(0, 500) : '';
          throw new Error(data.error || fallback || 'Import failed');
        }
        const payload = (data && data.data ? data.data : null) as CsvImportResult | null;

        this.result = payload || null;

        // After a real import, notify parent to reload list
        if (payload && !payload.dry_run) {
          this.$emit('imported', payload);
        }
      } catch (err) {
        const msg = err instanceof Error ? err.message : '';
        alert(msg || 'Import failed');
      } finally {
        this.loading = false;
      }
    },
    openConflicts() {
      if (!this.conflicts.length) return;
      this.showConflicts = true;
    },
    closeConflicts() {
      this.showConflicts = false;
    },
    exportConflicts() {
      if (!this.conflicts.length) return;
      const header = ['existing_id', 'new_id', 'title', 'authors', 'line'];
      const rows = this.conflicts.map((c) => [
        c.existing_id,
        c.new_id,
        c.title || '',
        c.authors || '',
        c.line,
      ]);

      const escapeCsv = (v: string | number) => {
        const s = String(v ?? '');
        if (s.includes('"')) {
          return `"${s.replace(/"/g, '""')}"`;
        }
        if (s.includes(',') || s.includes('\n') || s.includes('\r')) {
          return `"${s}"`;
        }
        return s;
      };

      const csv = [header, ...rows]
        .map((row) => row.map(escapeCsv).join(','))
        .join('\n');

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'import_id_conflicts.csv';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    },
  },
};
</script>

<style scoped>
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding: 1rem; z-index: 1000; }
.modal { background: var(--app-bg); border-radius:.75rem; width:min(720px, 95vw); max-height: 90vh; overflow:auto; box-shadow: 0 10px 30px rgba(0,0,0,.2); border: 1px solid var(--btn-border); }
.header, .footer { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid var(--line); }
.footer { border-top:1px solid var(--line); border-bottom:none; }
.body { padding:1rem 1.25rem; display: grid; gap:.75rem; }
.block { display:block; }
.row { display:flex; gap:.5rem; align-items:center; }
.between { justify-content: space-between; }
.panel { border:1px solid var(--btn-border); border-radius:8px; padding:.75rem; background: rgba(255,255,255,0.35); }
.grid2 { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:.25rem .75rem; }
.errors ul { margin:.25rem 0 0 1rem; }
.conflicts { margin-top: .5rem; }
.small-btn { padding: .25rem .5rem; }
.muted { opacity: .75; }
.small { font-size:.9em; }
input[type="file"] { font: inherit; }
button { padding:.4rem .75rem; border-radius:8px; border:1px solid var(--btn-border); background: var(--btn-bg); cursor:pointer; color: var(--btn-text); }
button.primary { background: var(--btn-primary-bg); color: var(--btn-primary-text); border-color: var(--btn-primary-border); }
button.ghost { background: transparent; }
.close { font-size:1.5rem; line-height:1; background:none; border:none; cursor:pointer; }
.conflict-table { border: 1px solid var(--btn-border); border-radius: 8px; overflow: hidden; }
.conflict-row { display: grid; grid-template-columns: 90px 90px 1.5fr 1.5fr 70px; gap: .5rem; padding: .5rem .75rem; border-top: 1px solid var(--line); }
.conflict-row:first-child { border-top: none; }
.header-row { font-weight: 600; background: rgba(0,0,0,0.05); }
.busy-box {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--btn-border);
  border-radius: 8px;
  background: rgba(255,255,255,0.35);
  font-weight: 600;
}
.spinner {
  width: 1rem;
  height: 1rem;
  border: 2px solid rgba(0,0,0,0.2);
  border-top-color: rgba(0,0,0,0.65);
  border-radius: 999px;
  display: inline-block;
  animation: spin 0.9s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
