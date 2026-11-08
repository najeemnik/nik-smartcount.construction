/* One accessible input, six visual cells: native paste/autofill and keyboard editing stay intact. */
(() => {
 'use strict';
 const t=(en,fa)=>document.documentElement.lang==='en'?en:fa;
 const normalize=value=>String(value).replace(/[۰-۹٠-٩]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.includes(c)?'۰۱۲۳۴۵۶۷۸۹'.indexOf(c):'٠١٢٣٤٥٦٧٨٩'.indexOf(c))).replace(/\D/g,'').slice(0,6);
 const icon='<svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M16 3 26 7v8c0 7-10 14-10 14S6 22 6 15V7L16 3Z" stroke="currentColor" stroke-width="1.7"/><path d="m11.5 15.5 3 3 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
 function decorate(dialog){
  dialog.classList.add('nsc-code-dialog');
  const form=dialog.querySelector('form');if(!form||form.querySelector('.nsc-code-emblem'))return;
  const emblem=document.createElement('div');emblem.className='nsc-code-emblem';emblem.innerHTML=icon;
  const brand=document.createElement('div');brand.className='nsc-code-brand';brand.textContent='NIK SMART COUNT';
  const title=form.querySelector('h2');if(title){title.before(emblem,brand);title.id||='nsc-code-title-'+crypto.randomUUID();dialog.setAttribute('aria-labelledby',title.id)}
 }
 function attach(input){
  if(!input||input.closest('.nsc-otp'))return;
  const frame=document.createElement('div');frame.className='nsc-otp';frame.dir='ltr';
  const cells=document.createElement('div');cells.className='nsc-otp-cells';cells.setAttribute('aria-hidden','true');
  for(let i=0;i<6;i++){const cell=document.createElement('span');cell.className='nsc-otp-cell';cells.append(cell)}
  input.before(frame);frame.append(input,cells);input.classList.add('nsc-otp-native');input.spellcheck=false;input.autocapitalize='off';input.setAttribute('enterkeyhint','done');
  const status=document.createElement('p');status.className='nsc-otp-help';status.id=input.id+'-help';status.setAttribute('aria-live','polite');frame.after(status);
  input.setAttribute('aria-describedby',[input.getAttribute('aria-describedby'),status.id].filter(Boolean).join(' '));
  let previous='';
  const paint=()=>{
   const value=normalize(input.value);if(input.value!==value)input.value=value;
   const active=Math.min(input.selectionStart??value.length,5);
   [...cells.children].forEach((cell,i)=>{if(cell.textContent!==(value[i]||''))cell.textContent=value[i]||'';cell.classList.toggle('is-filled',i<value.length);cell.classList.toggle('is-active',i===active&&document.activeElement===input);if(previous[i]!==value[i]&&value[i]){cell.classList.remove('is-pop');void cell.offsetWidth;cell.classList.add('is-pop')}});
   frame.classList.toggle('is-complete',value.length===6);status.textContent=value.length===6?t('Your code is ready to verify.','کُد آمادهٔ تأیید است.'):t('Type the code or paste all six digits.','کُد را بنویسید یا شش رقم را یک‌جا Paste کنید.');previous=value;
  };
  input.addEventListener('input',paint);input.addEventListener('focus',paint);input.addEventListener('blur',paint);input.addEventListener('select',paint);input.addEventListener('keyup',paint);
  input.addEventListener('paste',event=>{if(!event.clipboardData)return;event.preventDefault();input.value=normalize(event.clipboardData.getData('text'));input.setSelectionRange(input.value.length,input.value.length);input.dispatchEvent(new Event('input',{bubbles:true}))});
  input.addEventListener('click',event=>{const box=frame.getBoundingClientRect();const position=Math.min(input.value.length,Math.max(0,Math.floor((event.clientX-box.left)/box.width*6)));input.setSelectionRange(position,position);paint()});paint();
 }
 function error(dialog){dialog.classList.remove('nsc-code-shake');void dialog.offsetWidth;dialog.classList.add('nsc-code-shake');dialog.querySelector('.nsc-otp')?.classList.add('has-error')}
 function busy(dialog,on){dialog.classList.toggle('nsc-code-busy',on);dialog.querySelector('form')?.setAttribute('aria-busy',String(on));if(on)dialog.querySelector('.nsc-otp')?.classList.remove('has-error')}
 window.NSC_CODE_UI={decorate,attach,error,busy};
})();
