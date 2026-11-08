/* Explicit per-project employee access. The server rechecks every operation. */
(function(){
 'use strict';
 const actions=['view','create','edit','delete','approve','print','export'];
 const labels=['مشاهده','ثبت','ویرایش','حذف','تأیید','چاپ','خروجی'];
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let projects=[],modules=[],owner={},names={};
 window.NSC_TEAM_GRANTS={
  async load(api){const result=await api('workspace.php');projects=Array.isArray(result.state?.projects)?result.state.projects:[];},
  render(user,available,currentOwner,moduleNames){
   modules=available;owner=currentOwner;names=moduleNames;
   const grants=Array.isArray(user.projectGrants)?user.projectGrants:[];
   const assigned=Array.isArray(user.assignedProjectIds)?user.assignedProjectIds:[];
   return `<section id="project-access" dir="rtl"><h3>پروژه‌ها و صلاحیت‌های کارمند</h3><p>پروژه را انتخاب کنید؛ سپس در همان پروژه، مشاهده و کارهای مجاز هر بخش را علامت بزنید. پروژه‌های انتخاب‌نشده در دسترس کارمند نیستند. پروژهٔ تازه نیز خودکار دسترسی نمی‌گیرد.</p>${!projects.length?'<p role="alert">اول یک پروژه در حسابداری بسازید؛ سپس حساب کارمند را ایجاد کنید.</p>':''}${projects.map((project,index)=>{
    const grant=grants.find(g=>g.projectId===project.id)||grants.find(g=>g.projectId==='*');
    const selected=grants.length?!!grant:assigned.length?assigned.includes(project.id)||assigned.includes('*'):false;
    return `<details data-project-index="${index}" ${selected?'open':''} style="border:1px solid #cbd5e1;border-radius:12px;margin:12px 0;padding:12px"><summary><label><input type="checkbox" data-project="${esc(project.id)}" ${selected?'checked':''}> ${esc(project.name||project.code||project.id)}</label></summary><div class="project-rights" ${selected?'':'hidden'}><p>هر کار تنها با «مشاهده» همان بخش فعال می‌شود. نقش مشاهده‌کننده اجازهٔ تغییر ندارد.</p><div class="table-wrap"><table><thead><tr><th>بخش</th>${labels.map(l=>`<th>${l}</th>`).join('')}</tr></thead><tbody>${modules.map(mod=>`<tr><td>${esc(names[mod]||mod)}</td>${actions.map(action=>{
      const rights=grant?.sections?.[mod]||(!grants.length?user.permissions?.[mod]:null)||{};
      const allowed=owner.effectivePermissions?.[mod]?.[action]===true;
      const checked=rights[action]===true||(action==='delete'&&rights.del===true)||(!grant&&!grants.length&&rights[action]===undefined&&action==='view');
      return `<td><input type="checkbox" data-project-perm="${index}:${mod}:${action}" aria-label="${esc(project.name||project.id)} ${mod} ${action}" data-ceiling="${allowed?'1':'0'}" ${allowed?'':'disabled'} ${checked&&allowed?'checked':''}></td>`;
    }).join('')}</tr>`).join('')}</tbody></table></div></div></details>`;
   }).join('')}</section>`;
  },
  bind(){
   const role=document.querySelector('#role');
   const applyRole=()=>document.querySelectorAll('[data-project-perm]').forEach(box=>{const action=box.dataset.projectPerm.split(':')[2];box.disabled=box.dataset.ceiling!=='1'||(role?.value==='viewer'&&action!=='view');});
   role?.addEventListener('change',applyRole);applyRole();
   document.querySelectorAll('[data-project]').forEach(box=>box.addEventListener('change',()=>{
    const card=box.closest('details');card.querySelector('.project-rights').hidden=!box.checked;if(box.checked)card.open=true;
   }));
   document.querySelectorAll('[data-project-perm]').forEach(box=>box.addEventListener('change',()=>{
    const [index,mod,action]=box.dataset.projectPerm.split(':');
    const view=document.querySelector(`[data-project-perm="${index}:${mod}:view"]`);
    if(action!=='view'&&box.checked&&view&&!view.disabled)view.checked=true;
    if(action==='view'&&!box.checked)actions.slice(1).forEach(a=>{const other=document.querySelector(`[data-project-perm="${index}:${mod}:${a}"]`);if(other)other.checked=false;});
   }));
  },
  read(){
   const selected=[...document.querySelectorAll('[data-project]:checked')];
   if(!selected.length)throw Error('حداقل یک پروژه و صلاحیت مشاهدهٔ یک بخش را انتخاب کنید.');
   const permissions={users:Object.fromEntries(actions.map(a=>[a,false]))};
   modules.forEach(m=>permissions[m]=Object.fromEntries(actions.map(a=>[a,false])));
   const projectGrants=selected.map(box=>{
    const index=box.closest('details').dataset.projectIndex,sections={};
    modules.forEach(mod=>{
     const view=document.querySelector(`[data-project-perm="${index}:${mod}:view"]`);
     sections[mod]={};actions.forEach(action=>{const el=document.querySelector(`[data-project-perm="${index}:${mod}:${action}"]`);const allowed=!!(view?.checked&&!view.disabled&&el?.checked&&!el.disabled);sections[mod][action]=allowed;permissions[mod][action] ||= allowed;});
    });
    if(!Object.values(sections).some(s=>s.view))throw Error('برای هر پروژهٔ انتخاب‌شده حداقل یک بخش قابل مشاهده تعیین کنید.');
    return {projectId:box.dataset.project,sections};
   });
   return {permissions,projectGrants,assignedProjectIds:projectGrants.map(g=>g.projectId)};
  }
 };
})();
