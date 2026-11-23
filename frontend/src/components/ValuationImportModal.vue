<template>
  <div class="overlay" @click.self="close">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Értékelések importálása">
      <h3>Értékelések importálása</h3>
      <p>A kiegészített értékelési CSV meglévő könyvazonosítókhoz kapcsolódik. A könyvcímeket, példányokat, fotókat és számlákat nem módosítja. A korábbi értékelési változatok megmaradnak.</p>
      <input type="file" accept=".csv,text/csv" :disabled="busy" @change="pick" />
      <p v-if="file">{{ file.name }}</p>
      <button :disabled="busy || !file" @click="preview">Előnézet és ellenőrzés</button>
      <p v-if="busy" aria-live="polite">{{ applying ? 'Importálás…' : 'Ellenőrzés…' }}</p>
      <p v-if="error" role="alert">{{ error }}</p>
      <template v-if="summary">
        <dl><dt>Rekordok / egyező könyvek</dt><dd>{{ summary.records }} / {{ summary.matched }}</dd>
          <dt>Nagy értékűként megőrzendő</dt><dd>{{ summary.high_value }}</dd>
          <dt>Használtpiaci becslés / pótlási referencia</dt><dd>{{ summary.used_estimates }} / {{ summary.replacement_references }}</dd>
          <dt>Különálló forrásadatok</dt><dd>{{ summary.sources }}</dd>
        </dl>
        <p v-if="summary.already_imported">Ezt a fájlt már importáltuk. Újabb példány nem készül.</p>
        <template v-if="summary.conflicts.length"><p role="alert">Eltérő vagy hiányzó könyvadatok: {{ summary.conflicts.length }}. Az import az eltérések rendezéséig nem indítható.</p>
          <ul><li v-for="item in summary.conflicts.slice(0,100)" :key="item.book_id">#{{ item.book_id }} {{ item.title }} — {{ item.reasons.join(', ') }}</li></ul>
        </template>
        <button :disabled="busy || summary.conflicts.length > 0 || summary.already_imported || Boolean(result)" @click="apply">Ellenőrzött adatok importálása</button>
      </template>
      <p v-if="result" role="status">{{ result.already_imported ? 'A fájl már szerepelt az adatbázisban.' : `${result.applied} könyv értékelési adatai rögzítve.` }}</p>
      <footer><button :disabled="busy" @click="close">Bezárás</button></footer>
    </div>
  </div>
</template>
<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { apiUrl, csrfHeader } from '../api';
const emit=defineEmits(['close','imported']);
const file=ref(null), summary=ref(null), token=ref(''), error=ref(''), busy=ref(false), applying=ref(false), result=ref(null);
function pick(event) {file.value=event.target.files?.[0]||null; summary.value=null;token.value='';result.value=null;error.value='';}
const close=()=>{if(!busy.value) emit('close');};
const unload=(event)=>{if(busy.value){event.preventDefault();event.returnValue='';}};
window.addEventListener('beforeunload',unload);onBeforeUnmount(()=>window.removeEventListener('beforeunload',unload));
async function send(payload) {
  const response=await fetch(apiUrl('import_valuations.php'),{method:'POST',credentials:'same-origin',headers:csrfHeader(),body:payload});
  const json=await response.json();
  if(!response.ok || json.ok===false) throw new Error(json.error||'Az import nem sikerült.');
  return json.data;
}
async function preview() {
  busy.value=true;applying.value=false;error.value='';summary.value=null;result.value=null;
  try {const data=new FormData();data.set('action','preview');data.set('file',file.value);const response=await send(data);summary.value=response.summary;token.value=response.token;}
  catch(e){error.value=e.message;}finally{busy.value=false;}
}
async function apply() {
  busy.value=true;applying.value=true;error.value='';
  try {const data=new FormData();data.set('action','apply');data.set('token',token.value);result.value=await send(data);emit('imported');}
  catch(e){error.value=e.message;}finally{busy.value=false;}
}
</script>
<style scoped>
.overlay { position:fixed; inset:0; background:#0008; display:flex; align-items:center; justify-content:center; z-index:1000; }
.modal { background:var(--app-bg,#fff); color:var(--app-fg,#222); width:min(760px,94vw); max-height:90vh; overflow:auto; padding:1.2rem; border-radius:8px; }
dl { display:grid; grid-template-columns:1fr auto; gap:.5rem; } dd { margin:0; }
button { margin:.4rem .5rem .4rem 0; } footer { text-align:right; } [role=alert] { color:#ad2828; }
</style>
