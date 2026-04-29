<template>
  <div class="overlay" @click.self="emit('close')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Preferences">
      <header class="header">
        <h3>Preferences</h3>
        <button class="close" @click="emit('close')" aria-label="Close">×</button>
      </header>

      <section class="body">
        <div class="row">
          <label class="label">Background</label>
          <div class="color-row">
            <input type="color" v-model="form.bg_color" />
            <input
              type="text"
              v-model.trim="form.bg_color"
              placeholder="#f6e09f"
              name="pref_bg_color"
              autocomplete="new-password"
              autocapitalize="off"
              spellcheck="false"
              data-lpignore="true"
              data-1p-ignore="true"
              @focus="scrubAutofill('bg_color')"
              @blur="scrubAutofill('bg_color')"
            />
          </div>
        </div>

        <div class="row">
          <label class="label">Foreground</label>
          <div class="color-row">
            <input type="color" v-model="form.fg_color" />
            <input
              type="text"
              v-model.trim="form.fg_color"
              placeholder="#222222"
              name="pref_fg_color"
              autocomplete="new-password"
              autocapitalize="off"
              spellcheck="false"
              data-lpignore="true"
              data-1p-ignore="true"
              @focus="scrubAutofill('fg_color')"
              @blur="scrubAutofill('fg_color')"
            />
          </div>
        </div>

        <div class="row">
          <label class="label">Text size</label>
          <select v-model="form.text_size">
            <option value="small">Small</option>
            <option value="medium">Medium</option>
            <option value="large">Large</option>
          </select>
        </div>

        <div class="row">
          <label class="label">Per page</label>
          <select v-model.number="form.per_page">
            <option :value="10">10</option>
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </div>

        <div class="row">
          <label class="label">Logo</label>
          <div class="logo-row">
            <input type="file" accept="image/png,image/jpeg" @change="onPickLogo" />
            <label class="inline">
              <input type="checkbox" v-model="form.remove_logo" />
              Remove logo
            </label>
          </div>
          <div v-if="logoPreview" class="logo-preview">
            <img :src="logoPreview" alt="Logo preview" />
          </div>
        </div>

        <div class="row columns-row">
          <label class="label">Book list columns</label>
          <div class="columns-grid">
            <label class="inline">
              <input type="checkbox" v-model="form.show_cover" />
              Cover flag
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_subtitle" />
              Subtitle
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_series" />
              Series
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_is_hungarian" />
              Is Hungarian
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_publisher" />
              Publisher
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_language" />
              Language
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_format" />
              Format
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_year" />
              Year
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_copy_count" />
              Copies
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_status" />
              Status
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_placement" />
              Placement
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_isbn" />
              ISBN
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_loaned_to" />
              Loaned to
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_loaned_date" />
              Loaned date
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_subjects" />
              Subjects
            </label>
            <label class="inline">
              <input type="checkbox" v-model="form.show_notes" />
              Notes
            </label>
          </div>
        </div>

        <ChangePassword />
      </section>

      <footer class="footer">
        <button @click="emit('close')" :disabled="loading">Close</button>
        <button class="primary" @click="save" :disabled="loading">Save</button>
      </footer>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { updateUserPreferences, assetUrl } from "../api";
import ChangePassword from "./ChangePassword.vue";

const emit = defineEmits(["close", "saved"]);

const props = defineProps({
  preferences: { type: Object, default: () => ({}) },
});

const loading = ref(false);
const logoFile = ref<File | null>(null);
const form = ref({
  bg_color: "",
  fg_color: "",
  text_size: "medium",
  per_page: 25,
  remove_logo: false,
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

const objectUrl = ref("");
const autofillTimers = ref<number[]>([]);
const cleanHex = (value: unknown) => {
  if (!value) return "";
  const raw = String(value).trim();
  return /^#[0-9a-fA-F]{6}$/.test(raw) ? raw : "";
};
const scrubAutofill = (key: "bg_color" | "fg_color") => {
  const current = form.value[key];
  if (current && !cleanHex(current)) {
    form.value[key] = "";
  }
};
const logoPreview = computed(() => {
  if (objectUrl.value) return objectUrl.value;
  const raw = props.preferences?.logo_url;
  return raw ? assetUrl(raw) : "";
});

watch(
  () => props.preferences,
  (prefs) => {
    if (objectUrl.value) {
      URL.revokeObjectURL(objectUrl.value);
      objectUrl.value = "";
    }
    form.value = {
      bg_color: cleanHex(prefs?.bg_color),
      fg_color: cleanHex(prefs?.fg_color),
      text_size: prefs?.text_size || "medium",
      per_page: prefs?.per_page || 25,
      remove_logo: false,
      show_cover: typeof prefs?.show_cover === "boolean" ? prefs.show_cover : true,
      show_subtitle: typeof prefs?.show_subtitle === "boolean" ? prefs.show_subtitle : true,
      show_series: typeof prefs?.show_series === "boolean" ? prefs.show_series : true,
      show_is_hungarian: typeof prefs?.show_is_hungarian === "boolean" ? prefs.show_is_hungarian : true,
      show_publisher: typeof prefs?.show_publisher === "boolean" ? prefs.show_publisher : true,
      show_language: typeof prefs?.show_language === "boolean" ? prefs.show_language : false,
      show_format: typeof prefs?.show_format === "boolean" ? prefs.show_format : false,
      show_year: typeof prefs?.show_year === "boolean" ? prefs.show_year : true,
      show_copy_count: typeof prefs?.show_copy_count === "boolean" ? prefs.show_copy_count : false,
      show_status: typeof prefs?.show_status === "boolean" ? prefs.show_status : true,
      show_placement: typeof prefs?.show_placement === "boolean" ? prefs.show_placement : true,
      show_isbn: typeof prefs?.show_isbn === "boolean" ? prefs.show_isbn : false,
      show_loaned_to: typeof prefs?.show_loaned_to === "boolean" ? prefs.show_loaned_to : false,
      show_loaned_date: typeof prefs?.show_loaned_date === "boolean" ? prefs.show_loaned_date : false,
      show_subjects: typeof prefs?.show_subjects === "boolean" ? prefs.show_subjects : false,
      show_notes: typeof prefs?.show_notes === "boolean" ? prefs.show_notes : false,
    };
    logoFile.value = null;
  },
  { immediate: true, deep: true }
);

onMounted(() => {
  const scrub = () => {
    scrubAutofill("bg_color");
    scrubAutofill("fg_color");
  };
  autofillTimers.value.push(window.setTimeout(scrub, 0));
  autofillTimers.value.push(window.setTimeout(scrub, 250));
});

onBeforeUnmount(() => {
  autofillTimers.value.forEach((timer) => window.clearTimeout(timer));
  autofillTimers.value = [];
});

const onPickLogo = (e: Event) => {
  const input = e.target as HTMLInputElement | null;
  const file = input && input.files ? input.files[0] : null;
  if (objectUrl.value) {
    URL.revokeObjectURL(objectUrl.value);
    objectUrl.value = "";
  }
  logoFile.value = file || null;
  if (logoFile.value) {
    objectUrl.value = URL.createObjectURL(logoFile.value);
  }
};

onBeforeUnmount(() => {
  if (objectUrl.value) URL.revokeObjectURL(objectUrl.value);
});

const save = async () => {
  loading.value = true;
  try {
    const payload = {
      bg_color: form.value.bg_color,
      fg_color: form.value.fg_color,
      text_size: form.value.text_size,
      per_page: form.value.per_page,
      remove_logo: form.value.remove_logo,
      show_cover: form.value.show_cover,
      show_subtitle: form.value.show_subtitle,
      show_series: form.value.show_series,
      show_is_hungarian: form.value.show_is_hungarian,
      show_publisher: form.value.show_publisher,
      show_language: form.value.show_language,
      show_format: form.value.show_format,
      show_year: form.value.show_year,
      show_copy_count: form.value.show_copy_count,
      show_status: form.value.show_status,
      show_placement: form.value.show_placement,
      show_isbn: form.value.show_isbn,
      show_loaned_to: form.value.show_loaned_to,
      show_loaned_date: form.value.show_loaned_date,
      show_subjects: form.value.show_subjects,
      show_notes: form.value.show_notes,
    };
    const res = await updateUserPreferences(payload, logoFile.value);
    const prefs = res?.data?.preferences || null;
    if (prefs) emit("saved", prefs);
    emit("close");
  } catch (err) {
    const msg = err instanceof Error ? err.message : "";
    alert(msg || "Save failed.");
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding: 1rem; z-index: 1200; }
.modal { background: var(--app-bg); border-radius:.75rem; width:min(640px, 96vw); max-height: 90vh; overflow:auto; box-shadow: 0 10px 30px rgba(0,0,0,.2); border: 1px solid var(--btn-border); }
.header, .footer { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid var(--line); }
.footer { border-top:1px solid var(--line); border-bottom:none; }
.body { padding:1rem 1.25rem; display:grid; gap:.85rem; }
.row { display:grid; grid-template-columns: 120px 1fr; align-items:center; gap:.75rem; }
.label { font-weight: 600; }
.color-row { display:flex; gap:.6rem; align-items:center; }
.color-row input[type="text"] { width: 140px; }
.logo-row { display:flex; gap:.75rem; align-items:center; flex-wrap: wrap; }
.logo-preview img { max-height: 80px; border: 1px solid var(--btn-border); border-radius: 6px; padding: 4px; background: #fff; }
.inline { display:flex; align-items:center; gap:.35rem; }
.columns-row { align-items: start; }
.columns-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.45rem 0.85rem;
}
input[type="text"], select {
  padding: .45rem .55rem;
  border: 1px solid var(--btn-border);
  border-radius: 6px;
  font: inherit;
  background: #fff;
}
button { padding:.4rem .8rem; border-radius:8px; border:1px solid var(--btn-border); background: var(--btn-bg); cursor:pointer; color: var(--btn-text); }
button.primary { background: var(--btn-primary-bg); color: var(--btn-primary-text); border-color: var(--btn-primary-border); }
.close { font-size:1.5rem; line-height:1; background:none; border:none; cursor:pointer; }
</style>
