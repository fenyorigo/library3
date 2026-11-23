<template>
  <section class="valuation">
    <h4>Értékelés és megőrzés</h4>
    <p v-if="loading">Értékelési adatok betöltése…</p>
    <p v-else-if="error" role="alert">{{ error }} <button @click="load">Újrapróbálás</button></p>
    <p v-else-if="!assessment">Ehhez a könyvhöz még nincs importált értékelés. Ez nem jelenti azt, hogy értéktelen.</p>
    <template v-else>
      <p v-if="Number(assessment.is_high_value) === 1" class="preservation"><strong>{{ assessment.preservation_label }}</strong></p>
      <p v-if="assessment.reason">{{ assessment.reason }}</p>
      <div class="prices">
        <div><strong>Használtpiaci irányérték — becslés</strong><p>{{ amount(assessment.used_amount, assessment.currency) }}</p><small>{{ assessment.used_confidence }}</small><p>{{ assessment.used_method }}</p></div>
        <div><strong>Pótlási könyvár — referencia</strong><p>{{ amount(assessment.replacement_amount, assessment.currency) }}</p><small>{{ assessment.replacement_confidence }}</small><p>{{ assessment.replacement_method }}</p></div>
      </div>
      <p v-if="assessment.heirs_action"><strong>Örökösök teendője:</strong> {{ assessment.heirs_action }}</p>
      <p v-if="assessment.group_key"><strong>Együtt vizsgálandó csoport:</strong> {{ assessment.group_key }}. A közös csomag- vagy sorozatár nem kötetenkénti érték.</p>
      <p><strong>Kutatás:</strong> {{ assessment.research_status || 'Nincs megadva' }} · {{ assessment.priority || 'Nincs kiemelés' }}</p>
      <p><strong>Forrásellenőrzés dátuma:</strong> {{ assessment.checked_on || 'Nincs rögzítve' }}. Az import időpontja: {{ assessment.imported_at }}.</p>
      <details v-if="assessment.owner_condition || assessment.provenance || assessment.completeness">
        <summary>Példányadatok és beszerzési előzmény</summary>
        <p v-if="assessment.owner_condition"><strong>Állapot:</strong> {{ assessment.owner_condition }}</p>
        <p v-if="assessment.completeness"><strong>Teljesség és kiadásazonosítás:</strong> {{ assessment.completeness }}</p>
        <p v-if="assessment.provenance"><strong>Beszerzés:</strong> {{ assessment.provenance }}</p>
      </details>
      <h5>Források ({{ assessment.sources.length }})</h5>
      <p>A becslés, a kínálati ár és a korábbi vételár eltérő adat. A forrásban szereplő összeg jellegét és dátumát minden tételnél külön mutatjuk; a szállítás külön szerepel.</p>
      <article v-for="source in assessment.sources" :key="source.observation_id">
        <strong>{{ source.source_key }} — {{ source.title }}</strong>
        <p>{{ source.observation_kind }}</p>
        <p v-if="source.amount !== null">Forrásban szereplő összeg: <strong>{{ amount(source.amount, source.currency) }}</strong><span v-if="source.shipping_amount !== null">; szállítás: {{ amount(source.shipping_amount, source.currency) }}</span></p>
        <p v-if="source.event_date_text">Az ár / esemény időpontja: {{ source.event_date_text }}</p>
        <p v-if="source.checked_on">Forrás megtekintése: {{ source.checked_on }}</p>
        <p v-if="source.seller">Eladó: {{ source.seller }}</p>
        <p v-if="source.record_ids && /[,;]/.test(source.record_ids)">A forrás több könyvre vonatkozik: {{ source.record_ids }}. A közös árat nem osztottuk fel és nem többszöröztük.</p>
        <p>{{ source.edition_match }}</p><p>{{ source.notes }}</p>
        <small>Szerepe: {{ source.roles.map(role => roleLabels[role]).join(', ') }}</small>
        <a v-if="safeUrl(source.source_url)" :href="safeUrl(source.source_url)" target="_blank" rel="noopener noreferrer">Forrásoldal megnyitása</a>
        <p v-else-if="source.source_url">Dokumentumhivatkozás: {{ source.source_url }}</p>
      </article>
      <p v-if="!assessment.sources.length">Tételes árforrás még nincs rögzítve.</p>
      <details v-if="assessment.history.length > 1"><summary>Korábbi importok ({{ assessment.history.length - 1 }})</summary>
        <ul><li v-for="item in assessment.history.slice(1)" :key="item.assessment_id">{{ item.created_at }} — használt becslés: {{ amount(item.used_amount, 'HUF') }}; pótlási referencia: {{ amount(item.replacement_amount, 'HUF') }}</li></ul>
      </details>
    </template>
  </section>
</template>

<script setup>
import { ref, watch } from 'vue';
import { apiUrl } from '../api';
const props=defineProps({bookId:{type:[Number,String],required:true}});
const assessment=ref(null), loading=ref(false), error=ref('');
const roleLabels={reference:'referencia',used:'használtpiaci becslés alapja',replacement:'pótlási referencia alapja'};
const amount=(value,currency)=>value===null || value===undefined || value==='' ? 'Nincs megalapozott ár' : `${new Intl.NumberFormat('hu-HU',{maximumFractionDigits:2}).format(Number(value))} ${currency || ''}`;
const safeUrl=(value)=>{try {const url=new URL(value); return ['http:','https:'].includes(url.protocol)?url.href:null;} catch {return null;}};
let sequence=0;
async function load() {
  const token=++sequence; assessment.value=null; loading.value=true; error.value='';
  try {
    const response=await fetch(apiUrl(`get_valuation.php?book_id=${props.bookId}`),{credentials:'same-origin'});
    const json=await response.json();
    if(!response.ok || json.ok===false) throw new Error(json.error || 'Az értékelés nem tölthető be.');
    if(token===sequence) assessment.value=json.data.assessment;
  } catch(e) {if(token===sequence) error.value=e.message;}
  finally {if(token===sequence) loading.value=false;}
}
watch(()=>props.bookId,()=>{if(props.bookId) load();},{immediate:true});
</script>
<style scoped>
.valuation { border-top:1px solid var(--btn-border,#bbb); margin-top:1rem; padding-top:.5rem; overflow-wrap:anywhere; }
.prices { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem; }
.prices>div, article { border:1px solid var(--btn-border,#bbb); padding:.8rem; border-radius:6px; margin:.5rem 0; }
.preservation { border-left:4px solid #a66a00; padding:.6rem; }
article a { display:block; margin-top:.5rem; } details { margin:.8rem 0; } summary { cursor:pointer; }
[role=alert] { color:#a32222; }
</style>
