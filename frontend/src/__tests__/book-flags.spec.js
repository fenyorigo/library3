import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BookList from '../components/BookList.vue';
import BookDialog from '../components/BookDialog.vue';
vi.mock('../api',()=>({assetUrl:p=>p,apiUrl:p=>p,csrfHeader:()=>({}),bumpAssetCacheVersion:vi.fn()}));
const wrappers=[];
afterEach(()=>{for(const w of wrappers.splice(0))w.unmount();vi.unstubAllGlobals();});
describe('real cover totals',()=>{
 it('uses the filtered full cover count across pages',()=>{const w=mount(BookList,{props:{rows:[],total:120,coverCount:37,page:2,perPage:100}});wrappers.push(w);expect(w.text()).toContain('Results: 101-120 of 120 (37 covers)');expect(w.text()).not.toContain('images');});
 it('reports zero covers for empty results',()=>{const w=mount(BookList,{props:{rows:[],total:0,coverCount:0}});wrappers.push(w);expect(w.text()).toContain('Results: 0 of 0 (0 covers)');});
});
describe('manual rare/high-value category',()=>{
 function dialog(formats=['print'],flag=0,mode='edit',canManage=true){vi.stubGlobal('fetch',vi.fn(async()=>({ok:true,json:async()=>({ok:true,data:[]})})));const w=mount(BookDialog,{props:{mode,canManage,book:{id:7,title:'TEST',is_high_value:flag,copies:formats.map((format,i)=>({copy_id:i+1,format,quantity:1}))}},global:{stubs:{BookValuation:true,CopyDocumentation:true,CopyArchive:true}}});wrappers.push(w);return w;}
 it.each([['print',true],['epub',false]])('shows the checkbox for %s only',(format,visible)=>{expect(dialog([format]).find('[data-test="high-value-checkbox"]').exists()).toBe(visible);});
 it('hides editing from readers and in view mode',()=>{expect(dialog(['print'],1,'edit',false).find('[data-test="high-value-checkbox"]').exists()).toBe(false);expect(dialog(['print'],1,'view').find('[data-test="high-value-checkbox"]').exists()).toBe(false);});
 it('saves checking and unchecking as boolean changes',async()=>{for(const before of [0,1]){const w=dialog(['print','epub'],before);const box=w.find('[data-test="high-value-checkbox"]');expect(box.element.checked).toBe(Boolean(before));await box.setValue(!before);await w.find('footer .primary').trigger('click');expect(w.emitted('save')[0][0].is_high_value).toBe(!before);}});
 it('does not resend an untouched category during ordinary edits',async()=>{const w=dialog(['print'],1);await w.find('footer .primary').trigger('click');expect(w.emitted('save')[0][0]).not.toHaveProperty('is_high_value');});
 it('hides the checkbox for archived books',async()=>{const w=dialog(['print'],1);await w.setProps({book:{id:7,title:'TEST archive',record_status:'deleted',is_high_value:1,copies:[{copy_id:1,format:'print',quantity:1,record_status:'deleted'}]}});expect(w.find('[data-test="high-value-checkbox"]').exists()).toBe(false);});
 it('allows a classification when creating a printed book',async()=>{const w=dialog(['print'],0,'create');await w.find('[data-test="high-value-checkbox"]').setValue(true);await w.find('footer .primary').trigger('click');expect(w.emitted('create')[0][0].is_high_value).toBe(true);});
});
