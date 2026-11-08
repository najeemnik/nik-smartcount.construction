/* Presentation only: move the existing form nodes, preserving their authentication handlers. */
(() => {
 'use strict';
 const icons={building:'<path d="M5 21V7l7-4 7 4v14M9 21v-4h6v4M9 8h.01M15 8h.01M9 12h.01M15 12h.01M3 21h18"/>',user:'<path d="M20 21v-2a7 7 0 0 0-14 0v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>',lock:'<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/>',eye:'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',hide:'<path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a19 19 0 0 1-3 4M6.2 6.2C3.5 8.3 2 12 2 12s3.5 7 10 7c2 0 3.8-.7 5.3-1.7M10 10a3 3 0 0 0 4 4"/>',arrow:'<path d="M5 12h14m-5-5 5 5-5 5"/>',folder:'<path d="M3 7a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',calculator:'<rect x="5" y="2" width="14" height="20" rx="3"/><path d="M8 6h8M8 11h1m6 0h1m-8 4h1m6 0h1m-8 4h1m6 0h1"/>',shield:'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>'};
 const svg=name=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+icons[name]+'</svg>';
 const tx=(en,fa,ps=fa)=>document.documentElement.lang==='en'?en:document.documentElement.lang==='ps'?ps:fa;
 function extras(){
  const form=document.querySelector('#login'),tools=form?.querySelector('.auth-tools'),forgot=document.querySelector('#forgot-password');
  if(tools&&forgot&&forgot.parentElement!==tools)tools.append(forgot);
 }
 function enhance(){
  const page=document.querySelector('.auth-page'),form=document.querySelector('#login');
  if(!page||!form)return;
  if(page.dataset.authDesigned){extras();return}
  page.dataset.authDesigned='true';
  const art=page.querySelector('.auth-art'),content=page.querySelector('.auth-content'),language=page.querySelector('.lang'),master=location.pathname.includes('office7');
  if(!art||!content||!language)return;
  art.innerHTML=`<div class="auth-art-top"><span class="auth-art-badge"><i></i> NIK · CONSTRUCTION</span><span class="auth-edition">SMART WORKSPACE</span></div><div class="auth-scene" aria-hidden="true"><div class="auth-grid-lines"></div><div class="auth-scene-word">SMART<br><span>COUNT.</span></div><div class="auth-halo"></div><div class="auth-orb"><span class="auth-orb-glint"></span></div><div class="auth-orb-ring"></div><div class="auth-glass-plinth"></div><span class="auth-spark auth-spark-one"></span><span class="auth-spark auth-spark-two"></span></div><img class="auth-scene-logo" src="/assets/brand-full.webp" alt="NiK Smart Count — Construction Accounting"><div class="auth-art-copy"><p class="auth-kicker">${tx('BUILT AROUND YOUR PROJECT','برای پروژهٔ شما ساخته شده','ستاسو د پروژې لپاره')}</p><h2>${tx('Build with vision.<br>Count with confidence.','مدیریت ساختمان،<br>با حساب‌های روشن.','خپلې ودانۍ جوړې کړئ.<br>حسابونه روښانه وساتئ.')}</h2><p>${tx('From your first purchase to the final sale. Keep your projects, accounts and documents together.','از خرید مصالح تا فروش آپارتمان؛ پروژه‌ها، حساب‌ها و اسناد خود را یک‌جا دنبال کنید.','له لومړي پېر څخه تر وروستي پلور پورې؛ پروژې، حسابونه او اسناد یو ځای وساتئ.')}</p><div class="auth-features"><span>${svg('building')}${tx('Projects','پروژه‌ها','پروژې')}</span><span>${svg('calculator')}${tx('Accounting','حسابداری','حسابداري')}</span><span>${svg('folder')}${tx('Documents','اسناد','اسناد')}</span></div></div><div class="auth-art-signature"><span>Ahmad Najeem Nik</span><span>NiK Smart Count</span></div>`;
  const title=tx(master?'Office Seven':'Welcome back',master?'ورود دفتر هفت':'خوش آمدید',master?'دفتر اووه':'ښه راغلاست');
  const intro=tx(master?'Sign in to your administration workspace.':'Sign in to your construction workspace.',master?'برای مدیریت سیستم به حساب خود وارد شوید.':'برای ادامهٔ مدیریت پروژه به حساب خود وارد شوید.',master?'د سیستم مدیریت ته ننوځئ.':'د خپلې پروژې کاري ځای ته ننوځئ.');
  content.replaceChildren();
  const panel=document.createElement('div');panel.className='auth-form-panel';panel.innerHTML=`<div class="auth-toolbar"><div class="auth-mark">${svg('building')}</div><div class="auth-language"></div></div><div class="auth-intro"><p class="eyebrow">${master?'OFFICE SEVEN':'NIK SMART COUNT'}</p><h1>${title}</h1><p>${intro}</p></div><div class="auth-card"></div><p class="auth-form-caption">${tx('Construction accounting, made smart.','حسابداری ساختمانی، هوشمند و منظم.','هوښیاره او منظمه ساختماني حسابداري.')}</p>`;
  panel.querySelector('.auth-language').append(language);panel.querySelector('.auth-card').append(form);
  content.append(panel);
  const support=document.createElement('div');support.className='auth-support-panel';support.innerHTML=`<span class="auth-support-icon">${svg('folder')}</span><div><strong>${tx('Your projects. One workspace.','پروژه‌های شما؛ یک فضای کار.','ستاسو پروژې؛ یو کاري ځای.')}</strong><span>${tx('Projects · accounts · documents','پروژه‌ها · حساب‌ها · اسناد','پروژې · حسابونه · اسناد')}</span></div><a href="/install-app.php">${tx('Install guide','رهنمای نصب','د نصب لارښود')}${svg('arrow')}</a>`;content.append(support);
  const username=form.querySelector('#username'),password=form.querySelector('#password');
  for(const[input,icon]of[[username,'user'],[password,'lock']]){
   if(!input)continue;const wrap=document.createElement('div');wrap.className='auth-input-shell';const mark=document.createElement('span');mark.className='auth-field-icon';mark.innerHTML=svg(icon);input.before(wrap);wrap.append(mark,input);
  }
  const usernameLabel=form.querySelector('label[for=username]');if(usernameLabel)usernameLabel.textContent=tx('Username or email','نام کاربری یا ایمیل','کارن نوم یا برېښنالیک');
  if(username){username.maxLength=150;username.placeholder=tx('Enter your username or email','نام کاربری یا ایمیل‌تان','خپل کارن نوم یا برېښنالیک ولیکئ')}
  if(password)password.placeholder=tx('Enter your password','رمز عبورتان','خپل پټنوم ولیکئ');
  const show=form.querySelector('#show');if(show){const original=show.onclick;const update=()=>{const visible=password.type==='text';show.innerHTML=svg(visible?'hide':'eye');show.setAttribute('aria-label',tx(visible?'Hide password':'Show password',visible?'پنهان‌کردن رمز':'نمایش رمز'));show.setAttribute('aria-pressed',String(visible));show.title=show.getAttribute('aria-label')};show.onclick=()=>{original?.();update()};update()}
  const tools=document.createElement('div');tools.className='auth-tools';tools.innerHTML='<span class="auth-session-note"><i></i>'+tx('Your personal workspace','فضای کار اختصاصی شما','ستاسو ځانګړی کاري ځای')+'</span>';
  const error=form.querySelector('#error');if(error){error.setAttribute('role','alert');error.before(tools)}else form.querySelector('button[type=submit]')?.before(tools);
  extras();
 }
 const target=document.querySelector('#app');if(target)new MutationObserver(enhance).observe(target,{childList:true,subtree:true});enhance();
})();
