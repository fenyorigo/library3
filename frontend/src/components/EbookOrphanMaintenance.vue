<template>
  <div class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Ebook orphan maintenance">
      <header class="modal-header">
        <h3>Ebook orphan maintenance</h3>
        <div class="header-actions">
          <button class="ghost" @click="load">Refresh</button>
          <button class="ghost" :disabled="loading" @click="exportCsv">CSV</button>
          <button class="icon" @click="$emit('close')" aria-label="Close">×</button>
        </div>
      </header>

      <section class="modal-body">
        <div v-if="loading" class="muted">{{ loadingMessage || 'Loading…' }}</div>
        <template v-else>
          <div class="summary">
            <div><strong>Mount</strong> {{ ebookMountPoint || '—' }}</div>
            <div><strong>Candidate groups</strong> {{ summary.candidate_groups || 0 }}</div>
            <div><strong>Soft-delete rows</strong> {{ summary.delete_rows || 0 }}</div>
            <div><strong>Missing without replacement</strong> {{ summary.missing_only || 0 }}</div>
          </div>

          <div class="section">
            <div class="section-head">
              <h4>Stale duplicate ebook records ({{ candidates.length }})</h4>
              <button
                class="danger"
                :disabled="!deleteRowCount || applying"
                @click="softDeleteCandidates"
              >Soft-delete stale records</button>
            </div>
            <div v-if="!candidates.length" class="muted">No stale duplicate ebook records.</div>
            <div v-else class="candidate-list">
              <article v-for="candidate in candidates" :key="candidate.sha256" class="candidate">
                <div class="candidate-title">
                  <span class="badge">{{ reasonLabel(candidate.reason) }}</span>
                  <code>{{ shortSha(candidate.sha256) }}</code>
                </div>
                <div class="keep-row">
                  <strong>Keep</strong>
                  <span>#{{ candidate.keep.copy_id }} / book #{{ candidate.keep.book_id }}</span>
                  <span>{{ candidate.keep.title || '—' }}</span>
                  <code>{{ candidate.keep.file_path }}</code>
                </div>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Delete</th>
                      <th>Book</th>
                      <th>Title</th>
                      <th>Authors</th>
                      <th>Path</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in candidate.delete" :key="`${candidate.sha256}-${row.copy_id}`">
                      <td>#{{ row.copy_id }}</td>
                      <td>#{{ row.book_id }}</td>
                      <td>{{ row.title || '—' }}</td>
                      <td>{{ row.authors || '—' }}</td>
                      <td><code>{{ row.file_path }}</code></td>
                    </tr>
                  </tbody>
                </table>
              </article>
            </div>
          </div>

          <div class="section">
            <div class="section-head">
              <h4>Missing ebook records without replacement ({{ missingOnly.length }})</h4>
              <button
                class="danger"
                :disabled="!missingOnly.length || applying"
                @click="softDeleteMissingOnly"
              >Soft-delete missing records</button>
            </div>
            <table class="table" v-if="missingOnly.length">
              <thead>
                <tr>
                  <th>Copy</th>
                  <th>Book</th>
                  <th>Title</th>
                  <th>Path</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in missingOnly" :key="`m-${row.copy_id}`">
                  <td>#{{ row.copy_id }}</td>
                  <td>#{{ row.book_id }}</td>
                  <td>{{ row.title || '—' }}</td>
                  <td><code>{{ row.file_path }}</code></td>
                </tr>
              </tbody>
            </table>
            <div v-else class="muted">No missing ebook records without replacement.</div>
          </div>
        </template>
      </section>

      <footer class="modal-footer">
        <button @click="$emit('close')">Close</button>
      </footer>
    </div>
  </div>
</template>

<script lang="ts">
import {
  startEbookOrphanMaintenanceAnalysis,
  fetchEbookOrphanMaintenanceStatus,
  softDeleteEbookOrphans,
  softDeleteMissingEbookOrphans,
} from '../api';

type EbookOrphanRow = {
  copy_id: number;
  book_id: number;
  file_path?: string | null;
  title?: string | null;
  subtitle?: string | null;
  series?: string | null;
  authors?: string | null;
  sha256?: string | null;
};

type EbookOrphanCandidate = {
  sha256: string;
  reason?: string | null;
  keep: EbookOrphanRow;
  delete: EbookOrphanRow[];
};

export default {
  name: 'EbookOrphanMaintenance',
  emits: ['close'],
  data() {
    return {
      loading: false,
      loadingMessage: '',
      applying: false,
      ebookMountPoint: '',
      summary: {} as Record<string, number>,
      candidates: [] as EbookOrphanCandidate[],
      missingOnly: [] as EbookOrphanRow[],
    };
  },
  computed: {
    deleteRowCount(): number {
      return this.candidates.reduce((sum, item) => sum + (item.delete || []).length, 0);
    },
  },
  mounted() {
    this.load();
  },
  methods: {
    errorMessage(err: unknown) {
      return err instanceof Error ? err.message : '';
    },
    sleep(ms: number) {
      return new Promise((resolve) => window.setTimeout(resolve, ms));
    },
    applyPayload(payload: any) {
      this.ebookMountPoint = payload.ebook_mount_point || '';
      this.summary = payload.summary || {};
      this.candidates = payload.candidates || [];
      this.missingOnly = payload.missing_only || [];
    },
    async load() {
      this.loading = true;
      this.loadingMessage = 'Starting ebook orphan analysis…';
      try {
        const started = await startEbookOrphanMaintenanceAnalysis();
        const token = started?.token || started?.data?.token;
        if (!token) throw new Error('Ebook orphan analysis did not return a job token.');
        this.loadingMessage = 'Ebook orphan analysis is running…';
        for (;;) {
          await this.sleep(1500);
          const status = await fetchEbookOrphanMaintenanceStatus(token);
          const job = status?.job || {};
          if (job.status === 'error') throw new Error(job.error || 'Ebook orphan analysis failed.');
          if (job.status === 'complete') {
            this.applyPayload(job.data || {});
            break;
          }
          this.loadingMessage = job.message || 'Ebook orphan analysis is running…';
        }
      } catch (e) {
        alert(this.errorMessage(e) || 'Failed to load ebook orphan maintenance data.');
      } finally {
        this.loading = false;
        this.loadingMessage = '';
      }
    },
    reasonLabel(reason?: string | null) {
      if (reason === 'missing_path_replaced_by_existing_sha') return 'missing path replaced';
      if (reason === 'duplicate_catalog_rows_same_file') return 'same file duplicated';
      return reason || 'candidate';
    },
    shortSha(sha?: string | null) {
      return sha ? `${sha.slice(0, 12)}…` : '—';
    },
    download(filename: string, csv: string) {
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    },
    csvCell(value: unknown) {
      const text = value == null ? '' : String(value);
      return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    },
    buildCsv() {
      const rows: unknown[][] = [[
        'status', 'reason', 'sha256', 'copy_id', 'book_id', 'file_path', 'title', 'subtitle', 'series', 'authors', 'keep_copy_id', 'keep_book_id', 'keep_file_path',
      ]];
      for (const candidate of this.candidates) {
        for (const row of candidate.delete || []) {
          rows.push([
            'soft_delete_candidate', candidate.reason || '', candidate.sha256 || '', row.copy_id || '', row.book_id || '', row.file_path || '', row.title || '', row.subtitle || '', row.series || '', row.authors || '', candidate.keep?.copy_id || '', candidate.keep?.book_id || '', candidate.keep?.file_path || '',
          ]);
        }
      }
      for (const row of this.missingOnly) {
        rows.push(['missing_no_replacement', '', row.sha256 || '', row.copy_id || '', row.book_id || '', row.file_path || '', row.title || '', row.subtitle || '', row.series || '', row.authors || '', '', '', '']);
      }
      return rows.map((row) => row.map(this.csvCell).join(',')).join('\n') + '\n';
    },
    async exportCsv() {
      try {
        this.download(`ebook_orphan_maintenance_${new Date().toISOString().replace(/[-:T]/g, '').slice(0, 15)}.csv`, this.buildCsv());
      } catch (e) {
        alert(this.errorMessage(e) || 'CSV export failed.');
      }
    },
    async softDeleteCandidates() {
      if (!this.deleteRowCount) return;
      if (!confirm(`Soft-delete ${this.deleteRowCount} stale ebook catalog record(s)?\n\nFiles are not deleted. Only Books.record_status is changed to deleted.`)) return;
      this.applying = true;
      try {
        const data = await softDeleteEbookOrphans(this.candidates);
        const payload = (data && data.data) ? data.data : (data || {});
        const warnings = payload.warnings || [];
        alert(`Stale ebook records soft-deleted: ${payload.updated || 0}\nDuplicate copy rows deleted: ${payload.copy_rows_deleted || 0}${warnings.length ? `\nWarnings: ${warnings.length}` : ''}`);
        await this.load();
      } catch (e) {
        alert(this.errorMessage(e) || 'Soft-delete failed.');
      } finally {
        this.applying = false;
      }
    },
    async softDeleteMissingOnly() {
      if (!this.missingOnly.length) return;
      if (!confirm(`Soft-delete ${this.missingOnly.length} missing ebook catalog record(s) without a known replacement?\n\nFiles are not deleted. If matching files still exist in the repository, the next incremental scan can offer them as new import candidates.`)) return;
      this.applying = true;
      try {
        const data = await softDeleteMissingEbookOrphans(this.missingOnly);
        const payload = (data && data.data) ? data.data : (data || {});
        const warnings = payload.warnings || [];
        alert(`Missing ebook records soft-deleted: ${payload.updated || 0}${warnings.length ? `\nWarnings: ${warnings.length}` : ''}`);
        await this.load();
      } catch (e) {
        alert(this.errorMessage(e) || 'Soft-delete failed.');
      } finally {
        this.applying = false;
      }
    },
  },
};
</script>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding: 1rem; z-index: 1000; }
.modal { background: var(--app-bg); border-radius:.75rem; width:min(1500px, 96vw); max-height: 92vh; overflow:auto; box-shadow: 0 14px 44px rgba(0,0,0,.25); border: 1px solid var(--btn-border); }
.modal-header, .modal-footer { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid var(--line); }
.modal-footer { border-top:1px solid var(--line); border-bottom:none; }
.modal-body { padding:1rem 1.25rem; display:grid; gap:1.25rem; }
.header-actions, .section-head { display:flex; align-items:center; gap:.5rem; }
.section-head { justify-content:space-between; }
.summary { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:.75rem; }
.summary > div { border:1px solid var(--line); border-radius:8px; padding:.65rem .75rem; }
.section h4 { margin: 0 0 .5rem 0; }
.candidate-list { display:grid; gap:.8rem; }
.candidate { border:1px solid var(--line); border-radius:8px; padding:.8rem; display:grid; gap:.55rem; }
.candidate-title, .keep-row { display:flex; gap:.65rem; align-items:center; flex-wrap:wrap; }
.badge { border:1px solid var(--btn-border); border-radius:999px; padding:.15rem .45rem; font-size:.85rem; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { border-bottom: 1px solid var(--line); padding: .4rem .5rem; text-align: left; vertical-align: top; }
code { white-space: normal; overflow-wrap:anywhere; }
.muted { opacity: .7; }
button { padding:.35rem .7rem; border-radius:8px; border:1px solid var(--btn-border); background: var(--btn-bg); cursor:pointer; color: var(--btn-text); }
button:disabled { opacity:.55; cursor:not-allowed; }
button.ghost { background: transparent; }
button.danger { background:#c0392b; color:#fff; border-color:#c0392b; }
button:hover:not(:disabled) { filter: brightness(.98); }
.icon { font-size:1.5rem; line-height:1; background:none; border:none; cursor:pointer; }
@media (max-width: 900px) { .summary { grid-template-columns: 1fr 1fr; } }
</style>
