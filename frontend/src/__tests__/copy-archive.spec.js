import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CopyArchive from '../components/CopyArchive.vue';
vi.mock('../api',()=>({apiUrl:p=>p,csrfHeader:()=>({'X-CSRF-Token':'test'})}));
let wrapper;
afterEach(()=>{wrapper?.unmount();vi.unstubAllGlobals();});
describe('archived physical copies',()=>{
  it('offers restoration only for archived print copies and reports the updated copies',async()=>{
    const requests=[];vi.stubGlobal('fetch',vi.fn(async(url,options)=>{requests.push([url,options]);return {ok:true,json:async()=>({ok:true,data:options?.method==='POST'?{copies:[{copy_id:1,format:'print',record_status:'active'}]}:{copies:requests.length>2?[]:[{copy_id:1,format:'print',record_status:'deleted'},{copy_id:2,format:'epub',record_status:'deleted'},{copy_id:3,format:'print',record_status:'active'}]}})};}));
    wrapper=mount(CopyArchive,{props:{bookId:7}});await flushPromises();expect(wrapper.findAll('button')).toHaveLength(1);await wrapper.find('button').trigger('click');await flushPromises();expect(requests[1][1].headers['X-CSRF-Token']).toBe('test');expect(JSON.parse(requests[1][1].body)).toEqual({copy_id:1});expect(wrapper.emitted('restored')[0][0].copies[0].record_status).toBe('active');
  });
  it('does not present an empty archive',async()=>{vi.stubGlobal('fetch',vi.fn(async()=>({ok:true,json:async()=>({ok:true,data:{copies:[]}})})));wrapper=mount(CopyArchive,{props:{bookId:7}});await flushPromises();expect(wrapper.find('section').exists()).toBe(false);});
});
