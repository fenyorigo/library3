import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BookDetailModal from '../components/BookDetailModal.vue';
import BookDialog from '../components/BookDialog.vue';
vi.mock('../api',()=>({assetUrl:(path)=>path,apiUrl:(path)=>path,csrfHeader:()=>({}),bumpAssetCacheVersion:vi.fn()}));
const wrappers=[];
afterEach(()=>{for(const wrapper of wrappers.splice(0))wrapper.unmount();vi.unstubAllGlobals();});
describe.each([['details',BookDetailModal],['view dialog',BookDialog]])('%s print-only sections',(_label,Component)=>{
  it.each([['ebook only',['epub'],false],['print',['print'],true],['mixed',['epub','print'],true],['no saved copies',[],false]])('%s',(name,formats,expected)=>{
    vi.stubGlobal('fetch',vi.fn(async()=>({ok:true,json:async()=>({ok:true,data:[]})})));
    const book={id:7,title:name,copies:formats.map((format,index)=>({copy_id:index+1,format,quantity:1}))};
    const wrapper=mount(Component,{props:Component===BookDetailModal?{open:true,book}:{mode:'view',book,canManage:false},global:{stubs:{BookValuation:true,CopyDocumentation:true}}});
    wrappers.push(wrapper);
    expect(wrapper.find('book-valuation-stub').exists()).toBe(expected);
    expect(wrapper.find('copy-documentation-stub').exists()).toBe(expected);
  });
});
