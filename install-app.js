(() => {
 const button=document.getElementById('install-button'),status=document.getElementById('install-status');let prompt;status.removeAttribute('data-en');
 if(matchMedia('(display-mode: standalone)').matches||navigator.standalone)status.textContent=NSC_UI.t("The app is installed on this device.","برنامه روی این دستگاه نصب است.");
 window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();prompt=event;button.hidden=false;status.textContent=NSC_UI.t("The app is ready to install.","برنامه آمادهٔ نصب است.")});
 button.onclick=async()=>{if(!prompt)return;button.disabled=true;try{await prompt.prompt();const choice=await prompt.userChoice;status.textContent=choice.outcome==='accepted'?NSC_UI.t("Continue installation in your browser.","نصب را از پیام مرورگر ادامه بده."):NSC_UI.t("You can install later from your browser menu.","هر وقت خواستی، دوباره از منوی مرورگر نصب کن.")}finally{prompt=null;button.hidden=true;button.disabled=false}};
 window.addEventListener('appinstalled',()=>{button.hidden=true;status.textContent=NSC_UI.t("App installed. Open it from your home screen.","برنامه نصب شد؛ آیکن آن در صفحهٔ اصلی است.")});
 if('serviceWorker'in navigator)navigator.serviceWorker.register('/pwa-sw.js').catch(()=>{});
})();
