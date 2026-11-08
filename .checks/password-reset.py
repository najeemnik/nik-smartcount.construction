"""Only guarded nsc_audit and the local fake-mail sink. Never sends live mail."""
import pathlib,json,subprocess,re,urllib.request,urllib.error,concurrent.futures
base='http://127.0.0.1:8081/api/';accounts=json.loads(pathlib.Path('/workspace/nsc-test/accounts.json').read_text());old=accounts[0]['password'];new=old+'-new';checks=[]
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def fixture(mode):return subprocess.check_output(['docker','exec','nsc-audit-web','php','/run/reset-fixtures.php',mode],text=True)
def email(mode):subprocess.run(['docker','exec','nsc-audit-web','php','/run/email-fixtures.php',mode],capture_output=True,check=True)
def req(action,data=None,token=None,cookie=None):
 r=urllib.request.Request(base+action,data=json.dumps(data).encode()if data is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+token}if token else{}),**({'Cookie':cookie}if cookie else{})})
 try:s=opener.open(r,timeout=20)
 except urllib.error.HTTPError as e:s=e
 with s:
  try:b=json.load(s)
  except ValueError:b={}
  return s.status,b,s.headers

def check(label,ok):checks.append((label,bool(ok)));print(('PASS 'if ok else'FAIL ')+label,flush=True)
def mailbox():return subprocess.check_output(['docker','exec','nsc-audit-web','cat','/tmp/nsc-test-mailbox'],text=True)
def start(identifier='reset-test-user',portal='user'):
 s,b,h=req('auth.php?action=request_password_reset',{'identifier':identifier,'portal':portal});assert s==200,(s,b.get('error'));return b['data']['challengeId'],re.search(r'reset code is: ([0-9]{6})',mailbox()).group(1),b

def finish(cid,code,password=new,portal='user',confirm=None):return req('auth.php?action=reset_password',{'challengeId':cid,'code':code,'newPassword':password,'confirmPassword':password if confirm is None else confirm,'portal':portal})
fixture('seed');email('disable')
try:
 check('missing identifier rejected',req('auth.php?action=request_password_reset',{})[0]==400)
 check('invalid portal rejected',req('auth.php?action=request_password_reset',{'identifier':'reset-test-user','portal':'bogus'})[0]==400)
 s,b,h=req('auth.php?action=login',{'username':'reset-test-user','password':old});token=b['data']['token'];cookie=h.get('Set-Cookie').split(';')[0]
 fixture('artifacts');inspect=json.loads(fixture('inspect'));check('test account has live sessions and pending MFA/device records',inspect['web_sessions']>0 and inspect['email_trusted_devices']>0 and inspect['email_login_challenges']>0)
 cid,code,response=start();check('reset works even when login MFA is disabled',True)
 check('sender and purpose correct','From: security@nik-smartcount.com'in mailbox()and'Password reset code'in mailbox())
 check('request discloses no session or email hint',set(response['data'])=={'challengeId','expiresIn','retryAfter'}and code not in json.dumps(response))
 check('stored recovery code is hashed',json.loads(fixture('inspect'))['storedHashedCode'])
 raw=mailbox();s,b,h=req('auth.php?action=request_password_reset',{'identifier':'not-an-existing-user','portal':'user'})
 check('unknown account has same public response',s==200 and set(b['data'])==set(response['data'])and b['error']==response['error']and mailbox()==raw)
 s,b,h=req('auth.php?action=request_password_reset',{'identifier':'reset-test-master','portal':'user'})
 check('wrong portal does not send mail or disclose master',s==200 and mailbox()==raw)
 check('resend cooldown enforced',req('auth.php?action=request_password_reset',{'identifier':'reset-test-user'})[0]==429)
 check('unknown account cooldown enforced',req('auth.php?action=request_password_reset',{'identifier':'not-an-existing-user'})[0]==429)
 check('weak password rejected',finish(cid,code,'short')[0]==400)
 check('multibyte password minimum counts characters',finish(cid,code,'ابجدهوزح')[0]==400)
 check('password confirmation required',finish(cid,code,confirm='different-password')[0]==400)
 check('overlong bcrypt password rejected',finish(cid,code,'x'*73)[0]==400)
 check('NUL password rejected',finish(cid,code,'long-password\0with-null')[0]==400)
 check('malformed code rejected',finish(cid,'123')[0]==400)
 check('login-code endpoint cannot accept recovery code',req('auth.php?action=verify_email_code',{'challengeId':cid,'code':code})[0]==401)
 check('recovery cannot cross portals',finish(cid,code,portal='master')[0]==401)
 wrong='000000'if code!='000000'else'111111';check('wrong code rejected',finish(cid,wrong)[0]==401)
 s,b,h=finish(cid,code);check('correct code changes password with no automatic login',s==200 and b['data']=={'passwordReset':True}and not h.get('Set-Cookie'))
 check('consumed reset code cannot be replayed',finish(cid,code)[0]==401)
 check('old bearer session revoked',req('auth.php',token=token)[0]==401)
 check('old cookie session revoked',req('auth.php',cookie=cookie)[0]==401)
 inspect=json.loads(fixture('inspect'));check('password hashed and sessions/devices/login challenges removed',inspect['passwordHashed']and all(inspect[k]==0 for k in ['web_sessions','email_trusted_devices','email_login_challenges']))
 check('old password no longer signs in',req('auth.php?action=login',{'username':'reset-test-user','password':old})[0]==401)
 check('new password signs in',req('auth.php?action=login',{'username':'reset-test-user','password':new})[0]==200)
 fixture('seed');cid,code,_=start('RESET-TEST-USER@NIK-SMARTCOUNT.COM');check('registered email and case-insensitive identifier recover correct account',finish(cid,code)[0]==200)
 fixture('seed');cid,code,_=start();fixture('expire');check('expired reset code denied',finish(cid,code)[0]==401)
 fixture('clear');cid,code,_=start();wrong='000000'if code!='000000'else'111111'
 for _ in range(5):assert finish(cid,wrong)[0]==401
 check('five wrong attempts exhaust code',finish(cid,code)[0]==401)
 fixture('clear');cid,code,_=start();fixture('age');cid2,code2,_=start()
 check('resend invalidates earlier code',finish(cid,code)[0]==401)
 check('newly sent code succeeds',finish(cid2,code2)[0]==200)
 for mode in ['email','version','role']:
  fixture('seed');cid,code,_=start();fixture(mode);check('changed '+mode+' invalidates pending reset',finish(cid,code)[0]==401)
 fixture('seed');cid,code,_=start();fixture('lock');check('password reset does not unlock locked account',finish(cid,code)[0]==200 and json.loads(fixture('inspect'))['locked']==1 and req('auth.php?action=login',{'username':'reset-test-user','password':new})[0]==403)
 for mode in ['deleted','placeholder']:
  fixture('seed');fixture(mode);raw=mailbox();s,b,h=req('auth.php?action=request_password_reset',{'identifier':'reset-test-user'});check(mode+' account gets generic response and no mail',s==200 and raw==mailbox())
 fixture('seed');subprocess.run(['docker','exec','nsc-audit-web','touch','/tmp/nsc-mail-fail'],check=True)
 s,b,h=req('auth.php?action=request_password_reset',{'identifier':'reset-test-user'});check('mail failure does not disclose account or allow reset',s==200 and finish(b['data']['challengeId'],'000000')[0]==401)
 subprocess.run(['docker','exec','nsc-audit-web','rm','-f','/tmp/nsc-mail-fail'],check=True)
 fixture('seed');cid,code,_=start()
 with concurrent.futures.ThreadPoolExecutor(max_workers=2)as pool:results=list(pool.map(lambda _:finish(cid,code)[0],range(2)))
 check('simultaneous completion permits exactly one reset',sorted(results)==[200,401])
 fixture('clear')
 for i in range(3):
  if i:fixture('age')
  start()
 fixture('age');check('three per identifier per 15 minutes enforced',req('auth.php?action=request_password_reset',{'identifier':'reset-test-user'})[0]==429)
 fixture('clear')
 for i in range(20):assert req('auth.php?action=request_password_reset',{'identifier':'unknown-reset-'+str(i)})[0]==200
 check('IP request cap enforced across identifiers',req('auth.php?action=request_password_reset',{'identifier':'another-unknown-reset'})[0]==429)
 fixture('seed');cid,code,_=start('reset-test-master','master');check('Office7 recovery uses registered email', 'reset-test-master@nik-smartcount.com'in mailbox())
 check('Office7 reset succeeds',finish(cid,code,portal='master')[0]==200)
 email('enable');s,b,h=req('auth.php?action=master_login',{'username':'reset-test-master','password':new});check('normal email MFA still required after Office7 reset',s==200 and b['data'].get('requiresVerification')is True and 'token'not in b['data'])
 check('password reset helper blocked on web',req('password-reset.php')[0]==403)
 print(f'{sum(ok for _,ok in checks)}/{len(checks)} password recovery checks passed');assert all(ok for _,ok in checks)
 pathlib.Path('/workspace/nsc-test/results/password-reset.json').write_text(json.dumps(checks,indent=2))
finally:
 subprocess.run(['docker','exec','nsc-audit-web','rm','-f','/tmp/nsc-mail-fail'],check=True)
 fixture('seed');email('disable')
