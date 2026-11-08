/* Follow-up adapter for commit 0c5a360; no original frontend source is present. */
const fs=require('fs'),zlib=require('zlib'),crypto=require('crypto'),acorn=require('acorn');
const path=require('path').resolve(__dirname,'../nsc-accounting-r3.js.gz');
const original=fs.readFileSync(path);
let source=zlib.gunzipSync(original).toString();
if(source.includes('/* NSC workflow v2 */'))throw Error('Already patched');
function once(old,next){if(source.split(old).length!==2)throw Error('Unexpected bundle layout: '+old.slice(0,100));source=source.replace(old,next)}
// Documents, attachments and detail panels consume this context field.
once('projects:u,currentProject:Nt,setCurrentProjectId:Pt','projects:u,currentProject:Nt,currentProjectId:Nt?.id||f,setCurrentProjectId:Pt');
// Keep the selected ID in sync with the project actually displayed.
once('let Nt=(0,b.useMemo)(()=>u.find(e=>e.id===f)||u[0]||null,[u,f]),Pt=e=>', 'let Nt=(0,b.useMemo)(()=>u.find(e=>e.id===f)||u[0]||null,[u,f]);(0,b.useEffect)(()=>{if(Nt&&Nt.id!==f)p(Nt.id)},[Nt?.id,f]);let Pt=e=>');
// Allow readable UI controls to reflect the selected project as well as the server ceiling.
once('return(0,Q.jsx)(Yf.Provider,{value:{language:t,setLanguage:Dt', 'window.NSC_ACTIVE_PROJECT_ID=Nt?.id||f;return(0,Q.jsx)(Yf.Provider,{value:{language:t,setLanguage:Dt');
// Newly created folders open immediately, with search/category filters reset.
once('me(``),ge(``),fe(!1))},Tt=e=>','me(``),ge(``),fe(!1),ne(t.folder.id),G(``),K(`all`),te(`files`))},Tt=e=>');
once('"aria-label":V(`Folder Name`,`نام پوشه`,`د پوښۍ نوم`),value:pe','autoFocus:!0,"aria-label":V(`Folder Name`,`نام پوشه`,`د پوښۍ نوم`),value:pe');
// Reset folder navigation when switching projects.
once('ft=(0,b.useRef)(null),pt=(0,b.useMemo)', 'ft=(0,b.useRef)(null);(0,b.useEffect)(()=>{ne(null),G(``),K(`all`)},[i]);let pt=(0,b.useMemo)');
// Payroll is settled through the payroll ledger; ordinary AFN bills use USD equivalents here.
once('m.filter(e=>e.projectId===f&&(e.remainingAmount||0)>0).forEach(t=>{let n=', 'm.filter(e=>e.projectId===f&&(e.remainingAmount||0)>0&&e.partyType!==`labour`&&!String(e.id).startsWith(`exp-payroll-`)).forEach(t=>{let debtUSD=Number(((t.remainingAmount||0)/(t.currency===`AFN`?(t.exchangeRate||70):1)).toFixed(2)),n=');
once('debtAmount:t.remainingAmount||0,title:Y(', 'debtAmount:debtUSD,title:Y(');
source=source.replaceAll('${(t.remainingAmount||0).toLocaleString()}', '${debtUSD.toLocaleString()}');
// Search/notifications can also navigate to the exchange panel.
once('`payments`,`suppliers`,`contractors`,`labor`,`steel`','`payments`,`exchange`,`suppliers`,`contractors`,`labor`,`steel`');
source='/* NSC workflow v2 */\n'+source;
acorn.parse(source,{ecmaVersion:'latest',sourceType:'module'});
fs.writeFileSync(path,zlib.gzipSync(source,{level:9,mtime:0}));
console.log('Workflow adapter parsed; baseline SHA256 '+crypto.createHash('sha256').update(original).digest('hex'));
