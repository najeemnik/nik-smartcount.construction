import os,json,pathlib,urllib.request,urllib.error,concurrent.futures,uuid,base64
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
base=os.environ.get('NSC_TEST_BASE_URL','http://127.0.0.1:8081')
checks=[]
def request(path,data=None,token=None,headers=None,method=None,raw=None):
 h={'Content-Type':'application/json',**({'Authorization':'Bearer '+token} if token else {}),**(headers or {})}
 payload=raw if raw is not None else (json.dumps(data).encode() if data is not None else None)
 r=urllib.request.Request(base+path,data=payload,headers=h,method=method)
 try:s=opener.open(r,timeout=15)
 except urllib.error.HTTPError as e:s=e
 with s:
  text=s.read().decode()
  try:body=json.loads(text)
  except:body={'invalidJSON':text[:150]}
  return s.status,body

def check(name,condition):
 checks.append((name,bool(condition)));print(('PASS ' if condition else 'FAIL ')+name)
password=json.loads(pathlib.Path(os.environ.get('NSC_TEST_ACCOUNTS_FILE','/workspace/nsc-preview/accounts.json')).read_text())[0]['password']
def login(username):
 status,b=request('/api/auth.php?action=login',{'username':username,'password':password});assert status==200,(status,b);return b['data']['token']
owner=login('preview');staff=login('audit-staff');viewer=login('audit-viewer');other=login('audit-other');creator=login('audit-create')
def getstate(t):return request('/api/workspace.php',token=t)[1]['data']
check('anonymous workspace denied',request('/api/workspace.php')[0]==401)
state=getstate(staff)
check('project snapshot hides ungranted projects',set(r['id'] for r in state['state']['projects'])=={'audit-a','audit-b'})
check('unscoped records hidden from restricted employee',set(r['id'] for r in state['state']['expenses'])=={'expense-a','expense-b'})
check('disabled settings hidden','appSettings' not in state['state'])
status,b=request('/api/projects.php',token=staff);check('SQL project list filtered',set(r['id'] for r in b['data']['projects'])=={'audit-a','audit-b'})
for api in ['finance.php?section=payments','payroll.php','materials.php','reports.php?type=project_pnl','office7.php?action=workspace_sync']:
 check('restricted global list blocked: '+api,request('/api/'+api,token=staff)[0]==403)
for api in ['finance.php?section=payments','payroll.php','materials.php','assets.php','apartments.php','exchange.php','journal.php']:
 check('alternate parameter cannot bypass scope: '+api,request('/api/'+api+('&' if '?' in api else '?')+'project_id=audit-a',token=staff)[0]==403)
check('scoped employee cannot create unscoped asset',request('/api/assets.php',{'assetName':'Unscoped','purchaseCost':100},staff)[0]==403)
for api in ['finance.php?section=payments','payroll.php','materials.php','assets.php','apartments.php','exchange.php','journal.php']:
 check('allowed project list works: '+api,request('/api/'+api+('&' if '?' in api else '?')+'projectId=audit-a',token=staff)[0]==200)
expenses=json.loads(json.dumps(state['state']['expenses']));expenses[1]['projectId']='audit-a'
check('moving a read-only record cannot bypass edit grant',request('/api/workspace.php',{'baseVersion':state['stateVersion'],'state':{'expenses':expenses}},staff)[0]==403)
state=getstate(owner)
check('duplicate record identities rejected',request('/api/workspace.php',{'baseVersion':state['stateVersion'],'state':{'expenses':[{'id':'x'},{'id':'x'}]}},owner)[0]==400)
check('viewer cannot write',request('/api/workspace.php',{'baseVersion':state['stateVersion'],'state':{'expenses':[]}},viewer)[0]==403)
check('cross-company tenant override blocked',request('/api/workspace.php',{'tenantId':'preview-owner','baseVersion':0,'state':{}},other)[0]==403)
check('create-only permission cannot edit project',request('/api/projects.php',{'id':'audit-a','name':'Unauthorized change'},creator)[0]==403)
check('cross-company project read denied',request('/api/projects.php?id=audit-a',token=other)[0]==404)
status,b=request('/api/suppliers.php',token=owner);check('unclaimed legacy suppliers not globally visible','audit-null' not in {s['id'] for s in b['suppliers']})
check('JSON syntax error returns 400',request('/api/auth.php?action=login',raw=b'{invalid')[0]==400)
check('array JSON rejected',request('/api/workspace.php',token=owner,raw=b'[]')[0]==400)
check('hostile browser Origin rejected',request('/api/workspace.php',{'state':{}},owner,headers={'Origin':'https://hostile.invalid'})[0]==403)
for path in ['/api/.secrets/schema.sql','/api/.secrets/create-install-key.php','/api/permissions.php','/api/validation.php','/api/config.php']:
 check('private path denied '+path,request(path)[0]==403)
for lines,label in [([{'accountId':'cash','debit':-1},{'accountId':'payables','credit':-1}],'negative amounts'),([{'accountId':'cash','debit':1},{'accountId':'payables','credit':1.01}],'one-cent imbalance'),([{'accountId':'cash','debit':1.001},{'accountId':'payables','credit':1.001}],'excess decimal precision'),([{'accountId':'missing','debit':1},{'accountId':'cash','credit':1}],'unknown account'),([{'accountId':'cash','debit':1,'credit':1},{'accountId':'cash','debit':1,'credit':1}],'two-sided lines')]:
 check('journal rejects '+label,request('/api/journal.php',{'lines':lines},owner)[0]==400)
check('valid balanced journal saved',request('/api/journal.php',{'narration':'regression '+uuid.uuid4().hex,'lines':[{'accountId':'cash','debit':0.30},{'accountId':'capital','credit':0.30}]},owner)[0]==200)
for data,label in [({'currency':'USD','exchangeRate':-1,'sourceAccountId':'audit-usd'},'invalid rate'),({'currency':'USD','exchangeRate':70,'sourceAccountId':'audit-afn'},'account currency mismatch'),({'currency':'EUR','sourceAccountId':'audit-afn'},'unknown currency')]:
 check('finance rejects '+label,request('/api/finance.php?action=create_payment',{'projectId':'audit-a','amount':1,**data},owner)[0]==400)
# The same failed request with a key must remain retryable, not get a spurious duplicate response.
key=uuid.uuid4().hex
bad={'projectId':'audit-a','amount':1,'currency':'USD','exchangeRate':0}
a=request('/api/finance.php?action=create_payment',bad,owner,headers={'X-Idempotency-Key':key})[0];b=request('/api/finance.php?action=create_payment',bad,owner,headers={'X-Idempotency-Key':key})[0]
check('failed requests remain retryable',a==b==400)
key=uuid.uuid4().hex;data={'name':'Concurrent test','id':'race-'+key,'projectCode':key}
def send(_):return request('/api/projects.php',data,owner,headers={'X-Idempotency-Key':key})[0]
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(send,range(2)))
check('simultaneous duplicate request serialized',sorted(results)==[200,409])
for url,name in [('javascript:alert(1)','fake URL'),('data:image/png;base64,%%%%','invalid base64'),('data:image/png;base64,'+base64.b64encode(b'<?php echo 1;').decode(),'executable content')]:
 check('upload rejects '+name,request('/api/files.php',{'action':'upload_file','projectId':'audit-a','fileName':'file.png','mimeType':'image/png','sizeBytes':1,'dataUrl':url},owner)[0]==400)
text='data:text/plain;base64,'+base64.b64encode(b'Harmless audit test').decode()
status,b=request('/api/files.php',{'action':'upload_file','projectId':'audit-a','fileName':'test.txt','sizeBytes':1,'dataUrl':text},owner);check('valid file uploaded using actual bytes',status==201)
if status==201:
 status,b=request('/api/files.php?projectId=audit-a',token=owner);row=next(r for r in b['data']['files'] if r['safe_name']=='test.txt');check('server stores verified file length',row['size_bytes']==len(b'Harmless audit test'))
check('foreign folder reference rejected',request('/api/files.php',{'action':'create_folder','projectId':'audit-a','name':'Invalid parent','parentFolderId':999999},owner)[0]==403)
check('unknown API action returns defined error',request('/api/finance.php?section=missing',token=owner)[0]==404)
# Password change must invalidate the employee token; fixtures restore the test account on the next run.
status,b=request('/api/auth.php?action=sync_user',{'user':{'id':'audit-viewer','username':'audit-viewer','rawPassword':password+'changed'}},viewer)
check('self password change succeeds',status==200)
check('old session invalid after password change',request('/api/auth.php',token=viewer)[0]==401)
pathlib.Path(__file__).with_name('results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
print(f'{sum(ok for _,ok in checks)}/{len(checks)} passed')
raise SystemExit(0 if all(ok for _,ok in checks) else 1)
