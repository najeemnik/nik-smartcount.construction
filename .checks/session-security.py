"""Isolated nsc_audit only; local fake sendmail, no live mail or company data."""
import pathlib,json,urllib.request,urllib.error,subprocess,re
base='http://127.0.0.1:8081/api/';opener=urllib.request.build_opener(urllib.request.ProxyHandler({}));accounts=json.loads(pathlib.Path('/workspace/nsc-test/accounts.json').read_text());checks=[]
def fixture(mode):subprocess.run(['docker','exec','nsc-audit-web','php','/run/session-fixtures.php',mode],check=True,capture_output=True)
def email(mode):subprocess.run(['docker','exec','nsc-audit-web','php','/run/email-fixtures.php',mode],check=True,capture_output=True)
def request(path,data=None,cookie=None,csrf=None,portal='user',headers=None):
 h={'Content-Type':'application/json','X-NSC-Portal':portal,'User-Agent':'NSC-session-audit',**({'Cookie':cookie}if cookie else{}),**({'X-NSC-CSRF':csrf}if csrf else{}),**(headers or{})}
 r=urllib.request.Request(base+path,data=json.dumps(data).encode()if data is not None else None,headers=h)
 try:res=opener.open(r,timeout=20)
 except urllib.error.HTTPError as e:res=e
 with res:
  try:body=json.load(res)
  except ValueError:body={}
  return res.status,body,res.headers.get_all('Set-Cookie')or[]
def check(name,ok):checks.append(bool(ok));print(('PASS 'if ok else'FAIL ')+name)
def code():return re.search(r'code is: ([0-9]{6})',subprocess.check_output(['docker','exec','nsc-audit-web','cat','/tmp/nsc-test-mailbox'],text=True)).group(1)
def signin(account=0,trust=False,browser=True):
 s,b,h=request('auth.php?action='+('master_login'if account else'login'),{**accounts[account],'sessionMode':'cookie'if browser else'api'},portal='master'if account else'user');assert s==200,(s,b.get('error'))
 if b['data'].get('requiresVerification'):
  s,b,h=request('auth.php?action=verify_email_code',{'challengeId':b['data']['challengeId'],'code':code(),'trustDevice':trust,'sessionMode':'api'},portal='master'if account else'user');assert s==200,(s,b.get('error'))
 return b['data'],h
fixture('normal');fixture('clear');email('disable');fixture('fresh-migration')
try:
 old,_=signin(browser=False);browser,h=signin();cookie=next(v.split(';')[0]for v in h if v.startswith('__Host-nsc_session='));csrf=browser['csrfToken']
 check('browser sign-in sends no bearer token',browser['authenticated']and'token'not in browser)
 check('new browser migration revokes earlier bearer',request('auth.php',headers={'Authorization':'Bearer '+old['token']})[0]==401)
 check('opaque browser cookie is Secure HttpOnly SameSite Strict',all(flag in h[0].lower()for flag in ['secure','httponly','samesite=strict','path=/'])and len(cookie.split('=')[1])==64)
 check('cookie authenticates without local storage token',request('auth.php',cookie=cookie)[0]==200)
 check('cookie write without CSRF is rejected',request('auth.php?action=logout',{},cookie=cookie)[0]==403)
 check('incorrect CSRF is rejected',request('auth.php?action=logout',{},cookie=cookie,csrf='x')[0]==403)
 check('hostile Origin is rejected even with CSRF',request('auth.php?action=logout',{},cookie=cookie,csrf=csrf,headers={'Origin':'https://evil.invalid'})[0]==403)
 check('ordinary session cannot change support contacts',request('support.php',{'phone':'+93783788278','whatsapp':'+93783788278','email':'info@niksmartcount.com'},cookie,csrf)[0]in[401,403])
 check('logout succeeds with CSRF',request('auth.php?action=logout',{},cookie,csrf)[0]==200)
 check('logged-out cookie cannot be replayed',request('auth.php',cookie=cookie)[0]==401)
 browser,h=signin();cookie=next(v.split(';')[0]for v in h if v.startswith('__Host-nsc_session='));fixture('session-ip');check('session stops when observed IP changes',request('auth.php',cookie=cookie)[0]==401)
 fixture('normal');email('enable');fixture('clear')
 browser,h=signin(trust=True);trusted=next(v.split(';')[0]for v in h if v.startswith('__Host-nsc_trust_'))
 check('MFA challenge binds browser mode against response downgrade','token'not in browser)
 for i in range(7):
  s,b,_=request('auth.php?action=login',{**accounts[0],'sessionMode':'cookie'},trusted);assert s==200
  check('same IP remembered login '+str(i+1)+' skips code',b['data'].get('authenticated')and'token'not in b['data'])
 s,b,_=request('auth.php?action=login',{**accounts[0],'password':'wrong-password','sessionMode':'cookie'},trusted);check('remembered device still needs correct password',s==401)
 fixture('trust-ip');s,b,_=request('auth.php?action=login',{**accounts[0],'sessionMode':'cookie'},trusted);check('changed IP requires new email verification',s==200 and b['data'].get('requiresVerification'))
 s,b,_=request('auth.php?action=login',{**accounts[0],'sessionMode':'cookie'},trusted);check('revoked device stays revoked on subsequent login',b['data'].get('requiresVerification'))
 fixture('clear');browser,h=signin(trust=True);trusted=next(v.split(';')[0]for v in h if v.startswith('__Host-nsc_trust_'));fixture('trust-expire');s,b,_=request('auth.php?action=login',{**accounts[0],'sessionMode':'cookie'},trusted);check('expired 30 day trust requires code',b['data'].get('requiresVerification'))
 fixture('clear')
 for mode,expected in [('locked','ACCOUNT_LOCKED'),('inactive','ACCOUNT_INACTIVE'),('expired','SUBSCRIPTION_EXPIRED'),('deleted','ACCOUNT_REMOVED')]:
  fixture(mode);s,b,_=request('auth.php?action=login',{**accounts[0],'password':'wrong-password'});check(mode+' reason hidden from wrong password',s==401 and'accountStatus'not in b.get('data',{}))
  s,b,_=request('auth.php?action=login',accounts[0]);check(mode+' reason shown only after correct password',s==403 and b['data']['code']==expected and'token'not in b['data'])
 fixture('normal');fixture('clear');master,h=signin(account=1);cookie=next(v.split(';')[0]for v in h if v.startswith('__Host-nsc_master_session='));csrf=master['csrfToken']
 check('user portal cannot use master cookie',request('auth.php',cookie=cookie)[0]==401)
 check('master portal accepts separate master cookie',request('auth.php',cookie=cookie,portal='master')[0]==200)
 data={'phone':'+93783788278','whatsapp':'+93783788278','email':'info@niksmartcount.com'}
 check('master can save support contacts',request('support.php',data,cookie,csrf,'master')[0]==200)
 check('unsafe support phone rejected',request('support.php',{**data,'phone':'javascript:alert(1)'},cookie,csrf,'master')[0]==400)
 s,b,_=request('support.php');check('public support contacts match user request',s==200 and b['data']==data)
 # Lock/unlock must revoke existing browser sessions and remembered-device bypass.
 fixture('clear');browser,headers=signin(trust=True);usercookie=next(v.split(';')[0]for v in headers if v.startswith('__Host-nsc_session='));trustcookie=next(v.split(';')[0]for v in headers if v.startswith('__Host-nsc_trust_'))
 note='Account paused for review'
 s,b,_=request('office7.php?action=update_tenant',{'id':'preview-owner','accountNotice':note,'isLockedBySuperAdmin':True},cookie,csrf,'master')
 check('administrator saves account notice and locks account',s==200 and b['data']['user']['accountNotice']==note)
 check('locking account revokes existing browser session',request('auth.php',cookie=usercookie)[0]==401)
 request('office7.php?action=update_tenant',{'id':'preview-owner','isLockedBySuperAdmin':False},cookie,csrf,'master')
 s,b,_=request('auth.php?action=login',{**accounts[0],'sessionMode':'cookie'},trustcookie);check('unlock does not restore revoked device trust',s==200 and b['data'].get('requiresVerification'))
 check('oversized account notice rejected',request('office7.php?action=update_tenant',{'id':'preview-owner','accountNotice':'x'*1201},cookie,csrf,'master')[0]==400)
 for mode,expected in [('locked','COMPANY_LOCKED'),('inactive','COMPANY_INACTIVE'),('expired','COMPANY_EXPIRED')]:
  fixture(mode);s,b,_=request('auth.php?action=login',{'username':'audit-viewer','password':accounts[0]['password']});check('employee sees '+expected,s==403 and b.get('data',{}).get('code')==expected)
 fixture('normal')
 check('helper source inaccessible',request('web-session.php')[0]==403) # HTML denial is handled separately below.
finally:fixture('normal');fixture('clear');email('disable')
print(str(sum(checks))+'/'+str(len(checks))+' session security checks passed');assert all(checks)
