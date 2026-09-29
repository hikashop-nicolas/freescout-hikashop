#!/usr/bin/env python3
"""End-to-end tests of the module, against the dev FreeScout (dev/compose.yml) and local shops.

    python3 dev/test.py

It logs into FreeScout over HTTP like a browser, drives the settings pages and the sidebar's
AJAX route, and checks what comes back. Test data is written straight into the shops' databases
(pairing codes, a few orders) and removed afterwards. Written for the maintainers' machine: the
shops below are local test sites served at http://localhost:8080, reached by FreeScout's
container as host.docker.internal, with their databases on the local MySQL (root, socket).

    j67   a Joomla shop on HikaShop 6.7 (email lookup, sections), with the section test plugin
    j66   a Joomla shop on HikaShop 6.6 (neither)
    wp    a WordPress shop
"""
import http.cookiejar, json, os, re, secrets, subprocess, sys, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
DESK = 'http://127.0.0.1:8096'
ADMIN = ('admin@example.test', 'freescout-dev-admin')
AGENT = ('agent@example.test', 'freescout-dev-agent')
SHOPS = {
    'j67': {'db': 'joomla6_fs', 'prefix': None, 'url': 'http://host.docker.internal:8080/joomla6_fs', 'root': '/usr/local/var/www/joomla6_fs'},
    'j66': {'db': 'joomla6', 'prefix': None, 'url': 'http://host.docker.internal:8080/joomla6', 'root': '/usr/local/var/www/joomla6'},
    'wp':  {'db': 'wp7', 'prefix': 'wp_', 'url': 'http://host.docker.internal:8080/wordpress7', 'root': '/usr/local/var/www/wordpress7'},
}
ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

passed = failed = 0


def ok(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print('  \033[32mPASS\033[0m ' + name)
    else:
        failed += 1
        print('  \033[31mFAIL\033[0m ' + name + (' (' + detail + ')' if detail else ''))


# ---- shops ---------------------------------------------------------------

def prefix(shop):
    s = SHOPS[shop]
    if s['prefix'] is None:
        cfg = open(os.path.join(s['root'], 'configuration.php')).read()
        s['prefix'] = re.search(r"dbprefix = '([^']*)'", cfg).group(1)
    return s['prefix']


def shop_sql(shop, sql):
    out = subprocess.run(['mysql', '-uroot', '-N', '-B', SHOPS[shop]['db'], '-e', sql.replace('#__', prefix(shop))],
                         capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr)
    return out.stdout.strip()


def pairing_code(shop, scopes='read'):
    code = ''.join(secrets.choice(ALPHABET) for _ in range(9))
    h = __import__('hashlib').sha256(code.encode()).hexdigest()
    shop_sql(shop, "INSERT INTO #__hikashop_api_pairing (pairing_code_hash,pairing_scopes,pairing_user_id,pairing_created,"
                   "pairing_expires,pairing_used,pairing_attempts,pairing_created_by) SELECT '%s','%s',MIN(id),UNIX_TIMESTAMP(),"
                   "UNIX_TIMESTAMP()+600,0,0,MIN(id) FROM #__users" % (h, scopes) if shop != 'wp' else
                   "INSERT INTO #__hikashop_api_pairing (pairing_code_hash,pairing_scopes,pairing_user_id,pairing_created,"
                   "pairing_expires,pairing_used,pairing_attempts,pairing_created_by) SELECT '%s','%s',MIN(ID),UNIX_TIMESTAMP(),"
                   "UNIX_TIMESTAMP()+600,0,0,MIN(ID) FROM #__users" % (h, scopes))
    return code[:3] + '-' + code[3:6] + '-' + code[6:]


def forget_devices(shop):
    shop_sql(shop, "DELETE FROM #__hikashop_api_device WHERE device_name LIKE 'FreeScout%'")


# ---- FreeScout -----------------------------------------------------------

def compose(*args, stdin=None):
    return subprocess.run(['docker', 'compose', '-f', os.path.join(HERE, 'compose.yml')] + list(args),
                          input=stdin, capture_output=True, text=True)


def tinker(php):
    out = compose('exec', '-T', 'app', 'sh', '-c', 'cd /www/html && su -s /bin/sh nginx -c "php artisan tinker"', stdin=php)
    return out.stdout


class Session:
    def __init__(self, email, password):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''
        self.get('/login')
        self.last = self.post('/login', {'email': email, 'password': password})

    def _remember(self, html):
        m = re.search(r'name="csrf-token" content="([^"]+)"', html)
        if m:
            self.csrf = m.group(1)
        return html

    def get(self, path):
        try:
            with self.opener.open(DESK + path) as r:
                self.status = r.status
                return self._remember(r.read().decode())
        except urllib.error.HTTPError as e:
            self.status = e.code
            return e.read().decode(errors='replace')

    def post(self, path, data):
        data = dict(data, _token=self.csrf)
        body = urllib.parse.urlencode(data, doseq=True).encode()
        try:
            with self.opener.open(urllib.request.Request(DESK + path, data=body)) as r:
                self.status = r.status
                return self._remember(r.read().decode())
        except urllib.error.HTTPError as e:
            self.status = e.code
            return e.read().decode(errors='replace')

    def ajax(self, mailbox_id, emails, refresh=False):
        raw = self.post('/hikashop/ajax', {'mailbox_id': mailbox_id, 'emails[]': emails, 'refresh': 1 if refresh else 0})
        try:
            return json.loads(raw)
        except ValueError:
            return {'status': 'http', 'msg': raw[:200]}

    def order(self, mailbox_id, emails, order_id):
        raw = self.post('/hikashop/ajax', {'action': 'order', 'order_id': order_id, 'mailbox_id': mailbox_id, 'emails[]': emails})
        try:
            return json.loads(raw)
        except ValueError:
            return {'status': 'http', 'msg': raw[:200]}

    def save_global(self, url, code=''):
        self.get('/app-settings/hikashop')
        return self.post('/app-settings/hikashop', {'settings[hikashop.shop_url]': url, 'hikashop_pairing_code': code})

    def save_mailbox(self, mailbox_id, url, code=''):
        self.get('/mailbox/hikashop/%d' % mailbox_id)
        return self.post('/mailbox/hikashop/%d' % mailbox_id, {'shop_url': url, 'pairing_code': code})


def text_of(html):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html))


# ---- setup ---------------------------------------------------------------

def setup():
    # Mailboxes 1 (Support), 2 and 3, an agent in mailbox 1 only, and conversation 1 from Antoine.
    tinker(r"""
foreach ([['Support', 'support@example.com'], ['Brand two', 'brand2@example.com'], ['Brand three', 'brand3@example.com']] as $d) {
    $m = \App\Mailbox::where('email', $d[1])->first();
    if (!$m) { $m = new \App\Mailbox(); $m->name = $d[0]; $m->email = $d[1]; $m->save(); $m->createPublicFolders(); }
    $m->hikashop = null; $m->save();
}
$a = \App\User::where('email', '%s')->first();
if (!$a) { $a = new \App\User(); $a->email = '%s'; $a->first_name = 'Test'; $a->last_name = 'Agent'; $a->role = \App\User::ROLE_USER; }
$a->password = \Hash::make('%s'); $a->save();
$a->mailboxes()->sync([\App\Mailbox::where('email', 'support@example.com')->first()->id]);
foreach (\App\Mailbox::all() as $m) {
    $m->syncPersonalFolders();
    // Mail goes to the dev Mailpit only (compose.yml), never out.
    $m->out_method = \App\Mailbox::OUT_METHOD_SMTP; $m->out_server = 'mailpit'; $m->out_port = 1025;
    $m->out_encryption = \App\Mailbox::OUT_ENCRYPTION_NONE; $m->out_username = ''; $m->out_password = ''; $m->save();
}
foreach (['mail_driver' => 'smtp', 'mail_host' => 'mailpit', 'mail_port' => 1025, 'mail_encryption' => '', 'mail_username' => '', 'mail_password' => ''] as $k => $v) { \Option::set($k, $v); }
echo 'mailboxes:'.\App\Mailbox::pluck('id', 'email')->toJson();
""" % (AGENT[0], AGENT[0], AGENT[1]))
    ids = json.loads(re.search(r'mailboxes:(\{[^}]*\})', tinker("echo 'mailboxes:'.\\App\\Mailbox::pluck('id', 'email')->toJson();")).group(1))
    for shop in SHOPS:
        forget_devices(shop)
    shop_sql('j67', "DELETE FROM #__hikashop_order_product WHERE order_id IN (SELECT order_id FROM #__hikashop_order WHERE order_number LIKE 'FSTEST%')")
    shop_sql('j67', "DELETE FROM #__hikashop_order WHERE order_number LIKE 'FSTEST%'")
    return ids['support@example.com'], ids['brand2@example.com'], ids['brand3@example.com']


def add_order(shop, user_id, number, currency_id=1, total=10):
    shop_sql(shop, "SET SESSION sql_mode=''; INSERT INTO #__hikashop_order (order_user_id,order_type,order_status,order_number,"
                   "order_created,order_modified,order_currency_id,order_full_price) VALUES (%d,'sale','confirmed','%s',"
                   "UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),%d,%s)" % (user_id, number, currency_id, total))


# ---- the tests -----------------------------------------------------------

def main():
    support, brand2, brand3 = setup()
    admin = Session(*ADMIN)
    ok('admin logs in', 'Log out' in admin.last or 'logout' in admin.last, 'login page again')

    print('global settings')
    html = admin.save_global('file:///etc/passwd', pairing_code('j67'))
    ok('a non-http address is refused, with a message', 'cannot be used' in html)
    html = admin.save_global(SHOPS['j67']['url'], 'AAA-BBB-CCC')
    ok('a wrong pairing code is refused, with the shop\'s reason', 'refused the pairing code' in html, text_of(html)[:200])
    code = pairing_code('j67')
    html = admin.save_global(SHOPS['j67']['url'], code)
    ok('a good code connects', 'Connected to' in html, text_of(html)[:200])
    ok('the shop now lists the FreeScout device', shop_sql('j67', "SELECT COUNT(*) FROM #__hikashop_api_device WHERE device_name LIKE 'FreeScout%'") == '1')
    html = admin.save_global(SHOPS['j67']['url'], code)
    ok('a spent code is refused', 'refused the pairing code' in html)
    r = admin.ajax(support, ['antoine.fischer274@example.com'])
    ok('the spent code did not disconnect the shop', r.get('status') == 'success' and 'Antoine' in r.get('html', ''))

    print('sidebar')
    page = admin.get('/conversation/1')
    ok('the conversation page carries the HikaShop block', 'id="hikashop-customer"' in page)
    r = admin.ajax(support, ['antoine.fischer274@example.com'], refresh=True)
    h = r.get('html', '')
    ok('a known customer is shown', 'Antoine Fischer' in h, r.get('msg', ''))
    ok('with a link to their page in the backend', 'ctrl=user&amp;task=edit&amp;cid[]=5212' in h)
    ok('their latest orders, with totals in the shop format', 'DLTEST1' in h and '10,00 €' in h)
    ok('order links go to the backend order page', 'ctrl=order&amp;task=edit' in h)
    ok('the downloads section comes from the shop', 'Downloads' in h and 'Download period has elapsed' in h)
    ok('an expired download is flagged in red', 'hikashop-tone-danger' in h)
    ok('another extension\'s section is shown', 'Licences' in h and 'Until 3 April 2028' in h)
    ok('a ref row links to the backend product page', 'ctrl=product&amp;task=edit&amp;cid[]=136' in h)
    ok('an outside link is kept', 'href="https://www.example.com/"' in h)
    ok('HTML from the shop is escaped', '&lt;script&gt;' in h and '<script>alert' not in h and '&lt;b&gt;Bold' in h)
    ok('an unknown tone becomes neutral', 'hikashop-tone-evil' not in h)
    r = admin.ajax(support, ['nobody@example.org'])
    ok('an unknown address says so', 'No customer with this address' in r.get('html', ''))
    r = admin.ajax(support, ['nobody@example.org', 'antoine.fischer274@example.com'])
    ok('the second of several addresses is found', 'Antoine Fischer' in r.get('html', ''))
    r = admin.ajax(support, ['sb-kp9bf13762189@personal.example.com'])
    ok('a guest is labelled as such', '>guest<' in r.get('html', ''))
    r = admin.ajax(support, ['amelia.dubois189@example.com'])
    h = r.get('html', '')
    ok('a customer without orders shows no order list', 'Latest orders' not in h and '0 orders' in h, text_of(h)[:200])
    r = admin.ajax(support, ['not-an-address', 'ANTOINE.FISCHER274@EXAMPLE.COM'])
    ok('invalid addresses are ignored, case does not matter', 'Antoine Fischer' in r.get('html', ''))

    print('order lines')
    own = shop_sql('j67', "SELECT order_id FROM #__hikashop_order WHERE order_number = 'DEMO00012'")
    item = shop_sql('j67', "SELECT order_product_name FROM #__hikashop_order_product WHERE order_id = %s ORDER BY order_product_id LIMIT 1" % own)
    r = admin.order(support, ['antoine.fischer274@example.com'], own)
    h = r.get('html', '')
    ok('an order\'s lines come with its products', item.split(' ')[0] in h and '×' in h, text_of(h)[:200])
    ok('and its total', '775,07 €' in h)
    ok('the order numbers in the sidebar carry their id', 'data-order="%s"' % own in admin.ajax(support, ['antoine.fischer274@example.com']).get('html', ''))
    other = shop_sql('j67', "SELECT order_id FROM #__hikashop_order WHERE order_user_id <> 5212 AND order_type = 'sale' ORDER BY order_id LIMIT 1")
    r = admin.order(support, ['antoine.fischer274@example.com'], other)
    ok('another customer\'s order is refused', 'not one of this customer' in r.get('html', ''), text_of(r.get('html', ''))[:120])
    r = admin.order(support, ['antoine.fischer274@example.com'], 99999999)
    ok('an unknown order gives a readable error', r.get('status') == 'success' and 'text-danger' in r.get('html', ''))

    print('cache')
    add_order('j67', 5212, 'FSTEST1', currency_id=2, total=12.5)
    r = admin.ajax(support, ['antoine.fischer274@example.com'])
    ok('a cached answer is used within the hour', 'FSTEST1' not in r.get('html', ''))
    r = admin.ajax(support, ['antoine.fischer274@example.com'], refresh=True)
    h = r.get('html', '')
    ok('refresh asks the shop again', 'FSTEST1' in h)
    ok('a dollar total has its symbol in front', re.search(r'\$\s?12', h) is not None, re.findall(r'[^>]*12[.,]5[^<]*', h)[:1] and re.findall(r'[^>]*12[.,]5[^<]*', h)[0] or '')

    print('failures')
    forget_devices('j67')
    r = admin.ajax(support, ['antoine.fischer274@example.com'], refresh=True)
    ok('a revoked token gives a readable error, not a crash', r.get('status') == 'success' and 'could not be read' in r.get('html', ''), str(r)[:200])
    tinker(r"""$m = \App\Mailbox::find(%d); $m->hikashop = json_encode(['shop_url' => 'http://host.docker.internal:8099', 'token' => encrypt('x')]); $m->save();""" % brand3)
    r = admin.ajax(brand3, ['antoine.fischer274@example.com'], refresh=True)
    ok('an unreachable shop gives a readable error', 'Could not reach the shop' in r.get('html', ''), str(r)[:200])
    html = admin.save_global(SHOPS['j67']['url'], pairing_code('j67'))
    ok('pairing again after a revocation works', 'Connected to' in html)

    print('mailbox shops')
    html = admin.save_mailbox(brand2, 'ftp://example.com')
    ok('a refused mailbox address is reported', 'cannot be used' in html)
    html = admin.save_mailbox(brand2, SHOPS['wp']['url'], pairing_code('wp'))
    ok('a mailbox pairs with a shop of its own (WordPress)', 'Connected to' in html, text_of(html)[:200])
    ok('the mailbox page then says connected', 'Connected.' in admin.get('/mailbox/hikashop/%d' % brand2))
    r = admin.ajax(brand2, ['john.doe@example.com'], refresh=True)
    h = r.get('html', '')
    ok('that mailbox reads its own shop', 'john.doe@example.com' in h.lower() or 'John' in h, str(r)[:200])
    ok('WordPress backend links go to wp-admin', 'wp-admin/admin.php?page=com_hikashop&amp;ctrl=' in h)
    r = admin.ajax(support, ['john.doe@example.com'])
    ok('other mailboxes keep the global shop', 'wp-admin' not in r.get('html', ''))
    admin.save_mailbox(brand2, SHOPS['j66']['url'])
    r = admin.ajax(brand2, ['antoine.fischer274@example.com'], refresh=True)
    ok('changing the address without a code drops the pairing (global shop again)', 'Licences' in r.get('html', ''), str(r)[:200])
    admin.save_mailbox(brand2, '')
    ok('an empty address clears the mailbox shop', 'Connected.' not in admin.get('/mailbox/hikashop/%d' % brand2))

    print('older shop (HikaShop 6.6)')
    html = admin.save_mailbox(brand2, SHOPS['j66']['url'], pairing_code('j66'))
    ok('it pairs', 'Connected to' in html, text_of(html)[:200])
    r = admin.ajax(brand2, ['antoine.fischer274@example.com'], refresh=True)
    h = r.get('html', '')
    ok('the customer is found without the email lookup', 'Antoine Fischer' in h, str(r)[:200])
    ok('and shown without sections', 'Downloads' not in h and 'Licences' not in h)
    r = admin.ajax(brand2, ['fischer274@example.com'], refresh=True)
    ok('a partial address does not match', 'No customer with this address' in r.get('html', ''))

    print('permissions')
    agent = Session(*AGENT)
    ok('the agent logs in', 'Log out' in agent.last or 'logout' in agent.last)
    html = agent.get('/app-settings/hikashop')
    ok('the agent cannot open the HikaShop settings', 'Pairing code' not in html and 'hikashop_pairing_code' not in html, 'status %s' % agent.status)
    html = agent.get('/mailbox/hikashop/%d' % support)
    ok('nor a mailbox\'s HikaShop page', 'Pairing code' not in html, 'status %s' % agent.status)
    agent.get('/mailbox/hikashop/%d' % brand2)
    agent.post('/mailbox/hikashop/%d' % brand2, {'shop_url': 'https://www.example.com', 'pairing_code': ''})
    out = tinker("echo 'hs:'.json_encode(\\App\\Mailbox::find(%d)->hikashop);" % brand2)
    ok('nor save one', 'hs:' in out and 'example.com' not in out, out[-120:])
    r = agent.ajax(brand3, ['antoine.fischer274@example.com'])
    ok('nor read a mailbox they are not in', r.get('status') == 'error')
    r = agent.ajax(support, ['antoine.fischer274@example.com'])
    ok('but reads their own mailbox', 'Antoine Fischer' in r.get('html', ''))

    print('disconnect')
    admin.save_global('')
    ok('an empty address disconnects', 'Connected.' not in admin.get('/app-settings/hikashop'))
    ok('and the block leaves the conversation page', 'id="hikashop-customer"' not in admin.get('/conversation/1'))
    r = admin.ajax(support, ['antoine.fischer274@example.com'])
    ok('the sidebar route says no shop', r.get('status') == 'error')

    # Leave the dev desk connected to the 6.7 shop, as a developer expects to find it.
    admin.save_global(SHOPS['j67']['url'], pairing_code('j67'))
    tinker(r"""foreach ([%d, %d] as $id) { $m = \App\Mailbox::find($id); $m->hikashop = null; $m->save(); }""" % (brand2, brand3))
    shop_sql('j67', "DELETE FROM #__hikashop_order WHERE order_number LIKE 'FSTEST%'")
    forget_devices('j66')
    forget_devices('wp')

    print('\n%s== %d passed, %d failed ==\033[0m' % ('\033[32m' if not failed else '\033[31m', passed, failed))
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
