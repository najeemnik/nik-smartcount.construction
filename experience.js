/* Presentation and keyboard access only; business form handlers remain in the application. */
(() => {
 const started=performance.now(),reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
 window.NSC_FINISH_BOOT=async()=>{
  const boot=document.getElementById('boot-message');if(!boot)return;
  await new Promise(r=>setTimeout(r,reduced?0:Math.max(0,650-(performance.now()-started))));
  boot.classList.add('is-leaving');await new Promise(r=>setTimeout(r,reduced?0:180));
 };
 window.addEventListener('nsc:language',()=>{const link=document.querySelector('.nsc-install-link');if(link)link.textContent=NSC_UI.t('Install app','نصب برنامه')});
 window.NSC_SLOW_BOOT=()=>{const boot=document.getElementById('boot-message');if(!boot)return;const text=boot.querySelector('[data-boot-status]');if(text){text.removeAttribute('data-en');text.textContent=NSC_UI.t('This is taking longer than usual. Check your internet connection.','ارتباط کمی طول کشید؛ اتصال اینترنت را بررسی کنید.');}const retry=boot.querySelector('button');if(retry)retry.hidden=false;};
 document.querySelector('[data-boot-retry]')?.addEventListener('click',()=>location.reload());
 const saved=new WeakMap();let last=null;
 const focusable='button:not([disabled]),a[href],input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]';
 function scan(){
  const header=document.querySelector('#root header');
  if(header&&!header.classList.contains('nsc-app-header')){
   const row=header.querySelector('.h-11');
   if(row&&row.children.length===2){
    header.classList.add('nsc-app-header');row.classList.add('nsc-header-row');
    row.children[0].classList.add('nsc-header-brand');row.children[1].classList.add('nsc-header-actions');
    const install=document.createElement('a');install.href='/install-app.php';install.className='nsc-install-link';install.textContent=NSC_UI.t('Install app','نصب برنامه');install.setAttribute('aria-label',NSC_UI.t('How to install on your phone','رهنمای نصب برنامه در موبایل'));row.children[0].append(install);
   }
  }
  for(const dialog of document.querySelectorAll('dialog[open]'))dialog.classList.add('nsc-dialog');
  for(const form of document.querySelectorAll('#root form')){
   let overlay=form.parentElement;while(overlay&&overlay!==document.body&&!overlay.classList.contains('fixed'))overlay=overlay.parentElement;
   if(!overlay||overlay===document.body)continue;
   let frame=form;while(frame.parentElement!==overlay&&frame.parentElement)frame=frame.parentElement;
   if(frame===form)frame=form.parentElement;
   if(!saved.has(frame)){
    saved.set(frame,document.activeElement);frame.classList.add('nsc-dialog');frame.setAttribute('role','dialog');frame.setAttribute('aria-modal','true');
    const heading=frame.querySelector('h2,h3');if(heading){heading.id||=crypto.randomUUID();frame.setAttribute('aria-labelledby',heading.id)}else frame.setAttribute('aria-label','فورم معلومات');
    const header=[...frame.children].find(node=>node!==form&&node.querySelector('h2,h3'));if(header)header.classList.add('nsc-dialog-header');
    requestAnimationFrame(()=>{if(frame.isConnected&&!frame.contains(document.activeElement))frame.querySelector(focusable)?.focus()});
   }
   last=frame;
  }
  // Some existing editors use buttons rather than a native form (for example budgets).
  for(const overlay of document.querySelectorAll('#root div.fixed.inset-0')){
   const frame=[...overlay.children].find(node=>node.querySelector('h2,h3')&&node.querySelector('input:not([type=file]):not([type=hidden]),select,textarea'));
   if(!frame||frame.querySelector('canvas,video')||saved.has(frame))continue;
   saved.set(frame,document.activeElement);frame.classList.add('nsc-dialog');frame.setAttribute('role','dialog');frame.setAttribute('aria-modal','true');
   const heading=frame.querySelector('h2,h3');heading.id||=crypto.randomUUID();frame.setAttribute('aria-labelledby',heading.id);
   const header=[...frame.children].find(node=>node.querySelector('h2,h3')&&!node.querySelector('input,select,textarea'));if(header)header.classList.add('nsc-dialog-header');last=frame;
  }
  if(last&&!last.isConnected){const target=saved.get(last);if(target?.isConnected)target.focus();last=null;}
 }
 new MutationObserver(scan).observe(document.getElementById('root')||document.body,{childList:true,subtree:true});scan();
 document.addEventListener('keydown',event=>{
  const frames=[...document.querySelectorAll('.nsc-dialog')].filter(e=>e.getClientRects().length);const frame=frames.at(-1);if(!frame||event.key!=='Tab')return;
  const nodes=[...frame.querySelectorAll(focusable)].filter(e=>e.getClientRects().length);if(!nodes.length){event.preventDefault();return;}
  if(event.shiftKey&&(document.activeElement===nodes[0]||!frame.contains(document.activeElement))){event.preventDefault();nodes.at(-1).focus()}
  else if(!event.shiftKey&&(document.activeElement===nodes.at(-1)||!frame.contains(document.activeElement))){event.preventDefault();nodes[0].focus()}
 });
})();
