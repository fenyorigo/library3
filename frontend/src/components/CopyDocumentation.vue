<template>
  <section class="documentation">
    <h4>Condition photos and acquisition documents</h4>
    <p v-if="!copies.length">Save an individual print copy (quantity 1) to attach documentation.</p>
    <template v-else>
      <label>Physical copy
        <select v-model="copyId" :disabled="busy">
          <option v-for="c in copies" :key="c.copy_id" :value="String(c.copy_id)">
            #{{ c.copy_id }} — {{ c.physical_location || 'Location not recorded' }}
          </option>
        </select>
      </label>
      <p v-if="error" role="alert">{{ error }}</p>
      <p v-if="loading">Loading documentation…</p>
      <template v-else>
        <div class="photos">
          <div v-for="slot in 10" :key="slot" class="photo">
            <template v-if="photoAt(slot)">
              <a :href="fileUrl('photo_id', photoAt(slot).photo_id)">
                <img :src="fileUrl('photo_id', photoAt(slot).photo_id) + '&inline=1'" :alt="photoAt(slot).caption || `Condition photo ${slot}`" loading="lazy" />
                Download photo {{ slot }}
              </a>
              <div>{{ photoAt(slot).photo_type.replaceAll('_', ' ') }} · {{ photoAt(slot).caption }}</div>
              <small>{{ photoAt(slot).taken_on || 'Date not recorded' }}</small>
            </template>
            <span v-else>Photo {{ slot }} — empty</span>
          </div>
        </div>
        <details v-if="canManage && freeSlots.length">
          <summary>Add condition photo</summary>
          <form @submit.prevent="submit($event, 'photo')">
            <label>Slot <select name="slot_number"><option v-for="s in freeSlots" :key="s" :value="s">{{ s }}</option></select></label>
            <label>Type <select name="photo_type"><option v-for="type in photoTypes" :key="type" :value="type">{{ type.replaceAll('_', ' ') }}</option></select></label>
            <label>Caption <input name="caption" /></label>
            <label>Date taken (optional) <input type="date" name="taken_on" /></label>
            <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required />
            <button :disabled="busy">Upload photo</button>
          </form>
        </details>
        <h5>Acquisition documents</h5>
        <p v-if="!data.documents.length">No acquisition documents attached.</p>
        <article v-for="doc in data.documents" :key="doc.document_id">
          <strong>#{{ doc.document_id }} — {{ doc.title }}</strong>
          <div>{{ doc.document_type }} · {{ doc.document_number || 'No document number' }} · {{ doc.seller }}</div>
          <div v-if="doc.item_reference">Item: {{ doc.item_reference }}</div>
          <p>Paper original: {{ doc.original_location || 'Location not recorded' }}</p>
          <p v-if="doc.notes">{{ doc.notes }}</p>
          <small>Shared by {{ doc.linked_copies }} copy/copies.</small>
          <a v-if="doc.file_path" :href="fileUrl('document_id', doc.document_id)">Download PDF scan</a>
          <details v-if="canManage">
            <summary>Edit paper location (applies to all linked books)</summary>
            <form @submit.prevent="submit($event, 'location')">
              <input type="hidden" name="document_id" :value="doc.document_id" />
              <textarea name="original_location" :value="doc.original_location" aria-label="Paper original location"></textarea>
              <button :disabled="busy">Save location</button>
            </form>
          </details>
        </article>
        <template v-if="canManage">
          <details>
            <summary>Add invoice or other document</summary>
            <form @submit.prevent="submit($event, 'document')">
              <label>Title <input name="title" maxlength="512" required /></label>
              <label>Type <select name="document_type"><option value="invoice">Invoice</option><option value="receipt">Receipt</option><option value="seller_description">Seller description (not an invoice)</option><option value="other">Other</option></select></label>
              <label>Document number <input name="document_number" maxlength="190" /></label>
              <label>Seller <input name="seller" maxlength="512" /></label>
              <label>Item / lot reference <input name="item_reference" maxlength="190" /></label>
              <label>Location of the paper original <textarea name="original_location"></textarea></label>
              <label>Notes <textarea name="notes"></textarea></label>
              <label>PDF scan (optional) <input type="file" name="file" accept="application/pdf" /></label>
              <button :disabled="busy">Save document</button>
            </form>
          </details>
          <details>
            <summary>Link an existing shared document</summary>
            <form @submit.prevent="submit($event, 'link')">
              <label>Existing document ID (#...) <input name="document_id" type="number" min="1" required /></label>
              <label>Item / lot reference <input name="item_reference" maxlength="190" /></label>
              <button :disabled="busy">Link document</button>
            </form>
          </details>
        </template>
      </template>
      <button v-if="error" :disabled="busy" @click="load">Retry</button>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { apiUrl, csrfHeader } from '../api';
const props = defineProps({ bookId: { type: [Number, String], required: true }, canManage: Boolean });
const copies=ref([]), copyId=ref(''), data=ref({photos:[],documents:[]});
const error=ref(''), loading=ref(false), busy=ref(false);
const photoTypes=['front_cover','back_cover','spine','title_page','imprint','map','supplement','condition_detail','other'];
const photoAt=(slot)=>data.value.photos.find(p=>Number(p.slot_number)===slot);
const freeSlots=computed(()=>Array.from({length:10},(_,i)=>i+1).filter(s=>!photoAt(s)));
const fileUrl=(key,id)=>apiUrl(`copy_documentation.php?action=file&${key}=${id}`);
let sequence=0;
async function read(url,options={}) {
  const response=await fetch(url,{credentials:'same-origin',...options});
  const json=await response.json();
  if(!response.ok || json.ok===false) throw new Error(json.error || 'Unable to load documentation');
  return json.data;
}
async function load() {
  const token=++sequence;
  error.value=''; loading.value=true;
  try {
    const result=await read(apiUrl(`copy_documentation.php?copy_id=${copyId.value}`));
    if(token===sequence) data.value=result;
  } catch(e) { if(token===sequence) error.value=e.message; }
  finally { if(token===sequence) loading.value=false; }
}
watch(()=>props.bookId,async(id)=>{
  ++sequence; copies.value=[]; copyId.value=''; error.value='';
  if(!id) return;
  try {
    const result=await read(apiUrl(`list_book_copies.php?book_id=${id}`));
    if(id!==props.bookId) return;
    copies.value=result.copies.filter(c=>c.format==='print' && Number(c.quantity)===1);
    copyId.value=String(copies.value[0]?.copy_id || '');
  } catch(e) { error.value=e.message; }
},{immediate:true});
watch(copyId,(id)=>{data.value={photos:[],documents:[]}; if(id) load();});
async function submit(event,action) {
  if(busy.value) return;
  busy.value=true; error.value='';
  const form=event.target;
  const payload=new FormData(form); payload.set('copy_id',copyId.value); payload.set('action',action);
  try {
    await read(apiUrl('copy_documentation.php'),{method:'POST',headers:csrfHeader(),body:payload});
    form.reset(); await load();
  } catch(e) { error.value=e.message; }
  finally { busy.value=false; }
}
</script>

<style scoped>
.documentation { margin-top:1rem; border-top:1px solid #bbb; padding-top:.5rem; }
.photos { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.5rem; margin:1rem 0; }
.photo { border:1px solid #bbb; border-radius:4px; padding:.5rem; overflow-wrap:anywhere; }
.photo img { width:100%; height:110px; object-fit:contain; display:block; }
form { display:grid; gap:.6rem; margin:.8rem 0; }
label { display:grid; gap:.25rem; }
input,select,textarea { max-width:100%; box-sizing:border-box; }
article { border:1px solid #bbb; padding:.8rem; margin:.6rem 0; overflow-wrap:anywhere; }
article a { display:block; margin:.4rem 0; }
details { margin:.7rem 0; } summary { cursor:pointer; }
[role=alert] { color:#a32222; }
</style>
