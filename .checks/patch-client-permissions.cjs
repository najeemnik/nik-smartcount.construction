/* Build-time adapter for the preserved compiled client (original TS source is unavailable).
 * Run with Node + acorn 8: NODE_PATH=/path/to/node_modules node .checks/patch-client-permissions.cjs
 * Fails on unexpected upstream structure rather than silently dropping permission checks.
 */
const fs=require('fs'),zlib=require('zlib'),acorn=require('acorn');
const path=require('path').resolve(__dirname,'../nsc-accounting-r3.js.gz');
let source=zlib.gunzipSync(fs.readFileSync(path)).toString();
if(source.includes('/* NSC granular permissions v1 */'))throw Error('Already patched; start from the preserved upstream bundle to rebuild.');
function replaceOnce(old,next){if(source.split(old).length!==2)throw Error('Upstream adapter mismatch: '+old.slice(0,90));source=source.replace(old,next)}
replaceOnce('Zl=(e,t)=>{if(!e)return!1;', 'Zl=(e,t)=>{if(!e)return!1;if(!window.NSC_CAN(t,`view`))return!1;');
replaceOnce('lu=(e,t,n,r,i=Date.now())=>{if(!e)return!1;', 'lu=(e,t,n,r,i=Date.now())=>{if(!e)return!1;if(!window.NSC_CAN(n,r))return!1;');
replaceOnce('isLockedBySuperAdmin:o,permissions:{...a,', 'isLockedBySuperAdmin:o,effectivePermissions:e.effectivePermissions,permissions:{...a,');
replaceOnce('i=e.role===`admin`||e.role===`accountant`?e.role:`viewer`','i=[`admin`,`accountant`,`viewer`,`project_manager`,`procurement`].includes(e.role)?e.role:`viewer`');
// The PHP-calculated ceiling, rather than hardcoded role defaults, drives tab/action visibility.
const suStart=source.indexOf('su=(e,t)=>'),cuStart=source.indexOf('cu=(e,t,n)=>',suStart),luStart=source.indexOf('lu=(e,t,n,r,i=Date.now())=>',cuStart);
if(suStart<0||cuStart<0||luStart<0)throw Error('Permission helper layout changed');
source=source.slice(0,suStart)+'su=(e,t)=>Zl(e,t),cu=(e,t,n)=>window.NSC_CAN(t,n),'+source.slice(luStart);
// Navigation may be triggered by search or custom events, so gate the actual panel too.
for(const module of ['projects','expenses','steel','concrete','contractors','labor','suppliers','assets','apartments','payments','exchange','budget','accounting','documents','reports','users','audit_logs','settings']){
 replaceOnce('T===`'+module+'`&&(0,Q.jsx)', 'T===`'+module+'`&&u(`'+module+'`)&&(0,Q.jsx)');
}
replaceOnce('T===`builder`&&(0,Q.jsx)', 'T===`builder`&&e.isMasterSuperAdmin&&(0,Q.jsx)');
source=source.replaceAll('.filter(t=>t.enabled!==!1&&t.isEnabled!==!1&&(e[t.id]||e[t.actionKey||t.id]))', '.filter(t=>t.enabled!==!1&&t.isEnabled!==!1&&window.NSC_FILTER_ACTION(t.actionKey||t.id)&&(e[t.id]||e[t.actionKey||t.id]))');
const ast=acorn.parse(source,{ecmaVersion:'latest',sourceType:'module'}),edits=[];
function each(n,cb){if(!n||typeof n!=='object')return;if(n.type)cb(n);for(const [k,v]of Object.entries(n)){if(k==='start'||k==='end')continue;if(Array.isArray(v))for(const a of v)each(a,cb);else if(v&&typeof v==='object')each(v,cb)}}
function str(n){return n?.type==='TemplateLiteral'&&n.expressions.length===0?n.quasis[0].value.cooked:n?.type==='Literal'?n.value:null}
const componentModules={fO:'projects',_O:'expenses',DO:'steel',OO:'concrete',kO:'contractors',MO:'labor',NO:'suppliers',PO:'assets',mk:'apartments',hk:'payments',_k:'exchange',Rk:'budget',Vk:'accounting',Ck:'documents',wk:'reports',Jk:'audit_logs',eA:'settings',nA:'projects',fA:'projects',pA:'partners',mA:'projects',MA:'expenses',EO:'steel',NA:'concrete',PA:'contractors',FA:'suppliers',LA:'apartments',RA:'payments',zA:'documents',VA:'payments',HA:'suppliers',jO:'labor',WA:'projects',GA:'expenses',KA:'assets'};
const moduleFor=name=>/steel|rebar/i.test(name)?'steel':/concrete/i.test(name)?'concrete':/contractor/i.test(name)?'contractors':/supplier|procurement|material/i.test(name)?'suppliers':/payroll|labor|employee/i.test(name)?'labor':/expense|petty/i.test(name)?'expenses':/apartment|installment/i.test(name)?'apartments':/exchange/i.test(name)?'exchange':/payment|income/i.test(name)?'payments':/budget/i.test(name)?'budget':/document|file/i.test(name)?'documents':/partner/i.test(name)?'partners':/project/i.test(name)?'projects':/asset/i.test(name)?'assets':/audit/i.test(name)?'audit_logs':/report/i.test(name)?'reports':/account/i.test(name)?'accounting':null;
function meaning(name,module){if(Array.isArray(name))return name;let action=/onOpenPartners/.test(name)?'view':/onOpenTransfer/.test(name)?'edit':/post[A-Z]|unpost[A-Z]|reverse[A-Z]/.test(name)?'approve':/delete|remove|clearAudit/i.test(name)?'delete':/onOpenEdit|update|approve|reject/i.test(name)?(/approve|reject/i.test(name)?'approve':'edit'):/onOpenAdd|onOpenNew|^add[A-Z]|^create[A-Z]/.test(name)?'create':/^print/i.test(name)?'print':/^export/i.test(name)?'export':null;return action&&moduleFor(name)?[moduleFor(name),action]:null}
function walk(n,scope=new Map(),module=null,parent=null){
 if(!n||typeof n!=='object')return;
 if(/Function/.test(n.type||'')){
  scope=new Map(scope);module=componentModules[parent?.id?.name]||module;
  for(const p of n.params||[])if(p.type==='ObjectPattern')for(const prop of p.properties)if(prop.value?.type==='Identifier')scope.set(prop.value.name,prop.key.name);
  // Function-local destructuring names identify API actions and prop callbacks.
  function collect(x){if(!x||typeof x!=='object')return;if(x!==n&&/Function/.test(x.type||''))return;
   if(x.type==='VariableDeclarator'&&x.id?.type==='Identifier'&&/Function/.test(x.init?.type||'')){
     const pairs=[];each(x.init,y=>{if(y.type==='CallExpression'&&typeof str(y.arguments?.[0])==='string'&&['create','edit','delete','del','approve','print','export'].includes(str(y.arguments?.[1])))pairs.push([str(y.arguments[0]),str(y.arguments[1])])});
     const unique=[...new Map(pairs.map(p=>[p.join(':'),p])).values()];if(unique.length===1)scope.set(x.id.name,unique[0]);
   }
   if(x.type==='VariableDeclarator'&&x.id?.type==='ObjectPattern')for(const p of x.id.properties)if(p.value?.type==='Identifier')scope.set(p.value.name,p.key.name||str(p.key));
   for(const v of Object.values(x)){if(Array.isArray(v)){for(const y of v)collect(y)}else if(v&&typeof v==='object')collect(v)}
  }collect(n.body);
 }
 if(n.type==='CallExpression'&&['button','a','input'].includes(str(n.arguments?.[0]))&&n.arguments[1]?.type==='ObjectExpression'){
  const props=n.arguments[1],click=props.properties.find(p=>p.key?.name==='onClick'),checks=[];
  if(click){
   if(click.value.type==='Identifier'){const m=meaning(scope.get(click.value.name)||'',module);if(m)checks.push(m)}
   each(click.value,x=>{
    if(x.type!=='CallExpression')return;
    const mod=str(x.arguments?.[0]),act=str(x.arguments?.[1]);
    if(mod&&['view','create','edit','del','delete','approve','print','export'].includes(act))checks.push([mod,act]);
    else if(mod&&/printIfAllowed/.test(scope.get(x.callee?.name)||''))checks.push([mod,'print']);
    const m=meaning(scope.get(x.callee?.name)||'',module);if(m)checks.push(m);
   });
  }
  // Ordinary section action buttons use translation keys or explicit labels.
  if(module&&click&&!checks.length){
   const labelProps=props.properties.filter(p=>['title','children','aria-label'].includes(p.key?.name||str(p.key)));
   for(const p of labelProps)each(p.value,x=>{const label=str(x);const actions={edit:'edit',delete:'delete',remove:'delete',print:'print',export:'export',exportExcel:'export',exportCSV:'export',approve:'approve',reject:'approve',add:'create',addNew:'create'};if(actions[label])checks.push([module,actions[label]]);
    else if(typeof label==='string'){
      const action=/^(Edit\b|Rename\b|Link to\b|Click to change status|Manage chart|Save design|Save Size Limit|Reset Default|Add Format|Add\/Edit Rate|Move Folder|Move to Another Folder|File Policy|Configure permitted|Upload to Google Drive)/i.test(label)?'edit'
      :/^(Delete\b|Remove from register|Clear Test Transactions|Wipe test data)/i.test(label)?'delete'
      :/^(Print\b|printVoucher|Goods receipt|View \/ Print)/i.test(label)?'print'
      :/^(Export\b|Download\b|exportCsv|exportLocalJSON|Backup Now|Full Backup|Secure Download)/i.test(label)?'export'
      :/^(Post \(|Unpost \(|Reverse \()/i.test(label)?'approve'
      :/^(Add Employee|Record Earned|Record Work Progress|Pay Cash Wage|Pay Remaining Balance|New Exchange Transaction|Money In|Money Out|Create.*Instruction|budgetAddTitle|Set Budget|budgetAssign|Assign budget|Upload Files|New Folder|Camera Capture|Quick Image \/ PDF|Add & Link Bill|Compose Document|Copy File)/i.test(label)?'create':null;
      if(action)checks.push([module,action]);
    }});
  }
  if(!checks.length&&click){const handler=source.slice(click.value.start,click.value.end);
   if(module==='contractors'&&handler==='()=>fe(e.id,e.name)'||module==='suppliers'&&handler==='()=>ee(e.id,e.name)')checks.push(['payments','create']);
   if(module==='exchange'&&handler==='()=>Re(i)')checks.push(['exchange','export']);
   if(module==='settings'&&['()=>_(t.id)','()=>y(e.id)','()=>x(t.id)'].includes(handler))checks.push(['settings','edit']);
  }
  if(checks.length){const unique=[...new Map(checks.map(c=>[c.join(':'),c])).values()];edits.push({at:props.start+1,text:'"data-nsc-rights":'+JSON.stringify(unique)+','})}
 }
 for(const [k,v]of Object.entries(n)){if(k==='start'||k==='end')continue;if(Array.isArray(v))for(const a of v)walk(a,scope,module,n);else if(v&&typeof v==='object')walk(v,scope,module,n)}
}
walk(ast);
for(const e of edits.sort((a,b)=>b.at-a.at))source=source.slice(0,e.at)+e.text+source.slice(e.at);
const marker='(0,x.createRoot)(document.getElementById(`root`))';
replaceOnce(marker,`/* NSC granular permissions v1 */
for(const key of ['jsx','jsxs']){const original=Q[key];Q[key]=function(type,props,...rest){const rights=props?.['data-nsc-rights'];if(rights&&rights.some(([m,a])=>!window.NSC_CAN(m,a)))return null;return original(type,props,...rest)}}
`+marker);
acorn.parse(source,{ecmaVersion:'latest',sourceType:'module'});
fs.writeFileSync(path,zlib.gzipSync(source,{level:9,mtime:0}));console.log('Validated bundle; annotated '+edits.length+' action controls.');
