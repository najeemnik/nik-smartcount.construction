"""Requires the fresh local nsc-install-test-web instance with fake sendmail; never targets a real host."""
import urllib.request,urllib.error,json,pathlib,subprocess,re
base='http://127.0.0.1:8082';opener=urllib.request.build_opener(urllib.request.ProxyHandler({}));checks=[]
key=pathlib.Path('/workspace/nsc-install-test/test-key').read_text()
password=json.loads(pathlib.Path('/workspace/nsc-preview/accounts.json').read_text())[1]['password']
def request(path,data=None,token=None):
 req=urllib.request.Request(base+'/api/'+path,data=json.dumps(data).encode()if data is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+token}if token else{})})
 try:r=opener.open(req)
 except urllib.error.HTTPError as e:r=e
 with r:
  text=r.read()
  try:body=json.loads(text)
  except ValueError:body={}
  return r.status,body
def check(name,ok):checks.append(ok);print(('PASS 'if ok else'FAIL ')+name)
def code():return re.search(r'code is: ([0-9]{6})',subprocess.check_output(['docker','exec','nsc-install-test-web','cat','/tmp/nsc-test-mailbox'],text=True)).group(1)
master={'username':'install-audit','name':'Installation audit','email':'audit@nik-smartcount.com','password':password}
data={'key':key,'master':master,'from':'security@nik-smartcount.com'}
check('fresh installer has no master',request('install.php?action=status')[1]['data']['initialized']is False)
check('email test requires install key',request('install.php?action=email_test',{'key':'wrong','from':data['from'],'email':master['email']})[0]==403)
check('master requires usable email',request('install.php?action=complete',{**data,'master':{**master,'email':''}})[0]==400)
check('hash cannot bypass initial password length',request('install.php?action=complete',{**data,'master':{**master,'password':'short','passwordHash':'sha256$'+'a'*64}})[0]==400)
check('installation requires email proof',request('install.php?action=complete',data)[0]in[400,403])
check('sender header injection rejected',request('install.php?action=email_test',{'key':key,'from':'security@nik-smartcount.com\r\nBcc:evil@test.com','email':master['email']})[0]==400)
mail={'key':key,'from':data['from'],'email':master['email']}
check('test code sent before installing account',request('install.php?action=email_test',mail)[0]==200);c=code()
check('email setup resend throttled',request('install.php?action=email_test',mail)[0]==429)
check('wrong setup code rejected',request('install.php?action=email_verify',{**mail,'code':'000000'})[0]==403)
s,b=request('install.php?action=email_verify',{**mail,'code':c});proof=b['data']['proof'];check('received code proves email delivery',s==200)
check('setup code cannot be verified twice',request('install.php?action=email_verify',{**mail,'code':c})[0]==403)
check('proof cannot change sender',request('install.php?action=complete',{**data,'from':'other@nik-smartcount.com','emailProof':proof})[0]in[400,403])
s,b=request('install.php?action=complete',{**data,'emailProof':proof});check('fresh installation completes with verified email',s==200 and b['data']['initialized'])
check('completed installer cannot be reused',request('install.php?action=complete',{**data,'emailProof':proof})[0]==409)
s,b=request('auth.php?action=master_login',{'username':master['username'],'password':password});check('newly installed master can request email code',s==200 and b['data'].get('requiresVerification')is True)
s,b=request('auth.php?action=verify_email_code',{'challengeId':b['data']['challengeId'],'code':code()});token=b['data']['token'];check('installed master completes MFA login',s==200)
check('mail settings reject anonymous access',request('email-settings.php')[0]==401)
new={'from':'notifications@nik-smartcount.com','mailAction':'send'}
check('master can request new sender test',request('email-settings.php',new,token)[0]==200);c=code()
check('sender is preserved before save',request('email-settings.php',token=token)[1]['data']['from']==data['from'])
s,b=request('email-settings.php',{**new,'mailAction':'verify','code':c},token);newproof=b['data']['proof'];check('new sender requires delivery proof',s==200)
check('sender cannot change after verification',request('email-settings.php',{'from':'changed@nik-smartcount.com','mailAction':'save','proof':newproof},token)[0]in[400,403])
check('verified sender saves through UI API',request('email-settings.php',{**new,'mailAction':'save','proof':newproof},token)[0]==200)
check('sender persisted without editing code',request('email-settings.php',token=token)[1]['data']['from']==new['from'])
for path in ['mail-configuration.php','.secrets/email.php']:
 check('private setup helper denied '+path,request(path)[0]==403)
print(f'{sum(checks)}/{len(checks)} installer and mail settings checks passed');assert all(checks)
