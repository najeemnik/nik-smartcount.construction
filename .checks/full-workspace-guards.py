"""Local-only integration tests: monetary validation, approval rights and alternate project fields."""
import pathlib,json,urllib.request,urllib.error,uuid
base='http://127.0.0.1:8081/api/';accounts=json.loads(pathlib.Path('/workspace/nsc-test/accounts.json').read_text());pw=accounts[0]['password'];opener=urllib.request.build_opener(urllib.request.ProxyHandler({}));checks=[]
def req(path,data=None,token=None,raw=None):
 r=urllib.request.Request(base+path,data=raw if raw is not None else json.dumps(data).encode()if data is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+token}if token else{})})
 try:s=opener.open(r,timeout=20)
 except urllib.error.HTTPError as e:s=e
 with s:
  try:b=json.load(s)
  except ValueError:b={}
  return s.status,b
def check(n,ok):checks.append((n,bool(ok)));print(('PASS 'if ok else'FAIL ')+n,flush=True)
def login(name):
 s,b=req('auth.php?action=login',{'username':name,'password':pw});assert s==200,(s,b);return b['data']['token']
s,b=req('auth.php?action=master_login',accounts[1]);assert s==200;master=b['data']['token'];owner=login('workflow-demo');s,b=req('workspace.php',token=owner);before=b['data'];pid=before['state']['projects'][0]['id'];otherpid=before['state']['projects'][1]['id'];uid=None;username='guards-'+uuid.uuid4().hex[:12]
try:
 original={'id':'guard-expense','projectId':pid,'amount':100,'currency':'USD','exchangeRate':65,'date':'2026-11-06'}
 assets=[{'id':'guard-asset-a','assignedProjectId':pid,'name':'A generator','purchaseCost':65000,'salvageValue':5000,'usefulLifeYears':5},{'id':'guard-asset-b','assignedProjectId':otherpid,'name':'Private B asset','purchaseCost':10000,'usefulLifeYears':5}]
 employees=[{'id':'guard-worker-shared','fullName':'Shared worker','assignedProjectIds':[pid,otherpid]},{'id':'guard-worker-a','fullName':'A worker','assignedProjectIds':[pid]},{'id':'guard-worker-b','fullName':'Private B worker','assignedProjectIds':[otherpid]}]
 s,b=req('workspace.php',{'baseVersion':before['stateVersion'],'state':{'expenses':[original],'assets':assets,'employees':employees}},owner);assert s==200,(s,b)
 rights={m:{'view':True,'create':True,'edit':True,'delete':False,'approve':False}for m in ['projects','expenses','payments','assets','labor']};grants=[{'projectId':pid,'sections':rights},{'projectId':otherpid,'sections':{'projects':{'view':True}}}]
 s,b=req('auth.php?action=sync_user',{'user':{'createOnly':True,'username':username,'email':username+'@nik-smartcount.com','rawPassword':pw,'role':'accountant','ownerAdminId':'workflow-owner','projectGrants':grants,'assignedProjectIds':[pid,otherpid]}},master);assert s==200,(s,b);uid=b['data']['id'];actor=login(username)
 def write(section,rows,token=actor,raw=None):
  s,b=req('workspace.php',token=token);assert s==200;return req('workspace.php',{'baseVersion':b['data']['stateVersion'],'state':{section:rows}},token,raw)
 s,b=req('workspace.php',token=actor);visible=b['data']['state'];check('asset assignedProjectId is visible in the granted project',[x['id']for x in visible['assets']]==['guard-asset-a']);check('shared employee visible, private B worker hidden',set(x['id']for x in visible['employees'])=={'guard-worker-shared','guard-worker-a'})
 check('scoped asset edit uses assigned project permission',write('assets',[{**assets[0],'name':'Updated A generator'}])[0]==200);check('asset cannot be moved into unauthorized project',write('assets',[{**assets[0],'assignedProjectId':otherpid}])[0]==403);check('hidden foreign asset ID cannot be stolen',write('assets',[{**assets[0],'id':'guard-asset-b'}])[0]==403)
 check('shared employee edit needs rights for all assignments',write('employees',[{**employees[0],'fullName':'Unauthorized global rename'},employees[1]])[0]==403);check('single-project employee edit remains valid',write('employees',[employees[0],{**employees[1],'fullName':'Updated A worker'}])[0]==200)
 for field,value in [('amount',-1),('paidAmount',-1),('quantity',-2),('unitPrice',-2),('amount','not-a-number')]:check('invalid expense field rejected '+field+str(value),write('expenses',[{**original,field:value}])[0]==400)
 for patch in [{'approvalStatus':'approved'},{'approvalStatus':'rejected'},{'approvedBy':'forged approver'},{'approvedAt':'2026-11-06'},{'postedBy':'forged poster'},{'docStatus':'posted'}]:check('no-approve role cannot forge '+','.join(patch),write('expenses',[{**original,**patch}])[0]==403)
 check('ordinary valid edit still succeeds',write('expenses',[{**original,'amount':150}])[0]==200)
 check('negative payment without a reversal is rejected',write('payments',[{'id':'bad-payment','projectId':pid,'amount':-10,'currency':'USD'}])[0]==400)
 s,b=req('workspace.php',token=actor);payload=json.dumps({'baseVersion':b['data']['stateVersion'],'state':{'expenses':[original]}}).replace('"amount": 100','"amount": 1e309').encode();check('nonfinite numeric amount returns a validation error',req('workspace.php',token=actor,raw=payload)[0]==400)
 for patch in [{'salvageValue':70000},{'usefulLifeYears':0},{'accumulatedDepreciation':65001}]:check('invalid asset accounting rejected '+','.join(patch),write('assets',[{**assets[0],**patch}])[0]==400)
 check('reversal requires approval rights',write('expenses',[original,{**original,'id':'reversal','amount':-100,'reversalOf':original['id']}])[0]==403)
 check('authorized signed reversal remains supported',write('expenses',[original,{**original,'id':'reversal','amount':-100,'reversalOf':original['id'],'docStatus':'posted'}],owner)[0]==200)
 check('reversal cannot reference a missing document',write('expenses',[original,{**original,'id':'missing-reversal','amount':-100,'reversalOf':'missing','docStatus':'posted'}],owner)[0]==400)
 check('validation helper cannot be accessed through the web',req('workspace-validation.php')[0]==403)
finally:
 if uid:req('auth.php?action=delete_user',{'id':uid},master)
 s,b=req('workspace.php',token=owner);req('workspace.php',{'baseVersion':b['data']['stateVersion'],'state':before['state']},owner)
pathlib.Path('/workspace/nsc-test/full-audit/workspace-guards.json').write_text(json.dumps(checks,indent=2));print(f'{sum(ok for _,ok in checks)}/{len(checks)} workspace validation checks passed');assert all(ok for _,ok in checks)
