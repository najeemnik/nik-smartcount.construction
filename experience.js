/* Presentation and keyboard access only; business form handlers remain in the application. */
(() => {
 const started=performance.now(),reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
 window.NSC_FINISH_BOOT=async()=>{
  const boot=document.getElementById('boot-message');if(!boot)return;
  await new Promise(r=>setTimeout(r,reduced?0:Math.max(0,650-(performance.now()-started))));
  boot.classList.add('is-leaving');await new Promise(r=>setTimeout(r,reduced?0:180));
 };
 window.NSC_SLOW_BOOT=()=>{const boot=document.getElementById('boot-message');if(!boot)return;const text=boot.querySelector('[data-boot-status]');if(text)text.textContent='ارتباط کمی طول کشید؛ اتصال اینترنت را بررسی کنید.';const retry=boot.querySelector('button');if(retry)retry.hidden=false;};
 document.querySelector('[data-boot-retry]')?.addEventListener('click',()=>location.reload());
 const saved=new WeakMap();let last=null;
 const focusable='button:not([disabled]),a[href],input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]';
 function scan(){
  for(const dialog of document.querySelectorAll('dialog[open]'))dialog.classList.add('nsc-dialog');
  for(const form of document.querySelectorAll('#root form')){
   let overlay=form.parentElement;while(overlay&&overlay!==document.body&&!overlay.classList.contains('fixed'))overlay=overlay.parentElement;
   if(!overlay||overlay===document.body)continue;
   let frame=form;while(frame.parentElement!==overlay&&frame.parentElement)frame=frame.parentElement;
   if(frame===form)frame=form.parentElement;
   if(!saved.has(frame)){
    saved.set(frame,document.activeElement);frame.classList.add('nsc-dialog');frame.setAttribute('role','dialog');frame.setAttribute('aria-modal','true');
    const heading=frame.querySelector('h2,h3');if(heading){heading.id||=crypto.randomUUID();frame.setAttribute('aria-labelledby',heading.id)}else frame.setAttribute('aria-label','فورم معلومات');
    const header=frame.firstElementChild;if(header&&header!==form)header.classList.add('nsc-dialog-header');
    requestAnimationFrame(()=>{if(frame.isConnected&&!frame.contains(document.activeElement))frame.querySelector(focusable)?.focus()});
   }
   last=frame;
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
