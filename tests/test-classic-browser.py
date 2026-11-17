"""Standalone Classic Editor timestamp replacement regression using Chromium DOM.
The real WordPress Classic Editor must still be tested on staging.
Set SMART_SCHEDULE_CHROMIUM to a browser executable path to override the bundled Chromium.
"""
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
html=(root/'tests/browser-harness.html').read_text()
html=html.replace('<link rel="stylesheet" href="../assets/scheduler.css"/>','<style>'+(root/'assets/scheduler.css').read_text()+'</style>')
html=html.replace('<script src="../assets/scheduler.js"></script>','<script>'+(root/'assets/scheduler.js').read_text()+'</script>')
html=html.replace('<script src="../assets/editor.js"></script>', '')
# Use the same mocked conversion REST endpoints but a Classic Editor publish metabox.
classic_markup='''<form id="post"><input type="hidden" id="post_status" value="future"/><section id="classic-metabox"><p>Publish: <a href="#edit_timestamp" class="edit-timestamp">Edit</a></p>
<div id="timestampdiv" style="display:none"><input id="aa"/><input id="mm"/><input id="jj"/><input id="hh"/><input id="mn"/>
<input type="checkbox" id="timestamp"/><button type="button" class="save-timestamp">OK</button></div>
</section></form>'''
setup='''(markup)=>{document.body.insertAdjacentHTML('beforeend',markup);
    window.nativeClassicClicks=0;window.nativeClassicSaves=0;
    document.querySelector('a.edit-timestamp').addEventListener('click',()=>{window.nativeClassicClicks++;});
    document.querySelector('.save-timestamp').addEventListener('click',()=>{window.nativeClassicSaves++;});
    window.jQuery=(node)=>({trigger:(type)=>{if(type==='click'){node.click();}else{node.dispatchEvent(new Event(type,{bubbles:true}));}}});
}'''
launch_kwargs={'headless':True,'args':['--no-sandbox']}
if os.environ.get('SMART_SCHEDULE_CHROMIUM'):
    launch_kwargs['executable_path']=os.environ['SMART_SCHEDULE_CHROMIUM']
with sync_playwright() as p:
    browser=p.chromium.launch(**launch_kwargs)
    page=browser.new_page(viewport={'width':1280,'height':900})
    page.set_content(html)
    page.evaluate(setup,classic_markup)
    page.evaluate("window.SmartScheduleClassic={postId:5,status:'future',dateGmt:'2026-11-20T17:30:00Z'};")
    page.add_script_tag(content=(root/'assets/classic.js').read_text())
    page.locator('a.edit-timestamp').click()
    page.locator('.ss-native-shell').wait_for()
    assert page.evaluate('window.nativeClassicClicks')==0
    assert page.locator('.ss-advanced-toggle').is_visible()
    page.locator('.ss-compact-conversion').wait_for()
    page.get_by_role('button',name='Apply',exact=True).click()
    page.locator('.ss-native-shell').wait_for(state='detached')
    assert page.evaluate('window.nativeClassicSaves')==1
    assert page.locator('#aa').input_value()=='2026'
    assert page.locator('#mm').input_value()=='11'
    assert page.locator('#jj').input_value()=='20'
    assert page.locator('#hh').input_value()=='12'
    assert page.locator('#mn').input_value()=='30'
    assert page.locator('input[name="edit_date"]').input_value()=='true'
    page.set_content(html)
    page.evaluate(setup,classic_markup)
    page.evaluate("document.getElementById('post_status').value='publish';window.SmartScheduleClassic={postId:5,status:'publish',dateGmt:'2026-09-04T17:34:00Z'};")
    page.add_script_tag(content=(root/'assets/classic.js').read_text())
    page.locator('a.edit-timestamp').click()
    page.locator('.ss-native-shell').wait_for()
    assert page.evaluate('window.nativeClassicClicks')==0, 'Published classic posts must open Smart Schedule'
    page.wait_for_function("document.querySelector('#ss-day').value==='04'")
    assert page.locator('#ss-day').input_value()=='04'
    assert page.locator('.ss-apply').is_enabled()
    page.locator('.ss-apply').click()
    page.locator('.ss-native-shell').wait_for(state='detached')
    assert page.locator('#jj').input_value()=='04'
    assert page.evaluate('window.nativeClassicSaves')==1
    # Stale page-load status: the form says publish even though the page-load
    # snapshot said draft (as after an in-page Publish submit). The picker must
    # use the live status and block future dates on the now-published post.
    page.set_content(html)
    page.evaluate(setup,classic_markup)
    page.evaluate("document.getElementById('post_status').value='publish';window.SmartScheduleClassic={postId:5,status:'draft',dateGmt:'2026-09-04T17:34:00Z'};")
    page.add_script_tag(content=(root/'assets/classic.js').read_text())
    page.locator('a.edit-timestamp').click()
    page.locator('.ss-native-shell').wait_for()
    page.wait_for_function("document.querySelector('#ss-day').value==='04'")
    assert page.locator('.ss-apply').inner_text()=='Apply date', 'Live published status must switch the picker to date-edit mode'
    assert page.locator('.ss-apply').is_enabled()
    page.locator('#ss-year').fill('2028')
    page.locator('#ss-year').dispatch_event('change')
    page.wait_for_function("document.querySelector('.ss-warning').textContent.includes('already published')")
    assert page.locator('.ss-apply').is_disabled(), 'Stale draft status allowed a future date on a published post'
    browser.close()
    print('PASS: Classic Editor timestamp replacement, published date edit, and live post-status detection')