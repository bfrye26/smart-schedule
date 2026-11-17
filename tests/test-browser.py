"""No-network Gutenberg DOM smoke test with real headless Chromium.
Execute: python tests/test-browser.py
This tests the editor click interception against a mocked WP store, not WordPress itself.
Set SMART_SCHEDULE_CHROMIUM to a browser executable path to override the bundled Chromium.
Screenshots are written to a temp directory, not the plugin tree.
"""
import os
import tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright

plugin_dir = Path(__file__).resolve().parent.parent
artifacts = Path(tempfile.mkdtemp(prefix='smart-schedule-tests-'))
html = (plugin_dir / 'tests/browser-harness.html').read_text()
css = (plugin_dir / 'assets/scheduler.css').read_text()
scheduler = (plugin_dir / 'assets/scheduler.js').read_text()
editor = (plugin_dir / 'assets/editor.js').read_text()
html = html.replace('<link rel="stylesheet" href="../assets/scheduler.css"/>', '<style>' + css + '</style>')
html = html.replace('<script src="../assets/scheduler.js"></script>', '<script>' + scheduler + '</script>')
html = html.replace('<script src="../assets/editor.js"></script>', '<script>' + editor + '</script>')

launch_kwargs = {'headless': True, 'args': ['--no-sandbox']}
if os.environ.get('SMART_SCHEDULE_CHROMIUM'):
    launch_kwargs['executable_path'] = os.environ['SMART_SCHEDULE_CHROMIUM']

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(**launch_kwargs)
    page = browser.new_page(viewport={'width': 1440, 'height': 1050})
    errors = []
    page.on('pageerror', lambda err: errors.append(str(err)))
    page.set_content(html)
    toggle = page.locator('.editor-post-schedule__dialog-toggle')
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    assert page.evaluate('window.nativeClicks') == 0, 'Native Gutenberg click was not intercepted'
    toggle.click()
    assert page.locator('.ss-native-shell').count() == 0, 'Clicking the same native button did not toggle the popup closed'
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    assert page.locator('.ss-document-panel').count() == 0, 'Redundant sidebar panel present'
    assert page.locator('.ss-overlay').count() == 0, 'Opened fullscreen instead of anchored popover'
    assert page.locator('.ss-advanced-toggle').is_visible(), 'Advanced option not in popover'
    assert not page.locator('.ss-policy').is_visible(), 'Advanced-only controls visible by default'
    assert not page.locator('.ss-conversion').is_visible(), 'Detailed conversion must be advanced-only'
    assert page.locator('#ss-day').input_value() == '20'
    assert page.locator('#ss-month').input_value() == '11'
    assert page.locator('#ss-year').input_value() == '2026'
    # The picker must open in the website timezone; another zone only appears
    # after an explicit selection.
    assert page.locator('#ss-zone').input_value() == 'America/Toronto', 'Picker did not default to the website timezone'
    assert 'Website time' in page.locator('#ss-zone option:checked').inner_text()
    assert not page.locator('.ss-compact-conversion').is_visible(), 'No conversion summary should show for the website timezone'
    assert page.locator('#ss-hour').input_value() == '12'
    page.locator('#ss-zone').select_option('America/Los_Angeles')
    page.wait_for_function("document.querySelector('#ss-hour').value === '09'")
    assert page.locator('.ss-compact-conversion').is_visible(), 'Website time summary must show for a different timezone'
    assert '12:30 PM' in page.locator('.ss-compact-conversion').inner_text()
    assert page.locator('.ss-dialog').evaluate('(n)=>n.scrollHeight<=n.clientHeight+2'), 'Compact editor should fit in a normal 1050px viewport'
    page.screenshot(path=str(artifacts / 'native-popover-compact.png'))
    page.get_by_role('button', name='Advanced options').click()
    assert page.locator('.ss-policy').is_visible()
    assert page.locator('#ss-zone-search').is_visible()
    assert page.locator('.ss-preview-row').first.is_visible()
    assert '9:30 AM' in page.locator('.ss-preview-row').first.inner_text()
    assert '12:30 PM' in page.locator('.ss-preview-row').nth(1).inner_text()
    assert page.locator('.ss-advanced-toggle').get_attribute('aria-expanded') == 'true'
    assert page.locator('.ss-dialog').bounding_box()['width'] <= 645, 'Advanced popup too wide'
    assert page.locator('.ss-advanced-region').bounding_box()['x'] > page.locator('.ss-calendar').bounding_box()['x'] + 250, 'Advanced controls should open beside calendar'
    page.screenshot(path=str(artifacts / 'native-popover-advanced.png'))
    page.get_by_role('button', name='24-hour').click()
    assert page.locator('#ss-hour').input_value() == '09'
    assert '09:30' in page.locator('.ss-preview-row').first.inner_text()
    page.locator('#ss-hour').fill('99')
    assert page.locator('.ss-apply').is_disabled()
    page.locator('#ss-hour').fill('09')
    page.locator('#ss-hour').dispatch_event('change')
    page.wait_for_function("!document.querySelector('.ss-apply').disabled")
    page.locator('#ss-zone').select_option('America/Toronto')
    page.wait_for_function("document.querySelector('#ss-hour').value === '12'")
    assert not page.locator('.ss-compact-conversion').is_visible(), 'Website time should not show a duplicate conversion when website timezone is selected'
    # Calendar: Today button and arrow-key navigation.
    page.locator('.ss-month-today').click()
    assert page.evaluate("document.querySelector('#ss-date').value === new Date().toISOString().slice(0,10)"), 'Today button did not select the current date'
    page.locator('.ss-calendar-day.is-selected').focus()
    selected_date = page.evaluate("document.querySelector('.ss-calendar-day.is-selected').dataset.date")
    page.keyboard.press('ArrowRight')
    focused_date = page.evaluate("document.activeElement.dataset.date")
    assert focused_date and focused_date != selected_date, 'Arrow-key navigation did not move calendar focus'
    page.keyboard.press('ArrowLeft')
    assert page.evaluate("document.activeElement.dataset.date") == selected_date, 'ArrowLeft did not return to the original day'
    # Restore the staged scenario date before applying.
    page.locator('#ss-day').fill('20')
    page.locator('#ss-day').dispatch_event('change')
    page.locator('#ss-month').select_option('11')
    page.locator('#ss-year').fill('2026')
    page.locator('#ss-year').dispatch_event('change')
    page.wait_for_function("!document.querySelector('.ss-apply').disabled")
    # End this session in a non-site zone to prove the next open resets to site.
    page.locator('#ss-zone').select_option('America/Los_Angeles')
    page.wait_for_function("document.querySelector('#ss-hour').value === '09'")
    page.wait_for_function("!document.querySelector('.ss-apply').disabled")
    page.locator('.ss-apply').click()
    page.locator('.ss-native-shell').wait_for(state='detached')
    assert page.evaluate('window.edits.date') == '2026-11-20T12:30:00'
    assert page.evaluate('window.edits.smart_schedule_utc_intent') == '2026-11-20T17:30:00Z'
    assert page.evaluate('window.nativeClicks') == 0
    # Save verification: a manual save that persists the staged UTC must confirm.
    page.evaluate('window.notices = []')
    page.evaluate('window.simulateSave()')
    page.wait_for_function("window.notices.some(n => n.level === 'success')")
    assert page.evaluate("window.notices.some(n => n.message.includes('verified'))"), 'Successful save was not verified'
    # Staging again, then saving a DIFFERENT date_gmt must raise the warning.
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    assert page.locator('#ss-zone').input_value() == 'America/Toronto', 'Reopened picker must return to the website timezone'
    page.wait_for_function("!document.querySelector('.ss-apply').disabled")
    page.locator('.ss-apply').click()
    page.locator('.ss-native-shell').wait_for(state='detached')
    page.evaluate('window.notices = []')
    page.evaluate("window.simulateSave('2026-11-20T18:00:00')")
    page.wait_for_function("window.notices.some(n => n.level === 'warning')")
    assert page.evaluate("window.notices.some(n => n.message.includes('could not verify'))"), 'Mismatched save did not warn'
    # Editing the native date after staging must clear the pending UTC intent.
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    page.wait_for_function("!document.querySelector('.ss-apply').disabled")
    page.locator('.ss-apply').click()
    page.locator('.ss-native-shell').wait_for(state='detached')
    assert page.evaluate("window.edits.smart_schedule_utc_intent") == '2026-11-20T17:30:00Z'
    page.evaluate("wp.data.dispatch('core/editor').editPost({date:'2026-11-21T09:00:00'})")
    assert page.evaluate("window.edits.smart_schedule_utc_intent") == '', 'Native date edit did not clear the staged UTC intent'
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    page.locator('#ss-day').fill('31')
    page.locator('#ss-month').select_option('2')
    assert page.locator('.ss-apply').is_disabled(), 'Invalid calendar date was accepted'
    page.locator('#ss-month').select_option('11')
    page.keyboard.press('Escape')
    assert page.locator('.ss-native-shell').count() == 0
    assert toggle.evaluate('(node) => document.activeElement === node'), 'Focus did not restore to native control'
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    page.locator('#canvas h1').click(position={'x':10,'y':10})
    assert page.locator('.ss-native-shell').count() == 0, 'Outside-click did not dismiss popover'
    page.set_content(html)  # Start a fresh published-post edit session, as WordPress would after a save.
    page.evaluate("window.postData={id:5,status:'publish',date:'2026-09-04T13:34:00',date_gmt:'2026-09-04T17:34:00'};window.edits={}")
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    assert page.evaluate('window.nativeClicks') == 0, 'Published post should use the replacement picker'
    page.wait_for_function("document.querySelector('#ss-day').value==='04'")
    assert page.locator('#ss-day').input_value() == '04', 'Published post must load its existing date'
    assert page.locator('#ss-month').input_value() == '9'
    assert page.locator('#ss-year').input_value() == '2026'
    assert page.locator('.ss-apply').is_enabled(), 'Past publish-date editing must be allowed'
    assert page.locator('.ss-dialog').bounding_box()['width'] <= 345, 'Compact popup is too wide'
    page.screenshot(path=str(artifacts / 'native-popover-published.png'))
    page.locator('#ss-year').fill('2028')
    page.locator('#ss-year').dispatch_event('change')
    page.wait_for_function("document.querySelector('.ss-warning').textContent.includes('already published')")
    assert page.locator('.ss-apply').is_disabled(), 'Already published post must not silently unpublish for future scheduling'
    page.locator('#ss-year').fill('2026')
    page.locator('#ss-year').dispatch_event('change')
    page.wait_for_function("!document.querySelector('.ss-apply').disabled")
    page.locator('#ss-day').fill('03')
    page.locator('#ss-day').dispatch_event('change')
    page.wait_for_function("!document.querySelector('.ss-apply').disabled")
    page.locator('.ss-apply').click()
    page.locator('.ss-native-shell').wait_for(state='detached')
    assert page.evaluate('window.edits.date') == '2026-09-03T13:34:00', 'Published post date was not staged'
    assert page.evaluate('window.edits.smart_schedule_utc_intent') == '2026-09-03T17:34:00Z'
    assert not errors, errors
    # Drafts may retain a past WordPress post_date_gmt from their last edit.
    # The scheduler must start at a future time instead of showing a dead-end warning.
    page.set_content(html)
    page.evaluate("window.postData={id:5,status:'draft',date:'2026-09-04T13:34:00',date_gmt:'2026-09-04T17:34:00'};window.edits={}")
    toggle = page.locator('.editor-post-schedule__dialog-toggle')
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    page.wait_for_function("document.querySelector('.ss-apply') && !document.querySelector('.ss-apply').disabled")
    displayed = page.locator('#ss-date').input_value()
    assert displayed != '2026-09-04', 'An old draft date was retained in the initial scheduler'
    assert not page.locator('.ss-warning').is_visible(), 'New draft should not open with a past-time warning'
    page.locator('.ss-close').click()
    # With lead_minutes=0 a stored date one minute out is eligible and must be kept.
    page.set_content(html)
    page.evaluate("window.SmartScheduleData.minLead=0;window.postData={id:5,status:'draft',date:'',date_gmt:new Date(Date.now()+60000).toISOString().slice(0,19)+'Z'};window.edits={}")
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    page.wait_for_function("document.querySelector('.ss-apply') && !document.querySelector('.ss-apply').disabled")
    assert page.evaluate("document.querySelector('#ss-date').value === window.postData.date_gmt.slice(0,10)"), 'lead_minutes=0 must not bump an eligible stored date'
    page.locator('.ss-close').click()
    # REST outage fallback: the picker must still initialize locally and Apply
    # must re-validate on the server (convert route still reachable here).
    page.set_content(html)
    page.evaluate("window.failRoutes={format:true};window.postData={id:5,status:'draft',date:'',date_gmt:''};window.edits={}")
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    page.wait_for_function("document.querySelector('.ss-apply') && !document.querySelector('.ss-apply').disabled")
    assert page.locator('#ss-date').input_value() != '', 'REST fallback did not initialize a date'
    assert page.evaluate("document.querySelector('.ss-preview').textContent.includes('Offline preview')"), 'Fallback preview notice missing'
    page.locator('.ss-apply').click()
    page.locator('.ss-native-shell').wait_for(state='detached')
    assert page.evaluate("window.edits.smart_schedule_utc_intent && window.edits.smart_schedule_utc_intent.endsWith('Z')"), 'Apply after fallback did not stage a server-validated UTC intent'
    assert not errors, errors
    # Narrow editor: the anchored popup must stay inside the viewport.
    page.set_content(html)
    page.evaluate("window.postData.status='future';window.edits={}")
    page.set_viewport_size({'width':390,'height':740})
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    box = page.locator('.ss-native-shell').bounding_box()
    assert box['x'] >= 0 and box['x'] + box['width'] <= 390, box
    page.locator('.ss-advanced-toggle').click()
    box = page.locator('.ss-native-shell').bounding_box()
    assert box['x'] >= 0 and box['x'] + box['width'] <= 390, box
    page.screenshot(path=str(artifacts / 'native-popover-mobile.png'))
    page.locator('.ss-native-shell .ss-close').click()
    page.set_viewport_size({'width':1280,'height':900})
    page.evaluate("SmartScheduleData.siteZone='+00:00'; SmartScheduleData.zone='+00:00'; SmartScheduleData.siteFixed=true; SmartScheduleData.favorites=['+00:00'];")
    toggle.click()
    page.locator('.ss-native-shell').wait_for()
    assert 'Website time (UTC+00:00)' in page.locator('#ss-zone option:checked').inner_text()
    assert not page.locator('.ss-compact-conversion').is_visible(), 'No duplicated website conversion in the compact fixed-UTC case'
    page.screenshot(path=str(artifacts / 'native-popover-website-utc.png'))
    page.locator('.ss-advanced-toggle').click()
    assert page.locator('.ss-fixed-notice').first.is_visible(), 'Fixed offset diagnostic should live in Advanced only'
    assert not errors, errors
    browser.close()
    print('PASS: Gutenberg native popup, compact/advanced widths, conversion, calendar keyboard nav, staged saves, save verification (match + mismatch), intent clearing, published date editing, future safeguards, lead-time floor, REST fallback, keyboard and mobile')
    print('Screenshots: ' + str(artifacts))