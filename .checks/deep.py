exec(open(__import__('pathlib').Path(__file__).with_name('regression.py')).read().split("owner=login('preview')")[0])
owner=login('preview');other=login('audit-other')
a=json.loads(pathlib.Path(os.environ.get('NSC_TEST_ACCOUNTS_FILE','/workspace/nsc-preview/accounts.json')).read_text())[1]
s,b=request('/api/auth.php?action=master_login',{'username':a['username'],'password':a['password']});assert s==200
master=b['data']['token']
tag=uuid.uuid4().hex[:12]
s,b=request('/api/suppliers.php?action=rates',{'projectId':'audit-foreign','supplierName':'Deep supplier '+tag,'product':'steel','unit':'ton','rate':100,'currency':'USD'},other);assert s==201,(s,b)
foreign_id=b['id']
s,b=request('/api/suppliers.php?action=rates',{'id':foreign_id,'projectId':'audit-a','supplierName':'Attacker '+tag,'product':'different','unit':'ton','rate':999,'currency':'USD'},owner)
s2,rows=request('/api/suppliers.php?action=rates&projectId=audit-foreign',token=other)
r=next(x for x in rows['rates'] if x['id']==foreign_id)
check('foreign rate cannot be overwritten through supplied primary ID',float(r['last_agreed_rate'])==100)
# Cross-project collisions on a logical product must not update another project.
s,b=request('/api/suppliers.php?action=rates',{'projectId':'audit-a','supplierName':'Shared '+tag,'product':'cement','unit':'bag','rate':10},owner);assert s==201
s,b=request('/api/suppliers.php?action=rates',{'projectId':'audit-b','supplierName':'Shared '+tag,'product':'cement','unit':'bag','rate':20},owner);assert s==201
s,b=request('/api/suppliers.php?action=rates&projectId=audit-a',token=owner)
r=next(x for x in b['rates'] if x['supplier_name']=='Shared '+tag)
check('supplier rate memory isolated by project',float(r['last_agreed_rate'])==10)
check('unclaimed contractor cannot be taken over',request('/api/subcontractors.php',{'id':'deep-orphan-edit','contractor_name':'Taken'},owner,method='PUT')[0]in[403,404])
check('unclaimed contractor cannot be deleted',request('/api/subcontractors.php?id=deep-orphan-delete',token=owner,method='DELETE')[0]in[403,404])
# A manager whose user-management service is disabled must not use the alternate lock endpoint.
try:
 s,b=request('/api/office7.php?action=update_permissions',{'id':'preview-owner','customEnabledModules':{'users':False}},master);assert s==200
 check('disabled user-management service blocks toggle_lock',request('/api/office7.php?action=toggle_lock',{'id':'audit-staff','isLocked':True},owner)[0]==403)
finally:
 request('/api/office7.php?action=update_permissions',{'id':'preview-owner','customEnabledModules':{}},master)
 request('/api/office7.php?action=toggle_lock',{'id':'audit-staff','isLocked':False},master)
# Validation and use must select the SAME password field.
check('conflicting password aliases cannot bypass minimum length',request('/api/office7.php?action=reset_password',{'id':'audit-viewer','password':'x','newPassword':'LongEnoughForPolicy2026!'},master)[0]==400)
# Per-user project permissions also apply to drafts, not just committed data.
staff=login('audit-staff')
check('draft cannot be created for an ungranted project',request('/api/office7.php?action=save_draft',{'projectId':'audit-c','draftKey':'deep-'+tag,'data':{'secret':'test'}},staff)[0]==403)
# Correctly scoped operations must keep working after closing alternate paths.
s,b=request('/api/suppliers.php?action=rates',{'projectId':'audit-a','supplierName':'Valid '+tag,'product':'stone','unit':'ton','rate':5},owner)
check('legitimate supplier rate creation still works',s==201)
s,b=request('/api/suppliers.php?action=rates',{'projectId':'audit-a','supplierId':'foreign-supplier-missing','supplierName':'Invalid relation','product':'steel','unit':'ton','rate':5},owner)
check('foreign or missing supplier reference rejected',s==403)
s,b=request('/api/office7.php?action=update_permissions',{'id':'audit-create','permissions':{'suppliers':{'view':True,'create':True,'edit':False}}},master);assert s==200
create_only=login('audit-create')
rate={'projectId':'audit-a','supplierName':'Create only '+tag,'product':'sand','unit':'ton','rate':5}
check('create-only employee can create a rate',request('/api/suppliers.php?action=rates',rate,create_only)[0]==201)
check('create-only employee cannot overwrite existing rate',request('/api/suppliers.php?action=rates',{**rate,'rate':6},create_only)[0]==403)
key='allowed-deep-'+tag
check('authorized project draft save works',request('/api/office7.php?action=save_draft',{'projectId':'audit-a','draftKey':key,'data':{'marker':tag}},staff)[0]==200)
old_grants=request('/api/auth.php',token=staff)[1]['data']['user']['projectGrants']
try:
 s,b=request('/api/office7.php?action=update_permissions',{'id':'audit-staff','projectGrants':[{'projectId':'audit-b','sections':{'projects':{'view':True}}}]},master);assert s==200
 s,b=request('/api/office7.php?action=drafts',token=staff)
 check('revoked project draft is no longer returned',s==200 and all(d['draftKey']!=key for d in b['data']['drafts']))
 check('revoked project draft cannot be deleted by key',request('/api/office7.php?action=delete_draft',{'draftKey':key},staff)[0]==403)
finally:
 request('/api/office7.php?action=update_permissions',{'id':'audit-staff','projectGrants':old_grants},master)
check('authorized project draft deletion works',request('/api/office7.php?action=delete_draft',{'draftKey':key},staff)[0]==200)
s,b=request('/api/subcontractors.php',token=owner)
check('unclaimed contractors not exposed through list',s==200 and not any(r['id'].startswith('deep-orphan') for r in b['subcontractors']))
# Transfers must invalidate sessions and keep the old company's draft data isolated.
viewer=login('audit-viewer');draft_key='old-company-'+tag;form_type='deep_previous_'+tag
s,b=request('/api/office7.php?action=save_draft',{'projectId':'audit-a','draftKey':draft_key,'formType':form_type,'data':{'test':tag}},viewer)
check('draft prepared before company transfer',s==200)
try:
 s,b=request('/api/office7.php?action=update_tenant',{'id':'audit-viewer','ownerAdminId':'audit-other'},master);assert s==200
 check('old session invalidated on company transfer',request('/api/auth.php',token=viewer)[0]==401)
 moved=login('audit-viewer')
 request('/api/office7.php?action=delete_draft',{'formType':form_type},moved)
finally:
 request('/api/office7.php?action=update_tenant',{'id':'audit-viewer','ownerAdminId':'preview-owner'},master)
restored=login('audit-viewer');s,b=request('/api/office7.php?action=drafts',token=restored)
check('new company cannot delete previous company drafts',s==200 and any(d['draftKey']==draft_key for d in b['data']['drafts']))
request('/api/office7.php?action=delete_draft',{'draftKey':draft_key},restored)
try:
 s,b=request('/api/auth.php?action=sync_user',{'user':{'id':'audit-viewer','username':'audit-viewer','ownerAdminId':'audit-other'}},master);assert s==200,(s,b)
 check('sync_user ownership change also invalidates session',request('/api/auth.php',token=restored)[0]==401)
finally:
 request('/api/office7.php?action=update_tenant',{'id':'audit-viewer','ownerAdminId':'preview-owner'},master)
# SQL-side tenant conditions must also protect a pre-existing colliding legacy primary key.
s,b=request('/api/suppliers.php?action=rates',{'projectId':'audit-a','supplierName':'reserved-victim','product':'reserved','unit':'ton','rate':777,'currency':'AFN'},owner)
check('legacy primary-key collision is rejected',s==409)
s,b=request('/api/suppliers.php?action=rates&projectId=audit-foreign',token=other)
reserved=next(r for r in b['rates'] if r['supplier_name']=='Reserved legacy ID')
check('colliding foreign row remains unchanged',float(reserved['last_agreed_rate'])==50)
request('/api/office7.php?action=update_permissions',{'id':'audit-create','permissions':{'suppliers':{'view':True,'create':False,'edit':True}}},master)
edit_only=login('audit-create')
check('edit-only rate update remains available',request('/api/suppliers.php?action=rates',{**rate,'rate':7},edit_only)[0]==201)
check('edit-only employee cannot create a new rate',request('/api/suppliers.php?action=rates',{**rate,'product':'new-'+tag},edit_only)[0]==403)
print(f'{sum(ok for _,ok in checks)}/{len(checks)} deep security checks passed')
pathlib.Path(__file__).with_name('deep-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
raise SystemExit(0 if all(ok for _,ok in checks) else 1)
