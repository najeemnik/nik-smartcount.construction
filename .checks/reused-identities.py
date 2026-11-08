"""Removed-account identifier reuse, only local nsc_audit + fake mail sink."""
import pathlib,json,subprocess,urllib.request,urllib.error,uuid,re,concurrent.futures
base='http://127.0.0.1:8081/api/';checks=[];pwd=json.loads(pathlib.Path('/workspace/nsc-test/accounts.json').read_text())[0]['password'];opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def fixture(mode):subprocess.run(['docker','exec','nsc-audit-web','php','/run/reset-fixtures.php',mode],check=True,capture_output=True)
def email(mode):subprocess.run(['docker','exec','nsc-audit-web','php','/run/email-fixtures.php',mode],check=True,capture_output=True)
def req(action,data=None,token=None):
 r=urllib.request.Request(base+action,data=json.dumps(data).encode()if data is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+token}if token else{})})
 try:s=opener.open(r,timeout=25)
 except urllib.error.HTTPError as e:s=e
 with s:
  try:b=json.load(s)
  except ValueError:b={}
  return s.status,b

def check(label,ok):checks.append((label,bool(ok)));print(('PASS 'if ok else'FAIL ')+label,flush=True)
def login(username,password=pwd,master=False):
 s,b=req('auth.php?action='+('master_login'if master else'login'),{'username':username,'password':password});assert s==200,(s,b.get('error'));return b['data']['token']
def make(token,username=None,mail=None,role='admin',id=None,owner=None,password=pwd):
 username=username or'reuse-'+uuid.uuid4().hex[:10];data={'username':username,'name':'Identity reuse test','email':mail or username+'@nik-smartcount.com','role':role,'rawPassword':password,'createOnly':True,'staffLimit':6}
 if id:data['id']=id
 if owner:data['ownerAdminId']=owner
 return req('auth.php?action=sync_user',{'user':data},token)
def inspect(id):return json.loads(subprocess.check_output(['docker','exec','nsc-audit-web','php','/run/reused-identities-inspect.php',id],text=True))
def mailbox():
 try:return subprocess.check_output(['docker','exec','nsc-audit-web','cat','/tmp/nsc-test-mailbox'],text=True)
 except subprocess.CalledProcessError:return ''
fixture('seed');email('disable');master=login('reset-test-master',master=True)
try:
 oldid='reuse-owner-'+uuid.uuid4().hex[:16];name='reuse-owner-'+uuid.uuid4().hex[:10];mail=name+'@nik-smartcount.com'
 s,b=make(master,name,mail,id=oldid);assert s==200,(s,b.get('error'));old=login(name)
 st=req('workspace.php',token=old)[1]['data'];sentinel={'id':'preserved-old-project','name':'Original financial project'}
 assert req('workspace.php',{'baseVersion':st['stateVersion'],'state':{'projects':[sentinel],'expenses':[{'id':'historical-expense','projectId':sentinel['id'],'amount':100}]}},old)[0]==200
 assert req('auth.php?action=delete_user',{'id':oldid},master)[0]==200
 check('removed account is hidden from Office7',oldid not in [u['id']for u in req('office7.php?action=tenants',token=master)[1]['data']['users']])
 raw=mailbox();s,b=req('auth.php?action=request_password_reset',{'identifier':name});check('removed account gets no recovery email',s==200 and raw==mailbox())
 check('removed explicit ID cannot be reused',make(master,name,mail,id=oldid)[0]==409)
 check('weak-password failed creation does not archive old identity',make(master,name,mail,password='short')[0]==400 and inspect(oldid)['username']==name)
 s,b=make(master,name,mail);assert s==200,(s,b.get('error'));freshid=b['data']['id'];check('master reuses removed username and email for a different ID',freshid!=oldid)
 archived=inspect(oldid);check('old row stays removed and original identity is retained privately',archived['deleted']and archived['active']==0 and archived['locked']==1 and archived['username'].startswith('removed-')and archived['email'].endswith('@deleted.invalid')and archived['original']['username']==name and archived['original']['email']==mail)
 check('historical workspace and financial records preserved',archived['state']['projects']==[sentinel]and archived['state']['expenses'][0]['amount']==100)
 check('old session never becomes new account session',req('workspace.php',token=old)[0]in[401,403])
 fresh=login(name);state=req('workspace.php',token=fresh)[1]['data'];check('fresh owner has separate empty workspace',state['tenantId']==freshid and not state['state'].get('projects'))
 check('active email remains unique',make(master,mail=mail)[0]==409)
 check('active username remains unique',make(master,name)[0]==409)
 check('removed account cannot be reactivated directly',req('office7.php?action=update_tenant',{'id':oldid,'active':True},master)[0]==404)
 fixture('age');s,b=req('auth.php?action=request_password_reset',{'identifier':name});assert s==200
 code=re.search(r'reset code is: ([0-9]{6})',mailbox()).group(1);newpwd=pwd+'-fresh'
 s,b=req('auth.php?action=reset_password',{'challengeId':b['data']['challengeId'],'code':code,'newPassword':newpwd,'confirmPassword':newpwd});check('recovery now works for the newly registered account',s==200)
 check('new password belongs only to fresh account',req('auth.php?action=login',{'username':name,'password':newpwd})[1]['data']['user']['id']==freshid and inspect(oldid)['deleted'])
 # Owner-scoped staff reuse, and denial across companies.
 s,b=make(master);assert s==200;ownername=b['data']['user']['username'];ownerid=b['data']['id'];owner=login(ownername)
 s,b=make(master);assert s==200;other=login(b['data']['user']['username'])
 staffid='reuse-staff-'+uuid.uuid4().hex[:16];staffname='reuse-staff-'+uuid.uuid4().hex[:10];staffmail=staffname+'@nik-smartcount.com'
 s,b=make(owner,staffname,staffmail,'accountant',id=staffid);assert s==200,(s,b.get('error'));staff=login(staffname)
 assert req('auth.php?action=delete_user',{'id':staffid},owner)[0]==200
 check('different owner cannot release another company removed identity',make(other,mail=staffmail,role='accountant')[0]==409 and inspect(staffid)['username']==staffname)
 s,b=make(owner,role='viewer');assert s==200;viewer=login(b['data']['user']['username'])
 check('employee cannot release removed identities',make(viewer,mail=staffmail,role='accountant')[0]==403 and inspect(staffid)['username']==staffname)
 s,b=make(owner,staffname,staffmail,'accountant');assert s==200,(s,b.get('error'));newstaff=b['data']['id'];check('owner can recreate own removed staff with fresh ID',newstaff!=staffid and b['data']['user']['ownerAdminId']==ownerid)
 check('stale device sync cannot restore removed ID',req('auth.php?action=sync_user',{'user':{'id':staffid,'username':staffname,'email':staffmail,'role':'accountant','rawPassword':pwd}},owner)[0]==409)
 check('old employee session remains invalid',req('workspace.php',token=staff)[0]in[401,403])
 # Reuse only an email, and use the previous username independently later.
 id='reuse-email-'+uuid.uuid4().hex[:16];name='reuse-email-'+uuid.uuid4().hex[:10];mail=name+'@nik-smartcount.com';assert make(master,name,mail,id=id)[0]==200;assert req('auth.php?action=delete_user',{'id':id},master)[0]==200
 check('removed email can be reused with a different username',make(master,mail=mail)[0]==200)
 check('old username can subsequently be used for a separate new account',make(master,name,mail='separate-'+uuid.uuid4().hex[:12]+'@nik-smartcount.com')[0]==200)
 # Concurrent requests must yield one fresh account, not merge or revive records.
 id='reuse-race-'+uuid.uuid4().hex[:16];mail=id+'@nik-smartcount.com';s,b=make(master,mail=mail,id=id);assert s==200;assert req('auth.php?action=delete_user',{'id':id},master)[0]==200
 with concurrent.futures.ThreadPoolExecutor(max_workers=2)as pool:results=list(pool.map(lambda _:make(master,mail=mail)[0],range(2)))
 check('concurrent reuse creates one account and one duplicate rejection',sorted(results)==[200,409])
 check('identity helper is not public',req('account-identity.php')[0]==403)
 print(f'{sum(ok for _,ok in checks)}/{len(checks)} removed identity reuse checks passed');assert all(ok for _,ok in checks)
 pathlib.Path('/workspace/nsc-test/results/reused-identities.json').write_text(json.dumps(checks,indent=2))
finally:email('disable')
