import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from '../App.vue'
afterEach(() => vi.unstubAllGlobals())
describe('App', () => {
  it('requires sign-in before presenting the private catalog', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ok: false, status: 401, json: async () => ({ok: false, error: 'Not authenticated'})})))
    const wrapper = mount(App)
    await flushPromises()
    expect(wrapper.text()).toContain('My Book Catalog')
    expect(wrapper.text()).toContain('Sign in')
    expect(wrapper.find('table').exists()).toBe(false)
    wrapper.unmount()
  })
})
