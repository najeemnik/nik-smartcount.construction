require('./workflow-ui.cjs')(async({p,tab,api,saved,assert,errors})=>{
 const before=await api('workspace.php');const state=JSON.parse(JSON.stringify(before.state));const project=state.projects[0];assert(project);
 const now=new Date().toISOString(),id=project.id;
 project.defaultExchangeRate=65;project.initialGeneralExpenses=[{title:'Currency initial test',amount:20000,currency:'AFN'}];
 state.expenses=[{id:'exp-init-currency-test',projectId:id,category:'Currency initial test',amount:307.69,paidAmount:307.69,amountInUSD:307.69,amountInAFN:20000,currency:'AFN',exchangeRate:65,paymentStatus:'paid',remainingAmount:0,date:now.slice(0,10),createdAt:now},{id:'exp-dollar-test',projectId:id,amount:100,amountInUSD:100,amountInAFN:6500,currency:'USD',exchangeRate:65,paidAmount:0,remainingAmount:100,paymentStatus:'unpaid',date:now.slice(0,10)}];
 for(const key of ['payments','steel','concrete','labor','contractors','suppliers'])state[key]=[];
 await api('workspace.php',{baseVersion:before.stateVersion,state});
 try{
  await p.reload();await p.waitForFunction(()=>window.NSC_READY===true);await tab('dashboard');
  const afn=p.getByTestId('expense-native-AFN'),usd=p.getByTestId('expense-native-USD');
  await afn.waitFor();assert.equal(await afn.innerText(),'20,000 AFN');assert.equal(await usd.innerText(),'100 USD');console.log('PASS mixed native currencies without conversion');
  const paid=await p.getByTestId('kpi-card-total-payments').innerText();assert(/308|307/.test(paid),paid);console.log('PASS legacy initial payment converted once');
  await saved();let st=(await api('workspace.php')).state;assert.equal(st.expenses.find(x=>x.id==='exp-init-currency-test').paidAmount,20000);console.log('PASS corrected native fields persist');
  await p.screenshot({path:'/workspace/output/EXPENSE_CURRENCIES_DESKTOP.png'});
  await p.setViewportSize({width:390,height:844});assert.equal(await afn.innerText(),'20,000 AFN');assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await p.screenshot({path:'/workspace/output/EXPENSE_CURRENCIES_MOBILE.png'});console.log('PASS mobile split without horizontal overflow');
  await p.reload();await p.waitForFunction(()=>window.NSC_READY===true);await tab('dashboard');assert.equal(await afn.innerText(),'20,000 AFN');assert.equal(await usd.innerText(),'100 USD');assert.deepEqual(errors,[]);console.log('PASS reload stable and no browser errors');
 }finally{const current=await api('workspace.php');await api('workspace.php',{baseVersion:current.stateVersion,state:before.state})}
}).catch(e=>{console.error(e);process.exit(1)});
