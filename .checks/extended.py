exec(open(__import__('pathlib').Path(__file__).with_name('regression.py')).read().split("owner=login('preview')")[0])
owner=login('preview');staff=login('audit-staff');other=login('audit-other')
before=request('/api/reports.php?type=monthly_timeline&projectId=audit-a&year=2040',token=owner)[1]['data']['months'][0]['income']
for project,amount in [('audit-a',10),('audit-b',20)]:
 status,b=request('/api/finance.php?action=create_income',{'projectId':project,'amount':amount,'targetAccountId':'audit-afn','currency':'AFN','date':'2040-01-01','notes':uuid.uuid4().hex},owner);check('income saved '+project,status==200)
status,b=request('/api/reports.php?type=monthly_timeline&projectId=audit-a&year=2040',token=staff)
check('monthly report includes only selected project',status==200 and b['data']['months'][0]['income']==before+10)
status,b=request('/api/reports.php?type=project_pnl&projectId=audit-a',token=staff);check('project P&L filter respected',status==200 and [r['projectId'] for r in b['data']['pnl']]==['audit-a'])
for path in ['/api/reports.php?type=dashboard&projectId=audit-a','/api/finance.php?section=treasury_accounts&projectId=audit-a','/api/suppliers.php?projectId=audit-a']:
 check('scoped employee cannot read company-wide totals '+path,request(path,token=staff)[0]==403)
for token,project,weight in [(owner,'audit-a',18.5),(other,'audit-foreign',19.5)]:
 status,b=request('/api/materials.php?action=rebar_specs',{'projectId':project,'diameterMm':16,'lengthM':12,'brandSpec':'audit-'+uuid.uuid4().hex[:6],'weightPerBarKg':weight},token)
 check('reference weight saved within company '+project,status==200)
check('cannot write reference into another company',request('/api/materials.php?action=rebar_specs',{'projectId':'audit-foreign','diameterMm':16,'lengthM':12,'weightPerBarKg':20},owner)[0]==403)
status,b=request('/api/materials.php?action=rebar_specs&projectId=audit-a',token=owner);check('reference weights isolated by project',status==200 and all(r['project_id']=='audit-a' for r in b['data']['specs']))
status,b=request('/api/materials.php',{'projectId':'audit-a','materialName':'Test rebar','materialCategory':'steel','quantity':1,'unitPrice':100,'currency':'USD','exchangeRate':70,'confirmedTotalTon':1,'purchaseDate':'2040-01-01'},owner)
check('steel purchase saved',status==200)
if status!=200:print('Purchase error',status,b)
if status==200:
 record=b['data']['id']
 for path in ['/api/materials.php?action=steel_report&projectId=audit-a','/api/reports.php?type=unified_financial&projectId=audit-a','/api/reports.php?type=drilldown&metric=steel_cost&projectId=audit-a']:
  status,b=request(path,token=owner)
  rows=b.get('data',{}).get('rows',b.get('data',{}).get('steelReport',{}).get('rows',b.get('data',{}).get('items',[])))
  check('new steel purchase appears in report '+path,status==200 and any(r['id']==record for r in rows))
for path in ['/.git/HEAD','/.git/config','/api/report-data.php']:
 check('metadata/helper path denied '+path,request(path)[0]==403)
# Ensure edit-only roles can update without create permission.
master_account=json.loads(pathlib.Path(os.environ.get('NSC_TEST_ACCOUNTS_FILE','/workspace/nsc-preview/accounts.json')).read_text())[1]
status,b=request('/api/auth.php?action=master_login',{'username':master_account['username'],'password':master_account['password']});master=b['data']['token']
status,b=request('/api/office7.php?action=update_permissions',{'id':'audit-create','permissions':{'projects':{'view':True,'create':False,'edit':True}}},master)
check('edit-only permissions saved',status==200)
edit=login('audit-create')
check('edit-only user can edit project',request('/api/projects.php',{'id':'audit-a','name':'Edit-only test'},edit)[0]==200)
# Claims signed using the same installation key but malformed/expired are separately tested in PHP.
print(f'{sum(ok for _,ok in checks)}/{len(checks)} extended checks passed')
pathlib.Path(__file__).with_name('extended-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
raise SystemExit(0 if all(ok for _,ok in checks) else 1)
