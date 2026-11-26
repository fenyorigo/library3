<template>
  <div class="curator-backdrop">
    <section class="curator-modal" role="dialog" aria-modal="true" aria-labelledby="curator-title" :aria-busy="busy">
      <header><h2 id="curator-title">Kurátori rendezés</h2><button :disabled="busy" @click="emit('close')">Bezárás</button></header>
      <form class="search" @submit.prevent="search">
        <input v-model="query" type="search" aria-label="Keresés" placeholder="Keresés, például Pelham" :disabled="busy" />
        <select v-model="format" aria-label="Könyvtípus" :disabled="busy" @change="search"><option value="">Összes</option><option value="print">Nyomtatott</option><option value="ebooks">E-könyv</option></select>
        <button :disabled="busy">Keresés</button>
      </form>
      <p v-if="error" role="alert" class="error">{{ error }}</p>
      <p v-if="message" role="status">{{ message }}</p>
      <div class="selection-tools">
        <label><input type="checkbox" :checked="allSelected" :indeterminate="selected.size > 0 && !allSelected" :disabled="busy || !total" @change="toggleAll" /> Összes találat kijelölése ({{ total }})</label>
        <button :disabled="busy || !selected.size" @click="clearSelection">Kijelölések törlése</button>
        <strong>{{ selected.size }} kijelölve</strong>
      </div>
      <div class="results">
        <table><thead><tr><th>Kijelölés</th><th>ID</th><th>Cím</th><th>Szerző(k)</th><th>Sorozat</th><th>Kiadó</th><th>Év</th><th>Formátum</th></tr></thead>
          <tbody><tr v-for="book in rows" :key="book.id"><td><input type="checkbox" :aria-label="`Kijelölés: ${book.title}`" :checked="selected.has(Number(book.id))" :disabled="busy" @change="toggle(book,$event.target.checked)" /></td><td>{{ book.id }}</td><td>{{ book.title }}</td><td>{{ (book.curator_authors || []).map(a=>a.name).join('; ') || '—' }}</td><td>{{ book.series || '—' }}</td><td>{{ book.publisher || '—' }}</td><td>{{ book.year || book.year_published || '—' }}</td><td>{{ (book.curator_formats || []).join(', ') }}</td></tr>
          <tr v-if="!rows.length"><td colspan="8">{{ busy ? 'Betöltés…' : 'Nincs találat.' }}</td></tr></tbody>
        </table>
      </div>
      <nav><button :disabled="busy || page <= 1" @click="load(page-1)">Előző</button><span>{{ page }} / {{ pages }} oldal · {{ total }} találat</span><button :disabled="busy || page >= pages" @click="load(page+1)">Következő</button></nav>
      <div class="changes">
        <label>Új szerzőnév<input v-model="newAuthor" :disabled="busy" placeholder="Üresen hagyva nem változik" /></label>
        <label v-if="newAuthor.trim()">Cserélendő régi szerző<select v-model.number="sourceAuthor" :disabled="busy"><option :value="0">Válassz szerzőt</option><option v-for="author in authorChoices" :key="author.id" :value="author.id">{{ author.name }} ({{ author.count }} kijelölt könyv)</option></select></label>
        <p v-if="newAuthor.trim() && !authorChoices.length" class="hint">Szerzőcseréhez keress a régi szerző nevére.</p>
        <p v-if="newAuthor.trim() && sourceAuthor" class="hint">A szerző {{ matchingAuthor }} kijelölt könyvnél változik. A társszerzők megmaradnak.</p>
        <label>Új sorozat<input v-model="newSeries" :disabled="busy || clearSeries" placeholder="Üresen hagyva nem változik" /></label>
        <label class="clear-series"><input v-model="clearSeries" type="checkbox" :disabled="busy" /> Sorozat törlése a kijelölt könyveknél</label>
      </div>
      <footer><span>A módosítás csak a kijelölt könyvekre vonatkozik.</span><button class="primary" :disabled="busy || !canSave" @click="save">{{ busy ? 'Feldolgozás…' : 'Save' }}</button></footer>
    </section>
  </div>
</template>
<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { apiUrl, csrfHeader } from '../api';
const emit=defineEmits(['close','saved']);
const query=ref(''),format=ref(''),rows=ref([]),total=ref(0),page=ref(1),selected=ref(new Map()),busy=ref(false),error=ref(''),message=ref('');
const newAuthor=ref(''),sourceAuthor=ref(0),newSeries=ref(''),clearSeries=ref(false);
const searched=ref({q:'',format:''});const per=100;
const pages=computed(()=>Math.max(1,Math.ceil(total.value/per)));
const allSelected=computed(()=>total.value>0&&selected.value.size===total.value);
const authorChoices=computed(()=>{const authors=new Map();for(const book of selected.value.values())for(const a of book.curator_authors||[]){if(!a.query_match)continue;const id=Number(a.author_id);const existing=authors.get(id)||{id,name:a.name,count:0};existing.count++;authors.set(id,existing);}return [...authors.values()].sort((a,b)=>a.name.localeCompare(b.name));});
const matchingAuthor=computed(()=>authorChoices.value.find(a=>a.id===sourceAuthor.value)?.count||0);
const canSave=computed(()=>selected.value.size>0&&(newAuthor.value.trim()||newSeries.value.trim()||clearSeries.value)&&(!newAuthor.value.trim()||matchingAuthor.value>0));
watch(authorChoices,choices=>{if(choices.some(a=>a.id===sourceAuthor.value))return;const q=searched.value.q.trim().toLocaleLowerCase();const matches=q?choices.filter(a=>a.name.toLocaleLowerCase().includes(q)):[];sourceAuthor.value=choices.length===1?choices[0].id:matches.length===1?matches[0].id:0;});
async function read(path,options={}){const response=await fetch(apiUrl(path),{credentials:'same-origin',...options});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||`HTTP ${response.status}`);return result;}
function path(extra={}){const params=new URLSearchParams({curator:'1',record_status:'active',q:searched.value.q,format:searched.value.format,per:String(per),page:String(page.value),sort:'title',dir:'asc',...extra});return 'list_books.php?'+params;}
function clearSelection(){selected.value=new Map();sourceAuthor.value=0;}
function toggle(book,checked){const next=new Map(selected.value);if(checked)next.set(Number(book.id),book);else next.delete(Number(book.id));selected.value=next;}
async function load(next=1){if(busy.value)return;busy.value=true;error.value='';try{const result=await read(path({page:String(next)}));rows.value=result.data||[];total.value=Number(result.meta.total);page.value=next;}catch(e){error.value=e.message;}finally{busy.value=false;}}
async function search(){if(busy.value)return;searched.value={q:query.value.trim(),format:format.value};clearSelection();rows.value=[];total.value=0;message.value='';await load(1);}
async function toggleAll(event){if(!event.target.checked){clearSelection();return;}busy.value=true;error.value='';try{const result=await read(path({curator_all:'1'}));selected.value=new Map((result.data||[]).map(book=>[Number(book.id),book]));total.value=Number(result.meta.total);}catch(e){error.value=e.message;}finally{busy.value=false;}}
async function save(){if(busy.value||!canSave.value)return;busy.value=true;error.value='';message.value='';try{const result=await read('curate_books.php',{method:'POST',headers:{'Content-Type':'application/json',...csrfHeader()},body:JSON.stringify({ids:[...selected.value.keys()],versions:Object.fromEntries([...selected.value].map(([id,book])=>[id,book.curator_version])),search:searched.value.q,replace_author:!!newAuthor.value.trim(),source_author_id:sourceAuthor.value,new_author_name:newAuthor.value.trim(),set_series:clearSeries.value||!!newSeries.value.trim(),series:clearSeries.value?'':newSeries.value.trim()})});const data=result.data;message.value=`Mentve: ${data.changed_books} könyv módosult (${data.author_changes} szerzőkapcsolat, ${data.series_changes} sorozat).`+(data.author_not_present?` ${data.author_not_present} könyvnél a régi szerző nem szerepelt; ott a szerző változatlan.`:'');clearSelection();newAuthor.value='';newSeries.value='';clearSeries.value=false;emit('saved');busy.value=false;await load(1);}catch(e){error.value=e.message;}finally{busy.value=false;}}
onMounted(()=>search());
</script>
<style scoped>
.curator-backdrop{position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;padding:1rem}.curator-modal{width:min(1250px,98vw);max-height:95vh;overflow:auto;background:var(--app-bg,#fff);color:var(--app-fg,#222);border-radius:12px;padding:1.2rem}header,footer,nav,.search,.selection-tools{display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem}header,footer{justify-content:space-between}h2{margin:0}.search input{flex:1;min-width:220px}input:not([type=checkbox]),select{padding:.55rem;border:1px solid #aaa;border-radius:5px;background:var(--app-bg,#fff);color:inherit}button{padding:.5rem .8rem;cursor:pointer}button:disabled{cursor:default;opacity:.5}.primary{font-weight:bold}.results{max-height:40vh;overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:.55rem;border-bottom:1px solid #ccc}th{position:sticky;top:0;background:var(--app-bg,#fff)}.changes{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1rem 0}.changes label{display:flex;flex-direction:column;gap:.4rem}.changes .clear-series{flex-direction:row;align-items:center}.hint{margin:0;grid-column:1/-1}.error{color:#b22}@media(max-width:650px){.changes{grid-template-columns:1fr}.curator-backdrop{padding:.25rem}table{font-size:.9em}}
</style>
