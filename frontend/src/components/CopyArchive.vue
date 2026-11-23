<template>
  <section v-if="copies.length" class="copy-archive">
    <h3>Archivált nyomtatott példányok</h3>
    <p>A képek, számlák és értékelési előzmények megmaradtak.</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <div v-for="copy in copies" :key="copy.copy_id">
      <span>#{{ copy.copy_id }} · {{ copy.physical_location || 'Nincs megadott hely' }}</span>
      <button type="button" :disabled="busy" @click="restore(copy.copy_id)">Példány visszaállítása</button>
    </div>
  </section>
</template>
<script setup>
import { ref, watch } from 'vue';
import { apiUrl, csrfHeader } from '../api';
const props=defineProps({bookId:{type:[Number,String],required:true}});
const emit=defineEmits(['restored']);
const copies=ref([]),error=ref(''),busy=ref(false);
async function load(){
  try { const response=await fetch(apiUrl(`list_book_copies.php?book_id=${props.bookId}&include_deleted=1`),{credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Nem sikerült betölteni.');copies.value=(result.data?.copies||[]).filter(c=>c.format==='print'&&c.record_status==='deleted'); }
  catch(e){error.value=e.message;}
}
async function restore(copyId){busy.value=true;error.value='';try {const response=await fetch(apiUrl('restore_book_copy.php'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',...csrfHeader()},body:JSON.stringify({copy_id:copyId})});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Nem sikerült visszaállítani.');await load();emit('restored',result.data);}catch(e){error.value=e.message;}finally{busy.value=false;}}
watch(()=>props.bookId,load,{immediate:true});
</script>
<style scoped>
.copy-archive{margin-top:1rem;padding:1rem;border:1px solid #d3a969;border-radius:8px}.copy-archive div{display:flex;gap:1rem;align-items:center;margin:.5rem 0}
</style>
