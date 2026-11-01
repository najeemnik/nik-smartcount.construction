"""Run only on the seeded nsc_audit container with the local fake mail sink."""
import json,urllib.request,urllib.error,pathlib,subprocess,re,uuid
base='http://127.0.0.1:8081';opener=urllib.request.build_opener(urllib.request.ProxyHandler({}));checks=[]
accounts=json.loads(pathlib.Path('/workspace/nsc-test/accounts.json').read_text());password=accounts[0]['password']
def req(path,data=None,token=None):
 r=urllib.request.Request(base+'/api/'+path,data=json.dumps(data).encode()if data is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+token}if token else{})})
 try:s=opener.open(r,timeout=20)
 except urllib.error.HTTPError as e:s=e
 with s:
  raw=s.read()
  try:b=json.loads(raw)
  except ValueError:b={}
  return s.status,b

def check(name,ok):checks.append(ok);print(('PASS 'if ok else'FAIL ')+name)
def mail():return subprocess.check_output(['docker','exec','nsc-audit-web','cat','/tmp/nsc-test-mailbox'],text=True)
def login(user,pwd,action='login'):
 s,b=req('auth.php?action='+action,{'username':user,'password':pwd});assert s==200,(s,b.get('error'));assert b['data'].get('requiresVerification')and'token'not in b['data']
 raw=mail();code=re.search(r'code is: ([0-9]{6})',raw).group(1)
 s,b=req('auth.php?action=verify_email_code',{'challengeId':b['data']['challengeId'],'code':code});assert s==200,(s,b.get('error'));return b['data']['token'],raw
master,_=login(accounts[1]['username'],accounts[1]['password'],'master_login')
owner_name='owner-'+uuid.uuid4().hex[:8];owner_email=owner_name+'@nik-smartcount.com'
s,b=req('auth.php?action=sync_user',{'user':{'username':owner_name,'name':'Fresh owner','email':owner_email,'rawPassword':password,'role':'admin','companyName':'New owner company','staffLimit':3,'createOnly':True}},master);assert s==200,(s,b.get('error'));oid=b['data']['id'];check('Office7 creates a fresh owner',True)
owner,raw=login(owner_name,password);check('owner receives code at own email',owner_email in raw)
s,b=req('workspace.php',token=owner);check('new owner opens empty workspace',s==200 and b['data']['tenantId']==oid)
check('legacy master-only API still rejects owner',req('auth.php?action=master_login',{'username':owner_name,'password':password})[0]==401)
user_name='employee-'+uuid.uuid4().hex[:8];user_email=user_name+'@nik-smartcount.com'
s,b=req('auth.php?action=sync_user',{'user':{'username':user_name,'name':'Fresh employee','email':user_email,'role':'accountant','rawPassword':password,'createOnly':True}},owner);assert s==200,(s,b.get('error'));uid=b['data']['id'];employee,raw=login(user_name,password);check('employee receives own verification code',user_email in raw)
check('employee opens own company workspace',req('workspace.php',token=employee)[1]['data']['tenantId']==oid)
check('employee cannot remove owner',req('auth.php?action=delete_user',{'id':oid},employee)[0]==403)
check('owner with employees cannot be orphaned',req('auth.php?action=delete_user',{'id':oid},master)[0]==409)
other,_=login(accounts[0]['username'],password)
check('another owner cannot remove foreign employee',req('auth.php?action=delete_user',{'id':uid},other)[0]==403)
# These accounts are retained for browser validation; use separate accounts for destructive tests.
s,b=req('auth.php?action=sync_user',{'user':{'username':'remove-'+uuid.uuid4().hex[:8],'name':'Remove test','email':'remove-'+uuid.uuid4().hex[:8]+'@nik-smartcount.com','role':'viewer','rawPassword':password,'createOnly':True}},owner);assert s==200;remove_id=b['data']['id'];remove_name=b['data']['user']['username'];removal_token,_=login(remove_name,password)
state=req('workspace.php',token=owner)[1]['data'];project={'id':'preserve-'+uuid.uuid4().hex[:8],'name':'Preserved financial project','userId':remove_id}
s,b=req('workspace.php',{'baseVersion':state['stateVersion'],'state':{'projects':[project]}},owner);assert s==200,(s,b.get('error'))
check('owner can delete own employee',req('auth.php?action=delete_user',{'id':remove_id},owner)[0]==200)
check('deleted employee disappears from list',remove_id not in [u['id']for u in req('office7.php?action=tenants',token=master)[1]['data']['users']])
check('deleted employee old session is denied',req('workspace.php',token=removal_token)[0]in[401,403])
check('deleted employee cannot log in again',req('auth.php?action=login',{'username':remove_name,'password':password})[1].get('data',{}).get('code')=='ACCOUNT_REMOVED')
check('workspace records survive user removal',any(p['id']==project['id']for p in req('workspace.php',token=owner)[1]['data']['state']['projects']))
check('ordinary update cannot reactivate removed account',req('office7.php?action=update_tenant',{'id':remove_id,'active':True},master)[0]==404)
check('master cannot delete itself',req('auth.php?action=delete_user',{'id':'preview-master'},master)[0]==403)
# A second empty owner can be removed without deleting its stored workspace.
s,b=req('auth.php?action=sync_user',{'user':{'username':'empty-'+uuid.uuid4().hex[:8],'name':'Empty owner','email':'empty-'+uuid.uuid4().hex[:8]+'@nik-smartcount.com','role':'admin','rawPassword':password,'createOnly':True}},master);assert s==200;empty_id=b['data']['id']
check('Office7 can remove owner without employees',req('auth.php?action=delete_user',{'id':empty_id},master)[0]==200)
check('deleted owner cannot receive new staff',req('auth.php?action=sync_user',{'user':{'username':'orphan-'+uuid.uuid4().hex[:8],'name':'Orphan denied','email':'orphan-'+uuid.uuid4().hex[:8]+'@nik-smartcount.com','role':'viewer','ownerAdminId':empty_id,'rawPassword':password,'createOnly':True}},master)[0]==400)
req('office7.php?action=update_tenant',{'id':oid,'staffLimit':1},master)
child={'username':'quota-'+uuid.uuid4().hex[:8],'name':'Quota test','email':'quota-'+uuid.uuid4().hex[:8]+'@nik-smartcount.com','role':'viewer','rawPassword':password,'createOnly':True}
check('active employee fills owner allowance',req('auth.php?action=sync_user',{'user':child},owner)[0]==403)
check('owner removes active employee',req('auth.php?action=delete_user',{'id':uid},owner)[0]==200)
check('removed employee releases allowance',req('auth.php?action=sync_user',{'user':child},owner)[0]==200)
print(f'{sum(checks)}/{len(checks)} account checks passed');assert all(checks)
