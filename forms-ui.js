/* Normalize presentation without replacing React form fields or their handlers. */
(()=>{'use strict';const tx=(en,fa)=>document.documentElement.lang==='en'?en:fa;
const currencies=new WeakMap();let queued=false;
function scan(){queued=false;
 for(const form of document.querySelectorAll('.nsc-dialog')){
  for(const label of form.querySelectorAll('label:not([for])')){if(label.querySelector('input,select,textarea'))continue;const next=label.nextElementSibling;let control=next?.matches('input,select,textarea')?next:next?.querySelector('input,select,textarea');if(!control){const controls=[...label.parentElement.children].filter(e=>e.matches('input,select,textarea'));if(controls.length===1)control=controls[0]}if(control){control.id||='nsc-field-'+crypto.randomUUID();label.htmlFor=control.id}}

  for(const grid of form.querySelectorAll('.grid')){
   const cells=[...grid.children].filter(c=>c.querySelector(':scope > label')&&c.querySelector(':scope > input,:scope > select,:scope > textarea,:scope > .nsc-currency'));
   if(cells.length<2)continue;grid.classList.add('nsc-field-grid');
   for(const cell of cells){cell.classList.add('nsc-field-cell');const label=cell.querySelector(':scope > label'),control=cell.querySelector('input,select,textarea');if(label&&control){control.id||='nsc-field-'+crypto.randomUUID();label.htmlFor=control.id}}
  }
  for(const select of form.querySelectorAll('select')){
   const opts=[...select.options];if(opts.length!==2||!opts.every(o=>['AFN','USD'].includes(o.value)))continue;
   let widget=currencies.get(select);
   if(!widget){widget=document.createElement('div');widget.className='nsc-currency';widget.setAttribute('role','group');widget.setAttribute('aria-label',tx('Currency','واحد پول'));select.after(widget);for(const code of ['AFN','USD']){let b=document.createElement('button');b.type='button';b.dataset.currency=code;b.textContent=code==='AFN'?'AFN (؋)':'USD ($)';b.onclick=()=>{if(select.disabled)return;const setter=Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,'value').set;setter.call(select,code);select.dispatchEvent(new Event('change',{bubbles:true}));schedule()};widget.append(b)}currencies.set(select,widget);select.classList.add('nsc-currency-native')}
   for(const b of widget.children){const active=b.dataset.currency===select.value;if(b.getAttribute('aria-pressed')!==String(active))b.setAttribute('aria-pressed',String(active));b.disabled=select.disabled}
  }
  for(const div of form.querySelectorAll('div')){
   const buttons=[...div.children].filter(e=>e.tagName==='BUTTON');if(buttons.length===2&&buttons.some(e=>/\bAFN\b/.test(e.textContent.trim()))&&buttons.some(e=>/\bUSD\b/.test(e.textContent.trim()))){div.classList.add('nsc-currency');div.setAttribute('role','group');div.setAttribute('aria-label',tx('Currency','واحد پول'));for(const button of buttons){const code=/\bAFN\b/.test(button.textContent)?'AFN':'USD';button.dataset.currency=code;button.setAttribute('aria-label',code==='AFN'?'AFN (؋)':'USD ($)')}}
  }
 }
}
function schedule(){if(!queued){queued=true;requestAnimationFrame(scan)}}
new MutationObserver(schedule).observe(document.getElementById('root')||document.body,{childList:true,subtree:true});document.addEventListener('change',schedule);window.addEventListener('nsc:language',schedule);schedule();})();
