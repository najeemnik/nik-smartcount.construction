/* A presentation layer over the existing app: no financial state or file operations live here. */
(()=>{'use strict';document.body.classList.add('nsc-refined-ui');let pending=false;const keyCards=new WeakSet();
function update(){pending=false;
 const main=document.querySelector('#root main:not(.nsc-boot)');if(!main)return;
 const dashboard=!!main.querySelector('[data-testid="kpi-card-total-expenses"]');main.classList.toggle('nsc-dashboard-page',dashboard);
 const title=main.querySelector('h1');const files=!!title&&/File Manager|فایل منیجر|د فایلونو/i.test(title.textContent);main.classList.toggle('nsc-files-page',files);
 for(const group of main.querySelectorAll('div.w-fit')){if(group.children.length===5&&[...group.children].every(e=>e.tagName==='BUTTON')&&/P&L/.test(group.textContent))group.classList.add('nsc-report-tabs');}
 if(dashboard){const first=main.querySelector('[data-testid="kpi-card-total-expenses"]');first?.parentElement.classList.add('nsc-kpi-grid');main.querySelector('div.from-blue-700.via-indigo-700')?.classList.add('nsc-project-hero');for(const card of main.querySelectorAll('[data-testid^="kpi-card-"]')){card.setAttribute('role','button');card.tabIndex=0;if(!keyCards.has(card)){keyCards.add(card);card.addEventListener('keydown',e=>{if(e.target===card&&(e.key==='Enter'||e.key===' ')){e.preventDefault();card.click()}})}}}
 if(files){const actions=main.querySelector('select[title]')?.parentElement;if(actions){actions.classList.add('nsc-files-actions');actions.parentElement?.classList.add('nsc-files-action-row');}const page=main.querySelector('.max-w-7xl>div');if(page){const panels=[...page.querySelectorAll(':scope>div,:scope>div>div')].filter(e=>e.classList.contains('border'));panels.forEach(e=>e.classList.add('nsc-files-panel'));page.children[0]?.classList.add('nsc-files-header');page.querySelector('input[type=text]')?.closest('.rounded-2xl')?.classList.add('nsc-files-toolbar');}}
 for(const button of document.querySelectorAll('.theme-nav-item')){if(button.classList.contains('active'))button.setAttribute('aria-current','page');else button.removeAttribute('aria-current')}
}
function schedule(){if(!pending){pending=true;requestAnimationFrame(update)}}new MutationObserver(schedule).observe(document.getElementById('root')||document.body,{childList:true,subtree:true});window.addEventListener('nsc:goto-tab',schedule);schedule();})();
