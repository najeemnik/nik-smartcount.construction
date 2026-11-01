/* Apply after patch-client-permissions.cjs to the preserved, permission-adapted client. */
const fs=require('fs'),zlib=require('zlib'),cp=require('child_process'),path=require('path');
const target=path.resolve(__dirname,'../nsc-accounting-r3.js.gz');let source=zlib.gunzipSync(fs.readFileSync(target)).toString();
if(source.includes('/* NSC accounting display v1 */'))throw Error('Already patched; use the permission-adapted baseline before this patch.');
function replaceExact(before,after,count=1){if(source.split(before).length-1!==count)throw Error('Unexpected client structure: '+before.slice(0,90));source=source.split(before).join(after)}
replaceExact('let n=Math.round(e).toLocaleString(`en-US`);return t===`USD`?', 'let n=Number(e).toLocaleString(`en-US`,{minimumFractionDigits:2,maximumFractionDigits:2});return t===`USD`?');
replaceExact('g=e=>e.toLocaleString(`en-US`,{maximumFractionDigits:0})','g=e=>e.toLocaleString(`en-US`,{minimumFractionDigits:2,maximumFractionDigits:2})');
replaceExact('Nt=(0,b.useMemo)(()=>u.find(e=>e.id===f)||u[0]||null,[u,f]),Pt=', 'Nt=(0,b.useMemo)(()=>u.find(e=>e.id===f)||u[0]||null,[u,f]),_nscProjectSelection=(0,b.useEffect)(()=>{if(Nt&&f!==Nt.id)p(Nt.id)},[Nt?.id,f]),Pt=');
replaceExact('ji=(0,b.useMemo)(()=>Ai(f),[f,u,', 'ji=(0,b.useMemo)(()=>Ai(Nt?.id||f),[f,u,');
replaceExact('uO=e=>{let t=yp(),{tp:n,tx:r}=t,i=t.currentProjectId,','uO=e=>{let t=yp(),{tp:n,tx:r}=t,i=t.currentProject?.id||t.currentProjectId,');
replaceExact('`expenses`,`payments`,`suppliers`,`contractors`,`labor`,`steel`,`concrete`,`assets`,`documents`,`budget`,`accounting`,`reports`,`users`,`settings`,`audit_logs`,`builder`]),t=t=>{let n=t.detail?.tab;', '`expenses`,`payments`,`exchange`,`suppliers`,`contractors`,`labor`,`steel`,`concrete`,`assets`,`documents`,`budget`,`accounting`,`reports`,`users`,`settings`,`audit_logs`,`builder`]),t=t=>{let n=t.detail?.tab;',1);
replaceExact('let D=new Date,O=e=>{if(S===`all`)', 'let _nscChartLedger=Wd({projectId:i,defaultExchangeRate:t.currentProject?.defaultExchangeRate||t.appSettings.exchangeRateUSDToAFN||70,expenses:o,steelRecords:c,concreteRecords:l,contractors:e.contractors||t.contractors,suppliers:e.suppliers||t.suppliers,payments:s,laborRecords:t.laborRecords,apartments:u,exchangeTransactions:t.exchangeTransactions}),D=new Date,O=e=>{if(S===`all`)');
replaceExact('let N=j>0?Math.min(100,Math.round(M/j*100)):0,P={};', 'let N=a.totalExpenses>0?Math.min(100,Math.round(a.totalPayments/a.totalExpenses*100)):0,P={};');
replaceExact('I=a.steelRemaining||0,ee=a.concreteRemaining||0,L=a.contractorRemaining||0,R=a.supplierRemaining||0,z=I+ee+L+R,', 'I=_nscChartLedger.liabilities.steelUnpaidUSD,ee=_nscChartLedger.liabilities.concreteUnpaidUSD,L=_nscChartLedger.liabilities.contractorWorkPayableUSD,R=_nscChartLedger.liabilities.supplierExpensesUnpaidUSD,z=_nscChartLedger.liabilities.totalBilledLiabilitiesUSD,');
source+='\n/* NSC accounting display v1 */\n';
const syntax=cp.spawnSync(process.execPath,['--check','--input-type=module'],{input:source,encoding:'utf8'});if(syntax.status!==0)throw Error(syntax.stderr);
fs.writeFileSync(target,zlib.gzipSync(source,{level:9}));console.log('Applied precise money display, default-project chart scope and exchange navigation.');
